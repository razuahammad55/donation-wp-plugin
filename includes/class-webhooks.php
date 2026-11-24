<?php
/**
 * Gateway webhooks: keeps donations in sync when the donor never returns to
 * the success page (renewals, refunds, failed payments).
 *
 * Endpoints:
 *   /wp-json/cdf/v1/stripe
 *   /wp-json/cdf/v1/paypal
 *
 * @package CDF_Donation
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'rest_api_init', 'cdf_register_webhook_routes' );
/**
 * Register webhook endpoints.
 */
function cdf_register_webhook_routes() {
    register_rest_route(
        'cdf/v1',
        '/stripe',
        array(
            'methods'             => 'POST',
            'callback'            => 'cdf_handle_stripe_webhook',
            'permission_callback' => '__return_true',
        )
    );

    register_rest_route(
        'cdf/v1',
        '/paypal',
        array(
            'methods'             => 'POST',
            'callback'            => 'cdf_handle_paypal_webhook',
            'permission_callback' => '__return_true',
        )
    );
}

/**
 * Verify a Stripe webhook signature.
 *
 * @param string $payload   Raw body.
 * @param string $sig_header Stripe-Signature header.
 * @param string $secret    Signing secret.
 * @return bool
 */
function cdf_verify_stripe_signature( $payload, $sig_header, $secret ) {
    if ( empty( $sig_header ) || empty( $secret ) ) {
        return false;
    }

    $parts     = array();
    $timestamp = 0;
    $signature = '';

    foreach ( explode( ',', $sig_header ) as $pair ) {
        $kv = explode( '=', trim( $pair ), 2 );
        if ( 2 !== count( $kv ) ) {
            continue;
        }
        if ( 't' === $kv[0] ) {
            $timestamp = (int) $kv[1];
        } elseif ( 'v1' === $kv[0] ) {
            $signature = $kv[1];
        }
    }

    if ( ! $timestamp || ! $signature ) {
        return false;
    }

    // 5 minute tolerance to limit replay attacks.
    if ( abs( time() - $timestamp ) > 300 ) {
        return false;
    }

    $expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
    return hash_equals( $expected, $signature );
}

/**
 * Handle Stripe webhook events.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function cdf_handle_stripe_webhook( $request ) {
    $payload = $request->get_body();
    $keys    = cdf_get_gateway_keys();
    $secret  = isset( $keys['settings']['stripe_webhook_secret'] ) ? $keys['settings']['stripe_webhook_secret'] : '';

    $sig_header = isset( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) ) : '';

    if ( empty( $secret ) || ! cdf_verify_stripe_signature( $payload, $sig_header, $secret ) ) {
        cdf_log_error( 'Stripe webhook signature verification failed' );
        return new WP_REST_Response( array( 'error' => 'invalid signature' ), 400 );
    }

    $event = json_decode( $payload, true );
    if ( empty( $event['type'] ) ) {
        return new WP_REST_Response( array( 'error' => 'invalid payload' ), 400 );
    }

    $object = isset( $event['data']['object'] ) ? $event['data']['object'] : array();

    switch ( $event['type'] ) {
        case 'checkout.session.completed':
            cdf_webhook_log_stripe_session( $object );
            break;

        case 'invoice.payment_succeeded':
            cdf_webhook_log_stripe_invoice( $object );
            break;

        case 'charge.refunded':
            $charge_id = isset( $object['id'] ) ? $object['id'] : '';
            $payment   = isset( $object['payment_intent'] ) ? $object['payment_intent'] : '';
            $updated   = false;
            if ( $payment ) {
                $updated = cdf_update_donation_status( $payment, 'refunded' );
            }
            if ( ! $updated && $charge_id ) {
                cdf_update_donation_status( $charge_id, 'refunded' );
            }
            break;

        case 'invoice.payment_failed':
            $subscription = isset( $object['subscription'] ) ? $object['subscription'] : '';
            if ( $subscription ) {
                cdf_update_donation_status( $subscription, 'failed' );
            }
            break;
    }

    return new WP_REST_Response( array( 'received' => true ), 200 );
}

/**
 * Log a donation from a Stripe checkout session webhook.
 *
 * @param array $session Session object.
 */
function cdf_webhook_log_stripe_session( $session ) {
    $txn = isset( $session['id'] ) ? sanitize_text_field( $session['id'] ) : '';
    if ( ! $txn || 'paid' !== ( isset( $session['payment_status'] ) ? $session['payment_status'] : '' ) ) {
        return;
    }
    if ( cdf_donation_exists_by_txn_id( $txn ) ) {
        return;
    }

    $reference = isset( $session['metadata']['cdf_reference'] ) ? sanitize_text_field( $session['metadata']['cdf_reference'] ) : '';
    $pending   = cdf_get_pending_record( $reference );

    $amount = isset( $session['amount_total'] ) ? floatval( $session['amount_total'] ) / 100 : 0;
    if ( $amount <= 0 ) {
        return;
    }

    $keys = cdf_get_gateway_keys();

    cdf_log_donation(
        array(
            'first_name'      => isset( $session['metadata']['form_first_name'] ) ? $session['metadata']['form_first_name'] : ( $pending ? $pending['first_name'] : '' ),
            'last_name'       => isset( $session['metadata']['form_last_name'] ) ? $session['metadata']['form_last_name'] : ( $pending ? $pending['last_name'] : '' ),
            'email'           => isset( $session['customer_details']['email'] ) ? $session['customer_details']['email'] : ( $pending ? $pending['email'] : '' ),
            'phone'           => isset( $session['metadata']['form_phone'] ) ? $session['metadata']['form_phone'] : ( $pending ? $pending['phone'] : '' ),
            'amount'          => $amount,
            'currency'        => isset( $session['currency'] ) ? strtoupper( $session['currency'] ) : cdf_get_currency(),
            'gateway'         => 'Stripe',
            'frequency'       => ( isset( $session['mode'] ) && 'subscription' === $session['mode'] ) ? 'Monthly' : 'One-Time',
            'environment'     => $keys['is_sandbox'] ? 'Test / Sandbox' : 'Live',
            'transaction_id'  => $txn,
            'subscription_id' => isset( $session['subscription'] ) ? $session['subscription'] : '',
            'payment_intent'  => isset( $session['payment_intent'] ) ? $session['payment_intent'] : '',
            'status'          => 'completed',
            'anonymous'       => $pending ? $pending['anonymous'] : '0',
        )
    );
}

/**
 * Log a renewal payment from a Stripe invoice webhook.
 *
 * @param array $invoice Invoice object.
 */
function cdf_webhook_log_stripe_invoice( $invoice ) {
    if ( empty( $invoice['subscription'] ) || empty( $invoice['id'] ) ) {
        return;
    }
    $subscription = sanitize_text_field( $invoice['subscription'] );
    $invoice_id   = sanitize_text_field( $invoice['id'] );

    // Only log renewals (the first payment is logged via the checkout session).
    if ( ! empty( $invoice['billing_reason'] ) && 'subscription_create' === $invoice['billing_reason'] ) {
        return;
    }
    if ( cdf_donation_exists_by_txn_id( $invoice_id ) ) {
        return;
    }

    $amount = isset( $invoice['amount_paid'] ) ? floatval( $invoice['amount_paid'] ) / 100 : 0;
    if ( $amount <= 0 ) {
        return;
    }

    // Reuse donor details from the first donation of this subscription.
    $parent = get_posts(
        array(
            'post_type'      => 'donation',
            'meta_key'       => '_donation_subscription_id',
            'meta_value'     => $subscription,
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'post_status'    => 'publish',
        )
    );

    $first = $last = $email = $phone = '';
    if ( ! empty( $parent ) ) {
        $first = get_post_meta( $parent[0], '_donation_first_name', true );
        $last  = get_post_meta( $parent[0], '_donation_last_name', true );
        $email = get_post_meta( $parent[0], '_donation_email', true );
        $phone = get_post_meta( $parent[0], '_donation_phone', true );
    }

    $keys = cdf_get_gateway_keys();

    cdf_log_donation(
        array(
            'first_name'      => $first,
            'last_name'       => $last,
            'email'           => $email,
            'phone'           => $phone,
            'amount'          => $amount,
            'currency'        => isset( $invoice['currency'] ) ? strtoupper( $invoice['currency'] ) : cdf_get_currency(),
            'gateway'         => 'Stripe',
            'frequency'       => 'Monthly',
            'environment'     => $keys['is_sandbox'] ? 'Test / Sandbox' : 'Live',
            'transaction_id'  => $invoice_id,
            'subscription_id' => $subscription,
            'status'          => 'completed',
        )
    );
}

/**
 * Handle PayPal webhook events (verified through the PayPal API).
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function cdf_handle_paypal_webhook( $request ) {
    $payload = $request->get_body();
    $event   = json_decode( $payload, true );
    $keys    = cdf_get_gateway_keys();
    $webhook_id = isset( $keys['settings']['paypal_webhook_id'] ) ? $keys['settings']['paypal_webhook_id'] : '';

    if ( empty( $webhook_id ) ) {
        return new WP_REST_Response( array( 'error' => 'webhook id not configured' ), 400 );
    }

    // Ask PayPal to verify the signature of this exact event.
    $access_token = cdf_get_paypal_access_token();
    if ( empty( $access_token ) ) {
        return new WP_REST_Response( array( 'error' => 'cannot authenticate' ), 400 );
    }

    $api_base = $keys['is_sandbox'] ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';

    $verify = wp_remote_post(
        $api_base . '/v1/notifications/verify-webhook-signature',
        array(
            'timeout' => 20,
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode(
                array(
                    'auth_algo'         => isset( $_SERVER['HTTP_PAYPAL_AUTH_ALGO'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_PAYPAL_AUTH_ALGO'] ) ) : '',
                    'cert_url'          => isset( $_SERVER['HTTP_PAYPAL_CERT_URL'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_PAYPAL_CERT_URL'] ) ) : '',
                    'transmission_id'   => isset( $_SERVER['HTTP_PAYPAL_TRANSMISSION_ID'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_PAYPAL_TRANSMISSION_ID'] ) ) : '',
                    'transmission_sig'  => isset( $_SERVER['HTTP_PAYPAL_TRANSMISSION_SIG'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_PAYPAL_TRANSMISSION_SIG'] ) ) : '',
                    'transmission_time' => isset( $_SERVER['HTTP_PAYPAL_TRANSMISSION_TIME'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_PAYPAL_TRANSMISSION_TIME'] ) ) : '',
                    'webhook_id'        => $webhook_id,
                    'webhook_event'     => $event,
                )
            ),
        )
    );

    if ( is_wp_error( $verify ) ) {
        return new WP_REST_Response( array( 'error' => 'verification failed' ), 400 );
    }

    $result = json_decode( wp_remote_retrieve_body( $verify ), true );
    if ( empty( $result['verification_status'] ) || 'SUCCESS' !== $result['verification_status'] ) {
        cdf_log_error( 'PayPal webhook verification failed' );
        return new WP_REST_Response( array( 'error' => 'invalid signature' ), 400 );
    }

    $type   = isset( $event['event_type'] ) ? $event['event_type'] : '';
    $object = isset( $event['resource'] ) ? $event['resource'] : array();

    switch ( $type ) {
        case 'PAYMENT.CAPTURE.COMPLETED':
            $order_id = isset( $object['supplementary_data']['related_ids']['order_id'] )
                ? sanitize_text_field( $object['supplementary_data']['related_ids']['order_id'] )
                : '';

            if ( $order_id && ! cdf_donation_exists_by_txn_id( $order_id ) ) {
                if ( function_exists( 'cdf_verify_paypal_order' ) ) {
                    cdf_verify_paypal_order( $order_id, $keys, '' );
                }
            }
            break;

        case 'PAYMENT.CAPTURE.REFUNDED':
        case 'PAYMENT.CAPTURE.REVERSED':
            $order_id = isset( $object['supplementary_data']['related_ids']['order_id'] )
                ? sanitize_text_field( $object['supplementary_data']['related_ids']['order_id'] )
                : '';
            if ( $order_id ) {
                cdf_update_donation_status( $order_id, 'refunded' );
            }
            break;

        case 'BILLING.SUBSCRIPTION.ACTIVATED':
            $sub_id = isset( $object['id'] ) ? sanitize_text_field( $object['id'] ) : '';
            if ( $sub_id && ! cdf_donation_exists_by_txn_id( $sub_id ) ) {
                if ( function_exists( 'cdf_verify_paypal_subscription' ) ) {
                    cdf_verify_paypal_subscription( $sub_id, $keys, '' );
                }
            }
            break;

        case 'BILLING.SUBSCRIPTION.CANCELLED':
        case 'BILLING.SUBSCRIPTION.SUSPENDED':
            $sub_id = isset( $object['id'] ) ? sanitize_text_field( $object['id'] ) : '';
            if ( $sub_id ) {
                cdf_update_donation_status( $sub_id, 'failed' );
            }
            break;

        case 'PAYMENT.SALE.COMPLETED':
            // Renewal for subscription based (legacy) billing.
            $sub_id  = isset( $object['billing_agreement_id'] ) ? sanitize_text_field( $object['billing_agreement_id'] ) : '';
            $sale_id = isset( $object['id'] ) ? sanitize_text_field( $object['id'] ) : '';
            $amount  = isset( $object['amount']['total'] ) ? floatval( $object['amount']['total'] ) : 0;
            $currency = isset( $object['amount']['currency'] ) ? strtoupper( $object['amount']['currency'] ) : cdf_get_currency();

            if ( $sub_id && $sale_id && $amount > 0 && ! cdf_donation_exists_by_txn_id( $sale_id ) ) {
                $parent = get_posts(
                    array(
                        'post_type'      => 'donation',
                        'meta_key'       => '_donation_subscription_id',
                        'meta_value'     => $sub_id,
                        'posts_per_page' => 1,
                        'fields'         => 'ids',
                        'post_status'    => 'publish',
                    )
                );
                cdf_log_donation(
                    array(
                        'first_name'      => ! empty( $parent ) ? get_post_meta( $parent[0], '_donation_first_name', true ) : '',
                        'last_name'       => ! empty( $parent ) ? get_post_meta( $parent[0], '_donation_last_name', true ) : '',
                        'email'           => ! empty( $parent ) ? get_post_meta( $parent[0], '_donation_email', true ) : '',
                        'phone'           => ! empty( $parent ) ? get_post_meta( $parent[0], '_donation_phone', true ) : '',
                        'amount'          => $amount,
                        'currency'        => $currency,
                        'gateway'         => 'PayPal',
                        'frequency'       => 'Monthly',
                        'environment'     => $keys['is_sandbox'] ? 'Test / Sandbox' : 'Live',
                        'transaction_id'  => $sale_id,
                        'subscription_id' => $sub_id,
                        'status'          => 'completed',
                    )
                );
            }
            break;
    }

    return new WP_REST_Response( array( 'received' => true ), 200 );
}
