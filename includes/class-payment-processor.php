<?php
/**
 * Payment processor: form submission, gateway requests, return verification,
 * donation logging.
 *
 * @package CDF_Donation
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Convert an error code into a user facing message and send the donor back
 * to the form instead of killing the page with wp_die().
 *
 * @param string $code Error code.
 */
function cdf_redirect_with_error( $code ) {
    $referer = wp_get_referer();
    if ( ! $referer ) {
        $referer = home_url( '/' );
    }
    $referer = remove_query_arg( array( 'cdf_error', 'cdf_success' ), $referer );
    $url     = add_query_arg( 'cdf_error', rawurlencode( $code ), $referer );
    wp_safe_redirect( $url );
    exit;
}

/**
 * Human readable message for an error code.
 *
 * @param string $code Code.
 * @return string
 */
function cdf_get_error_message( $code ) {
    $messages = array(
        'nonce'           => __( 'Your session expired. Please refresh the page and try again.', CDF_PLUGIN_TEXT_DOMAIN ),
        'invalid_email'   => __( 'Please enter a valid email address.', CDF_PLUGIN_TEXT_DOMAIN ),
        'invalid_name'    => __( 'Please enter your first name.', CDF_PLUGIN_TEXT_DOMAIN ),
        'invalid_amount'  => __( 'Please enter a valid donation amount.', CDF_PLUGIN_TEXT_DOMAIN ),
        'amount_range'    => sprintf(
            /* translators: 1: min amount, 2: max amount */
            __( 'Donation amount must be between %1$s and %2$s.', CDF_PLUGIN_TEXT_DOMAIN ),
            cdf_get_currency_symbol() . number_format( floatval( cdf_get_general_settings()['min_amount'] ), 2 ),
            cdf_get_currency_symbol() . number_format( floatval( cdf_get_general_settings()['max_amount'] ), 2 )
        ),
        'invalid_gateway' => __( 'Please choose a valid payment method.', CDF_PLUGIN_TEXT_DOMAIN ),
        'gateway_unavailable' => __( 'That payment method is not available right now. Please choose another.', CDF_PLUGIN_TEXT_DOMAIN ),
        'spam'            => __( 'Your submission could not be processed. Please try again.', CDF_PLUGIN_TEXT_DOMAIN ),
        'rate_limit'      => __( 'Too many attempts. Please wait a few minutes and try again.', CDF_PLUGIN_TEXT_DOMAIN ),
        'duplicate'       => __( 'A donation is already being processed. Please wait a moment.', CDF_PLUGIN_TEXT_DOMAIN ),
        'gateway_error'   => __( 'The payment gateway could not be reached. Please try again in a moment.', CDF_PLUGIN_TEXT_DOMAIN ),
    );
    $code = sanitize_key( $code );
    return isset( $messages[ $code ] ) ? $messages[ $code ] : __( 'Something went wrong. Please try again.', CDF_PLUGIN_TEXT_DOMAIN );
}

/**
 * Get client IP (best effort, used only for rate limiting).
 *
 * @return string
 */
function cdf_get_client_ip() {
    $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
    return $ip ? $ip : '0.0.0.0';
}

/**
 * Simple rate limiting per IP.
 *
 * @return bool True when the request is allowed.
 */
function cdf_check_rate_limit() {
    $key   = 'cdf_rate_' . md5( cdf_get_client_ip() );
    $count = (int) get_transient( $key );
    if ( $count >= 10 ) {
        return false;
    }
    set_transient( $key, $count + 1, HOUR_IN_SECONDS );
    return true;
}

add_action( 'template_redirect', 'cdf_listen_frontend_donation_submission' );

/**
 * Listen for donation form submissions.
 */
function cdf_listen_frontend_donation_submission() {
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    if ( ! isset( $_POST['cdf_action'] ) || 'process_donation' !== $_POST['cdf_action'] ) {
        return;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $type = isset( $_POST['donation_form_type'] ) && 'monthly' === $_POST['donation_form_type'] ? 'monthly' : 'one_time';

    // Nonce verification. Disabling it is only meant for sites whose page
    // cache serves stale nonces (Donations → Form → Security). Honeypot,
    // rate limiting and every validation rule still apply either way.
    if ( ! cdf_is_nonce_check_disabled() ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $nonce = isset( $_POST['cdf_donation_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['cdf_donation_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'cdf_process_donation' ) ) {
            // Legacy forms from cached pages used type specific actions.
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $legacy = 'monthly' === $type
                ? ( isset( $_POST['custom_monthly_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_monthly_nonce'] ) ) : '' )
                : ( isset( $_POST['custom_onetime_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_onetime_nonce'] ) ) : '' );
            $legacy_action = 'monthly' === $type ? 'execute_monthly_donation_nonce' : 'execute_onetime_donation_nonce';
            if ( ! $legacy || ! wp_verify_nonce( $legacy, $legacy_action ) ) {
                cdf_redirect_with_error( 'nonce' );
            }
        }
    }

    // Honeypot: bots fill every field.
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    if ( ! empty( $_POST['cdf_website'] ) ) {
        cdf_redirect_with_error( 'spam' );
    }

    if ( ! cdf_check_rate_limit() ) {
        cdf_redirect_with_error( 'rate_limit' );
    }

    cdf_process_gateway_request( $type );
}

/**
 * Validate and hand the request to the chosen gateway.
 *
 * @param string $type one_time|monthly.
 */
function cdf_process_gateway_request( $type ) {
    $general = cdf_get_general_settings();

    // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified above.
    $chosen_gateway = isset( $_POST['payment_method'] ) ? sanitize_key( wp_unslash( $_POST['payment_method'] ) ) : '';
    $custom_amount  = isset( $_POST['donation_amount'] ) ? floatval( wp_unslash( $_POST['donation_amount'] ) ) : 0;
    $preset_amount  = isset( $_POST['preset_amount'] ) ? floatval( wp_unslash( $_POST['preset_amount'] ) ) : 0;
    $email          = isset( $_POST['donor_email'] ) ? sanitize_email( wp_unslash( $_POST['donor_email'] ) ) : '';
    $first_name     = isset( $_POST['donor_first'] ) ? sanitize_text_field( wp_unslash( $_POST['donor_first'] ) ) : '';
    $last_name      = isset( $_POST['donor_last'] ) ? sanitize_text_field( wp_unslash( $_POST['donor_last'] ) ) : '';
    $phone          = isset( $_POST['donor_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['donor_phone'] ) ) : '';
    $is_anonymous   = isset( $_POST['donor_anonymous'] ) ? '1' : '0';
    // phpcs:enable WordPress.Security.NonceVerification.Missing

    // A typed custom amount always wins; otherwise the selected preset is used.
    // This covers both form modes, so the amount can never be empty silently.
    if ( $custom_amount <= 0 && $preset_amount > 0 ) {
        $custom_amount = $preset_amount;
    }

    // ---- Server side validation ----
    if ( ! in_array( $chosen_gateway, array( 'stripe', 'paypal' ), true ) ) {
        cdf_redirect_with_error( 'invalid_gateway' );
    }
    if ( empty( $first_name ) ) {
        cdf_redirect_with_error( 'invalid_name' );
    }
    if ( ! is_email( $email ) ) {
        cdf_redirect_with_error( 'invalid_email' );
    }
    if ( $custom_amount <= 0 ) {
        cdf_redirect_with_error( 'invalid_amount' );
    }
    $min = floatval( $general['min_amount'] );
    $max = floatval( $general['max_amount'] );
    if ( $custom_amount < $min || $custom_amount > $max ) {
        cdf_redirect_with_error( 'amount_range' );
    }

    if ( 'monthly' === $type && '1' !== $general['enable_monthly'] ) {
        cdf_redirect_with_error( 'gateway_unavailable' );
    }
    if ( 'one_time' === $type && '1' !== $general['enable_onetime'] ) {
        cdf_redirect_with_error( 'gateway_unavailable' );
    }
    if ( ! cdf_is_gateway_configured( $chosen_gateway ) ) {
        cdf_redirect_with_error( 'gateway_unavailable' );
    }

    // ---- Idempotency / double submit guard ----
    $lock_key = 'cdf_lock_' . md5( strtolower( $email ) . '|' . $custom_amount . '|' . $type . '|' . $chosen_gateway );
    if ( get_transient( $lock_key ) ) {
        cdf_redirect_with_error( 'duplicate' );
    }
    set_transient( $lock_key, 1, 60 );

    // ---- Pending donation record ----
    // Donor submitted data is stored server-side and referenced by an
    // unguessable key, so the return URL never carries amount/name/email.
    $reference = wp_generate_password( 32, false, false );
    $pending   = array(
        'first_name'   => $first_name,
        'last_name'    => $last_name,
        'email'        => $email,
        'phone'        => $phone,
        'amount'       => $custom_amount,
        'frequency'    => 'monthly' === $type ? 'Monthly' : 'One-Time',
        'gateway'      => 'stripe' === $chosen_gateway ? 'Stripe' : 'PayPal',
        'anonymous'    => $is_anonymous,
        'currency'     => cdf_get_currency(),
        'created'      => time(),
    );
    set_transient( 'cdf_pending_' . $reference, $pending, 3 * HOUR_IN_SECONDS );

    $keys = cdf_get_gateway_keys();

    if ( 'stripe' === $chosen_gateway ) {
        cdf_create_stripe_session( $type, $custom_amount, $email, $first_name, $last_name, $reference, $keys, $pending );
    } else {
        cdf_create_paypal_checkout( $type, $custom_amount, $email, $first_name, $last_name, $reference, $keys, $pending );
    }
}

/**
 * Build the return URLs used by both gateways.
 *
 * @param string $reference Pending record key.
 * @return array
 */
function cdf_get_gateway_return_urls( $reference ) {
    return array(
        'success' => add_query_arg( 'cdf_ref', $reference, cdf_get_success_url() ),
        'cancel'  => cdf_get_cancel_url(),
    );
}

/**
 * Create a Stripe Checkout session and redirect.
 *
 * @param string $type        one_time|monthly.
 * @param float  $amount      Amount.
 * @param string $email       Donor email.
 * @param string $first_name  First name.
 * @param string $last_name   Last name.
 * @param string $reference   Pending key.
 * @param array  $keys        Gateway keys.
 * @param array  $pending     Pending data.
 */
function cdf_create_stripe_session( $type, $amount, $email, $first_name, $last_name, $reference, $keys, $pending ) {
    $stripe_secret = $keys['stripe_secret'];
    $currency      = strtolower( cdf_get_currency() );
    $general       = cdf_get_general_settings();
    $urls          = cdf_get_gateway_return_urls( $reference );

    // Braces must stay literal for Stripe to substitute the session ID.
    $success_url = $urls['success'] . ( false === strpos( $urls['success'], '?' ) ? '?' : '&' ) . 'session_id={CHECKOUT_SESSION_ID}';

    $body = array(
        'mode'                                          => 'one_time' === $type ? 'payment' : 'subscription',
        'customer_email'                                => $email,
        'success_url'                                   => $success_url,
        'cancel_url'                                    => $urls['cancel'],
        'metadata[cdf_reference]'                       => $reference,
        'metadata[form_first_name]'                     => $first_name,
        'metadata[form_last_name]'                      => $last_name,
        'metadata[form_phone]'                          => $pending['phone'],
        'metadata[donation_type]'                       => $pending['frequency'],
        'payment_method_types[0]'                       => 'card',
        'line_items[0][quantity]'                       => 1,
        'line_items[0][price_data][currency]'           => $currency,
        'line_items[0][price_data][unit_amount]'        => (int) round( $amount * 100 ),
        'line_items[0][price_data][product_data][name]' => 'one_time' === $type
            ? __( 'One-Time Donation', CDF_PLUGIN_TEXT_DOMAIN )
            : __( 'Monthly Recurring Donation', CDF_PLUGIN_TEXT_DOMAIN ),
    );

    if ( 'monthly' === $type ) {
        $body['line_items[0][price_data][recurring][interval]'] = 'month';
    }

    // Allow Link as an extra method when enabled (wallets ride on "card").
    $methods = isset( $general['stripe_payment_methods'] ) ? $general['stripe_payment_methods'] : 'card';
    if ( false !== strpos( $methods, 'link' ) ) {
        $body['payment_method_types[1]'] = 'link';
    }

    $body = apply_filters( 'cdf_stripe_checkout_body', $body, $pending );

    $args = array(
        'timeout' => 20,
        'headers' => array(
            'Authorization' => 'Bearer ' . $stripe_secret,
            'Content-Type'  => 'application/x-www-form-urlencoded',
        ),
        'body'    => $body,
    );

    // Idempotency key prevents duplicate sessions from double submits.
    $args['headers']['Idempotency-Key'] = 'cdf_' . $reference;

    $response = wp_remote_post( 'https://api.stripe.com/v1/checkout/sessions', $args );

    if ( is_wp_error( $response ) ) {
        cdf_log_error( 'Stripe checkout WP_Error', array( 'error' => $response->get_error_message() ) );
        delete_transient( 'cdf_pending_' . $reference );
        cdf_redirect_with_error( 'gateway_error' );
    }

    $code = wp_remote_retrieve_response_code( $response );
    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( 200 !== $code || empty( $data['url'] ) ) {
        cdf_log_error(
            'Stripe checkout failed',
            array(
                'code'    => $code,
                'message' => isset( $data['error']['message'] ) ? $data['error']['message'] : '',
            )
        );
        delete_transient( 'cdf_pending_' . $reference );
        cdf_redirect_with_error( 'gateway_error' );
    }

    wp_redirect( esc_url_raw( $data['url'] ) );
    exit;
}

/**
 * Create a PayPal order / subscription and redirect.
 *
 * @param string $type       one_time|monthly.
 * @param float  $amount     Amount.
 * @param string $email      Donor email.
 * @param string $first_name First name.
 * @param string $last_name  Last name.
 * @param string $reference  Pending key.
 * @param array  $keys       Gateway keys.
 * @param array  $pending    Pending data.
 */
function cdf_create_paypal_checkout( $type, $amount, $email, $first_name, $last_name, $reference, $keys, $pending ) {
    $access_token = cdf_get_paypal_access_token();
    $api_base     = $keys['is_sandbox'] ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
    $currency     = cdf_get_currency();
    $urls         = cdf_get_gateway_return_urls( $reference );

    if ( empty( $access_token ) ) {
        delete_transient( 'cdf_pending_' . $reference );
        cdf_redirect_with_error( 'gateway_error' );
    }

    if ( 'one_time' === $type ) {
        $payload = array(
            'intent'         => 'CAPTURE',
            'purchase_units' => array(
                array(
                    'custom_id'   => $reference,
                    'description' => __( 'One-Time Donation', CDF_PLUGIN_TEXT_DOMAIN ),
                    'amount'      => array(
                        'currency_code' => $currency,
                        'value'         => number_format( $amount, 2, '.', '' ),
                    ),
                ),
            ),
            'payer'                => array(
                'email_address' => $email,
                'name'          => array( 'given_name' => $first_name, 'surname' => $last_name ),
            ),
            'application_context'  => array(
                'brand_name'  => get_bloginfo( 'name' ),
                'user_action' => 'PAY_NOW',
                'return_url'  => esc_url_raw( $urls['success'] ),
                'cancel_url'  => esc_url_raw( $urls['cancel'] ),
            ),
        );
        $endpoint = $api_base . '/v2/checkout/orders';
    } else {
        $plan_id = $keys['paypal_plan_id'];
        if ( empty( $plan_id ) ) {
            delete_transient( 'cdf_pending_' . $reference );
            cdf_redirect_with_error( 'gateway_error' );
        }
        // PayPal plans have a fixed price; quantity expresses the amount.
        // The plan must be configured at 1.00 of the store currency.
        $quantity = max( 1, (int) round( $amount ) );
        $payload  = array(
            'plan_id'   => $plan_id,
            'quantity'  => (string) $quantity,
            'custom_id' => $reference,
            'subscriber' => array(
                'email_address' => $email,
                'name'          => array( 'given_name' => $first_name, 'surname' => $last_name ),
            ),
            'application_context' => array(
                'brand_name'  => get_bloginfo( 'name' ),
                'user_action' => 'SUBSCRIBE_NOW',
                'return_url'  => esc_url_raw( $urls['success'] ),
                'cancel_url'  => esc_url_raw( $urls['cancel'] ),
            ),
        );
        $endpoint = $api_base . '/v1/billing/subscriptions';
    }

    $payload = apply_filters( 'cdf_paypal_checkout_payload', $payload, $pending );

    $response = wp_remote_post( $endpoint, array(
        'timeout' => 20,
        'headers' => array(
            'Authorization'      => 'Bearer ' . $access_token,
            'Content-Type'       => 'application/json',
            'Accept'             => 'application/json',
            'PayPal-Request-Id'  => 'cdf_' . $reference,
        ),
        'body'    => wp_json_encode( $payload ),
    ) );

    if ( is_wp_error( $response ) ) {
        cdf_log_error( 'PayPal checkout WP_Error', array( 'error' => $response->get_error_message() ) );
        delete_transient( 'cdf_pending_' . $reference );
        cdf_redirect_with_error( 'gateway_error' );
    }

    $code      = wp_remote_retrieve_response_code( $response );
    $resp_data = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( 201 !== $code && 200 !== $code ) {
        cdf_log_error(
            'PayPal checkout failed',
            array( 'code' => $code, 'body' => wp_remote_retrieve_body( $response ) )
        );
        delete_transient( 'cdf_pending_' . $reference );
        cdf_redirect_with_error( 'gateway_error' );
    }

    $approve_url = '';
    if ( ! empty( $resp_data['links'] ) ) {
        foreach ( $resp_data['links'] as $link ) {
            if ( isset( $link['rel'] ) && in_array( $link['rel'], array( 'approve', 'payer-action' ), true ) ) {
                $approve_url = $link['href'];
                break;
            }
        }
    }

    if ( empty( $approve_url ) ) {
        delete_transient( 'cdf_pending_' . $reference );
        cdf_redirect_with_error( 'gateway_error' );
    }

    wp_redirect( esc_url_raw( $approve_url ) );
    exit;
}

/* =========================================================================
 * RETURN HANDLING — verify with the gateway API, then log the donation
 * ========================================================================= */

add_action( 'template_redirect', 'cdf_verify_donation_return' );

/**
 * Verify a returning donor and log the donation.
 */
function cdf_verify_donation_return() {
    if ( is_admin() || wp_doing_ajax() ) {
        return;
    }

    $general   = cdf_get_general_settings();
    $success_id = ! empty( $general['success_page_id'] ) ? absint( $general['success_page_id'] ) : 0;
    $is_success_page = $success_id ? is_page( $success_id ) : is_page( cdf_get_config( 'success_slug', 'donation-success' ) );
    if ( ! $is_success_page ) {
        return;
    }

    // phpcs:disable WordPress.Security.NonceVerification.Recommended
    $session_id = isset( $_GET['session_id'] ) ? sanitize_text_field( wp_unslash( $_GET['session_id'] ) ) : '';
    $token      = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
    $sub_id     = isset( $_GET['subscription_id'] ) ? sanitize_text_field( wp_unslash( $_GET['subscription_id'] ) ) : '';
    $reference  = isset( $_GET['cdf_ref'] ) ? sanitize_text_field( wp_unslash( $_GET['cdf_ref'] ) ) : '';
    // phpcs:enable WordPress.Security.NonceVerification.Recommended

    if ( empty( $session_id ) && empty( $token ) && empty( $sub_id ) ) {
        return;
    }

    $keys = cdf_get_gateway_keys();

    if ( $session_id ) {
        cdf_verify_stripe_session( $session_id, $keys, $reference );
    } elseif ( $sub_id ) {
        cdf_verify_paypal_subscription( $sub_id, $keys, $reference );
    } elseif ( $token ) {
        cdf_verify_paypal_order( $token, $keys, $reference );
    }
}

/**
 * Fetch the pending record for a reference.
 *
 * @param string $reference Key from URL (may be empty for legacy links).
 * @return array|false
 */
function cdf_get_pending_record( $reference ) {
    if ( empty( $reference ) ) {
        return false;
    }
    $pending = get_transient( 'cdf_pending_' . $reference );
    return is_array( $pending ) ? $pending : false;
}

/**
 * Verify a Stripe Checkout Session and log.
 *
 * @param string $session_id Stripe session ID.
 * @param array  $keys       Gateway keys.
 * @param string $reference  Pending reference.
 */
function cdf_verify_stripe_session( $session_id, $keys, $reference ) {
    if ( empty( $keys['stripe_secret'] ) ) {
        return;
    }
    if ( cdf_donation_exists_by_txn_id( $session_id ) ) {
        return;
    }

    $response = wp_remote_get(
        'https://api.stripe.com/v1/checkout/sessions/' . rawurlencode( $session_id ),
        array(
            'timeout' => 20,
            'headers' => array( 'Authorization' => 'Bearer ' . $keys['stripe_secret'] ),
        )
    );

    if ( is_wp_error( $response ) ) {
        cdf_log_error( 'Stripe verify WP_Error', array( 'error' => $response->get_error_message() ) );
        return;
    }
    if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
        cdf_log_error( 'Stripe verify HTTP error', array( 'code' => wp_remote_retrieve_response_code( $response ) ) );
        return;
    }

    $data = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( empty( $data['payment_status'] ) || 'paid' !== $data['payment_status'] ) {
        return;
    }

    $pending = cdf_get_pending_record( empty( $reference ) && ! empty( $data['metadata']['cdf_reference'] ) ? $data['metadata']['cdf_reference'] : $reference );

    // Amounts ALWAYS come from the gateway, never from the browser.
    $amount = isset( $data['amount_total'] ) ? ( floatval( $data['amount_total'] ) / 100 ) : 0;
    if ( $amount <= 0 ) {
        return;
    }

    $first_name = ! empty( $data['metadata']['form_first_name'] ) ? sanitize_text_field( $data['metadata']['form_first_name'] )
        : ( ! empty( $data['customer_details']['name'] ) ? sanitize_text_field( $data['customer_details']['name'] ) : '' );
    $last_name = ! empty( $data['metadata']['form_last_name'] ) ? sanitize_text_field( $data['metadata']['form_last_name'] ) : '';
    $email     = ! empty( $data['customer_details']['email'] ) ? sanitize_email( $data['customer_details']['email'] )
        : ( ! empty( $data['customer_email'] ) ? sanitize_email( $data['customer_email'] ) : '' );

    if ( $pending ) {
        $first_name = $first_name ? $first_name : $pending['first_name'];
        $last_name  = $last_name ? $last_name : $pending['last_name'];
        $email      = $email ? $email : $pending['email'];
    }

    $mode = isset( $data['mode'] ) ? $data['mode'] : 'payment';

    cdf_log_donation( array(
        'first_name'     => $first_name,
        'last_name'      => $last_name,
        'email'          => $email,
        'phone'          => $pending ? $pending['phone'] : '',
        'amount'         => $amount,
        'currency'       => isset( $data['currency'] ) ? strtoupper( $data['currency'] ) : cdf_get_currency(),
        'gateway'        => 'Stripe',
        'frequency'      => ( 'subscription' === $mode ) ? 'Monthly' : 'One-Time',
        'environment'    => $keys['is_sandbox'] ? 'Test / Sandbox' : 'Live',
        'transaction_id' => $session_id,
        'status'         => 'completed',
        'subscription_id' => isset( $data['subscription'] ) ? $data['subscription'] : '',
        'payment_intent'  => isset( $data['payment_intent'] ) ? $data['payment_intent'] : '',
        'anonymous'      => $pending ? $pending['anonymous'] : '0',
    ) );
}

/**
 * Verify a PayPal order and log.
 *
 * Name, email and amount are read from the PayPal API response so a crafted
 * return URL cannot fake a donation amount.
 *
 * @param string $order_id  PayPal order ID.
 * @param array  $keys      Gateway keys.
 * @param string $reference Pending reference.
 */
function cdf_verify_paypal_order( $order_id, $keys, $reference ) {
    if ( cdf_donation_exists_by_txn_id( $order_id ) ) {
        return;
    }

    $access_token = cdf_get_paypal_access_token();
    if ( empty( $access_token ) ) {
        return;
    }

    $api_base = $keys['is_sandbox'] ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';

    $response = wp_remote_get(
        $api_base . '/v2/checkout/orders/' . rawurlencode( $order_id ),
        array(
            'timeout' => 20,
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json',
            ),
        )
    );

    if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
        cdf_log_error( 'PayPal order verify failed', array( 'order' => $order_id ) );
        return;
    }

    $data = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( empty( $data['status'] ) || ! in_array( $data['status'], array( 'COMPLETED', 'APPROVED' ), true ) ) {
        return;
    }

    // If the order is only approved, capture it now.
    if ( 'APPROVED' === $data['status'] ) {
        $capture = wp_remote_post(
            $api_base . '/v2/checkout/orders/' . rawurlencode( $order_id ) . '/capture',
            array(
                'timeout' => 20,
                'headers' => array(
                    'Authorization'      => 'Bearer ' . $access_token,
                    'Content-Type'       => 'application/json',
                    'PayPal-Request-Id'  => 'capture_' . $order_id,
                ),
                'body'    => '{}',
            )
        );
        if ( ! is_wp_error( $capture ) && in_array( wp_remote_retrieve_response_code( $capture ), array( 200, 201 ), true ) ) {
            $data = json_decode( wp_remote_retrieve_body( $capture ), true );
        } else {
            cdf_log_error( 'PayPal capture failed', array( 'order' => $order_id ) );
            return;
        }
    }

    $unit = isset( $data['purchase_units'][0] ) ? $data['purchase_units'][0] : array();

    // Amount from PayPal, not from the URL.
    $amount   = 0;
    $currency = cdf_get_currency();
    if ( isset( $unit['payments']['captures'][0]['amount']['value'] ) ) {
        $amount   = floatval( $unit['payments']['captures'][0]['amount']['value'] );
        $currency = isset( $unit['payments']['captures'][0]['amount']['currency_code'] ) ? $unit['payments']['captures'][0]['amount']['currency_code'] : $currency;
    } elseif ( isset( $unit['amount']['value'] ) ) {
        $amount   = floatval( $unit['amount']['value'] );
        $currency = isset( $unit['amount']['currency_code'] ) ? $unit['amount']['currency_code'] : $currency;
    }

    if ( $amount <= 0 ) {
        return;
    }

    // Donor identity from PayPal payer object.
    $payer      = isset( $data['payer'] ) ? $data['payer'] : array();
    $first_name = isset( $payer['name']['given_name'] ) ? sanitize_text_field( $payer['name']['given_name'] ) : '';
    $last_name  = isset( $payer['name']['surname'] ) ? sanitize_text_field( $payer['name']['surname'] ) : '';
    $email      = isset( $payer['email_address'] ) ? sanitize_email( $payer['email_address'] ) : '';

    $ref     = ! empty( $unit['custom_id'] ) ? $unit['custom_id'] : $reference;
    $pending = cdf_get_pending_record( $ref );
    if ( $pending ) {
        $first_name = $first_name ? $first_name : $pending['first_name'];
        $last_name  = $last_name ? $last_name : $pending['last_name'];
        $email      = $email ? $email : $pending['email'];
    }

    cdf_log_donation( array(
        'first_name'     => $first_name,
        'last_name'      => $last_name,
        'email'          => $email,
        'phone'          => $pending ? $pending['phone'] : '',
        'amount'         => $amount,
        'currency'       => strtoupper( $currency ),
        'gateway'        => 'PayPal',
        'frequency'      => 'One-Time',
        'environment'    => $keys['is_sandbox'] ? 'Test / Sandbox' : 'Live',
        'transaction_id' => $order_id,
        'status'         => 'completed',
        'anonymous'      => $pending ? $pending['anonymous'] : '0',
    ) );
}

/**
 * Verify a PayPal subscription and log the first payment.
 *
 * @param string $subscription_id Subscription ID.
 * @param array  $keys            Gateway keys.
 * @param string $reference       Pending reference.
 */
function cdf_verify_paypal_subscription( $subscription_id, $keys, $reference ) {
    if ( cdf_donation_exists_by_txn_id( $subscription_id ) ) {
        return;
    }

    $access_token = cdf_get_paypal_access_token();
    if ( empty( $access_token ) ) {
        return;
    }

    $api_base = $keys['is_sandbox'] ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';

    $response = wp_remote_get(
        $api_base . '/v1/billing/subscriptions/' . rawurlencode( $subscription_id ),
        array(
            'timeout' => 20,
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json',
            ),
        )
    );

    if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
        cdf_log_error( 'PayPal subscription verify failed', array( 'sub' => $subscription_id ) );
        return;
    }

    $data = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( empty( $data['status'] ) || ! in_array( $data['status'], array( 'ACTIVE', 'APPROVAL_PENDING', 'APPROVED' ), true ) ) {
        return;
    }

    $pending = cdf_get_pending_record( ! empty( $data['custom_id'] ) ? $data['custom_id'] : $reference );

    // Amount: prefer the gateway's own payment record, fall back to our
    // server-side pending record. Never trust URL input.
    $amount   = 0;
    $currency = cdf_get_currency();
    if ( isset( $data['billing_info']['last_payment']['amount']['value'] ) ) {
        $amount = floatval( $data['billing_info']['last_payment']['amount']['value'] );
        if ( isset( $data['billing_info']['last_payment']['amount']['currency_code'] ) ) {
            $currency = $data['billing_info']['last_payment']['amount']['currency_code'];
        }
    } elseif ( $pending ) {
        $amount = floatval( $pending['amount'] );
        if ( ! empty( $pending['currency'] ) ) {
            $currency = $pending['currency'];
        }
    }

    if ( $amount <= 0 || ( ! $pending && empty( $data['subscriber']['email_address'] ) ) ) {
        return; // Nothing trustworthy to log.
    }

    $first_name = isset( $data['subscriber']['name']['given_name'] ) ? sanitize_text_field( $data['subscriber']['name']['given_name'] ) : ( $pending ? $pending['first_name'] : '' );
    $last_name  = isset( $data['subscriber']['name']['surname'] ) ? sanitize_text_field( $data['subscriber']['name']['surname'] ) : ( $pending ? $pending['last_name'] : '' );
    $email      = isset( $data['subscriber']['email_address'] ) ? sanitize_email( $data['subscriber']['email_address'] ) : ( $pending ? $pending['email'] : '' );

    cdf_log_donation( array(
        'first_name'      => $first_name,
        'last_name'       => $last_name,
        'email'           => $email,
        'phone'           => $pending ? $pending['phone'] : '',
        'amount'          => $amount,
        'currency'        => strtoupper( $currency ),
        'gateway'         => 'PayPal',
        'frequency'       => 'Monthly',
        'environment'     => $keys['is_sandbox'] ? 'Test / Sandbox' : 'Live',
        'transaction_id'  => $subscription_id,
        'subscription_id' => $subscription_id,
        'status'          => 'completed',
        'anonymous'       => $pending ? $pending['anonymous'] : '0',
    ) );
}

/**
 * Atomically increment and return the next donation serial number.
 *
 * Uses a single UPDATE with LAST_INSERT_ID() so concurrent donations can
 * never receive the same serial.
 *
 * @return int
 */
function cdf_next_serial_number() {
    global $wpdb;

    if ( false === get_option( 'cdf_serial_count', false ) ) {
        add_option( 'cdf_serial_count', 0, '', 'no' );
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = LAST_INSERT_ID( CAST( option_value AS UNSIGNED ) + 1 ) WHERE option_name = %s",
            'cdf_serial_count'
        )
    );

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $serial = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );

    if ( $serial <= 0 ) {
        // Fallback for environments where LAST_INSERT_ID() is unavailable.
        $serial = (int) get_option( 'cdf_serial_count', 0 ) + 1;
        update_option( 'cdf_serial_count', $serial );
    }

    // Keep the options cache consistent.
    wp_cache_delete( 'cdf_serial_count', 'options' );
    wp_cache_delete( 'alloptions', 'options' );

    return $serial;
}

/**
 * Persist a verified donation.
 *
 * @param array $data Donation data.
 * @return int|false Post ID on success.
 */
function cdf_log_donation( $data ) {
    $defaults = array(
        'first_name'      => '',
        'last_name'       => '',
        'email'           => '',
        'phone'           => '',
        'amount'          => 0,
        'currency'        => cdf_get_currency(),
        'gateway'         => '',
        'frequency'       => 'One-Time',
        'environment'     => 'Live',
        'transaction_id'  => '',
        'subscription_id' => '',
        'status'          => 'completed',
        'payment_intent'  => '',
        'anonymous'       => '0',
    );
    $data = wp_parse_args( $data, $defaults );

    if ( empty( $data['transaction_id'] ) || floatval( $data['amount'] ) <= 0 ) {
        cdf_log_error( 'Refused to log donation without transaction id or amount', $data );
        return false;
    }

    // Final duplicate guard (covers webhook + return URL racing each other).
    if ( cdf_donation_exists_by_txn_id( $data['transaction_id'] ) ) {
        return false;
    }

    $data = apply_filters( 'cdf_donation_data', $data );

    $serial = cdf_next_serial_number();

    $title = sprintf(
        'Donation #%d – %s %s (%s%s)',
        $serial,
        $data['first_name'],
        $data['last_name'],
        cdf_get_currency_symbol(),
        number_format( floatval( $data['amount'] ), 2 )
    );

    $post_id = wp_insert_post(
        array(
            'post_type'   => 'donation',
            'post_title'  => trim( preg_replace( '/\s+/', ' ', $title ) ),
            'post_status' => 'publish',
        ),
        true
    );

    if ( is_wp_error( $post_id ) || ! $post_id ) {
        cdf_log_error( 'Failed to insert donation post', array( 'error' => is_wp_error( $post_id ) ? $post_id->get_error_message() : '' ) );
        return false;
    }

    $meta = array(
        'first_name'      => sanitize_text_field( $data['first_name'] ),
        'last_name'       => sanitize_text_field( $data['last_name'] ),
        'email'           => sanitize_email( $data['email'] ),
        'phone'           => sanitize_text_field( $data['phone'] ),
        'amount'          => floatval( $data['amount'] ),
        'currency'        => sanitize_text_field( strtoupper( $data['currency'] ) ),
        'gateway'         => sanitize_text_field( $data['gateway'] ),
        'frequency'       => sanitize_text_field( $data['frequency'] ),
        'environment'     => sanitize_text_field( $data['environment'] ),
        'transaction_id'  => sanitize_text_field( $data['transaction_id'] ),
        'subscription_id' => sanitize_text_field( $data['subscription_id'] ),
        'payment_intent'  => sanitize_text_field( $data['payment_intent'] ),
        'status'          => sanitize_text_field( $data['status'] ),
        'anonymous'       => '1' === (string) $data['anonymous'] ? '1' : '0',
        'serial_number'   => $serial,
    );

    foreach ( $meta as $key => $value ) {
        update_post_meta( $post_id, '_donation_' . $key, $value );
    }

    // Cache invalidation for analytics + dynamic CSS.
    delete_transient( 'cdf_analytics_cache' );

    /**
     * Fires after a donation has been stored.
     *
     * @param int   $post_id Donation post ID.
     * @param array $data    Donation data.
     */
    do_action( 'cdf_donation_logged', $post_id, $data );

    return $post_id;
}

/**
 * Update a donation status by transaction or subscription ID (webhooks).
 *
 * @param string $txn_id Transaction / subscription ID.
 * @param string $status completed|pending|failed|refunded.
 * @return bool
 */
function cdf_update_donation_status( $txn_id, $status ) {
    $allowed = array( 'completed', 'pending', 'failed', 'refunded' );
    if ( ! in_array( $status, $allowed, true ) || empty( $txn_id ) ) {
        return false;
    }

    $existing = get_posts(
        array(
            'post_type'      => 'donation',
            'meta_query'     => array(
                'relation' => 'OR',
                array( 'key' => '_donation_transaction_id', 'value' => $txn_id ),
                array( 'key' => '_donation_subscription_id', 'value' => $txn_id ),
                array( 'key' => '_donation_payment_intent', 'value' => $txn_id ),
            ),
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'post_status'    => 'any',
        )
    );

    if ( empty( $existing ) ) {
        return false;
    }

    update_post_meta( $existing[0], '_donation_status', $status );
    delete_transient( 'cdf_analytics_cache' );

    /**
     * Fires when a donation status changes via webhook.
     *
     * @param int    $post_id Donation post ID.
     * @param string $status  New status.
     */
    do_action( 'cdf_donation_status_changed', $existing[0], $status );

    return true;
}

/**
 * Check whether a donation already exists for a transaction ID.
 *
 * @param string $txn_id Transaction ID.
 * @return bool
 */
function cdf_donation_exists_by_txn_id( $txn_id ) {
    if ( empty( $txn_id ) ) {
        return false;
    }
    $existing = get_posts(
        array(
            'post_type'      => 'donation',
            'meta_key'       => '_donation_transaction_id',
            'meta_value'     => $txn_id,
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'post_status'    => 'any',
        )
    );
    return ! empty( $existing );
}
