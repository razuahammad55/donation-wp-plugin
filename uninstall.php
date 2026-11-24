<?php
/**
 * Uninstall handler.
 *
 * Only removes data when "Delete all data on uninstall" is enabled in
 * Donations → Tools. Donations are always preserved unless that option is on.
 *
 * @package CDF_Donation
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$cdf_advanced = get_option( 'cdf_advanced_settings', array() );
$cdf_purge    = isset( $cdf_advanced['delete_data_on_uninstall'] ) && '1' === $cdf_advanced['delete_data_on_uninstall'];

// Always clear transients and scheduled events.
$cdf_transients = array(
    'cdf_dynamic_css',
    'cdf_analytics_cache',
    'cdf_paypal_token_sandbox',
    'cdf_paypal_token_live',
    'cdf_activation_redirect',
);
foreach ( $cdf_transients as $cdf_transient ) {
    delete_transient( $cdf_transient );
}

wp_clear_scheduled_hook( 'cdf_cleanup_pending_donations' );

if ( ! $cdf_purge ) {
    return;
}

global $wpdb;

// Remove donation records.
$cdf_donation_ids = get_posts(
    array(
        'post_type'      => 'donation',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    )
);
foreach ( $cdf_donation_ids as $cdf_id ) {
    wp_delete_post( $cdf_id, true );
}

// Remove generated pages.
foreach ( array( 'donation-success', 'donation-canceled' ) as $cdf_slug ) {
    $cdf_page = get_page_by_path( $cdf_slug );
    if ( $cdf_page ) {
        wp_delete_post( $cdf_page->ID, true );
    }
}

// Remove options.
$cdf_options = array(
    'cdf_api_settings',
    'cdf_environment',
    'cdf_general_settings',
    'cdf_style_options',
    'cdf_email_settings',
    'cdf_advanced_settings',
    'cdf_admin_email_enabled',
    'cdf_donor_email_enabled',
    'cdf_admin_email_recipients',
    'cdf_nonce_bypass',
    'cdf_serial_count',
    'cdf_activation_redirect_done',
);
foreach ( $cdf_options as $cdf_option ) {
    delete_option( $cdf_option );
}

// Remove leftover donation meta (safety net if posts were orphaned).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
        $wpdb->esc_like( '_donation_' ) . '%'
    )
);
