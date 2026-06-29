<?php
if (!defined('ABSPATH')) { exit; }

final class DHLWC_Payload_Builder {
    private $settings;

    public function __construct(array $settings = null) {
        $this->settings = $settings ?: DHLWC_Settings::get();
    }

    public function reference_id(WC_Order $order) {
        return substr(strtoupper(preg_replace('/[^A-Z0-9]/', '', remove_accents('WC' . $order->get_order_number()))), 0, 20);
    }

    public function piece_barcode(WC_Order $order, $reference_id) {
        $stored = $order->get_meta(DHLWC_Constants::META_PIECE_BARCODE);
        if ($stored) { return $stored; }
        return substr($reference_id . '_P1', 0, 30);
    }

    public function create_recipient(WC_Order $order) {
        $location = $this->resolve_city_district($order);
        return array('recipient' => array(
            'customerId' => '',
            'refCustomerId' => '',
            'cityCode' => 0,
            'cityName' => $location['city'],
            'districtCode' => 0,
            'districtName' => $location['district'],
            'address' => $this->limit($order->get_shipping_address_1() ?: $order->get_billing_address_1(), 200),
            'bussinessPhoneNumber' => '',
            'email' => $order->get_billing_email(),
            'taxOffice' => '',
            'taxNumber' => '',
            'fullName' => $this->limit(trim(($order->get_shipping_first_name() ?: $order->get_billing_first_name()) . ' ' . ($order->get_shipping_last_name() ?: $order->get_billing_last_name())), 150),
            'homePhoneNumber' => '',
            'mobilePhoneNumber' => $this->phone($order->get_billing_phone()),
        ));
    }

    public function create_order(WC_Order $order) {
        $reference = $this->reference_id($order);
        $piece_barcode = $this->piece_barcode($order, $reference);
        $content = $this->content($order);
        $location = $this->resolve_city_district($order);
        return array(
            'order' => array(
                'referenceId' => $reference,
                'barcode' => $reference,
                'billOfLandingId' => 'WC-' . $order->get_order_number(),
                'isCOD' => $this->is_cod($order) ? 1 : 0,
                'codAmount' => $this->is_cod($order) ? (float) $order->get_total() : 0,
                'shipmentServiceType' => (int) $this->settings['shipment_service_type'],
                'packagingType' => (int) $this->settings['packaging_type'],
                'content' => $this->limit($content, 200),
                'smsPreference1' => (int) $this->settings['sms_preference_1'],
                'smsPreference2' => (int) $this->settings['sms_preference_2'],
                'smsPreference3' => (int) $this->settings['sms_preference_3'],
                'paymentType' => (int) $this->settings['payment_type'],
                'deliveryType' => (int) $this->settings['delivery_type'],
                'description' => $this->limit($this->settings['description_text'] . ' #' . $order->get_order_number(), 150),
                'marketPlaceShortCode' => '',
                'marketPlaceSaleCode' => '',
                'pudoId' => '',
            ),
            'orderPieceList' => array(array(
                'barcode' => $piece_barcode,
                'desi' => max(1, (int) $this->settings['default_desi']),
                'kg' => max(1, (int) $this->settings['default_kg']),
                'content' => $this->limit($content, 150),
            )),
            'recipient' => array(
                'customerId' => '',
                'refCustomerId' => '',
                'cityCode' => 0,
                'cityName' => $location['city'],
                'districtCode' => 0,
                'districtName' => $location['district'],
                'address' => $this->limit($order->get_shipping_address_1() ?: $order->get_billing_address_1(), 200),
                'bussinessPhoneNumber' => '',
                'email' => $order->get_billing_email(),
                'taxOffice' => '',
                'taxNumber' => '',
                'fullName' => $this->limit(trim(($order->get_shipping_first_name() ?: $order->get_billing_first_name()) . ' ' . ($order->get_shipping_last_name() ?: $order->get_billing_last_name())), 150),
                'homePhoneNumber' => '',
                'mobilePhoneNumber' => $this->phone($order->get_billing_phone()),
            ),
        );
    }

    public function create_barcode(WC_Order $order, $reference_id) {
        return array(
            'referenceId' => $reference_id,
            'billOfLandingId' => 'WC-' . $order->get_order_number(),
            'isCOD' => $this->is_cod($order) ? 1 : 0,
            'codAmount' => $this->is_cod($order) ? (float) $order->get_total() : 0,
            'packagingType' => (int) $this->settings['packaging_type'],
            'printReferenceBarcodeOnError' => 1,
            'message' => 'WooCommerce',
            'additionalContent1' => '',
            'additionalContent2' => '',
            'additionalContent3' => '',
            'additionalContent4' => '',
            'orderPieceList' => array(array(
                'barcode' => $this->piece_barcode($order, $reference_id),
                'desi' => max(1, (int) $this->settings['default_desi']),
                'kg' => max(1, (int) $this->settings['default_kg']),
                'content' => $this->content($order),
            )),
        );
    }

    private function resolve_city_district(WC_Order $order) {
        $city = $order->get_shipping_city() ?: $order->get_billing_city();
        $district = $order->get_shipping_state() ?: $order->get_billing_state();
        if (preg_match('/^TR(\d{2})$/i', (string) $district)) {
            $district = $city;
            $city = $this->city_from_tr_code($order->get_shipping_state() ?: $order->get_billing_state());
        }
        return array('city' => $this->upper($city), 'district' => $this->upper($district));
    }

    private function city_from_tr_code($code) {
        $map = array('TR07'=>'ANTALYA','TR34'=>'İSTANBUL','TR06'=>'ANKARA','TR35'=>'İZMİR','TR42'=>'KONYA','TR51'=>'NİĞDE');
        return isset($map[strtoupper((string) $code)]) ? $map[strtoupper((string) $code)] : '';
    }

    private function content(WC_Order $order) {
        $text = trim((string) $this->settings['content_text']);
        if ((int) $order->get_item_count() > 1) { $text .= ' - ' . (int) $order->get_item_count() . ' adet'; }
        return $this->limit($text, 150);
    }

    private function phone($phone) {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if (strlen($digits) > 10 && substr($digits, 0, 2) === '90') { $digits = substr($digits, 2); }
        if (strlen($digits) > 10 && substr($digits, 0, 1) === '0') { $digits = substr($digits, 1); }
        return substr($digits, -10);
    }

    private function upper($value) {
        $value = trim((string) $value);
        return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
    }

    private function is_cod(WC_Order $order) { return in_array($order->get_payment_method(), array('cod', 'kapida-odeme'), true); }
    private function limit($text, $limit) { $text = trim(wp_strip_all_tags((string) $text)); return function_exists('mb_substr') ? mb_substr($text, 0, $limit, 'UTF-8') : substr($text, 0, $limit); }
}
