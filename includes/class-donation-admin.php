<?php
/**
 * Admin UI: one page, six tabs (Overview, Forms, Style, Emails, Settings, Tools).
 *
 * @package CDF_Donation
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Register the single admin page and drop the default CPT list submenu.
 */
add_action( 'admin_menu', 'cdf_register_admin_page', 99 );
function cdf_register_admin_page() {
    add_submenu_page(
        'edit.php?post_type=donation',
        __( 'Donations Dashboard', CDF_PLUGIN_TEXT_DOMAIN ),
        __( 'Dashboard', CDF_PLUGIN_TEXT_DOMAIN ),
        'manage_options',
        'cdf-settings',
        'cdf_render_admin_page'
    );

    // The CPT's own "All Donations" link is replaced by our dashboard.
    remove_submenu_page( 'edit.php?post_type=donation', 'edit.php?post_type=donation' );
}

/**
 * Send the default CPT list screen to our dashboard.
 */
add_action( 'admin_init', 'cdf_redirect_cpt_list' );
function cdf_redirect_cpt_list() {
    global $pagenow;

    if ( 'edit.php' === $pagenow
        && isset( $_GET['post_type'] ) && 'donation' === sanitize_key( wp_unslash( $_GET['post_type'] ) )
        && ! isset( $_GET['page'] ) ) {
        wp_safe_redirect( admin_url( 'edit.php?post_type=donation&page=cdf-settings' ) );
        exit;
    }
}

/**
 * Tabs definition.
 *
 * @return array
 */
function cdf_get_admin_tabs() {
    return array(
        'overview' => __( 'Overview', CDF_PLUGIN_TEXT_DOMAIN ),
        'forms'    => __( 'Form', CDF_PLUGIN_TEXT_DOMAIN ),
        'style'    => __( 'Style', CDF_PLUGIN_TEXT_DOMAIN ),
        'emails'   => __( 'Emails', CDF_PLUGIN_TEXT_DOMAIN ),
        'settings' => __( 'Settings', CDF_PLUGIN_TEXT_DOMAIN ),
        'tools'    => __( 'Tools', CDF_PLUGIN_TEXT_DOMAIN ),
    );
}

/**
 * Current tab (sanitized).
 *
 * @return string
 */
function cdf_get_current_admin_tab() {
    $tabs = cdf_get_admin_tabs();
    $tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
    return isset( $tabs[ $tab ] ) ? $tab : 'overview';
}

/* =========================================================================
 * SETTINGS REGISTRATION (real Settings API + sanitizers)
 * ========================================================================= */

add_action( 'admin_init', 'cdf_register_settings' );
function cdf_register_settings() {
    register_setting( 'cdf_general_group', 'cdf_general_settings', array(
        'type'              => 'array',
        'sanitize_callback' => 'cdf_sanitize_general_settings',
        'default'           => array(),
    ) );

    register_setting( 'cdf_style_group', 'cdf_style_options', array(
        'type'              => 'array',
        'sanitize_callback' => 'cdf_sanitize_style_options',
        'default'           => array(),
    ) );

    register_setting( 'cdf_email_group', 'cdf_email_settings', array(
        'type'              => 'array',
        'sanitize_callback' => 'cdf_sanitize_email_settings',
        'default'           => array(),
    ) );
    register_setting( 'cdf_email_group', 'cdf_admin_email_enabled', array(
        'type'              => 'string',
        'sanitize_callback' => 'cdf_sanitize_toggle',
        'default'           => '1',
    ) );
    register_setting( 'cdf_email_group', 'cdf_donor_email_enabled', array(
        'type'              => 'string',
        'sanitize_callback' => 'cdf_sanitize_toggle',
        'default'           => '1',
    ) );
    register_setting( 'cdf_email_group', 'cdf_admin_email_recipients', array(
        'type'              => 'string',
        'sanitize_callback' => 'cdf_sanitize_recipients',
        'default'           => '',
    ) );

    register_setting( 'cdf_api_group', 'cdf_api_settings', array(
        'type'              => 'array',
        'sanitize_callback' => 'cdf_sanitize_api_settings',
        'default'           => array(),
    ) );
    register_setting( 'cdf_api_group', 'cdf_environment', array(
        'type'              => 'string',
        'sanitize_callback' => 'cdf_sanitize_environment',
        'default'           => 'sandbox',
    ) );

    register_setting( 'cdf_general_group', 'cdf_nonce_bypass', array(
        'type'              => 'string',
        'sanitize_callback' => 'cdf_sanitize_toggle',
        'default'           => '0',
    ) );

    register_setting( 'cdf_tools_group', 'cdf_advanced_settings', array(
        'type'              => 'array',
        'sanitize_callback' => 'cdf_sanitize_advanced_settings',
        'default'           => array(),
    ) );
}

/**
 * Sanitize toggle values.
 *
 * @param mixed $value Raw.
 * @return string
 */
function cdf_sanitize_toggle( $value ) {
    return ( '1' === (string) $value || 'on' === $value || true === $value ) ? '1' : '0';
}

/**
 * Sanitize environment value.
 *
 * @param mixed $value Raw.
 * @return string
 */
function cdf_sanitize_environment( $value ) {
    return in_array( $value, array( 'sandbox', 'live' ), true ) ? $value : 'sandbox';
}

/**
 * Sanitize a comma separated list of emails.
 *
 * @param mixed $value Raw.
 * @return string
 */
function cdf_sanitize_recipients( $value ) {
    $parts = array_map( 'trim', explode( ',', (string) $value ) );
    $valid = array();
    foreach ( $parts as $part ) {
        $email = sanitize_email( $part );
        if ( $email && is_email( $email ) ) {
            $valid[] = $email;
        }
    }
    return implode( ',', array_unique( $valid ) );
}

/**
 * Sanitize general settings.
 *
 * @param mixed $input Raw input.
 * @return array
 */
function cdf_sanitize_general_settings( $input ) {
    $defaults = cdf_get_general_settings();
    $input    = is_array( $input ) ? $input : array();
    $out      = $defaults;

    $text_fields = array(
        'currency', 'currency_symbol', 'button_text_onetime', 'button_text_monthly',
        'thank_you_message', 'default_amount', 'min_amount', 'max_amount', 'preset_amounts',
        'custom_amount_label',
    );
    foreach ( $text_fields as $field ) {
        if ( isset( $input[ $field ] ) ) {
            $out[ $field ] = sanitize_text_field( $input[ $field ] );
        }
    }

    $out['currency'] = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', $out['currency'] ), 0, 3 ) );
    if ( empty( $out['currency'] ) ) {
        $out['currency'] = 'USD';
    }

    // Preset amounts: keep numeric list only.
    $raw   = isset( $input['preset_amounts'] ) ? (string) $input['preset_amounts'] : $defaults['preset_amounts'];
    $clean = array();
    foreach ( explode( ',', $raw ) as $part ) {
        $val = floatval( trim( $part ) );
        if ( $val > 0 ) {
            $clean[] = rtrim( rtrim( number_format( $val, 2, '.', '' ), '0' ), '.' );
        }
    }
    $out['preset_amounts'] = implode( ',', array_unique( $clean ) );

    $out['default_amount'] = (string) floatval( $out['default_amount'] );
    $out['min_amount']     = (string) max( 0.01, floatval( $out['min_amount'] ) );
    $out['max_amount']     = (string) max( floatval( $out['min_amount'] ), floatval( $out['max_amount'] ) );
    if ( floatval( $out['default_amount'] ) <= 0 ) {
        $out['default_amount'] = $out['min_amount'];
    }

    $toggles = array(
        'enable_stripe', 'enable_paypal', 'enable_onetime', 'enable_monthly',
        'show_phone_field', 'custom_amount_mode',
    );
    foreach ( $toggles as $toggle ) {
        $out[ $toggle ] = isset( $input[ $toggle ] ) ? '1' : '0';
    }

    $out['success_page_id'] = isset( $input['success_page_id'] ) ? (string) absint( $input['success_page_id'] ) : '';
    $out['cancel_page_id']  = isset( $input['cancel_page_id'] ) ? (string) absint( $input['cancel_page_id'] ) : '';

    $methods = isset( $input['stripe_payment_methods'] ) && is_array( $input['stripe_payment_methods'] )
        ? array_intersect( $input['stripe_payment_methods'], array( 'card', 'link' ) )
        : array( 'card' );
    if ( empty( $methods ) ) {
        $methods = array( 'card' );
    }
    $out['stripe_payment_methods'] = implode( ',', $methods );

    // Style is regenerated. Analytics does not depend on this, but be safe.
    delete_transient( 'cdf_dynamic_css' );

    return $out;
}

/**
 * Sanitize style options.
 *
 * @param mixed $input Raw input.
 * @return array
 */
function cdf_sanitize_style_options( $input ) {
    $input = is_array( $input ) ? $input : array();
    $out   = array();
    $colors = array(
        'amt_bg_normal', 'amt_text_normal', 'amt_bg_hover', 'amt_text_hover', 'amt_bg_active', 'amt_text_active',
        'gw_bg_normal', 'gw_text_normal', 'gw_bg_hover', 'gw_text_hover', 'gw_bg_active', 'gw_text_active',
        'sub_bg_normal', 'sub_text_normal', 'sub_bg_hover', 'sub_text_hover', 'sub_bg_active', 'sub_text_active',
    );
    foreach ( $colors as $key ) {
        if ( isset( $input[ $key ] ) ) {
            $hex = sanitize_hex_color( $input[ $key ] );
            if ( $hex ) {
                $out[ $key ] = $hex;
            }
        }
    }
    if ( isset( $input['border_radius'] ) ) {
        $out['border_radius'] = (string) min( 50, absint( $input['border_radius'] ) );
    }
    delete_transient( 'cdf_dynamic_css' );
    return $out;
}

/**
 * Sanitize email settings.
 *
 * @param mixed $input Raw input.
 * @return array
 */
function cdf_sanitize_email_settings( $input ) {
    $input = is_array( $input ) ? $input : array();
    $out   = array();
    foreach ( array( 'admin_subject', 'donor_subject', 'admin_heading', 'donor_heading', 'donor_intro' ) as $key ) {
        if ( isset( $input[ $key ] ) ) {
            $out[ $key ] = sanitize_text_field( $input[ $key ] );
        }
    }
    foreach ( array( 'from_name', 'from_email', 'reply_to' ) as $key ) {
        if ( isset( $input[ $key ] ) ) {
            $out[ $key ] = sanitize_email( $input[ $key ] ) ? sanitize_email( $input[ $key ] ) : sanitize_text_field( $input[ $key ] );
        }
    }
    return $out;
}

/**
 * Sanitize API settings.
 *
 * @param mixed $input Raw input.
 * @return array
 */
function cdf_sanitize_api_settings( $input ) {
    $input   = is_array( $input ) ? $input : array();
    $out     = array();
    $allowed = array(
        'stripe_publishable_test', 'stripe_secret_test', 'stripe_webhook_secret',
        'stripe_publishable_live', 'stripe_secret_live',
        'paypal_client_test', 'paypal_secret_test',
        'paypal_client_live', 'paypal_secret_live',
        'paypal_plan_id', 'paypal_webhook_id',
    );
    foreach ( $allowed as $key ) {
        if ( isset( $input[ $key ] ) ) {
            $out[ $key ] = preg_replace( '/\s+/', '', sanitize_text_field( $input[ $key ] ) );
        }
    }

    // Basic format warnings (non blocking).
    $checks = array(
        'stripe_secret_test' => array( 'sk_test_', __( 'Test Stripe secret key should start with sk_test_.', CDF_PLUGIN_TEXT_DOMAIN ) ),
        'stripe_secret_live' => array( 'sk_live_', __( 'Live Stripe secret key should start with sk_live_.', CDF_PLUGIN_TEXT_DOMAIN ) ),
    );
    foreach ( $checks as $key => $rule ) {
        if ( ! empty( $out[ $key ] ) && 0 !== strpos( $out[ $key ], $rule[0] ) ) {
            add_settings_error( 'cdf_api_settings', 'cdf_key_format', $rule[1], 'warning' );
        }
    }
    if ( ! empty( $out['paypal_plan_id'] ) && 0 !== strpos( $out['paypal_plan_id'], 'P-' ) ) {
        add_settings_error( 'cdf_api_settings', 'cdf_plan_format', __( 'PayPal Plan IDs usually start with P-.', CDF_PLUGIN_TEXT_DOMAIN ), 'warning' );
    }

    // Credentials changed: drop the cached PayPal token.
    delete_transient( 'cdf_paypal_token_sandbox' );
    delete_transient( 'cdf_paypal_token_live' );

    return $out;
}

/**
 * Sanitize advanced settings.
 *
 * @param mixed $input Raw input.
 * @return array
 */
function cdf_sanitize_advanced_settings( $input ) {
    $input = is_array( $input ) ? $input : array();
    return array(
        'debug_logging'            => isset( $input['debug_logging'] ) ? '1' : '0',
        'delete_data_on_uninstall' => isset( $input['delete_data_on_uninstall'] ) ? '1' : '0',
    );
}

/* =========================================================================
 * ASSETS
 * ========================================================================= */

add_action( 'admin_enqueue_scripts', 'cdf_enqueue_admin_assets' );
function cdf_enqueue_admin_assets( $hook ) {
    $is_our_page = ( isset( $_GET['page'] ) && 'cdf-settings' === sanitize_key( wp_unslash( $_GET['page'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $is_donation_hook = ( false !== strpos( (string) $hook, 'donation' ) );

    if ( ! $is_our_page && ! $is_donation_hook ) {
        return;
    }

    $css = CDF_PLUGIN_DIR . 'assets/css/admin-style.css';
    $js  = CDF_PLUGIN_DIR . 'assets/js/admin-script.js';

    wp_enqueue_style( 'cdf-admin-style', CDF_PLUGIN_URL . 'assets/css/admin-style.css', array(), file_exists( $css ) ? filemtime( $css ) : CDF_PLUGIN_VERSION );
    wp_enqueue_style( 'wp-color-picker' );
    wp_enqueue_script( 'cdf-admin-script', CDF_PLUGIN_URL . 'assets/js/admin-script.js', array( 'jquery', 'wp-color-picker' ), file_exists( $js ) ? filemtime( $js ) : CDF_PLUGIN_VERSION, true );

    wp_localize_script(
        'cdf-admin-script',
        'cdfAdmin',
        array(
            'symbol'       => cdf_get_currency_symbol(),
            'liveLabel'    => __( 'Live / Production', CDF_PLUGIN_TEXT_DOMAIN ),
            'testLabel'    => __( 'Test / Sandbox', CDF_PLUGIN_TEXT_DOMAIN ),
            'amountLabel'  => __( 'Donation amount', CDF_PLUGIN_TEXT_DOMAIN ),
            'receiptLabel' => __( 'Receipt', CDF_PLUGIN_TEXT_DOMAIN ),
            'dateLabel'    => __( 'Date', CDF_PLUGIN_TEXT_DOMAIN ),
            'nameLabel'    => __( 'Donor name', CDF_PLUGIN_TEXT_DOMAIN ),
            'emailLabel'   => __( 'Email', CDF_PLUGIN_TEXT_DOMAIN ),
            'phoneLabel'   => __( 'Phone', CDF_PLUGIN_TEXT_DOMAIN ),
            'gatewayLabel' => __( 'Gateway', CDF_PLUGIN_TEXT_DOMAIN ),
            'freqLabel'    => __( 'Frequency', CDF_PLUGIN_TEXT_DOMAIN ),
            'envLabel'     => __( 'Environment', CDF_PLUGIN_TEXT_DOMAIN ),
            'txnLabel'     => __( 'Transaction ID', CDF_PLUGIN_TEXT_DOMAIN ),
            'subLabel'     => __( 'Subscription ID', CDF_PLUGIN_TEXT_DOMAIN ),
        )
    );
}

/* =========================================================================
 * PAGE RENDERER
 * ========================================================================= */

/**
 * Render the dashboard.
 */
function cdf_render_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', CDF_PLUGIN_TEXT_DOMAIN ) );
    }

    $tab  = cdf_get_current_admin_tab();
    $tabs = cdf_get_admin_tabs();

    if ( isset( $_GET['settings-updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', CDF_PLUGIN_TEXT_DOMAIN ) . '</p></div>';
    }
    ?>
    <div class="wrap cdf-admin-wrap">
        <div class="cdf-header">
            <div class="cdf-header-title">
                <span class="cdf-logo" aria-hidden="true">♥</span>
                <div>
                    <h1><?php echo esc_html( CDF_PLUGIN_NAME ); ?></h1>
                    <p class="cdf-subtitle">
                        <?php
                        printf(
                            /* translators: %s: plugin version */
                            esc_html__( 'Version %s — one page for every donation setting.', CDF_PLUGIN_TEXT_DOMAIN ),
                            esc_html( CDF_PLUGIN_VERSION )
                        );
                        ?>
                    </p>
                </div>
            </div>
            <div class="cdf-header-actions">
                <?php
                $env = get_option( 'cdf_environment', 'sandbox' );
                ?>
                <span class="cdf-badge <?php echo 'live' === $env ? 'cdf-badge-live' : 'cdf-badge-test'; ?>">
                    <?php echo 'live' === $env ? esc_html__( 'Live mode', CDF_PLUGIN_TEXT_DOMAIN ) : esc_html__( 'Sandbox mode', CDF_PLUGIN_TEXT_DOMAIN ); ?>
                </span>
            </div>
        </div>

        <nav class="cdf-tabs" role="tablist">
            <?php foreach ( $tabs as $slug => $label ) : ?>
                <a class="cdf-tab<?php echo $tab === $slug ? ' active' : ''; ?>"
                    href="<?php echo esc_url( admin_url( 'edit.php?post_type=donation&page=cdf-settings&tab=' . $slug ) ); ?>"
                    role="tab"
                    aria-selected="<?php echo $tab === $slug ? 'true' : 'false'; ?>">
                    <?php echo esc_html( $label ); ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="cdf-tab-body">
            <?php
            switch ( $tab ) {
                case 'forms':
                    cdf_render_tab_forms();
                    break;
                case 'style':
                    cdf_render_tab_style();
                    break;
                case 'emails':
                    cdf_render_tab_emails();
                    break;
                case 'settings':
                    cdf_render_tab_settings();
                    break;
                case 'tools':
                    cdf_render_tab_tools();
                    break;
                default:
                    cdf_render_tab_overview();
            }
            ?>
        </div>
    </div>
    <?php
    cdf_render_donation_modal();
}

/**
 * Small helper: text input row.
 *
 * @param string $name        Field name.
 * @param string $value       Value.
 * @param string $label       Label.
 * @param string $description Description.
 * @param string $type        Input type.
 */
function cdf_field_id( $name ) {
    return 'cdf_' . preg_replace( '/[^a-z0-9_]+/i', '_', $name );
}

function cdf_admin_text_field( $name, $value, $label, $description = '', $type = 'text' ) {
    $field_id = cdf_field_id( $name );
    ?>
    <tr>
        <th scope="row"><label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $label ); ?></label></th>
        <td>
            <input type="<?php echo esc_attr( $type ); ?>" id="<?php echo esc_attr( $field_id ); ?>" class="regular-text"
                name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>"
                <?php echo 'password' === $type ? 'autocomplete="new-password"' : ''; ?>>
            <?php if ( $description ) : ?>
                <p class="description"><?php echo wp_kses_post( $description ); ?></p>
            <?php endif; ?>
        </td>
    </tr>
    <?php
}

/**
 * Small helper: toggle row.
 *
 * @param string $name        Checkbox name.
 * @param string $value       Current value.
 * @param string $label       Label.
 * @param string $description Description.
 */
function cdf_admin_toggle_field( $name, $value, $label, $description = '' ) {
    ?>
    <tr>
        <th scope="row"><?php echo esc_html( $label ); ?></th>
        <td>
            <label class="cdf-switch">
                <input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( '1', (string) $value ); ?>>
                <span class="cdf-slider"></span>
            </label>
            <?php if ( $description ) : ?>
                <p class="description"><?php echo wp_kses_post( $description ); ?></p>
            <?php endif; ?>
        </td>
    </tr>
    <?php
}

/* =========================================================================
 * TAB: OVERVIEW
 * ========================================================================= */

/**
 * Analytics data (cached).
 *
 * @return array
 */
function cdf_get_analytics_data() {
    $cached = get_transient( 'cdf_analytics_cache' );
    if ( is_array( $cached ) ) {
        return $cached;
    }

    global $wpdb;

    // phpcs:disable WordPress.DB.DirectDatabaseQuery
    $totals = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(CAST(pm.meta_value AS DECIMAL(14,2))),0) AS total
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
             WHERE p.post_type = 'donation' AND p.post_status = 'publish'",
            '_donation_amount'
        )
    );

    $breakdown = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT g.meta_value AS gateway, f.meta_value AS frequency,
                    COUNT(*) AS cnt,
                    COALESCE(SUM(CAST(a.meta_value AS DECIMAL(14,2))),0) AS total
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = %s
             LEFT JOIN {$wpdb->postmeta} g ON g.post_id = p.ID AND g.meta_key = %s
             LEFT JOIN {$wpdb->postmeta} f ON f.post_id = p.ID AND f.meta_key = %s
             WHERE p.post_type = 'donation' AND p.post_status = 'publish'
             GROUP BY g.meta_value, f.meta_value",
            '_donation_amount',
            '_donation_gateway',
            '_donation_frequency'
        ),
        ARRAY_A
    );

    $monthly = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT DATE_FORMAT(p.post_date, '%%Y-%%m') AS ym,
                    COUNT(*) AS cnt,
                    COALESCE(SUM(CAST(a.meta_value AS DECIMAL(14,2))),0) AS total
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = %s
             WHERE p.post_type = 'donation' AND p.post_status = 'publish'
             GROUP BY ym ORDER BY ym ASC",
            '_donation_amount'
        ),
        ARRAY_A
    );

    $top_donors = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT e.meta_value AS email, n.meta_value AS first_name, n2.meta_value AS last_name,
                    COUNT(*) AS cnt, COALESCE(SUM(CAST(a.meta_value AS DECIMAL(14,2))),0) AS total
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = %s
             LEFT JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = %s
             LEFT JOIN {$wpdb->postmeta} n ON n.post_id = p.ID AND n.meta_key = %s
             LEFT JOIN {$wpdb->postmeta} n2 ON n2.post_id = p.ID AND n2.meta_key = %s
             WHERE p.post_type = 'donation' AND p.post_status = 'publish'
             GROUP BY e.meta_value ORDER BY total DESC LIMIT 5",
            '_donation_amount',
            '_donation_email',
            '_donation_first_name',
            '_donation_last_name'
        ),
        ARRAY_A
    );
    $recent = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(CAST(a.meta_value AS DECIMAL(14,2))),0) AS total
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = %s
             WHERE p.post_type = 'donation' AND p.post_status = 'publish'
             AND p.post_date >= DATE_SUB( NOW(), INTERVAL 30 DAY )",
            '_donation_amount'
        )
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery

    $data = array(
        'count'        => $totals ? (int) $totals->cnt : 0,
        'total'        => $totals ? (float) $totals->total : 0,
        'recent_total' => $recent ? (float) $recent->total : 0,
        'recent_count' => $recent ? (int) $recent->cnt : 0,
        'breakdown'    => is_array( $breakdown ) ? $breakdown : array(),
        'monthly'      => is_array( $monthly ) ? $monthly : array(),
        'donors'       => is_array( $top_donors ) ? $top_donors : array(),
    );

    set_transient( 'cdf_analytics_cache', $data, 5 * MINUTE_IN_SECONDS );
    return $data;
}

/**
 * Overview tab: analytics + donation table.
 */
function cdf_render_tab_overview() {
    $analytics = cdf_get_analytics_data();
    $symbol    = cdf_get_currency_symbol();

    // phpcs:disable WordPress.Security.NonceVerification.Recommended
    if ( isset( $_GET['cdf_deleted'] ) ) {
        $deleted = absint( $_GET['cdf_deleted'] );
        echo '<div class="notice notice-success is-dismissible"><p>'
            . esc_html( sprintf( _n( '%s donation deleted permanently.', '%s donations deleted permanently.', $deleted, CDF_PLUGIN_TEXT_DOMAIN ), number_format_i18n( $deleted ) ) )
            . '</p></div>';
    }
    if ( isset( $_GET['cdf_delete_error'] ) ) {
        echo '<div class="notice notice-error is-dismissible"><p>'
            . esc_html__( 'The donation could not be deleted. It may already be gone.', CDF_PLUGIN_TEXT_DOMAIN )
            . '</p></div>';
    }
    // phpcs:enable WordPress.Security.NonceVerification.Recommended

    $gateway_totals = array( 'Stripe' => array( 'one' => 0, 'monthly' => 0, 'count' => 0 ), 'PayPal' => array( 'one' => 0, 'monthly' => 0, 'count' => 0 ) );
    foreach ( $analytics['breakdown'] as $row ) {
        $key = ( 'PayPal' === $row['gateway'] ) ? 'PayPal' : 'Stripe';
        if ( 'Monthly' === $row['frequency'] ) {
            $gateway_totals[ $key ]['monthly'] += (float) $row['total'];
        } else {
            $gateway_totals[ $key ]['one'] += (float) $row['total'];
        }
        $gateway_totals[ $key ]['count'] += (int) $row['cnt'];
    }

    $recent_total = $analytics['recent_total'];
    $recent_count = $analytics['recent_count'];

    $max_month = 0;
    foreach ( $analytics['monthly'] as $month ) {
        $max_month = max( $max_month, (float) $month['total'] );
    }
    $chart = array_slice( $analytics['monthly'], -6 );
    ?>
    <div class="cdf-stats-grid">
        <div class="cdf-card cdf-stat">
            <span class="cdf-stat-label"><?php esc_html_e( 'Total Revenue', CDF_PLUGIN_TEXT_DOMAIN ); ?></span>
            <strong class="cdf-stat-value"><?php echo esc_html( $symbol . number_format( $analytics['total'], 2 ) ); ?></strong>
            <span class="cdf-stat-foot"><?php echo esc_html( cdf_get_currency() ); ?></span>
        </div>
        <div class="cdf-card cdf-stat">
            <span class="cdf-stat-label"><?php esc_html_e( 'Donations', CDF_PLUGIN_TEXT_DOMAIN ); ?></span>
            <strong class="cdf-stat-value"><?php echo esc_html( number_format_i18n( $analytics['count'] ) ); ?></strong>
            <span class="cdf-stat-foot"><?php esc_html_e( 'completed', CDF_PLUGIN_TEXT_DOMAIN ); ?></span>
        </div>
        <div class="cdf-card cdf-stat">
            <span class="cdf-stat-label"><?php esc_html_e( 'Average Gift', CDF_PLUGIN_TEXT_DOMAIN ); ?></span>
            <strong class="cdf-stat-value">
                <?php echo esc_html( $symbol . number_format( $analytics['count'] > 0 ? $analytics['total'] / $analytics['count'] : 0, 2 ) ); ?>
            </strong>
            <span class="cdf-stat-foot"><?php esc_html_e( 'per donation', CDF_PLUGIN_TEXT_DOMAIN ); ?></span>
        </div>
        <div class="cdf-card cdf-stat">
            <span class="cdf-stat-label"><?php esc_html_e( 'Last 30 Days', CDF_PLUGIN_TEXT_DOMAIN ); ?></span>
            <strong class="cdf-stat-value"><?php echo esc_html( $symbol . number_format( $recent_total, 2 ) ); ?></strong>
            <span class="cdf-stat-foot"><?php echo esc_html( sprintf( _n( '%s donation', '%s donations', $recent_count, CDF_PLUGIN_TEXT_DOMAIN ), number_format_i18n( $recent_count ) ) ); ?></span>
        </div>
    </div>

    <div class="cdf-grid-2">
        <div class="cdf-card">
            <h2><?php esc_html_e( 'Gateway Breakdown', CDF_PLUGIN_TEXT_DOMAIN ); ?></h2>
            <div class="cdf-breakdown">
                <?php foreach ( $gateway_totals as $gateway => $figures ) : ?>
                    <div class="cdf-breakdown-item">
                        <div class="cdf-breakdown-head">
                            <span><?php echo cdf_get_gateway_icon( strtolower( $gateway ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                            <strong><?php echo esc_html( $gateway ); ?></strong>
                            <span class="cdf-breakdown-total"><?php echo esc_html( $symbol . number_format( $figures['one'] + $figures['monthly'], 2 ) ); ?></span>
                        </div>
                        <div class="cdf-breakdown-body">
                            <div><span><?php esc_html_e( 'One-Time', CDF_PLUGIN_TEXT_DOMAIN ); ?></span><strong><?php echo esc_html( $symbol . number_format( $figures['one'], 2 ) ); ?></strong></div>
                            <div><span><?php esc_html_e( 'Monthly', CDF_PLUGIN_TEXT_DOMAIN ); ?></span><strong><?php echo esc_html( $symbol . number_format( $figures['monthly'], 2 ) ); ?></strong></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="cdf-card">
            <h2><?php esc_html_e( 'Monthly Trend', CDF_PLUGIN_TEXT_DOMAIN ); ?></h2>
            <?php if ( empty( $chart ) ) : ?>
                <p class="cdf-muted"><?php esc_html_e( 'No donations yet.', CDF_PLUGIN_TEXT_DOMAIN ); ?></p>
            <?php else : ?>
                <div class="cdf-chart">
                    <?php foreach ( $chart as $month ) : ?>
                        <?php
                        $height = $max_month > 0 ? max( 4, ( (float) $month['total'] / $max_month ) * 100 ) : 4;
                        $label  = date_i18n( 'M', strtotime( $month['ym'] . '-01' ) );
                        ?>
                        <div class="cdf-chart-col" title="<?php echo esc_attr( $symbol . number_format( (float) $month['total'], 2 ) . ' · ' . $month['ym'] ); ?>">
                            <div class="cdf-chart-bar" style="height: <?php echo esc_attr( round( $height, 1 ) ); ?>%"></div>
                            <span class="cdf-chart-label"><?php echo esc_html( $label ); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ( ! empty( $analytics['donors'] ) ) : ?>
                <h3 class="cdf-mt"><?php esc_html_e( 'Top Donors', CDF_PLUGIN_TEXT_DOMAIN ); ?></h3>
                <ul class="cdf-top-donors">
                    <?php foreach ( $analytics['donors'] as $donor ) : ?>
                        <li>
                            <span class="cdf-donor-name"><?php echo esc_html( trim( $donor['first_name'] . ' ' . $donor['last_name'] ) ?: __( 'Anonymous', CDF_PLUGIN_TEXT_DOMAIN ) ); ?></span>
                            <span class="cdf-donor-meta"><?php echo esc_html( $donor['email'] ); ?></span>
                            <strong><?php echo esc_html( $symbol . number_format( (float) $donor['total'], 2 ) ); ?></strong>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <div class="cdf-card cdf-donations-card" id="donations">
        <div class="cdf-card-head">
            <h2><?php esc_html_e( 'Donations', CDF_PLUGIN_TEXT_DOMAIN ); ?></h2>
            <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cdf_export_csv' ), 'cdf_export_csv' ) ); ?>">
                <?php esc_html_e( 'Export CSV', CDF_PLUGIN_TEXT_DOMAIN ); ?>
            </a>
        </div>

        <?php cdf_render_donations_filters(); ?>
        <?php cdf_render_donations_table(); ?>
    </div>
    <?php
}

/**
 * Donation list filters.
 */
function cdf_render_donations_filters() {
    $gateway   = isset( $_GET['cdf_gateway'] ) ? sanitize_key( wp_unslash( $_GET['cdf_gateway'] ) ) : '';
    $frequency = isset( $_GET['cdf_frequency'] ) ? sanitize_key( wp_unslash( $_GET['cdf_frequency'] ) ) : '';
    $search    = isset( $_GET['cdf_search'] ) ? sanitize_text_field( wp_unslash( $_GET['cdf_search'] ) ) : '';
    $status    = isset( $_GET['cdf_status'] ) ? sanitize_key( wp_unslash( $_GET['cdf_status'] ) ) : '';
    ?>
    <form method="get" class="cdf-filters">
        <input type="hidden" name="post_type" value="donation">
        <input type="hidden" name="page" value="cdf-settings">
        <input type="search" name="cdf_search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search name or email…', CDF_PLUGIN_TEXT_DOMAIN ); ?>">
        <select name="cdf_gateway">
            <option value=""><?php esc_html_e( 'All gateways', CDF_PLUGIN_TEXT_DOMAIN ); ?></option>
            <option value="stripe" <?php selected( $gateway, 'stripe' ); ?>><?php esc_html_e( 'Stripe', CDF_PLUGIN_TEXT_DOMAIN ); ?></option>
            <option value="paypal" <?php selected( $gateway, 'paypal' ); ?>><?php esc_html_e( 'PayPal', CDF_PLUGIN_TEXT_DOMAIN ); ?></option>
        </select>
        <select name="cdf_frequency">
            <option value=""><?php esc_html_e( 'All types', CDF_PLUGIN_TEXT_DOMAIN ); ?></option>
            <option value="one-time" <?php selected( $frequency, 'one-time' ); ?>><?php esc_html_e( 'One-Time', CDF_PLUGIN_TEXT_DOMAIN ); ?></option>
            <option value="monthly" <?php selected( $frequency, 'monthly' ); ?>><?php esc_html_e( 'Monthly', CDF_PLUGIN_TEXT_DOMAIN ); ?></option>
        </select>
        <select name="cdf_status">
            <option value=""><?php esc_html_e( 'All statuses', CDF_PLUGIN_TEXT_DOMAIN ); ?></option>
            <?php foreach ( array( 'completed', 'pending', 'refunded', 'failed' ) as $option ) : ?>
                <option value="<?php echo esc_attr( $option ); ?>" <?php selected( $status, $option ); ?>><?php echo esc_html( ucfirst( $option ) ); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="button"><?php esc_html_e( 'Filter', CDF_PLUGIN_TEXT_DOMAIN ); ?></button>
        <a class="button-link" href="<?php echo esc_url( admin_url( 'edit.php?post_type=donation&page=cdf-settings' ) ); ?>"><?php esc_html_e( 'Reset', CDF_PLUGIN_TEXT_DOMAIN ); ?></a>
    </form>
    <?php
}

/**
 * Donation table with modal triggers.
 */
function cdf_render_donations_table() {
    $paged     = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
    $gateway   = isset( $_GET['cdf_gateway'] ) ? sanitize_key( wp_unslash( $_GET['cdf_gateway'] ) ) : '';
    $frequency = isset( $_GET['cdf_frequency'] ) ? sanitize_key( wp_unslash( $_GET['cdf_frequency'] ) ) : '';
    $search    = isset( $_GET['cdf_search'] ) ? sanitize_text_field( wp_unslash( $_GET['cdf_search'] ) ) : '';
    $status    = isset( $_GET['cdf_status'] ) ? sanitize_key( wp_unslash( $_GET['cdf_status'] ) ) : '';

    $args = array(
        'post_type'      => 'donation',
        'post_status'    => 'publish',
        'posts_per_page' => 20,
        'paged'          => $paged,
        'orderby'        => 'date',
        'order'          => 'DESC',
    );

    $meta_query = array();
    if ( $gateway ) {
        $meta_query[] = array(
            'key'     => '_donation_gateway',
            'value'   => 'stripe' === $gateway ? 'Stripe' : 'PayPal',
            'compare' => '=',
        );
    }
    if ( $frequency ) {
        $meta_query[] = array(
            'key'     => '_donation_frequency',
            'value'   => 'monthly' === $frequency ? 'Monthly' : 'One-Time',
            'compare' => '=',
        );
    }
    if ( $status ) {
        if ( 'completed' === $status ) {
            $meta_query[] = array(
                'relation' => 'OR',
                array( 'key' => '_donation_status', 'value' => 'completed' ),
                array( 'key' => '_donation_status', 'compare' => 'NOT EXISTS' ),
            );
        } else {
            $meta_query[] = array( 'key' => '_donation_status', 'value' => $status );
        }
    }
    if ( $search ) {
        $meta_query[] = array(
            'relation' => 'OR',
            array( 'key' => '_donation_email', 'value' => $search, 'compare' => 'LIKE' ),
            array( 'key' => '_donation_first_name', 'value' => $search, 'compare' => 'LIKE' ),
            array( 'key' => '_donation_last_name', 'value' => $search, 'compare' => 'LIKE' ),
            array( 'key' => '_donation_transaction_id', 'value' => $search, 'compare' => 'LIKE' ),
        );
    }
    if ( ! empty( $meta_query ) ) {
        if ( count( $meta_query ) > 1 && ! isset( $meta_query['relation'] ) ) {
            $meta_query['relation'] = 'AND';
        }
        $args['meta_query'] = $meta_query;
    }

    $query = new WP_Query( $args );
    $symbol = cdf_get_currency_symbol();

    if ( ! $query->have_posts() ) {
        echo '<p class="cdf-empty">' . esc_html__( 'No donations found.', CDF_PLUGIN_TEXT_DOMAIN ) . '</p>';
        return;
    }
    ?>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cdf-bulk-form" id="cdf-bulk-form">
        <?php wp_nonce_field( 'cdf_delete_donation' ); ?>
        <input type="hidden" name="action" value="cdf_delete_donation">

        <div class="cdf-bulk-bar">
            <label class="cdf-bulk-all">
                <input type="checkbox" id="cdf-select-all">
                <?php esc_html_e( 'Select all', CDF_PLUGIN_TEXT_DOMAIN ); ?>
            </label>
            <button type="submit" class="button cdf-bulk-delete" data-confirm="<?php esc_attr_e( 'Permanently delete the selected donations and all of their data? This cannot be undone.', CDF_PLUGIN_TEXT_DOMAIN ); ?>">
                <?php esc_html_e( 'Delete selected', CDF_PLUGIN_TEXT_DOMAIN ); ?>
            </button>
            <span class="cdf-muted cdf-bulk-hint"><?php esc_html_e( 'Deleting a donation also removes its meta (transaction ID, donor details, webhook status).', CDF_PLUGIN_TEXT_DOMAIN ); ?></span>
        </div>

    <table class="cdf-table widefat">
        <thead>
            <tr>
                <th class="cdf-col-check"><input type="checkbox" id="cdf-select-all-top" aria-label="<?php esc_attr_e( 'Select all donations', CDF_PLUGIN_TEXT_DOMAIN ); ?>"></th>
                <th><?php esc_html_e( 'Receipt', CDF_PLUGIN_TEXT_DOMAIN ); ?></th>
                <th><?php esc_html_e( 'Donor', CDF_PLUGIN_TEXT_DOMAIN ); ?></th>
                <th><?php esc_html_e( 'Email', CDF_PLUGIN_TEXT_DOMAIN ); ?></th>
                <th><?php esc_html_e( 'Amount', CDF_PLUGIN_TEXT_DOMAIN ); ?></th>
                <th><?php esc_html_e( 'Type', CDF_PLUGIN_TEXT_DOMAIN ); ?></th>
                <th><?php esc_html_e( 'Gateway', CDF_PLUGIN_TEXT_DOMAIN ); ?></th>
                <th><?php esc_html_e( 'Status', CDF_PLUGIN_TEXT_DOMAIN ); ?></th>
                <th><?php esc_html_e( 'Date', CDF_PLUGIN_TEXT_DOMAIN ); ?></th>
                <th class="cdf-col-actions"><?php esc_html_e( 'Actions', CDF_PLUGIN_TEXT_DOMAIN ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php
            while ( $query->have_posts() ) :
                $query->the_post();
                $post_id   = get_the_ID();
                $data      = cdf_get_donation_row_data( $post_id );
                $status_v  = $data['status'] ? $data['status'] : 'completed';
                ?>
                <tr>
                    <td class="cdf-col-check">
                        <input type="checkbox" class="cdf-donation-check" name="donation_ids[]" value="<?php echo esc_attr( $post_id ); ?>" aria-label="<?php esc_attr_e( 'Select donation', CDF_PLUGIN_TEXT_DOMAIN ); ?>">
                    </td>
                    <td><span class="cdf-mono">#<?php echo esc_html( $data['serial'] ); ?></span></td>
                    <td><strong><?php echo esc_html( $data['anonymous'] ? __( 'Anonymous', CDF_PLUGIN_TEXT_DOMAIN ) : $data['name'] ); ?></strong></td>
                    <td><?php echo esc_html( $data['email'] ? $data['email'] : '—' ); ?></td>
                    <td><strong><?php echo esc_html( $symbol . number_format( $data['amount'], 2 ) ); ?></strong></td>
                    <td><span class="cdf-pill"><?php echo esc_html( $data['frequency'] ); ?></span></td>
                    <td><?php echo esc_html( $data['gateway'] ); ?></td>
                    <td><span class="cdf-status cdf-status-<?php echo esc_attr( $status_v ); ?>"><?php echo esc_html( ucfirst( $status_v ) ); ?></span></td>
                    <td><?php echo esc_html( get_the_date( 'M j, Y H:i', $post_id ) ); ?></td>
                    <td class="cdf-col-actions">
                        <button type="button" class="button button-small cdf-view-donation"
                            data-donation="<?php echo esc_attr( wp_json_encode( $data ) ); ?>">
                            <?php esc_html_e( 'View', CDF_PLUGIN_TEXT_DOMAIN ); ?>
                        </button>
                        <?php if ( 'test / sandbox' === strtolower( $data['environment'] ) ) : ?>
                            <span class="cdf-mini-badge"><?php esc_html_e( 'Test', CDF_PLUGIN_TEXT_DOMAIN ); ?></span>
                        <?php endif; ?>
                        <button type="submit" class="button button-small cdf-delete-donation"
                            name="donation_id" value="<?php echo esc_attr( $post_id ); ?>"
                            data-confirm="<?php esc_attr_e( 'Permanently delete this donation and all of its data? This cannot be undone.', CDF_PLUGIN_TEXT_DOMAIN ); ?>">
                            <?php esc_html_e( 'Delete', CDF_PLUGIN_TEXT_DOMAIN ); ?>
                        </button>
                    </td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
    </form>

    <?php
    $total_pages = (int) $query->max_num_pages;
    if ( $total_pages > 1 ) {
        $base = admin_url( 'edit.php' ) . '?post_type=donation&page=cdf-settings';
        if ( $gateway ) {
            $base .= '&cdf_gateway=' . rawurlencode( $gateway );
        }
        if ( $frequency ) {
            $base .= '&cdf_frequency=' . rawurlencode( $frequency );
        }
        if ( $status ) {
            $base .= '&cdf_status=' . rawurlencode( $status );
        }
        if ( $search ) {
            $base .= '&cdf_search=' . rawurlencode( $search );
        }
        $base .= '&paged=%#%';

        echo '<div class="cdf-pagination">';
        echo wp_kses_post(
            paginate_links(
                array(
                    'base'      => $base,
                    'format'    => '',
                    'current'   => $paged,
                    'total'     => $total_pages,
                    'prev_text' => '‹',
                    'next_text' => '›',
                )
            )
        );
        echo '</div>';
    }
    wp_reset_postdata();
}

/**
 * Data for one donation row / modal.
 *
 * @param int $post_id Post ID.
 * @return array
 */
function cdf_get_donation_row_data( $post_id ) {
    $first = get_post_meta( $post_id, '_donation_first_name', true );
    $last  = get_post_meta( $post_id, '_donation_last_name', true );
    return array(
        'id'              => $post_id,
        'serial'          => get_post_meta( $post_id, '_donation_serial_number', true ) ?: $post_id,
        'name'            => trim( $first . ' ' . $last ),
        'first'           => $first,
        'last'            => $last,
        'email'           => get_post_meta( $post_id, '_donation_email', true ),
        'phone'           => get_post_meta( $post_id, '_donation_phone', true ),
        'amount'          => floatval( get_post_meta( $post_id, '_donation_amount', true ) ),
        'currency'        => get_post_meta( $post_id, '_donation_currency', true ) ?: cdf_get_currency(),
        'gateway'         => get_post_meta( $post_id, '_donation_gateway', true ),
        'frequency'       => get_post_meta( $post_id, '_donation_frequency', true ),
        'environment'     => get_post_meta( $post_id, '_donation_environment', true ),
        'status'          => get_post_meta( $post_id, '_donation_status', true ),
        'transaction_id'  => get_post_meta( $post_id, '_donation_transaction_id', true ),
        'subscription_id' => get_post_meta( $post_id, '_donation_subscription_id', true ),
        'anonymous'       => '1' === get_post_meta( $post_id, '_donation_anonymous', true ),
        'date'            => get_the_date( 'M j, Y H:i', $post_id ),
    );
}

/**
 * Modal shell (populated by JS).
 */
function cdf_render_donation_modal() {
    ?>
    <div class="cdf-modal" id="cdf-donation-modal" aria-hidden="true">
        <div class="cdf-modal-backdrop" data-close="1"></div>
        <div class="cdf-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="cdf-modal-title">
            <div class="cdf-modal-head">
                <h2 id="cdf-modal-title"><?php esc_html_e( 'Donation Details', CDF_PLUGIN_TEXT_DOMAIN ); ?></h2>
                <button type="button" class="cdf-modal-close" data-close="1" aria-label="<?php esc_attr_e( 'Close', CDF_PLUGIN_TEXT_DOMAIN ); ?>">×</button>
            </div>
            <div class="cdf-modal-body"></div>
            <div class="cdf-modal-foot">
                <button type="button" class="button" data-close="1"><?php esc_html_e( 'Close', CDF_PLUGIN_TEXT_DOMAIN ); ?></button>
            </div>
        </div>
    </div>
    <?php
}

/* =========================================================================
 * TAB: FORM
 * ========================================================================= */

/**
 * Forms tab.
 */
function cdf_render_tab_forms() {
    $general   = cdf_get_general_settings();
    $symbol    = cdf_get_currency_symbol();
    $pages     = get_pages();
    ?>
    <form method="post" action="options.php" class="cdf-form-card">
        <?php settings_fields( 'cdf_general_group' ); ?>

        <div class="cdf-card">
            <h2><?php esc_html_e( 'Shortcodes', CDF_PLUGIN_TEXT_DOMAIN ); ?></h2>
            <p class="cdf-muted"><?php esc_html_e( 'Copy a shortcode into any page, post or widget.', CDF_PLUGIN_TEXT_DOMAIN ); ?></p>
            <div class="cdf-shortcode-grid">
                <div class="cdf-shortcode">
                    <code>[donation_form]</code>
                    <p><?php esc_html_e( 'Full form with a One-Time / Monthly switch and every enabled gateway.', CDF_PLUGIN_TEXT_DOMAIN ); ?></p>
                    <button type="button" class="button cdf-copy" data-copy="[donation_form]"><?php esc_html_e( 'Copy', CDF_PLUGIN_TEXT_DOMAIN ); ?></button>
                </div>
                <div class="cdf-shortcode">
                    <code>[donation_form type="one_time"]</code>
                    <p><?php esc_html_e( 'One-time donations only.', CDF_PLUGIN_TEXT_DOMAIN ); ?></p>
                    <button type="button" class="button cdf-copy" data-copy='[donation_form type="one_time"]'><?php esc_html_e( 'Copy', CDF_PLUGIN_TEXT_DOMAIN ); ?></button>
                </div>
                <div class="cdf-shortcode">
                    <code>[donation_form type="monthly"]</code>
                    <p><?php esc_html_e( 'Monthly recurring donations only.', CDF_PLUGIN_TEXT_DOMAIN ); ?></p>
                    <button type="button" class="button cdf-copy" data-copy='[donation_form type="monthly"]'><?php esc_html_e( 'Copy', CDF_PLUGIN_TEXT_DOMAIN ); ?></button>
                </div>
                <div class="cdf-shortcode">
                    <code>[donation_form amounts="500,250,100,50"]</code>
                    <p><?php esc_html_e( 'Override the preset buttons for one form.', CDF_PLUGIN_TEXT_DOMAIN ); ?></p>
                    <button type="button" class="button cdf-copy" data-copy='[donation_form amounts="500,250,100,50"]'><?php esc_html_e( 'Copy', CDF_PLUGIN_TEXT_DOMAIN ); ?></button>
                </div>
            </div>
        </div>

        <div class="cdf-card">
            <h2><?php esc_html_e( 'Amounts & Currency', CDF_PLUGIN_TEXT_DOMAIN ); ?></h2>
            <table class="form-table" role="presentation">
                <?php
                cdf_admin_text_field( 'cdf_general_settings[currency]', $general['currency'], __( 'Currency code', CDF_PLUGIN_TEXT_DOMAIN ), __( 'Three letter ISO code, e.g. USD, EUR, GBP.', CDF_PLUGIN_TEXT_DOMAIN ) );
                cdf_admin_text_field( 'cdf_general_settings[currency_symbol]', $general['currency_symbol'], __( 'Currency symbol', CDF_PLUGIN_TEXT_DOMAIN ), __( 'Leave empty to use the default symbol for the currency.', CDF_PLUGIN_TEXT_DOMAIN ) );
                cdf_admin_text_field( 'cdf_general_settings[preset_amounts]', $general['preset_amounts'], __( 'Preset amounts', CDF_PLUGIN_TEXT_DOMAIN ), __( 'Comma separated list, e.g. 500,250,100,50,25,10', CDF_PLUGIN_TEXT_DOMAIN ) );
                cdf_admin_text_field( 'cdf_general_settings[default_amount]', $general['default_amount'], __( 'Default amount', CDF_PLUGIN_TEXT_DOMAIN ), sprintf( /* translators: %s symbol */ esc_html__( 'Pre-selected amount (%s).', CDF_PLUGIN_TEXT_DOMAIN ), esc_html( $symbol ) ) );
                cdf_admin_text_field( 'cdf_general_settings[min_amount]', $general['min_amount'], __( 'Minimum amount', CDF_PLUGIN_TEXT_DOMAIN ) );
                cdf_admin_text_field( 'cdf_general_settings[max_amount]', $general['max_amount'], __( 'Maximum amount', CDF_PLUGIN_TEXT_DOMAIN ) );
                cdf_admin_toggle_field(
                    'cdf_general_settings[custom_amount_mode]',
                    $general['custom_amount_mode'],
                    __( 'Custom amount mode', CDF_PLUGIN_TEXT_DOMAIN ),
                    __( 'OFF: clicking a preset fills the number field and the donor can edit it. ON: the number field stays empty with a placeholder; clicking a preset only selects it, and a typed amount overrides the preset (the preset is then unchecked).', CDF_PLUGIN_TEXT_DOMAIN )
                );
                cdf_admin_text_field( 'cdf_general_settings[custom_amount_label]', $general['custom_amount_label'], __( 'Custom amount placeholder', CDF_PLUGIN_TEXT_DOMAIN ), __( 'Shown inside the amount field in custom amount mode, e.g. "Custom amount".', CDF_PLUGIN_TEXT_DOMAIN ) );
                ?>
            </table>
        </div>

        <div class="cdf-card">
            <h2><?php esc_html_e( 'Form Behaviour', CDF_PLUGIN_TEXT_DOMAIN ); ?></h2>
            <table class="form-table" role="presentation">
                <?php
                cdf_admin_text_field( 'cdf_general_settings[button_text_onetime]', $general['button_text_onetime'], __( 'One-time button text', CDF_PLUGIN_TEXT_DOMAIN ) );
                cdf_admin_text_field( 'cdf_general_settings[button_text_monthly]', $general['button_text_monthly'], __( 'Monthly button text', CDF_PLUGIN_TEXT_DOMAIN ) );
                ?>
                <tr>
                    <th scope="row"><label for="cdf_thank_you"><?php esc_html_e( 'Thank-you message', CDF_PLUGIN_TEXT_DOMAIN ); ?></label></th>
                    <td>
                        <textarea id="cdf_thank_you" class="large-text" rows="2" name="cdf_general_settings[thank_you_message]"><?php echo esc_textarea( $general['thank_you_message'] ); ?></textarea>
                        <p class="description"><?php esc_html_e( 'Shown on the success page above the receipt.', CDF_PLUGIN_TEXT_DOMAIN ); ?></p>
                    </td>
                </tr>
                <?php
                cdf_admin_toggle_field( 'cdf_general_settings[enable_onetime]', $general['enable_onetime'], __( 'Enable one-time donations', CDF_PLUGIN_TEXT_DOMAIN ) );
                cdf_admin_toggle_field( 'cdf_general_settings[enable_monthly]', $general['enable_monthly'], __( 'Enable monthly donations', CDF_PLUGIN_TEXT_DOMAIN ) );
                cdf_admin_toggle_field( 'cdf_general_settings[enable_stripe]', $general['enable_stripe'], __( 'Enable Stripe', CDF_PLUGIN_TEXT_DOMAIN ), __( 'Also requires Stripe keys for the current mode.', CDF_PLUGIN_TEXT_DOMAIN ) );
                cdf_admin_toggle_field( 'cdf_general_settings[enable_paypal]', $general['enable_paypal'], __( 'Enable PayPal', CDF_PLUGIN_TEXT_DOMAIN ), __( 'Also requires PayPal keys for the current mode.', CDF_PLUGIN_TEXT_DOMAIN ) );
                cdf_admin_toggle_field( 'cdf_general_settings[show_phone_field]', $general['show_phone_field'], __( 'Show phone field', CDF_PLUGIN_TEXT_DOMAIN ), __( 'Optional phone number collected with the donation.', CDF_PLUGIN_TEXT_DOMAIN ) );
                ?>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Stripe payment methods', CDF_PLUGIN_TEXT_DOMAIN ); ?></th>
                    <td>
                        <label><input type="checkbox" name="cdf_general_settings[stripe_payment_methods][]" value="card" <?php checked( false !== strpos( $general['stripe_payment_methods'], 'card' ) ); ?>> <?php esc_html_e( 'Cards (Apple Pay / Google Pay appear automatically when enabled in Stripe)', CDF_PLUGIN_TEXT_DOMAIN ); ?></label><br>
                        <label><input type="checkbox" name="cdf_general_settings[stripe_payment_methods][]" value="link" <?php checked( false !== strpos( $general['stripe_payment_methods'], 'link' ) ); ?>> <?php esc_html_e( 'Stripe Link', CDF_PLUGIN_TEXT_DOMAIN ); ?></label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="cdf_success_page"><?php esc_html_e( 'Success page', CDF_PLUGIN_TEXT_DOMAIN ); ?></label></th>
                    <td>
                        <select id="cdf_success_page" name="cdf_general_settings[success_page_id]">
                            <option value=""><?php esc_html_e( 'Default (/donation-success/)', CDF_PLUGIN_TEXT_DOMAIN ); ?></option>
                            <?php foreach ( $pages as $page ) : ?>
                                <option value="<?php echo esc_attr( $page->ID ); ?>" <?php selected( $general['success_page_id'], (string) $page->ID ); ?>><?php echo esc_html( $page->post_title ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e( 'This page must contain the [donation_receipt] shortcode.', CDF_PLUGIN_TEXT_DOMAIN ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="cdf_cancel_page"><?php esc_html_e( 'Cancel page', CDF_PLUGIN_TEXT_DOMAIN ); ?></label></th>
                    <td>
                        <select id="cdf_cancel_page" name="cdf_general_settings[cancel_page_id]">
                            <option value=""><?php esc_html_e( 'Default (/donation-canceled/)', CDF_PLUGIN_TEXT_DOMAIN ); ?></option>
                            <?php foreach ( $pages as $page ) : ?>
                                <option value="<?php echo esc_attr( $page->ID ); ?>" <?php selected( $general['cancel_page_id'], (string) $page->ID ); ?>><?php echo esc_html( $page->post_title ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </table>
        </div>

        <div class="cdf-card">
            <h2><?php esc_html_e( 'Security & Caching', CDF_PLUGIN_TEXT_DOMAIN ); ?></h2>
            <?php $nonce_bypass = get_option( 'cdf_nonce_bypass', '0' ); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Nonce verification', CDF_PLUGIN_TEXT_DOMAIN ); ?></th>
                    <td>
                        <label class="cdf-switch">
                            <input type="checkbox" name="cdf_nonce_bypass" value="1" <?php checked( '1', $nonce_bypass ); ?>>
                            <span class="cdf-slider"></span>
                        </label>
                        <span class="cdf-env-label">
                            <?php echo '1' === $nonce_bypass ? esc_html__( 'Disabled (cached-site mode)', CDF_PLUGIN_TEXT_DOMAIN ) : esc_html__( 'Enabled (recommended)', CDF_PLUGIN_TEXT_DOMAIN ); ?>
                        </span>
                        <p class="description">
                            <?php esc_html_e( 'Keep this on unless your full-page cache serves stale security tokens and donations fail with "session expired" — for example Cloudflare APO, WP Rocket or a host cache that ignores DONOTCACHEPAGE.', CDF_PLUGIN_TEXT_DOMAIN ); ?>
                        </p>
                        <?php if ( '1' === $nonce_bypass ) : ?>
                            <div class="cdf-notice cdf-notice-warning cdf-notice-inline">
                                <?php esc_html_e( 'Warning: with nonce verification off, form submissions are not protected against cross-site request forgery. Honeypot, rate limiting and every validation rule stay active — but prefer excluding the form page from caching instead.', CDF_PLUGIN_TEXT_DOMAIN ); ?>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        </div>

        <?php submit_button( __( 'Save form settings', CDF_PLUGIN_TEXT_DOMAIN ) ); ?>
    </form>

    <div class="cdf-card">
        <h2><?php esc_html_e( 'Caching Note', CDF_PLUGIN_TEXT_DOMAIN ); ?></h2>
        <p class="cdf-muted">
            <?php esc_html_e( 'The plugin sets DONOTCACHEPAGE on pages that hold the form, so most caching plugins skip them automatically. If your host or CDN still caches the page, either exclude it manually or turn off nonce verification under Security & Caching above.', CDF_PLUGIN_TEXT_DOMAIN ); ?>
        </p>
    </div>

    <div class="cdf-card">
        <h2><?php esc_html_e( 'Gateway Availability', CDF_PLUGIN_TEXT_DOMAIN ); ?></h2>
        <p class="cdf-muted"><?php esc_html_e( 'A gateway is only shown to donors when it is enabled here and has API keys for the current mode.', CDF_PLUGIN_TEXT_DOMAIN ); ?></p>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Stripe', CDF_PLUGIN_TEXT_DOMAIN ); ?></th>
                <td>
                    <?php echo cdf_is_gateway_configured( 'stripe' ) ? '<span class="cdf-status cdf-status-completed">' . esc_html__( 'Configured', CDF_PLUGIN_TEXT_DOMAIN ) . '</span>' : '<span class="cdf-status cdf-status-failed">' . esc_html__( 'Not configured', CDF_PLUGIN_TEXT_DOMAIN ) . '</span>'; ?>
                    <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=donation&page=cdf-settings&tab=settings' ) ); ?>" class="cdf-inline-link"><?php esc_html_e( 'Manage keys', CDF_PLUGIN_TEXT_DOMAIN ); ?></a>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'PayPal', CDF_PLUGIN_TEXT_DOMAIN ); ?></th>
                <td>
                    <?php echo cdf_is_gateway_configured( 'paypal' ) ? '<span class="cdf-status cdf-status-completed">' . esc_html__( 'Configured', CDF_PLUGIN_TEXT_DOMAIN ) . '</span>' : '<span class="cdf-status cdf-status-failed">' . esc_html__( 'Not configured', CDF_PLUGIN_TEXT_DOMAIN ) . '</span>'; ?>
                    <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=donation&page=cdf-settings&tab=settings' ) ); ?>" class="cdf-inline-link"><?php esc_html_e( 'Manage keys', CDF_PLUGIN_TEXT_DOMAIN ); ?></a>
                </td>
            </tr>
        </table>
    </div>
    <?php
}

/* =========================================================================
 * TAB: STYLE
 * ========================================================================= */

/**
 * Render one color picker input.
 *
 * @param string $name  Field name.
 * @param string $value Value.
 */
function cdf_render_color_picker( $name, $value ) {
    ?>
    <input type="text" class="cdf-color-field" id="<?php echo esc_attr( cdf_field_id( $name ) ); ?>"
        name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>"
        data-default-color="<?php echo esc_attr( $value ); ?>">
    <?php
}

/**
 * Style tab.
 */
function cdf_render_tab_style() {
    $styles = get_option( 'cdf_style_options', array() );
    $s = function( $key, $default ) use ( $styles ) {
        return isset( $styles[ $key ] ) ? $styles[ $key ] : $default;
    };

    $groups = array(
        'normal' => array(
            'label' => __( 'Normal', CDF_PLUGIN_TEXT_DOMAIN ),
            'items' => array(
                array( 'amt_bg_normal', __( 'Amount button background', CDF_PLUGIN_TEXT_DOMAIN ), '#f7fafc' ),
                array( 'amt_text_normal', __( 'Amount button text', CDF_PLUGIN_TEXT_DOMAIN ), '#2d3748' ),
                array( 'gw_bg_normal', __( 'Gateway card background', CDF_PLUGIN_TEXT_DOMAIN ), '#ffffff' ),
                array( 'gw_text_normal', __( 'Gateway card text', CDF_PLUGIN_TEXT_DOMAIN ), '#2d3748' ),
                array( 'sub_bg_normal', __( 'Submit button background', CDF_PLUGIN_TEXT_DOMAIN ), '#3182ce' ),
                array( 'sub_text_normal', __( 'Submit button text', CDF_PLUGIN_TEXT_DOMAIN ), '#ffffff' ),
            ),
        ),
        'hover' => array(
            'label' => __( 'Hover', CDF_PLUGIN_TEXT_DOMAIN ),
            'items' => array(
                array( 'amt_bg_hover', __( 'Amount button background', CDF_PLUGIN_TEXT_DOMAIN ), '#edf2f7' ),
                array( 'amt_text_hover', __( 'Amount button text', CDF_PLUGIN_TEXT_DOMAIN ), '#2d3748' ),
                array( 'gw_bg_hover', __( 'Gateway card background', CDF_PLUGIN_TEXT_DOMAIN ), '#f7fafc' ),
                array( 'gw_text_hover', __( 'Gateway card text', CDF_PLUGIN_TEXT_DOMAIN ), '#2d3748' ),
                array( 'sub_bg_hover', __( 'Submit button background', CDF_PLUGIN_TEXT_DOMAIN ), '#2b6cb0' ),
                array( 'sub_text_hover', __( 'Submit button text', CDF_PLUGIN_TEXT_DOMAIN ), '#ffffff' ),
            ),
        ),
        'active' => array(
            'label' => __( 'Active', CDF_PLUGIN_TEXT_DOMAIN ),
            'items' => array(
                array( 'amt_bg_active', __( 'Amount button background', CDF_PLUGIN_TEXT_DOMAIN ), '#ebf8ff' ),
                array( 'amt_text_active', __( 'Amount button text', CDF_PLUGIN_TEXT_DOMAIN ), '#2b6cb0' ),
                array( 'gw_bg_active', __( 'Gateway card background', CDF_PLUGIN_TEXT_DOMAIN ), '#ebf8ff' ),
                array( 'gw_text_active', __( 'Gateway card text', CDF_PLUGIN_TEXT_DOMAIN ), '#2b6cb0' ),
                array( 'sub_bg_active', __( 'Submit button background', CDF_PLUGIN_TEXT_DOMAIN ), '#2b6cb0' ),
                array( 'sub_text_active', __( 'Submit button text', CDF_PLUGIN_TEXT_DOMAIN ), '#ffffff' ),
            ),
        ),
    );
    ?>
    <form method="post" action="options.php">
        <?php settings_fields( 'cdf_style_group' ); ?>

        <div class="cdf-card">
            <h2><?php esc_html_e( 'Shape', CDF_PLUGIN_TEXT_DOMAIN ); ?></h2>
            <table class="form-table" role="presentation">
                <?php cdf_admin_text_field( 'cdf_style_options[border_radius]', $s( 'border_radius', '8' ), __( 'Border radius (px)', CDF_PLUGIN_TEXT_DOMAIN ), __( '0 for square corners, 8–16 for soft rounded.', CDF_PLUGIN_TEXT_DOMAIN ), 'number' ); ?>
            </table>
        </div>

        <div class="cdf-card">
            <h2><?php esc_html_e( 'Colors', CDF_PLUGIN_TEXT_DOMAIN ); ?></h2>
            <div class="cdf-subtabs" role="tablist">
                <?php $first = true; foreach ( $groups as $slug => $group ) : ?>
                    <button type="button" class="cdf-subtab<?php echo $first ? ' active' : ''; ?>" data-subtab="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $group['label'] ); ?></button>
                <?php $first = false; endforeach; ?>
            </div>

            <?php $first = true; foreach ( $groups as $slug => $group ) : ?>
                <div class="cdf-subpane" id="cdf-subpane-<?php echo esc_attr( $slug ); ?>" <?php echo $first ? '' : 'style="display:none"'; ?>>
                    <div class="cdf-color-grid">
                        <?php foreach ( $group['items'] as $item ) : ?>
                            <div class="cdf-color-item">
                                <label for="cdf_<?php echo esc_attr( $item[0] ); ?>"><?php echo esc_html( $item[1] ); ?></label>
                                <?php cdf_render_color_picker( 'cdf_style_options[' . $item[0] . ']', $s( $item[0], $item[2] ) ); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php $first = false; endforeach; ?>
        </div>

        <?php submit_button( __( 'Save style', CDF_PLUGIN_TEXT_DOMAIN ) ); ?>
    </form>
    <?php
}

/* =========================================================================
 * TAB: EMAILS
 * ========================================================================= */

/**
 * Emails tab.
 */
function cdf_render_tab_emails() {
    $email    = cdf_get_email_settings();
    $admin_on = get_option( 'cdf_admin_email_enabled', '1' );
    $donor_on = get_option( 'cdf_donor_email_enabled', '1' );
    $recip    = get_option( 'cdf_admin_email_recipients', get_option( 'admin_email' ) );

    if ( isset( $_GET['cdf_test_email'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $result = sanitize_key( wp_unslash( $_GET['cdf_test_email'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        echo '<div class="notice ' . ( 'sent' === $result ? 'notice-success' : 'notice-error' ) . ' is-dismissible"><p>'
            . ( 'sent' === $result ? esc_html__( 'Test email sent.', CDF_PLUGIN_TEXT_DOMAIN ) : esc_html__( 'Test email could not be sent. Check your mail configuration.', CDF_PLUGIN_TEXT_DOMAIN ) )
            . '</p></div>';
    }
    ?>
    <div class="cdf-card">
        <h2><?php esc_html_e( 'Notifications', CDF_PLUGIN_TEXT_DOMAIN ); ?></h2>
        <p class="cdf-muted">
            <?php
            printf(
                /* translators: %s: available placeholders */
                esc_html__( 'Available placeholders: %s', CDF_PLUGIN_TEXT_DOMAIN ),
                '<code>%site_name% %first_name% %last_name% %full_name% %email% %amount% %gateway% %frequency% %serial% %transaction_id%</code>'
            );
            ?>
        </p>
        <form method="post" action="options.php">
            <?php settings_fields( 'cdf_email_group' ); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Admin notification', CDF_PLUGIN_TEXT_DOMAIN ); ?></th>
                    <td>
                        <label class="cdf-switch">
                            <input type="checkbox" name="cdf_admin_email_enabled" value="1" <?php checked( '1', $admin_on ); ?>>
                            <span class="cdf-slider"></span>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="cdf_recipients"><?php esc_html_e( 'Recipients', CDF_PLUGIN_TEXT_DOMAIN ); ?></label></th>
                    <td>
                        <input type="text" id="cdf_recipients" class="large-text" name="cdf_admin_email_recipients" value="<?php echo esc_attr( $recip ); ?>">
                        <p class="description"><?php esc_html_e( 'Comma separated email addresses. Invalid addresses are dropped on save.', CDF_PLUGIN_TEXT_DOMAIN ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Donor receipt', CDF_PLUGIN_TEXT_DOMAIN ); ?></th>
                    <td>
                        <label class="cdf-switch">
                            <input type="checkbox" name="cdf_donor_email_enabled" value="1" <?php checked( '1', $donor_on ); ?>>
                            <span class="cdf-slider"></span>
                        </label>
                    </td>
                </tr>
                <?php
                cdf_admin_text_field( 'cdf_email_settings[admin_subject]', $email['admin_subject'], __( 'Admin subject', CDF_PLUGIN_TEXT_DOMAIN ) );
                cdf_admin_text_field( 'cdf_email_settings[admin_heading]', $email['admin_heading'], __( 'Admin heading', CDF_PLUGIN_TEXT_DOMAIN ) );
                cdf_admin_text_field( 'cdf_email_settings[donor_subject]', $email['donor_subject'], __( 'Donor subject', CDF_PLUGIN_TEXT_DOMAIN ) );
                cdf_admin_text_field( 'cdf_email_settings[donor_heading]', $email['donor_heading'], __( 'Donor heading', CDF_PLUGIN_TEXT_DOMAIN ) );
                ?>
                <tr>
                    <th scope="row"><label for="cdf_donor_intro"><?php esc_html_e( 'Donor intro text', CDF_PLUGIN_TEXT_DOMAIN ); ?></label></th>
                    <td><textarea id="cdf_donor_intro" class="large-text" rows="2" name="cdf_email_settings[donor_intro]"><?php echo esc_textarea( $email['donor_intro'] ); ?></textarea></td>
                </tr>
                <?php
                cdf_admin_text_field( 'cdf_email_settings[from_name]', $email['from_name'], __( 'From name', CDF_PLUGIN_TEXT_DOMAIN ), __( 'Defaults to your site name.', CDF_PLUGIN_TEXT_DOMAIN ) );
                cdf_admin_text_field( 'cdf_email_settings[from_email]', $email['from_email'], __( 'From email', CDF_PLUGIN_TEXT_DOMAIN ), __( 'Defaults to the site admin email.', CDF_PLUGIN_TEXT_DOMAIN ), 'email' );
                cdf_admin_text_field( 'cdf_email_settings[reply_to]', $email['reply_to'], __( 'Reply-to', CDF_PLUGIN_TEXT_DOMAIN ), '', 'email' );
                ?>
            </table>
            <?php submit_button( __( 'Save email settings', CDF_PLUGIN_TEXT_DOMAIN ) ); ?>
        </form>
    </div>

    <div class="cdf-card">
        <h2><?php esc_html_e( 'Send a Test Email', CDF_PLUGIN_TEXT_DOMAIN ); ?></h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'cdf_test_email' ); ?>
            <input type="hidden" name="action" value="cdf_send_test_email">
            <p>
                <input type="email" class="regular-text" name="cdf_test_recipient" value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" required>
                <button type="submit" class="button"><?php esc_html_e( 'Send test email', CDF_PLUGIN_TEXT_DOMAIN ); ?></button>
            </p>
        </form>
    </div>
    <?php
}

/* =========================================================================
 * TAB: SETTINGS
 * ========================================================================= */

/**
 * Settings tab.
 */
function cdf_render_tab_settings() {
    $settings = get_option( 'cdf_api_settings', array() );
    $env      = get_option( 'cdf_environment', 'sandbox' );

    $get = function( $key, $fallback = '' ) use ( $settings ) {
        return isset( $settings[ $key ] ) && '' !== $settings[ $key ] ? $settings[ $key ] : $fallback;
    };

    if ( isset( $_GET['cdf_test_connection'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $result = sanitize_key( wp_unslash( $_GET['cdf_test_connection'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( urldecode( $result ) ) . '</p></div>';
    }

    $webhook_stripe  = rest_url( 'cdf/v1/stripe' );
    $webhook_paypal  = rest_url( 'cdf/v1/paypal' );
    ?>
    <form method="post" action="options.php">
        <?php settings_fields( 'cdf_api_group' ); ?>

        <div class="cdf-card">
            <h2><?php esc_html_e( 'Environment', CDF_PLUGIN_TEXT_DOMAIN ); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Mode', CDF_PLUGIN_TEXT_DOMAIN ); ?></th>
                    <td>
                        <label class="cdf-switch">
                            <input type="checkbox" id="cdf_env_switch" name="cdf_environment" value="live" <?php checked( 'live', $env ); ?>>
                            <span class="cdf-slider"></span>
                        </label>
                        <strong id="cdf_env_label" class="cdf-env-label"><?php echo 'live' === $env ? esc_html__( 'Live / Production', CDF_PLUGIN_TEXT_DOMAIN ) : esc_html__( 'Test / Sandbox', CDF_PLUGIN_TEXT_DOMAIN ); ?></strong>
                    </td>
                </tr>
            </table>
        </div>

        <div class="cdf-card">
            <h2><?php esc_html_e( 'Stripe', CDF_PLUGIN_TEXT_DOMAIN ); ?></h2>
            <div class="cdf-env-block cdf-env-test" <?php echo 'live' === $env ? 'style="display:none"' : ''; ?>>
                <h3 class="cdf-env-heading"><?php esc_html_e( 'Sandbox / test credentials', CDF_PLUGIN_TEXT_DOMAIN ); ?></h3>
                <table class="form-table" role="presentation">
                    <?php
                    cdf_admin_text_field( 'cdf_api_settings[stripe_publishable_test]', $get( 'stripe_publishable_test' ), __( 'Publishable key (test)', CDF_PLUGIN_TEXT_DOMAIN ), 'pk_test_…' );
                    cdf_admin_text_field( 'cdf_api_settings[stripe_secret_test]', $get( 'stripe_secret_test' ), __( 'Secret key (test)', CDF_PLUGIN_TEXT_DOMAIN ), 'sk_test_…', 'password' );
                    ?>
                </table>
            </div>
            <div class="cdf-env-block cdf-env-live" <?php echo 'live' === $env ? '' : 'style="display:none"'; ?>>
                <h3 class="cdf-env-heading"><?php esc_html_e( 'Live / production credentials', CDF_PLUGIN_TEXT_DOMAIN ); ?></h3>
                <table class="form-table" role="presentation">
                    <?php
                    cdf_admin_text_field( 'cdf_api_settings[stripe_publishable_live]', $get( 'stripe_publishable_live' ), __( 'Publishable key (live)', CDF_PLUGIN_TEXT_DOMAIN ), 'pk_live_…' );
                    cdf_admin_text_field( 'cdf_api_settings[stripe_secret_live]', $get( 'stripe_secret_live' ), __( 'Secret key (live)', CDF_PLUGIN_TEXT_DOMAIN ), 'sk_live_…', 'password' );
                    ?>
                </table>
            </div>
            <table class="form-table" role="presentation">
                <?php
                cdf_admin_text_field( 'cdf_api_settings[stripe_webhook_secret]', $get( 'stripe_webhook_secret' ), __( 'Webhook signing secret', CDF_PLUGIN_TEXT_DOMAIN ), __( 'whsec_… — shared by both modes.', CDF_PLUGIN_TEXT_DOMAIN ), 'password' );
                ?>
            </table>
        </div>

        <div class="cdf-card">
            <h2><?php esc_html_e( 'PayPal', CDF_PLUGIN_TEXT_DOMAIN ); ?></h2>
            <div class="cdf-env-block cdf-env-test" <?php echo 'live' === $env ? 'style="display:none"' : ''; ?>>
                <h3 class="cdf-env-heading"><?php esc_html_e( 'Sandbox / test credentials', CDF_PLUGIN_TEXT_DOMAIN ); ?></h3>
                <table class="form-table" role="presentation">
                    <?php
                    cdf_admin_text_field( 'cdf_api_settings[paypal_client_test]', $get( 'paypal_client_test' ), __( 'Client ID (test)', CDF_PLUGIN_TEXT_DOMAIN ) );
                    cdf_admin_text_field( 'cdf_api_settings[paypal_secret_test]', $get( 'paypal_secret_test' ), __( 'Secret (test)', CDF_PLUGIN_TEXT_DOMAIN ), '', 'password' );
                    ?>
                </table>
            </div>
            <div class="cdf-env-block cdf-env-live" <?php echo 'live' === $env ? '' : 'style="display:none"'; ?>>
                <h3 class="cdf-env-heading"><?php esc_html_e( 'Live / production credentials', CDF_PLUGIN_TEXT_DOMAIN ); ?></h3>
                <table class="form-table" role="presentation">
                    <?php
                    cdf_admin_text_field( 'cdf_api_settings[paypal_client_live]', $get( 'paypal_client_live' ), __( 'Client ID (live)', CDF_PLUGIN_TEXT_DOMAIN ) );
                    cdf_admin_text_field( 'cdf_api_settings[paypal_secret_live]', $get( 'paypal_secret_live' ), __( 'Secret (live)', CDF_PLUGIN_TEXT_DOMAIN ), '', 'password' );
                    ?>
                </table>
            </div>
            <table class="form-table" role="presentation">
                <?php
                cdf_admin_text_field( 'cdf_api_settings[paypal_plan_id]', $get( 'paypal_plan_id' ), __( 'Monthly Plan ID', CDF_PLUGIN_TEXT_DOMAIN ), __( 'Required for PayPal monthly donations. Create a plan priced at 1.00 of your currency — the donation amount is sent as quantity.', CDF_PLUGIN_TEXT_DOMAIN ) );
                cdf_admin_text_field( 'cdf_api_settings[paypal_webhook_id]', $get( 'paypal_webhook_id' ), __( 'Webhook ID', CDF_PLUGIN_TEXT_DOMAIN ), __( 'From PayPal → Apps → Webhooks. Used to verify webhook signatures.', CDF_PLUGIN_TEXT_DOMAIN ) );
                ?>
            </table>
        </div>

        <?php submit_button( __( 'Save API settings', CDF_PLUGIN_TEXT_DOMAIN ) ); ?>
    </form>

    <div class="cdf-card">
        <h2><?php esc_html_e( 'Webhooks', CDF_PLUGIN_TEXT_DOMAIN ); ?></h2>
        <p class="cdf-muted"><?php esc_html_e( 'Webhooks keep donations, renewals and refunds in sync even if the donor closes the browser. Add these URLs in your gateway dashboard.', CDF_PLUGIN_TEXT_DOMAIN ); ?></p>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Stripe endpoint', CDF_PLUGIN_TEXT_DOMAIN ); ?></th>
                <td>
                    <code class="cdf-webhook-url"><?php echo esc_html( $webhook_stripe ); ?></code>
                    <button type="button" class="button cdf-copy" data-copy="<?php echo esc_attr( $webhook_stripe ); ?>"><?php esc_html_e( 'Copy', CDF_PLUGIN_TEXT_DOMAIN ); ?></button>
                    <p class="description"><?php esc_html_e( 'Events: checkout.session.completed, invoice.payment_succeeded, charge.refunded.', CDF_PLUGIN_TEXT_DOMAIN ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'PayPal endpoint', CDF_PLUGIN_TEXT_DOMAIN ); ?></th>
                <td>
                    <code class="cdf-webhook-url"><?php echo esc_html( $webhook_paypal ); ?></code>
                    <button type="button" class="button cdf-copy" data-copy="<?php echo esc_attr( $webhook_paypal ); ?>"><?php esc_html_e( 'Copy', CDF_PLUGIN_TEXT_DOMAIN ); ?></button>
                    <p class="description"><?php esc_html_e( 'Events: PAYMENT.CAPTURE.COMPLETED, PAYMENT.CAPTURE.REFUNDED, BILLING.SUBSCRIPTION.ACTIVATED, BILLING.SUBSCRIPTION.CANCELLED.', CDF_PLUGIN_TEXT_DOMAIN ); ?></p>
                </td>
            </tr>
        </table>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'cdf_test_connection' ); ?>
            <input type="hidden" name="action" value="cdf_test_connection">
            <button type="submit" class="button button-secondary"><?php esc_html_e( 'Test gateway connection', CDF_PLUGIN_TEXT_DOMAIN ); ?></button>
        </form>
    </div>
    <?php
}

/* =========================================================================
 * TAB: TOOLS
 * ========================================================================= */

/**
 * Tools tab.
 */
function cdf_render_tab_tools() {
    $advanced = get_option( 'cdf_advanced_settings', array() );
    $debug    = isset( $advanced['debug_logging'] ) ? $advanced['debug_logging'] : '0';
    $delete   = isset( $advanced['delete_data_on_uninstall'] ) ? $advanced['delete_data_on_uninstall'] : '0';

    $checks = array(
        array(
            'label' => __( 'HTTPS', CDF_PLUGIN_TEXT_DOMAIN ),
            'ok'    => is_ssl(),
            'help'  => __( 'Live payments require HTTPS.', CDF_PLUGIN_TEXT_DOMAIN ),
        ),
        array(
            'label' => __( 'cURL', CDF_PLUGIN_TEXT_DOMAIN ),
            'ok'    => function_exists( 'curl_init' ),
            'help'  => __( 'Required for gateway API calls.', CDF_PLUGIN_TEXT_DOMAIN ),
        ),
        array(
            'label' => __( 'PHP version 7.4+', CDF_PLUGIN_TEXT_DOMAIN ),
            'ok'    => version_compare( PHP_VERSION, '7.4', '>=' ),
            'help'  => sprintf( /* translators: %s PHP version */ esc_html__( 'Current: %s', CDF_PLUGIN_TEXT_DOMAIN ), PHP_VERSION ),
        ),
        array(
            'label' => __( 'Success page', CDF_PLUGIN_TEXT_DOMAIN ),
            'ok'    => (bool) get_page_by_path( cdf_get_config( 'success_slug', 'donation-success' ) ) || ! empty( cdf_get_general_settings()['success_page_id'] ),
            'help'  => cdf_get_success_url(),
        ),
        array(
            'label' => __( 'WP-Cron (webhooks retry)', CDF_PLUGIN_TEXT_DOMAIN ),
            'ok'    => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
            'help'  => __( 'Used for background retries.', CDF_PLUGIN_TEXT_DOMAIN ),
        ),
    );
    ?>
    <form method="post" action="options.php">
        <?php settings_fields( 'cdf_tools_group' ); ?>
        <div class="cdf-card">
            <h2><?php esc_html_e( 'Advanced', CDF_PLUGIN_TEXT_DOMAIN ); ?></h2>
            <table class="form-table" role="presentation">
                <?php
                cdf_admin_toggle_field( 'cdf_advanced_settings[debug_logging]', $debug, __( 'Debug logging', CDF_PLUGIN_TEXT_DOMAIN ), __( 'Write gateway errors to the PHP error log. Never enable on a busy production site for long.', CDF_PLUGIN_TEXT_DOMAIN ) );
                cdf_admin_toggle_field( 'cdf_advanced_settings[delete_data_on_uninstall]', $delete, __( 'Delete all data on uninstall', CDF_PLUGIN_TEXT_DOMAIN ), __( 'Removes donations, settings and generated pages when the plugin is deleted.', CDF_PLUGIN_TEXT_DOMAIN ) );
                ?>
            </table>
            <?php submit_button( __( 'Save tools settings', CDF_PLUGIN_TEXT_DOMAIN ) ); ?>
        </div>
    </form>

    <div class="cdf-card">
        <h2><?php esc_html_e( 'System Status', CDF_PLUGIN_TEXT_DOMAIN ); ?></h2>
        <ul class="cdf-status-list">
            <?php foreach ( $checks as $check ) : ?>
                <li>
                    <span class="cdf-status-dot <?php echo $check['ok'] ? 'ok' : 'bad'; ?>"></span>
                    <strong><?php echo esc_html( $check['label'] ); ?></strong>
                    <span class="cdf-muted"><?php echo esc_html( $check['help'] ); ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <div class="cdf-card">
        <h2><?php esc_html_e( 'Maintenance', CDF_PLUGIN_TEXT_DOMAIN ); ?></h2>
        <p class="cdf-muted"><?php esc_html_e( 'Clear cached analytics and regenerated CSS after changing styles or donation records.', CDF_PLUGIN_TEXT_DOMAIN ); ?></p>
        <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cdf_clear_cache' ), 'cdf_clear_cache' ) ); ?>">
            <?php esc_html_e( 'Clear plugin cache', CDF_PLUGIN_TEXT_DOMAIN ); ?>
        </a>
    </div>
    <?php
}

/* =========================================================================
 * ADMIN-POST HANDLERS
 * ========================================================================= */

add_action( 'admin_post_cdf_delete_donation', 'cdf_handle_delete_donation' );
/**
 * Permanently delete one or more donations with all of their meta.
 */
function cdf_handle_delete_donation() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to delete donations.', CDF_PLUGIN_TEXT_DOMAIN ) );
    }
    check_admin_referer( 'cdf_delete_donation' );

    $ids = array();

    // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
    if ( ! empty( $_POST['donation_id'] ) ) {
        // Single row delete wins; ignore any checked boxes in that case.
        $ids[] = absint( wp_unslash( $_POST['donation_id'] ) );
    } elseif ( isset( $_POST['donation_ids'] ) && is_array( $_POST['donation_ids'] ) ) {
        foreach ( wp_unslash( $_POST['donation_ids'] ) as $raw_id ) {
            $ids[] = absint( $raw_id );
        }
    }
    // phpcs:enable WordPress.Security.NonceVerification.Missing

    $ids = array_values( array_unique( array_filter( $ids ) ) );

    if ( empty( $ids ) ) {
        wp_safe_redirect(
            add_query_arg(
                array(
                    'post_type'        => 'donation',
                    'page'             => 'cdf-settings',
                    'cdf_delete_error' => '1',
                ),
                admin_url( 'edit.php' )
            )
        );
        exit;
    }

    $deleted = 0;
    foreach ( $ids as $id ) {
        // Only ever touch donation records.
        if ( 'donation' !== get_post_type( $id ) ) {
            continue;
        }
        /**
         * Fires before a donation is permanently deleted.
         *
         * @param int $id Donation post ID.
         */
        do_action( 'cdf_before_delete_donation', $id );

        // wp_delete_post() also removes every _donation_* meta row.
        if ( wp_delete_post( $id, true ) ) {
            $deleted++;
        }
    }

    delete_transient( 'cdf_analytics_cache' );

    $redirect = add_query_arg(
        array(
            'post_type'   => 'donation',
            'page'        => 'cdf-settings',
            'cdf_deleted' => $deleted,
        ),
        admin_url( 'edit.php' )
    );

    if ( 0 === $deleted ) {
        $redirect = add_query_arg( 'cdf_delete_error', '1', $redirect );
    }

    wp_safe_redirect( $redirect );
    exit;
}

add_action( 'admin_post_cdf_export_csv', 'cdf_handle_export_csv' );
/**
 * Export all donations as CSV.
 */
function cdf_handle_export_csv() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Not allowed.', CDF_PLUGIN_TEXT_DOMAIN ) );
    }
    check_admin_referer( 'cdf_export_csv' );

    $donations = get_posts(
        array(
            'post_type'      => 'donation',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        )
    );

    nocache_headers();
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename=donations-' . gmdate( 'Y-m-d' ) . '.csv' );

    $out = fopen( 'php://output', 'w' );
    fputcsv( $out, array( 'Receipt', 'First Name', 'Last Name', 'Email', 'Phone', 'Amount', 'Currency', 'Gateway', 'Frequency', 'Status', 'Environment', 'Transaction ID', 'Date' ) );

    foreach ( $donations as $donation ) {
        fputcsv(
            $out,
            array(
                get_post_meta( $donation->ID, '_donation_serial_number', true ),
                get_post_meta( $donation->ID, '_donation_first_name', true ),
                get_post_meta( $donation->ID, '_donation_last_name', true ),
                get_post_meta( $donation->ID, '_donation_email', true ),
                get_post_meta( $donation->ID, '_donation_phone', true ),
                get_post_meta( $donation->ID, '_donation_amount', true ),
                get_post_meta( $donation->ID, '_donation_currency', true ),
                get_post_meta( $donation->ID, '_donation_gateway', true ),
                get_post_meta( $donation->ID, '_donation_frequency', true ),
                get_post_meta( $donation->ID, '_donation_status', true ),
                get_post_meta( $donation->ID, '_donation_environment', true ),
                get_post_meta( $donation->ID, '_donation_transaction_id', true ),
                $donation->post_date,
            )
        );
    }

    fclose( $out );
    exit;
}

add_action( 'admin_post_cdf_send_test_email', 'cdf_handle_test_email' );
/**
 * Send a test email.
 */
function cdf_handle_test_email() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Not allowed.', CDF_PLUGIN_TEXT_DOMAIN ) );
    }
    check_admin_referer( 'cdf_test_email' );

    $to = isset( $_POST['cdf_test_recipient'] ) ? sanitize_email( wp_unslash( $_POST['cdf_test_recipient'] ) ) : '';
    $ok = $to ? cdf_send_test_email( $to ) : false;

    wp_safe_redirect( add_query_arg(
        array(
            'post_type'      => 'donation',
            'page'           => 'cdf-settings',
            'tab'            => 'emails',
            'cdf_test_email' => $ok ? 'sent' : 'failed',
        ),
        admin_url( 'edit.php' )
    ) );
    exit;
}

add_action( 'admin_post_cdf_test_connection', 'cdf_handle_test_connection' );
/**
 * Test the configured gateway credentials.
 */
function cdf_handle_test_connection() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Not allowed.', CDF_PLUGIN_TEXT_DOMAIN ) );
    }
    check_admin_referer( 'cdf_test_connection' );

    $keys    = cdf_get_gateway_keys();
    $results = array();

    if ( ! empty( $keys['stripe_secret'] ) ) {
        $response = wp_remote_get(
            'https://api.stripe.com/v1/account',
            array(
                'timeout' => 15,
                'headers' => array( 'Authorization' => 'Bearer ' . $keys['stripe_secret'] ),
            )
        );
        $code = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
        $results[] = sprintf( 'Stripe: %s', 200 === $code ? __( 'connected', CDF_PLUGIN_TEXT_DOMAIN ) : __( 'failed', CDF_PLUGIN_TEXT_DOMAIN ) . ' (HTTP ' . $code . ')' );
    } else {
        $results[] = 'Stripe: ' . __( 'no secret key for this mode', CDF_PLUGIN_TEXT_DOMAIN );
    }

    if ( ! empty( $keys['paypal_client'] ) && ! empty( $keys['paypal_secret'] ) ) {
        $token = cdf_get_paypal_access_token();
        $results[] = sprintf( 'PayPal: %s', $token ? __( 'connected', CDF_PLUGIN_TEXT_DOMAIN ) : __( 'failed', CDF_PLUGIN_TEXT_DOMAIN ) );
    } else {
        $results[] = 'PayPal: ' . __( 'credentials missing', CDF_PLUGIN_TEXT_DOMAIN );
    }

    wp_safe_redirect( add_query_arg(
        array(
            'post_type'            => 'donation',
            'page'                 => 'cdf-settings',
            'tab'                  => 'settings',
            'cdf_test_connection'  => rawurlencode( implode( ' · ', $results ) ),
        ),
        admin_url( 'edit.php' )
    ) );
    exit;
}

add_action( 'admin_post_cdf_clear_cache', 'cdf_handle_clear_cache' );
/**
 * Clear plugin caches.
 */
function cdf_handle_clear_cache() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Not allowed.', CDF_PLUGIN_TEXT_DOMAIN ) );
    }
    check_admin_referer( 'cdf_clear_cache' );

    delete_transient( 'cdf_analytics_cache' );
    delete_transient( 'cdf_dynamic_css' );

    wp_safe_redirect( add_query_arg(
        array(
            'post_type' => 'donation',
            'page'      => 'cdf-settings',
            'tab'       => 'tools',
            'cdf_cache' => 'cleared',
        ),
        admin_url( 'edit.php' )
    ) );
    exit;
}
