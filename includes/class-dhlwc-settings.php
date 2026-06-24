<?php
if (!defined('ABSPATH')) { exit; }

final class DHLWC_Settings {
    public static function defaults() {
        return array(
            'environment' => 'production',
            'customer_number' => '',
            'customer_password' => '',
            'client_id' => '',
            'client_secret' => '',
            'auto_send' => 'yes',
            'prepare_recipient' => 'yes',
            'auto_barcode' => 'no',
            'tracking_enabled' => 'yes',
            'tracking_email_enabled' => 'yes',
            'shipment_service_type' => '1',
            'packaging_type' => '3',
            'payment_type' => '1',
            'delivery_type' => '1',
            'sms_preference_1' => '1',
            'sms_preference_2' => '0',
            'sms_preference_3' => '0',
            'default_desi' => '1',
            'default_kg' => '1',
            'content_text' => 'Ürün',
            'description_text' => 'WooCommerce order',
            'debug_log' => 'no',
            'label_logo_url' => '',
            'label_sender_name' => get_bloginfo('name'),
            'label_sender_address' => '',
            'label_sender_phone' => '',
            'label_note' => 'Bu barkod DHL eCommerce sipariş barkodudur. Şubede okutularak teslim edilmelidir.',
            'label_accent_color' => '#ffcc00',
            'email_subject_prepared' => '{site_name} - Kargonuz hazırlandı',
            'email_body_prepared' => "Merhaba {customer_name},\n\n#{order_number} numaralı siparişiniz kargoya verilmeye hazırlandı.\n\n{tracking_line}\n\n{site_name}",
            'email_subject_shipped' => '{site_name} - Kargonuz teslim alındı',
            'email_body_shipped' => "Merhaba {customer_name},\n\n#{order_number} numaralı siparişiniz DHL eCommerce sistemine aktarıldı.\n\n{tracking_line}\n\n{site_name}",
            'email_subject_branch' => '{site_name} - Kargonuz varış şubesinde',
            'email_body_branch' => "Merhaba {customer_name},\n\n#{order_number} numaralı siparişiniz varış şubesine ulaştı.\n\n{tracking_line}\n\n{site_name}",
            'email_subject_delivery' => '{site_name} - Kargonuz dağıtıma çıktı',
            'email_body_delivery' => "Merhaba {customer_name},\n\n#{order_number} numaralı siparişiniz dağıtıma çıktı.\n\n{tracking_line}\n\n{site_name}",
            'email_subject_delivered' => '{site_name} - Kargonuz teslim edildi',
            'email_body_delivered' => "Merhaba {customer_name},\n\n#{order_number} numaralı siparişiniz teslim edildi. Siparişiniz tamamlandı olarak güncellendi.\n\n{site_name}",
        );
    }

    public static function get() {
        $saved = get_option(DHLWC_Constants::OPTION_KEY, array());
        return wp_parse_args(is_array($saved) ? $saved : array(), self::defaults());
    }

    public static function sanitize($input) {
        $defaults = self::defaults();
        $existing = get_option(DHLWC_Constants::OPTION_KEY, array());
        $existing = is_array($existing) ? $existing : array();
        $input = is_array($input) ? $input : array();
        $clean = array();
        foreach ($defaults as $key => $value) {
            if (isset($input[$key])) {
                $clean[$key] = sanitize_text_field(wp_unslash($input[$key]));
            } elseif (array_key_exists($key, $existing)) {
                $clean[$key] = $existing[$key];
            } else {
                $clean[$key] = $value;
            }
        }
        $clean['environment'] = in_array($clean['environment'], array('test', 'production'), true) ? $clean['environment'] : 'production';
        foreach (array('auto_send', 'prepare_recipient', 'auto_barcode', 'tracking_enabled', 'tracking_email_enabled', 'debug_log') as $checkbox) {
            $clean[$checkbox] = isset($input[$checkbox]) && sanitize_text_field(wp_unslash($input[$checkbox])) === 'yes' ? 'yes' : 'no';
        }
        foreach ($defaults as $key => $value) {
            if (strpos($key, 'email_body_') === 0 && isset($input[$key])) {
                $clean[$key] = sanitize_textarea_field(wp_unslash($input[$key]));
            }
        }
        foreach (array('label_sender_address', 'label_note') as $textarea_key) {
            if (isset($input[$textarea_key])) {
                $clean[$textarea_key] = sanitize_textarea_field(wp_unslash($input[$textarea_key]));
            }
        }
        if (isset($input['label_logo_url'])) { $clean['label_logo_url'] = esc_url_raw(wp_unslash($input['label_logo_url'])); }
        if (isset($input['label_accent_color'])) { $clean['label_accent_color'] = sanitize_hex_color(wp_unslash($input['label_accent_color'])) ?: $defaults['label_accent_color']; }
        return $clean;
    }
}
