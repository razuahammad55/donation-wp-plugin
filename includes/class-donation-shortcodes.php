<?php
/**
 * Front-end shortcodes: [donation_form] and [donation_receipt].
 *
 * @package CDF_Donation
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Find a logged donation post by gateway transaction / subscription ID.
 *
 * @param string $txn_id Transaction ID.
 * @return int Post ID or 0.
 */
function cdf_get_donation_id_by_txn( $txn_id ) {
    if ( empty( $txn_id ) ) {
        return 0;
    }
    $ids = get_posts(
        array(
            'post_type'      => 'donation',
            'meta_query'     => array(
                'relation' => 'OR',
                array( 'key' => '_donation_transaction_id', 'value' => $txn_id ),
                array( 'key' => '_donation_subscription_id', 'value' => $txn_id ),
            ),
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'post_status'    => 'any',
        )
    );
    return ! empty( $ids ) ? (int) $ids[0] : 0;
}

/**
 * Gateway logo markup.
 *
 * @param string $gateway stripe|paypal.
 * @return string
 */
function cdf_get_gateway_icon( $gateway ) {
    if ( 'stripe' === $gateway ) {
        return '<svg class="cdf-gw-icon" viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false">'
            . '<rect x="1" y="4" width="22" height="16" rx="3" fill="#635bff"/>'
            . '<rect x="4" y="13" width="7" height="2" rx="1" fill="#fff"/>'
            . '<rect x="4" y="17" width="4" height="1.5" rx=".75" fill="#fff" opacity=".7"/>'
            . '</svg>';
    }
    return '<svg class="cdf-gw-icon" viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false">'
        . '<rect x="1" y="4" width="22" height="16" rx="3" fill="#003087"/>'
        . '<path d="M7 16.5l1.6-8h3.2c1.7 0 2.7 1 2.4 2.6-.3 1.7-1.6 2.6-3.3 2.6H9.2l-.5 2.8H7z" fill="#fff"/>'
        . '</svg>';
}

add_shortcode( 'donation_form', 'cdf_render_donation_form_shortcode' );

/**
 * Render the donation form.
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
function cdf_render_donation_form_shortcode( $atts ) {
    $general = cdf_get_general_settings();

    $atts = shortcode_atts(
        array(
            'type'           => 'both',
            'amounts'        => '',
            'default_amount' => '',
            'title'          => '',
        ),
        $atts,
        'donation_form'
    );

    $type = strtolower( $atts['type'] );
    if ( ! in_array( $type, array( 'one_time', 'monthly', 'both' ), true ) ) {
        $type = 'both';
    }

    $enable_onetime = ( '1' === $general['enable_onetime'] );
    $enable_monthly = ( '1' === $general['enable_monthly'] );
    if ( $enable_monthly && ! $enable_onetime ) {
        $type = 'monthly';
    } elseif ( $enable_onetime && ! $enable_monthly ) {
        $type = 'one_time';
    } elseif ( ! $enable_onetime && ! $enable_monthly ) {
        return '';
    }
    if ( 'both' === $type && ! ( $enable_onetime && $enable_monthly ) ) {
        $type = $enable_monthly ? 'monthly' : 'one_time';
    }
    if ( 'monthly' === $type && ! $enable_monthly ) {
        $type = 'one_time';
    }
    if ( 'one_time' === $type && ! $enable_onetime ) {
        $type = 'monthly';
    }

    $gateways = array();
    if ( cdf_is_gateway_configured( 'stripe' ) ) {
        $gateways[] = 'stripe';
    }
    if ( cdf_is_gateway_configured( 'paypal' ) ) {
        $gateways[] = 'paypal';
    }
    if ( empty( $gateways ) ) {
        if ( current_user_can( 'manage_options' ) ) {
            return '<div class="cdf-notice cdf-notice-warning">'
                . esc_html__( 'No payment gateway is configured yet. Add your Stripe or PayPal keys under Donations → Settings.', CDF_PLUGIN_TEXT_DOMAIN )
                . '</div>';
        }
        return '';
    }

    cdf_enqueue_assets_for_shortcode();

    // A cached page would serve stale nonces, so tell caching plugins to skip it.
    if ( ! defined( 'DONOTCACHEPAGE' ) ) {
        define( 'DONOTCACHEPAGE', true );
    }

    $amounts = cdf_get_preset_amounts();
    if ( ! empty( $atts['amounts'] ) ) {
        $custom  = array_filter( array_map( 'floatval', array_map( 'trim', explode( ',', $atts['amounts'] ) ) ) );
        $amounts = ! empty( $custom ) ? array_values( $custom ) : $amounts;
    }
    $default_amount = ! empty( $atts['default_amount'] ) ? floatval( $atts['default_amount'] ) : floatval( $general['default_amount'] );
    if ( $default_amount <= 0 ) {
        $default_amount = $amounts[0];
    }

    $symbol      = cdf_get_currency_symbol();
    $currency    = cdf_get_currency();
    $is_both     = ( 'both' === $type );
    $form_type   = $is_both ? 'one_time' : $type;
    $show_phone  = ( '1' === $general['show_phone_field'] );
    $is_sandbox  = ( 'sandbox' === get_option( 'cdf_environment', 'sandbox' ) );
    $show_mode   = ( $is_sandbox && current_user_can( 'manage_options' ) );
    $form_id     = 'cdf-donation-form-' . wp_rand( 1000, 9999 );
    $button_text = $is_both ? $general['button_text_onetime'] : ( 'monthly' === $type ? $general['button_text_monthly'] : $general['button_text_onetime'] );

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $error_code = isset( $_GET['cdf_error'] ) ? sanitize_key( wp_unslash( $_GET['cdf_error'] ) ) : '';

    ob_start();
    ?>
    <div class="styled-donation-container cdf-container" id="<?php echo esc_attr( $form_id ); ?>-wrap">
        <?php if ( $error_code ) : ?>
            <div class="cdf-notice cdf-notice-error" role="alert">
                <?php echo esc_html( cdf_get_error_message( $error_code ) ); ?>
            </div>
        <?php endif; ?>

        <?php if ( $show_mode ) : ?>
            <div class="cdf-notice cdf-notice-test" role="status">
                <?php esc_html_e( 'Test mode is active — no real money will be charged.', CDF_PLUGIN_TEXT_DOMAIN ); ?>
            </div>
        <?php endif; ?>

        <?php if ( ! empty( $atts['title'] ) ) : ?>
            <h3 class="cdf-form-title"><?php echo esc_html( $atts['title'] ); ?></h3>
        <?php endif; ?>

        <form method="POST" action="" id="<?php echo esc_attr( $form_id ); ?>" class="cdf-form" novalidate
            data-onetime-text="<?php echo esc_attr( $general['button_text_onetime'] ); ?>"
            data-monthly-text="<?php echo esc_attr( $general['button_text_monthly'] ); ?>">
            <input type="hidden" name="cdf_action" value="process_donation">
            <?php wp_nonce_field( 'cdf_process_donation', 'cdf_donation_nonce' ); ?>

            <?php if ( $is_both ) : ?>
                <fieldset class="cdf-fieldset">
                    <legend class="cdf-legend"><?php esc_html_e( 'Donation Type', CDF_PLUGIN_TEXT_DOMAIN ); ?></legend>
                    <div class="cdf-frequency-toggle" role="radiogroup" aria-label="<?php esc_attr_e( 'Donation type', CDF_PLUGIN_TEXT_DOMAIN ); ?>">
                        <label class="cdf-freq-option">
                            <input type="radio" name="donation_form_type" value="one_time" checked>
                            <span><?php esc_html_e( 'One-Time', CDF_PLUGIN_TEXT_DOMAIN ); ?></span>
                        </label>
                        <label class="cdf-freq-option">
                            <input type="radio" name="donation_form_type" value="monthly">
                            <span><?php esc_html_e( 'Monthly', CDF_PLUGIN_TEXT_DOMAIN ); ?></span>
                        </label>
                    </div>
                </fieldset>
            <?php else : ?>
                <input type="hidden" name="donation_form_type" value="<?php echo esc_attr( $type ); ?>">
            <?php endif; ?>

            <fieldset class="cdf-fieldset">
                <legend class="cdf-legend"><?php esc_html_e( 'Select Amount', CDF_PLUGIN_TEXT_DOMAIN ); ?></legend>
                <div class="cdf-amount-grid">
                    <?php foreach ( $amounts as $amount ) : ?>
                        <button type="button"
                            class="cdf-amt-btn<?php echo ( floatval( $amount ) === floatval( $default_amount ) ) ? ' active' : ''; ?>"
                            data-val="<?php echo esc_attr( $amount ); ?>"
                            aria-pressed="<?php echo ( floatval( $amount ) === floatval( $default_amount ) ) ? 'true' : 'false'; ?>">
                            <?php echo esc_html( $symbol . number_format( floatval( $amount ), ( fmod( floatval( $amount ), 1 ) > 0 ? 2 : 0 ) ) ); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <div class="cdf-amount-input-wrap">
                <label class="screen-reader-text" for="<?php echo esc_attr( $form_id ); ?>-amount">
                    <?php esc_html_e( 'Donation amount', CDF_PLUGIN_TEXT_DOMAIN ); ?>
                </label>
                <span class="cdf-currency-prefix" aria-hidden="true"><?php echo esc_html( $symbol ); ?></span>
                <input type="number"
                    id="<?php echo esc_attr( $form_id ); ?>-amount"
                    name="donation_amount"
                    class="cdf-amount-input"
                    min="<?php echo esc_attr( $general['min_amount'] ); ?>"
                    max="<?php echo esc_attr( $general['max_amount'] ); ?>"
                    step="any"
                    inputmode="decimal"
                    value="<?php echo esc_attr( $default_amount ); ?>"
                    required>
                <span class="cdf-currency-suffix"><?php echo esc_html( $currency ); ?></span>
            </div>
            <p class="cdf-field-hint">
                <?php
                printf(
                    /* translators: 1: min amount, 2: max amount */
                    esc_html__( 'Minimum %1$s — maximum %2$s', CDF_PLUGIN_TEXT_DOMAIN ),
                    esc_html( $symbol . number_format( floatval( $general['min_amount'] ), 2 ) ),
                    esc_html( $symbol . number_format( floatval( $general['max_amount'] ), 2 ) )
                );
                ?>
            </p>

            <fieldset class="cdf-fieldset">
                <legend class="cdf-legend"><?php esc_html_e( 'Your Information', CDF_PLUGIN_TEXT_DOMAIN ); ?></legend>
                <div class="cdf-row cdf-row-2">
                    <p class="cdf-field">
                        <label for="<?php echo esc_attr( $form_id ); ?>-first"><?php esc_html_e( 'First Name', CDF_PLUGIN_TEXT_DOMAIN ); ?> <span class="cdf-required">*</span></label>
                        <input type="text" id="<?php echo esc_attr( $form_id ); ?>-first" name="donor_first" autocomplete="given-name" required>
                    </p>
                    <p class="cdf-field">
                        <label for="<?php echo esc_attr( $form_id ); ?>-last"><?php esc_html_e( 'Last Name', CDF_PLUGIN_TEXT_DOMAIN ); ?></label>
                        <input type="text" id="<?php echo esc_attr( $form_id ); ?>-last" name="donor_last" autocomplete="family-name">
                    </p>
                </div>
                <p class="cdf-field">
                    <label for="<?php echo esc_attr( $form_id ); ?>-email"><?php esc_html_e( 'Email Address', CDF_PLUGIN_TEXT_DOMAIN ); ?> <span class="cdf-required">*</span></label>
                    <input type="email" id="<?php echo esc_attr( $form_id ); ?>-email" name="donor_email" autocomplete="email" required>
                </p>
                <?php if ( $show_phone ) : ?>
                    <p class="cdf-field">
                        <label for="<?php echo esc_attr( $form_id ); ?>-phone"><?php esc_html_e( 'Phone Number', CDF_PLUGIN_TEXT_DOMAIN ); ?> <span class="cdf-optional"><?php esc_html_e( '(optional)', CDF_PLUGIN_TEXT_DOMAIN ); ?></span></label>
                        <input type="tel" id="<?php echo esc_attr( $form_id ); ?>-phone" name="donor_phone" autocomplete="tel">
                    </p>
                <?php endif; ?>
                <p class="cdf-field cdf-checkbox">
                    <input type="checkbox" id="<?php echo esc_attr( $form_id ); ?>-anon" name="donor_anonymous" value="1">
                    <label for="<?php echo esc_attr( $form_id ); ?>-anon"><?php esc_html_e( 'Make my donation anonymous', CDF_PLUGIN_TEXT_DOMAIN ); ?></label>
                </p>
            </fieldset>

            <fieldset class="cdf-fieldset">
                <legend class="cdf-legend"><?php esc_html_e( 'Payment Method', CDF_PLUGIN_TEXT_DOMAIN ); ?></legend>
                <div class="cdf-gateway-block">
                    <?php foreach ( $gateways as $index => $gateway ) : ?>
                        <label class="cdf-gateway-row<?php echo 0 === $index ? ' active' : ''; ?>">
                            <input type="radio" name="payment_method" value="<?php echo esc_attr( $gateway ); ?>" <?php checked( 0, $index ); ?>>
                            <?php echo cdf_get_gateway_icon( $gateway ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
                            <span class="cdf-gateway-text">
                                <?php
                                echo esc_html(
                                    'stripe' === $gateway
                                        ? __( 'Credit / Debit Card', CDF_PLUGIN_TEXT_DOMAIN )
                                        : __( 'PayPal', CDF_PLUGIN_TEXT_DOMAIN )
                                );
                                ?>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <!-- Honeypot: hidden from humans, catches bots -->
            <p class="cdf-hp" aria-hidden="true">
                <label for="<?php echo esc_attr( $form_id ); ?>-website"><?php esc_html_e( 'Leave this field empty', CDF_PLUGIN_TEXT_DOMAIN ); ?></label>
                <input type="text" id="<?php echo esc_attr( $form_id ); ?>-website" name="cdf_website" tabindex="-1" autocomplete="off">
            </p>

            <button type="submit" class="cdf-submit-btn" data-loading="<?php esc_attr_e( 'Processing…', CDF_PLUGIN_TEXT_DOMAIN ); ?>">
                <span class="cdf-submit-label"><?php echo esc_html( $button_text ); ?></span>
                <span class="cdf-spinner" aria-hidden="true"></span>
            </button>
            <p class="cdf-secure-note">
                <span class="cdf-lock" aria-hidden="true">🔒</span>
                <?php esc_html_e( 'Your payment is processed securely. We never store card details.', CDF_PLUGIN_TEXT_DOMAIN ); ?>
            </p>
        </form>
    </div>
    <?php
    return ob_get_clean();
}

add_shortcode( 'donation_receipt', 'cdf_render_donation_receipt_shortcode' );

/**
 * Render the thank-you receipt using the logged donation (no API call).
 *
 * @return string
 */
function cdf_render_donation_receipt_shortcode() {
    $general = cdf_get_general_settings();

    $success_id      = ! empty( $general['success_page_id'] ) ? absint( $general['success_page_id'] ) : 0;
    $is_success_page = $success_id ? is_page( $success_id ) : is_page( cdf_get_config( 'success_slug', 'donation-success' ) );
    if ( ! $is_success_page ) {
        return '';
    }

    cdf_enqueue_assets_for_shortcode();

    // phpcs:disable WordPress.Security.NonceVerification.Recommended
    $session_id = isset( $_GET['session_id'] ) ? sanitize_text_field( wp_unslash( $_GET['session_id'] ) ) : '';
    $token      = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
    $sub_id     = isset( $_GET['subscription_id'] ) ? sanitize_text_field( wp_unslash( $_GET['subscription_id'] ) ) : '';
    // phpcs:enable WordPress.Security.NonceVerification.Recommended

    $txn_id  = $session_id ? $session_id : ( $sub_id ? $sub_id : $token );
    $post_id = $txn_id ? cdf_get_donation_id_by_txn( $txn_id ) : 0;

    if ( ! $post_id ) {
        return '<div class="cdf-receipt-card cdf-receipt-pending">'
            . '<h2>' . esc_html__( 'Thank You!', CDF_PLUGIN_TEXT_DOMAIN ) . '</h2>'
            . '<p>' . esc_html__( 'Your payment is being confirmed. Your receipt will arrive by email shortly.', CDF_PLUGIN_TEXT_DOMAIN ) . '</p>'
            . '</div>';
    }

    $first_name = get_post_meta( $post_id, '_donation_first_name', true );
    $last_name  = get_post_meta( $post_id, '_donation_last_name', true );
    $email      = get_post_meta( $post_id, '_donation_email', true );
    $amount     = floatval( get_post_meta( $post_id, '_donation_amount', true ) );
    $currency   = get_post_meta( $post_id, '_donation_currency', true );
    $frequency  = get_post_meta( $post_id, '_donation_frequency', true );
    $gateway    = get_post_meta( $post_id, '_donation_gateway', true );
    $serial     = get_post_meta( $post_id, '_donation_serial_number', true );
    $anonymous  = get_post_meta( $post_id, '_donation_anonymous', true );

    $display_name = ( '1' === $anonymous ) ? __( 'Anonymous Donor', CDF_PLUGIN_TEXT_DOMAIN ) : trim( $first_name . ' ' . $last_name );
    $thank_you    = ! empty( $general['thank_you_message'] ) ? $general['thank_you_message'] : __( 'Your donation has been successfully received.', CDF_PLUGIN_TEXT_DOMAIN );

    ob_start();
    ?>
    <div class="cdf-receipt-card">
        <div class="cdf-receipt-head">
            <div class="cdf-receipt-check" aria-hidden="true">✓</div>
            <h2>
                <?php
                printf(
                    /* translators: %s: donor name */
                    esc_html__( 'Thank You, %s.', CDF_PLUGIN_TEXT_DOMAIN ),
                    esc_html( $display_name ? $display_name : __( 'Friend', CDF_PLUGIN_TEXT_DOMAIN ) )
                );
                ?>
            </h2>
            <p><?php echo esc_html( $thank_you ); ?></p>
        </div>
        <dl class="cdf-receipt-lines">
            <div><dt><?php esc_html_e( 'Receipt #', CDF_PLUGIN_TEXT_DOMAIN ); ?></dt><dd><?php echo esc_html( '#' . ( $serial ? $serial : $post_id ) ); ?></dd></div>
            <div><dt><?php esc_html_e( 'Amount', CDF_PLUGIN_TEXT_DOMAIN ); ?></dt>
                <dd><strong><?php echo esc_html( cdf_get_currency_symbol() . number_format( $amount, 2 ) . ' ' . ( $currency ? $currency : cdf_get_currency() ) ); ?></strong></dd>
            </div>
            <div><dt><?php esc_html_e( 'Frequency', CDF_PLUGIN_TEXT_DOMAIN ); ?></dt><dd><?php echo esc_html( $frequency ); ?></dd></div>
            <div><dt><?php esc_html_e( 'Payment Method', CDF_PLUGIN_TEXT_DOMAIN ); ?></dt><dd><?php echo esc_html( $gateway ); ?></dd></div>
            <?php if ( $email ) : ?>
                <div><dt><?php esc_html_e( 'Email', CDF_PLUGIN_TEXT_DOMAIN ); ?></dt><dd><?php echo esc_html( $email ); ?></dd></div>
            <?php endif; ?>
        </dl>
        <p class="cdf-receipt-foot"><?php esc_html_e( 'A copy of this receipt has been sent to your email address.', CDF_PLUGIN_TEXT_DOMAIN ); ?></p>
    </div>
    <?php
    return ob_get_clean();
}
