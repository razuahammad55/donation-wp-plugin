<?php
/**
 * Donation notification emails.
 *
 * @package CDF_Donation
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'cdf_donation_logged', 'cdf_send_notification_emails', 10, 2 );

/**
 * Email settings with defaults.
 *
 * @return array
 */
function cdf_get_email_settings() {
    $defaults = array(
        'admin_subject' => __( 'New donation received on %site_name%', CDF_PLUGIN_TEXT_DOMAIN ),
        'donor_subject' => __( 'Thank you for your donation, %first_name%!', CDF_PLUGIN_TEXT_DOMAIN ),
        'admin_heading' => __( 'New Donation Received!', CDF_PLUGIN_TEXT_DOMAIN ),
        'donor_heading' => __( 'Thank You for Your Generosity!', CDF_PLUGIN_TEXT_DOMAIN ),
        'donor_intro'   => __( 'Dear %first_name%, we have successfully received your donation. Below is your official receipt.', CDF_PLUGIN_TEXT_DOMAIN ),
        'from_name'     => '',
        'from_email'    => '',
        'reply_to'      => '',
    );
    $saved = get_option( 'cdf_email_settings', array() );
    if ( is_array( $saved ) ) {
        foreach ( $saved as $key => $value ) {
            if ( '' !== $value && null !== $value ) {
                $defaults[ $key ] = $value;
            }
        }
    }
    return $defaults;
}

/**
 * Replace placeholders in a template string.
 *
 * @param string $template Template.
 * @param array  $tags     Tag => value map (without % signs).
 * @return string
 */
function cdf_replace_email_tags( $template, $tags ) {
    $search  = array();
    $replace = array();
    foreach ( $tags as $key => $value ) {
        $search[]  = '%' . $key . '%';
        $replace[] = (string) $value;
    }
    return str_replace( $search, $replace, $template );
}

/**
 * Send admin + donor emails for a logged donation.
 *
 * @param int   $post_id Donation post ID.
 * @param array $data    Donation data (optional).
 */
function cdf_send_notification_emails( $post_id, $data = array() ) {
    if ( 'donation' !== get_post_type( $post_id ) ) {
        return;
    }

    if ( empty( $data ) ) {
        $data = array(
            'first_name'     => get_post_meta( $post_id, '_donation_first_name', true ),
            'last_name'      => get_post_meta( $post_id, '_donation_last_name', true ),
            'email'          => get_post_meta( $post_id, '_donation_email', true ),
            'phone'          => get_post_meta( $post_id, '_donation_phone', true ),
            'amount'         => get_post_meta( $post_id, '_donation_amount', true ),
            'currency'       => get_post_meta( $post_id, '_donation_currency', true ),
            'gateway'        => get_post_meta( $post_id, '_donation_gateway', true ),
            'frequency'      => get_post_meta( $post_id, '_donation_frequency', true ),
            'environment'    => get_post_meta( $post_id, '_donation_environment', true ),
            'transaction_id' => get_post_meta( $post_id, '_donation_transaction_id', true ),
        );
    }

    $serial     = get_post_meta( $post_id, '_donation_serial_number', true );
    $site_name  = get_bloginfo( 'name' );
    $admin_mail = get_option( 'admin_email' );
    $currency   = ! empty( $data['currency'] ) ? $data['currency'] : cdf_get_currency();
    $formatted  = cdf_get_currency_symbol() . number_format( floatval( $data['amount'] ), 2 ) . ' ' . $currency;
    $settings   = cdf_get_email_settings();

    $tags = array(
        'site_name'      => $site_name,
        'first_name'     => $data['first_name'] ? $data['first_name'] : __( 'Donor', CDF_PLUGIN_TEXT_DOMAIN ),
        'last_name'      => $data['last_name'],
        'full_name'      => trim( $data['first_name'] . ' ' . $data['last_name'] ),
        'email'          => $data['email'],
        'amount'         => $formatted,
        'gateway'        => $data['gateway'],
        'frequency'      => $data['frequency'],
        'serial'         => '#' . ( $serial ? $serial : $post_id ),
        'transaction_id' => $data['transaction_id'],
        'site_url'       => home_url( '/' ),
    );

    $from_name  = $settings['from_name'] ? $settings['from_name'] : $site_name;
    $from_email = ( ! empty( $settings['from_email'] ) && is_email( $settings['from_email'] ) ) ? $settings['from_email'] : $admin_mail;
    $reply_to   = ( ! empty( $settings['reply_to'] ) && is_email( $settings['reply_to'] ) ) ? $settings['reply_to'] : $admin_mail;

    /* ---------------- Admin notification ---------------- */
    if ( '1' === get_option( 'cdf_admin_email_enabled', '1' ) ) {
        $recipients_raw = get_option( 'cdf_admin_email_recipients', $admin_mail );
        $recipients     = array_filter( array_map( 'sanitize_email', array_map( 'trim', explode( ',', (string) $recipients_raw ) ) ), 'is_email' );

        $subject = cdf_replace_email_tags( $settings['admin_subject'], $tags );

        $details = array(
            __( 'Serial Number', CDF_PLUGIN_TEXT_DOMAIN )          => $tags['serial'],
            __( 'Donor Name', CDF_PLUGIN_TEXT_DOMAIN )             => $tags['full_name'],
            __( 'Email Address', CDF_PLUGIN_TEXT_DOMAIN )          => $data['email'],
            __( 'Phone', CDF_PLUGIN_TEXT_DOMAIN )                  => ! empty( $data['phone'] ) ? $data['phone'] : '—',
            __( 'Donation Amount', CDF_PLUGIN_TEXT_DOMAIN )        => $formatted,
            __( 'Payment Gateway', CDF_PLUGIN_TEXT_DOMAIN )        => $data['gateway'],
            __( 'Frequency', CDF_PLUGIN_TEXT_DOMAIN )              => $data['frequency'],
            __( 'Environment', CDF_PLUGIN_TEXT_DOMAIN )            => $data['environment'],
            __( 'Transaction / Order ID', CDF_PLUGIN_TEXT_DOMAIN ) => $data['transaction_id'],
        );

        $message = cdf_get_email_template(
            cdf_replace_email_tags( $settings['admin_heading'], $tags ),
            __( 'A new donation has been successfully processed and logged into your site.', CDF_PLUGIN_TEXT_DOMAIN ),
            $details,
            array(
                'url'  => admin_url( 'edit.php?post_type=donation&page=cdf-settings#donations' ),
                'text' => __( 'View in Donations Dashboard', CDF_PLUGIN_TEXT_DOMAIN ),
            )
        );

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            sprintf( 'From: %s <%s>', $from_name, $from_email ),
            sprintf( 'Reply-To: %s', $reply_to ),
        );

        foreach ( $recipients as $recipient ) {
            wp_mail( $recipient, $subject, $message, $headers );
        }
    }

    /* ---------------- Donor receipt ---------------- */
    if ( '1' === get_option( 'cdf_donor_email_enabled', '1' ) && is_email( $data['email'] ) ) {
        $subject = cdf_replace_email_tags( $settings['donor_subject'], $tags );

        $donor_details = array(
            __( 'Receipt / Serial #', CDF_PLUGIN_TEXT_DOMAIN ) => $tags['serial'],
            __( 'Donor Name', CDF_PLUGIN_TEXT_DOMAIN )         => $tags['full_name'] ? $tags['full_name'] : __( 'Valued Donor', CDF_PLUGIN_TEXT_DOMAIN ),
            __( 'Donation Amount', CDF_PLUGIN_TEXT_DOMAIN )    => $formatted,
            __( 'Frequency', CDF_PLUGIN_TEXT_DOMAIN )          => $data['frequency'],
            __( 'Payment Method', CDF_PLUGIN_TEXT_DOMAIN )     => $data['gateway'],
            __( 'Transaction ID', CDF_PLUGIN_TEXT_DOMAIN )     => $data['transaction_id'],
            __( 'Date', CDF_PLUGIN_TEXT_DOMAIN )               => date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
        );

        $message = cdf_get_email_template(
            cdf_replace_email_tags( $settings['donor_heading'], $tags ),
            cdf_replace_email_tags( $settings['donor_intro'], $tags ),
            $donor_details,
            array(
                'url'  => home_url( '/' ),
                'text' => __( 'Visit Our Website', CDF_PLUGIN_TEXT_DOMAIN ),
            ),
            sprintf(
                /* translators: %s: site name */
                __( 'If you have any questions, please feel free to reply to this email. — %s', CDF_PLUGIN_TEXT_DOMAIN ),
                $site_name
            )
        );

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            sprintf( 'From: %s <%s>', $from_name, $from_email ),
            sprintf( 'Reply-To: %s', $reply_to ),
        );

        wp_mail( $data['email'], $subject, $message, $headers );
    }
}

/**
 * Build a branded HTML email body.
 *
 * @param string $heading Heading.
 * @param string $intro   Intro paragraph.
 * @param array  $rows    Label => value rows.
 * @param array  $button  url/text.
 * @param string $footer  Footer text.
 * @return string
 */
function cdf_get_email_template( $heading, $intro, $rows, $button = array(), $footer = '' ) {
    $rows_html = '';
    foreach ( $rows as $label => $value ) {
        $rows_html .= '<tr>'
            . '<td style="padding:10px;border-bottom:1px solid #e2e8f0;font-weight:bold;color:#475569;width:45%;">' . esc_html( $label ) . ':</td>'
            . '<td style="padding:10px;border-bottom:1px solid #e2e8f0;color:#0f172a;">' . esc_html( $value ? $value : '—' ) . '</td>'
            . '</tr>';
    }

    $button_html = '';
    if ( ! empty( $button['url'] ) ) {
        $button_html = '<div style="margin-top:24px;text-align:center;">'
            . '<a href="' . esc_url( $button['url'] ) . '" style="background-color:#2563eb;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:6px;font-weight:bold;display:inline-block;">'
            . esc_html( isset( $button['text'] ) ? $button['text'] : __( 'Open', CDF_PLUGIN_TEXT_DOMAIN ) )
            . '</a></div>';
    }

    $footer_html = '';
    if ( $footer ) {
        $footer_html = '<div style="margin-top:28px;padding-top:20px;border-top:1px solid #e2e8f0;text-align:center;color:#94a3b8;font-size:13px;">'
            . '<p style="margin:0;">' . esc_html( $footer ) . '</p></div>';
    }

    return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>'
        . '<body style="font-family:Arial,sans-serif;background-color:#f4f6f9;margin:0;padding:20px;">'
        . '<div style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:8px;border:1px solid #e2e8f0;padding:26px;">'
        . '<h2 style="color:#0f172a;margin-top:0;font-size:22px;">' . esc_html( $heading ) . '</h2>'
        . '<p style="color:#64748b;font-size:15px;margin-bottom:18px;">' . esc_html( $intro ) . '</p>'
        . '<table style="width:100%;border-collapse:collapse;">' . $rows_html . '</table>'
        . $button_html . $footer_html
        . '</div></body></html>';
}

/**
 * Send a test email from the settings screen.
 *
 * @param string $to Recipient.
 * @return bool
 */
function cdf_send_test_email( $to ) {
    if ( ! is_email( $to ) ) {
        return false;
    }
    $settings   = cdf_get_email_settings();
    $from_name  = $settings['from_name'] ? $settings['from_name'] : get_bloginfo( 'name' );
    $from_email = ( ! empty( $settings['from_email'] ) && is_email( $settings['from_email'] ) ) ? $settings['from_email'] : get_option( 'admin_email' );

    $message = cdf_get_email_template(
        __( 'Test Email From Your Donation Plugin', CDF_PLUGIN_TEXT_DOMAIN ),
        __( 'This is a test message to confirm that donation notification emails can be delivered from your site.', CDF_PLUGIN_TEXT_DOMAIN ),
        array(
            __( 'Site', CDF_PLUGIN_TEXT_DOMAIN )   => get_bloginfo( 'name' ),
            __( 'Plugin', CDF_PLUGIN_TEXT_DOMAIN ) => CDF_PLUGIN_NAME,
            __( 'Version', CDF_PLUGIN_TEXT_DOMAIN ) => CDF_PLUGIN_VERSION,
        )
    );

    $headers = array(
        'Content-Type: text/html; charset=UTF-8',
        sprintf( 'From: %s <%s>', $from_name, $from_email ),
    );

    return wp_mail( $to, __( 'Donation plugin test email', CDF_PLUGIN_TEXT_DOMAIN ), $message, $headers );
}
