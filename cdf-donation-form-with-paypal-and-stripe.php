<?php
/**
 * Plugin Name: CDF Donation Form with PayPal and Stripe
 * Plugin URI:  https://github.com/razuahammad55/cdf-donation-form-with-paypal-and-stripe
 * Description: A secure, modular WordPress donation plugin supporting Stripe Checkout and PayPal REST API for One-Time and Monthly Recurring donations.
 * Version:     2.1.0
 * Author:      Razu Ahammad
 * Author URI:  https://profiles.wordpress.org/razuahammad/
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cdf-donation-form-with-paypal-and-stripe
 * Domain Path: /languages
 * Requires at least: 5.8
 * Tested up to: 6.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* =========================================================================
 * PLUGIN IDENTITY & GLOBAL CONFIG
 * -------------------------------------------------------------------------
 * Change plugin name / slug / version / currency here in ONE place.
 * All other files use these constants instead of hardcoded strings,
 * so renaming or re-branding the plugin in future is a 1-file change.
 * ========================================================================= */
define( 'CDF_PLUGIN_NAME', 'CDF Donation Form with PayPal and Stripe' );
define( 'CDF_PLUGIN_SLUG', 'cdf-donation-form-with-paypal-and-stripe' );
define( 'CDF_PLUGIN_VERSION', '2.1.0' );
define( 'CDF_PLUGIN_TEXT_DOMAIN', 'cdf-donation-form-with-paypal-and-stripe' );
define( 'CDF_PLUGIN_PREFIX', 'cdf_' );
define( 'CDF_PLUGIN_FILE', __FILE__ );
define( 'CDF_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'CDF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CDF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/* Default currency / amounts — can be overridden in Donations > Settings > Forms */
define( 'CDF_PLUGIN_CURRENCY', 'USD' );
define( 'CDF_PLUGIN_CURRENCY_SYMBOL', '$' );
define( 'CDF_PLUGIN_MIN_AMOUNT', 1 );
define( 'CDF_PLUGIN_MAX_AMOUNT', 100000 );

/* Global config array for easy future changes (name, currency, etc.) */
$GLOBALS['cdf_plugin_config'] = array(
    'name'            => CDF_PLUGIN_NAME,
    'slug'            => CDF_PLUGIN_SLUG,
    'version'         => CDF_PLUGIN_VERSION,
    'text_domain'     => CDF_PLUGIN_TEXT_DOMAIN,
    'prefix'          => CDF_PLUGIN_PREFIX,
    'currency'        => CDF_PLUGIN_CURRENCY,
    'currency_symbol' => CDF_PLUGIN_CURRENCY_SYMBOL,
    'min_amount'      => CDF_PLUGIN_MIN_AMOUNT,
    'max_amount'      => CDF_PLUGIN_MAX_AMOUNT,
    'default_amounts' => array( 500, 250, 100, 50, 25, 10 ),
    'default_amount'  => 100,
    'success_slug'    => 'donation-success',
    'canceled_slug'   => 'donation-canceled',
);

/**
 * Helper: get a value from the global plugin config.
 */
function cdf_get_config( $key, $default = '' ) {
    if ( isset( $GLOBALS['cdf_plugin_config'][ $key ] ) ) {
        return $GLOBALS['cdf_plugin_config'][ $key ];
    }
    return $default;
}

/**
 * Helper: get general settings (currency, amounts, gateways, etc.)
 * Falls back to defaults so existing installs keep working.
 */
function cdf_get_general_settings() {
    $defaults = array(
        'currency'              => CDF_PLUGIN_CURRENCY,
        'currency_symbol'       => CDF_PLUGIN_CURRENCY_SYMBOL,
        'preset_amounts'        => '500,250,100,50,25,10',
        'default_amount'        => '100',
        'min_amount'            => (string) CDF_PLUGIN_MIN_AMOUNT,
        'max_amount'            => (string) CDF_PLUGIN_MAX_AMOUNT,
        'button_text_onetime'   => 'DONATE NOW',
        'button_text_monthly'   => 'DONATE MONTHLY',
        'thank_you_message'     => 'Your donation has been successfully received.',
        'enable_stripe'         => '1',
        'enable_paypal'         => '1',
        'enable_onetime'        => '1',
        'enable_monthly'        => '1',
        'show_phone_field'      => '1',
        'success_page_id'       => '',
        'cancel_page_id'        => '',
        'stripe_payment_methods' => 'card,link',
    );
    $saved = get_option( 'cdf_general_settings', array() );
    if ( is_array( $saved ) ) {
        $defaults = array_merge( $defaults, $saved );
    }
    return $defaults;
}

function cdf_get_currency() {
    $g = cdf_get_general_settings();
    return strtoupper( $g['currency'] );
}

function cdf_get_currency_symbol() {
    $g = cdf_get_general_settings();
    $map = array(
        'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'AUD' => '$',
        'CAD' => '$', 'BDT' => '৳', 'INR' => '₹',
    );
    $code = cdf_get_currency();
    if ( ! empty( $g['currency_symbol'] ) ) {
        return $g['currency_symbol'];
    }
    return isset( $map[ $code ] ) ? $map[ $code ] : $code . ' ';
}

function cdf_get_preset_amounts() {
    $g = cdf_get_general_settings();
    $raw = isset( $g['preset_amounts'] ) ? $g['preset_amounts'] : '500,250,100,50,25,10';
    $parts = array_map( 'trim', explode( ',', $raw ) );
    $amounts = array();
    foreach ( $parts as $p ) {
        $v = floatval( $p );
        if ( $v > 0 ) {
            $amounts[] = $v;
        }
    }
    if ( empty( $amounts ) ) {
        $amounts = cdf_get_config( 'default_amounts', array( 500, 250, 100, 50, 25, 10 ) );
    }
    return $amounts;
}

function cdf_get_success_url() {
    $g = cdf_get_general_settings();
    if ( ! empty( $g['success_page_id'] ) ) {
        $url = get_permalink( absint( $g['success_page_id'] ) );
        if ( $url ) {
            return $url;
        }
    }
    return home_url( '/' . cdf_get_config( 'success_slug', 'donation-success' ) . '/' );
}

function cdf_get_cancel_url() {
    $g = cdf_get_general_settings();
    if ( ! empty( $g['cancel_page_id'] ) ) {
        $url = get_permalink( absint( $g['cancel_page_id'] ) );
        if ( $url ) {
            return $url;
        }
    }
    return home_url( '/' . cdf_get_config( 'canceled_slug', 'donation-canceled' ) . '/' );
}

/**
 * Helper: get API keys for current environment.
 * Returns array with stripe_secret, paypal_client, paypal_secret, paypal_plan_id.
 */
function cdf_get_gateway_keys() {
    $settings = get_option( 'cdf_api_settings', array() );
    $is_sandbox = ( 'sandbox' === get_option( 'cdf_environment', 'sandbox' ) );
    if ( $is_sandbox ) {
        $stripe_secret = isset( $settings['stripe_secret_test'] ) ? trim( $settings['stripe_secret_test'] ) : ( isset( $settings['stripe_secret'] ) ? trim( $settings['stripe_secret'] ) : '' );
        $paypal_client = isset( $settings['paypal_client_test'] ) ? trim( $settings['paypal_client_test'] ) : ( isset( $settings['paypal_client'] ) ? trim( $settings['paypal_client'] ) : '' );
        $paypal_secret = isset( $settings['paypal_secret_test'] ) ? trim( $settings['paypal_secret_test'] ) : ( isset( $settings['paypal_secret'] ) ? trim( $settings['paypal_secret'] ) : '' );
    } else {
        $stripe_secret = isset( $settings['stripe_secret_live'] ) ? trim( $settings['stripe_secret_live'] ) : '';
        $paypal_client = isset( $settings['paypal_client_live'] ) ? trim( $settings['paypal_client_live'] ) : '';
        $paypal_secret = isset( $settings['paypal_secret_live'] ) ? trim( $settings['paypal_secret_live'] ) : '';
    }
    return array(
        'stripe_secret'  => $stripe_secret,
        'paypal_client'  => $paypal_client,
        'paypal_secret'  => $paypal_secret,
        'paypal_plan_id' => isset( $settings['paypal_plan_id'] ) ? trim( $settings['paypal_plan_id'] ) : '',
        'is_sandbox'     => $is_sandbox,
        'settings'       => $settings,
    );
}

function cdf_is_gateway_configured( $gateway ) {
    $keys = cdf_get_gateway_keys();
    $g = cdf_get_general_settings();
    if ( 'stripe' === $gateway ) {
        return ( '1' === $g['enable_stripe'] && ! empty( $keys['stripe_secret'] ) );
    }
    if ( 'paypal' === $gateway ) {
        return ( '1' === $g['enable_paypal'] && ! empty( $keys['paypal_client'] ) && ! empty( $keys['paypal_secret'] ) );
    }
    return false;
}

/**
 * Helper: log errors when debug logging is enabled.
 */
function cdf_log_error( $message, $context = array() ) {
    $advanced = get_option( 'cdf_advanced_settings', array() );
    $enabled  = isset( $advanced['debug_logging'] ) ? $advanced['debug_logging'] : '0';
    if ( '1' !== $enabled ) {
        return;
    }
    $log = '[CDF] ' . $message;
    if ( ! empty( $context ) ) {
        $log .= ' | ' . wp_json_encode( $context );
    }
    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
    error_log( $log );
}

/**
 * Helper: get PayPal access token with transient caching.
 * Avoids an OAuth call on every donation.
 */
function cdf_get_paypal_access_token() {
    $keys = cdf_get_gateway_keys();
    $client = $keys['paypal_client'];
    $secret = $keys['paypal_secret'];
    if ( empty( $client ) || empty( $secret ) ) {
        return '';
    }
    $env_key = $keys['is_sandbox'] ? 'sandbox' : 'live';
    $cache_key = 'cdf_paypal_token_' . $env_key;
    $cached = get_transient( $cache_key );
    if ( $cached && ! empty( $cached['token'] ) && ! empty( $cached['expires'] ) && time() < ( $cached['expires'] - 60 ) ) {
        return $cached['token'];
    }
    $api_base = $keys['is_sandbox'] ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
    $auth_response = wp_remote_post( $api_base . '/v1/oauth2/token', array(
        'timeout' => 15,
        'headers' => array(
            'Authorization' => 'Basic ' . base64_encode( $client . ':' . $secret ),
            'Content-Type'  => 'application/x-www-form-urlencoded',
        ),
        'body' => 'grant_type=client_credentials',
    ) );
    if ( is_wp_error( $auth_response ) ) {
        cdf_log_error( 'PayPal auth WP_Error', array( 'error' => $auth_response->get_error_message() ) );
        return '';
    }
    $code = wp_remote_retrieve_response_code( $auth_response );
    if ( 200 !== $code ) {
        cdf_log_error( 'PayPal auth HTTP error', array( 'code' => $code, 'body' => wp_remote_retrieve_body( $auth_response ) ) );
        return '';
    }
    $auth_data = json_decode( wp_remote_retrieve_body( $auth_response ), true );
    $token = isset( $auth_data['access_token'] ) ? $auth_data['access_token'] : '';
    if ( ! empty( $token ) ) {
        $expires_in = isset( $auth_data['expires_in'] ) ? intval( $auth_data['expires_in'] ) : 3600;
        set_transient( $cache_key, array( 'token' => $token, 'expires' => time() + $expires_in ), $expires_in );
    }
    return $token;
}

/* Load text domain for translations */
add_action( 'plugins_loaded', function() {
    load_plugin_textdomain( CDF_PLUGIN_TEXT_DOMAIN, false, dirname( CDF_PLUGIN_BASENAME ) . '/languages' );
} );

/* Activation */
register_activation_hook( __FILE__, 'cdf_plugin_activate' );
function cdf_plugin_activate() {
    require_once CDF_PLUGIN_DIR . 'includes/class-donation-cpt.php';
    if ( function_exists( 'cdf_register_donation_cpt' ) ) {
        cdf_register_donation_cpt();
    }
    flush_rewrite_rules();

    if ( get_option( 'cdf_activation_redirect_done' ) !== '1' ) {
        set_transient( 'cdf_activation_redirect', true, 30 );
    }

    if ( ! get_page_by_path( cdf_get_config( 'success_slug', 'donation-success' ) ) ) {
        wp_insert_post( array(
            'post_title'   => 'Donation Success',
            'post_name'    => cdf_get_config( 'success_slug', 'donation-success' ),
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => '[donation_receipt]',
        ) );
    }

    if ( ! get_page_by_path( cdf_get_config( 'canceled_slug', 'donation-canceled' ) ) ) {
        wp_insert_post( array(
            'post_title'   => 'Donation Canceled',
            'post_name'    => cdf_get_config( 'canceled_slug', 'donation-canceled' ),
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => '<div class="cdf-canceled-card"><h2>Donation Canceled</h2><p>Your donation process was canceled. No charges were made.</p><a href="' . esc_url( home_url() ) . '" class="cdf-canceled-btn">Return Home</a></div>',
        ) );
    }

    $defaults = array(
        'cdf_admin_email_enabled'    => '1',
        'cdf_donor_email_enabled'    => '1',
        'cdf_environment'            => 'sandbox',
        'cdf_general_settings'       => cdf_get_general_settings(),
        'cdf_advanced_settings'      => array(
            'debug_logging'            => '0',
            'delete_data_on_uninstall' => '0',
        ),
        'cdf_email_settings'         => array(
            'admin_subject' => 'Someone just donated on your site [%site_name%]',
            'donor_subject' => 'Thank you for your donation, %first_name%! [%site_name%]',
            'from_name'     => '',
            'from_email'    => '',
        ),
    );
    foreach ( $defaults as $opt => $val ) {
        if ( false === get_option( $opt ) ) {
            update_option( $opt, $val );
        }
    }
    if ( false === get_option( 'cdf_admin_email_recipients' ) ) {
        update_option( 'cdf_admin_email_recipients', get_option( 'admin_email' ) );
    }
    // Legacy nonce bypass is deprecated — force off for security.
    update_option( 'cdf_nonce_bypass', '0' );
}

/* One-time redirect to Donations > Settings (single tabbed page) */
add_action( 'admin_init', function() {
    if ( get_transient( 'cdf_activation_redirect' ) ) {
        delete_transient( 'cdf_activation_redirect' );
        update_option( 'cdf_activation_redirect_done', '1' );
        if ( ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
            wp_safe_redirect( admin_url( 'edit.php?post_type=donation&page=cdf-settings' ) );
            exit;
        }
    }
} );

register_deactivation_hook( __FILE__, function() {
    flush_rewrite_rules();
} );

/* Load plugin files (renamed for clarity) */
require_once CDF_PLUGIN_DIR . 'includes/class-donation-cpt.php';
require_once CDF_PLUGIN_DIR . 'includes/class-payment-processor.php';
require_once CDF_PLUGIN_DIR . 'includes/class-donation-emails.php';
require_once CDF_PLUGIN_DIR . 'includes/class-donation-shortcodes.php';
require_once CDF_PLUGIN_DIR . 'includes/class-donation-admin.php';
require_once CDF_PLUGIN_DIR . 'includes/class-webhooks.php';

/* Frontend assets — only when shortcode is present (performance) */
add_action( 'wp_enqueue_scripts', 'cdf_enqueue_frontend_assets' );
function cdf_enqueue_frontend_assets() {
    $should_load = false;
    if ( is_singular() ) {
        global $post;
        if ( $post && has_shortcode( $post->post_content, 'donation_form' ) ) {
            $should_load = true;
        }
        if ( $post && has_shortcode( $post->post_content, 'donation_receipt' ) ) {
            $should_load = true;
        }
    }
    // Also load on success/cancel pages by slug for receipt styling.
    if ( is_page( array( cdf_get_config( 'success_slug', 'donation-success' ), cdf_get_config( 'canceled_slug', 'donation-canceled' ) ) ) ) {
        $should_load = true;
    }
    if ( ! $should_load ) {
        return;
    }
    $ver = CDF_PLUGIN_VERSION;
    $css_file = CDF_PLUGIN_DIR . 'assets/css/frontend-style.css';
    if ( file_exists( $css_file ) ) {
        $ver = filemtime( $css_file );
    }
    wp_enqueue_style( 'cdf-frontend-style', CDF_PLUGIN_URL . 'assets/css/frontend-style.css', array(), $ver );
    $js_file = CDF_PLUGIN_DIR . 'assets/js/frontend-script.js';
    $js_ver = $ver;
    if ( file_exists( $js_file ) ) {
        $js_ver = filemtime( $js_file );
    }
    wp_enqueue_script( 'cdf-frontend-script', CDF_PLUGIN_URL . 'assets/js/frontend-script.js', array(), $js_ver, true );
    wp_add_inline_style( 'cdf-frontend-style', cdf_generate_dynamic_css() );
}

/**
 * Enqueue frontend assets from inside the shortcode (fallback for
 * shortcodes in widgets/Elementor where is_singular check misses).
 */
function cdf_enqueue_assets_for_shortcode() {
    static $done = false;
    if ( $done ) {
        return;
    }
    $done = true;
    if ( ! wp_style_is( 'cdf-frontend-style', 'enqueued' ) ) {
        wp_enqueue_style( 'cdf-frontend-style', CDF_PLUGIN_URL . 'assets/css/frontend-style.css', array(), CDF_PLUGIN_VERSION );
        wp_add_inline_style( 'cdf-frontend-style', cdf_generate_dynamic_css() );
    }
    if ( ! wp_script_is( 'cdf-frontend-script', 'enqueued' ) ) {
        wp_enqueue_script( 'cdf-frontend-script', CDF_PLUGIN_URL . 'assets/js/frontend-script.js', array(), CDF_PLUGIN_VERSION, true );
    }
}

function cdf_generate_dynamic_css() {
    // Cache CSS and regenerate only when style settings change.
    $cached = get_transient( 'cdf_dynamic_css' );
    if ( $cached ) {
        return $cached;
    }
    $styles = get_option( 'cdf_style_options', array() );
    $radius            = isset( $styles['border_radius'] ) ? absint( $styles['border_radius'] ) . 'px' : '8px';
    $amt_bg_normal     = isset( $styles['amt_bg_normal'] ) ? sanitize_hex_color( $styles['amt_bg_normal'] ) : '#f7fafc';
    $amt_text_normal   = isset( $styles['amt_text_normal'] ) ? sanitize_hex_color( $styles['amt_text_normal'] ) : '#2d3748';
    $amt_bg_hover      = isset( $styles['amt_bg_hover'] ) ? sanitize_hex_color( $styles['amt_bg_hover'] ) : '#edf2f7';
    $amt_text_hover    = isset( $styles['amt_text_hover'] ) ? sanitize_hex_color( $styles['amt_text_hover'] ) : '#2d3748';
    $amt_bg_active     = isset( $styles['amt_bg_active'] ) ? sanitize_hex_color( $styles['amt_bg_active'] ) : '#ebf8ff';
    $amt_text_active   = isset( $styles['amt_text_active'] ) ? sanitize_hex_color( $styles['amt_text_active'] ) : '#2b6cb0';
    $gw_bg_normal      = isset( $styles['gw_bg_normal'] ) ? sanitize_hex_color( $styles['gw_bg_normal'] ) : '#ffffff';
    $gw_text_normal    = isset( $styles['gw_text_normal'] ) ? sanitize_hex_color( $styles['gw_text_normal'] ) : '#2d3748';
    $gw_bg_hover       = isset( $styles['gw_bg_hover'] ) ? sanitize_hex_color( $styles['gw_bg_hover'] ) : '#f7fafc';
    $gw_text_hover     = isset( $styles['gw_text_hover'] ) ? sanitize_hex_color( $styles['gw_text_hover'] ) : '#2d3748';
    $gw_bg_active      = isset( $styles['gw_bg_active'] ) ? sanitize_hex_color( $styles['gw_bg_active'] ) : '#ebf8ff';
    $gw_text_active    = isset( $styles['gw_text_active'] ) ? sanitize_hex_color( $styles['gw_text_active'] ) : '#2b6cb0';
    $sub_bg_normal     = isset( $styles['sub_bg_normal'] ) ? sanitize_hex_color( $styles['sub_bg_normal'] ) : '#3182ce';
    $sub_text_normal   = isset( $styles['sub_text_normal'] ) ? sanitize_hex_color( $styles['sub_text_normal'] ) : '#ffffff';
    $sub_bg_hover      = isset( $styles['sub_bg_hover'] ) ? sanitize_hex_color( $styles['sub_bg_hover'] ) : '#2b6cb0';
    $sub_text_hover    = isset( $styles['sub_text_hover'] ) ? sanitize_hex_color( $styles['sub_text_hover'] ) : '#ffffff';
    $sub_bg_active     = isset( $styles['sub_bg_active'] ) ? sanitize_hex_color( $styles['sub_bg_active'] ) : '#2b6cb0';
    $sub_text_active   = isset( $styles['sub_text_active'] ) ? sanitize_hex_color( $styles['sub_text_active'] ) : '#ffffff';

    $css = "
    :root {
        --cdf-border-radius: {$radius};
        --amt-btn-bg-normal: {$amt_bg_normal};
        --amt-btn-text-normal: {$amt_text_normal};
        --amt-btn-bg-hover: {$amt_bg_hover};
        --amt-btn-text-hover: {$amt_text_hover};
        --amt-btn-bg-active: {$amt_bg_active};
        --amt-btn-text-active: {$amt_text_active};
        --gateway-bg-normal: {$gw_bg_normal};
        --gateway-text-normal: {$gw_text_normal};
        --gateway-bg-hover: {$gw_bg_hover};
        --gateway-text-hover: {$gw_text_hover};
        --gateway-bg-active: {$gw_bg_active};
        --gateway-text-active: {$gw_text_active};
        --submit-btn-bg-normal: {$sub_bg_normal};
        --submit-btn-text-normal: {$sub_text_normal};
        --submit-btn-bg-hover: {$sub_bg_hover};
        --submit-btn-text-hover: {$sub_text_hover};
        --submit-btn-bg-active: {$sub_bg_active};
        --submit-btn-text-active: {$sub_text_active};
    }";
    set_transient( 'cdf_dynamic_css', $css, DAY_IN_SECONDS );
    return $css;
}
