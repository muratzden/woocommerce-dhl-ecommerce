<?php
if (!defined('ABSPATH')) { exit; }

final class DHLWC_Orders {
    private $settings;
    private $api;
    private $builder;

    public function __construct() {
        $this->settings = DHLWC_Settings::get();
        $this->api = new DHLWC_API_Client($this->settings);
        $this->builder = new DHLWC_Payload_Builder($this->settings);
    }

    public function register_hooks() {
        add_action('init', array($this, 'register_order_statuses'));
        add_filter('wc_order_statuses', array($this, 'add_order_statuses'));
        add_action('woocommerce_order_status_processing', array($this, 'maybe_auto_send_order'), 20, 1);
        add_action('add_meta_boxes', array($this, 'add_order_metabox'));
        add_action('admin_post_dhlwc_send_order', array($this, 'handle_send_order_post'));
        add_action('admin_post_dhlwc_create_barcode', array($this, 'handle_create_barcode_post'));
        add_action('admin_post_dhlwc_check_tracking', array($this, 'handle_check_tracking_post'));
        add_action(DHLWC_Constants::CRON_HOOK, array($this, 'sync_tracking_cron'));
        add_action(DHLWC_Constants::BARCODE_RETRY_HOOK, array($this, 'retry_create_barcode'), 10, 1);
        add_filter('woocommerce_email_order_meta_fields', array($this, 'email_order_meta_fields'), 10, 3);
    }

    public function register_order_statuses() {
        foreach (array('wc-dhl-ready'=>'Kargoya verilmeye hazır','wc-dhl-shipped'=>'Kargoya teslim edildi','wc-dhl-branch'=>'Varış şubesinde','wc-dhl-delivery'=>'Dağıtımda') as $key => $label) {
            register_post_status($key, array(
                'label' => $label,
                'public' => true,
                'exclude_from_search' => false,
                'show_in_admin_all_list' => true,
                'show_in_admin_status_list' => true,
                /* translators: %s: Number of orders with this DHL order status. */
                'label_count' => _n_noop(
                    'DHL order status <span class="count">(%s)</span>',
                    'DHL order statuses <span class="count">(%s)</span>',
                    'dhl-ecommerce-for-woocommerce'
                ),
            ));
        }
    }

    public function add_order_statuses($statuses) {
        $new = array();
        foreach ($statuses as $key => $label) {
            $new[$key] = $label;
            if ($key === 'wc-processing') {
                $new['wc-dhl-ready'] = 'Kargoya verilmeye hazır';
                $new['wc-dhl-shipped'] = 'Kargoya teslim edildi';
                $new['wc-dhl-branch'] = 'Varış şubesinde';
                $new['wc-dhl-delivery'] = 'Dağıtımda';
            }
        }
        return $new;
    }

    public function maybe_auto_send_order($order_id) {
        if ($this->settings['auto_send'] !== 'yes') { return; }
        $order = wc_get_order($order_id);
        if ($order instanceof WC_Order) { $this->send_order($order, false); }
    }

    public function send_order(WC_Order $order, $force = false) {
        if (!$force && $order->get_meta(DHLWC_Constants::META_SENT)) { return true; }

        if ($this->settings['prepare_recipient'] === 'yes' && !$order->get_meta(DHLWC_Constants::META_RECIPIENT_CREATED)) {
            $recipient = $this->api->request('POST', '/pluscmdapi/createRecipient', $this->builder->create_recipient($order));
            if (!is_wp_error($recipient)) {
                $order->update_meta_data(DHLWC_Constants::META_RECIPIENT_CREATED, 'yes');
            }
        }

        $payload = $this->builder->create_order($order);
        $reference = $payload['order']['referenceId'];
        $piece_barcode = $payload['orderPieceList'][0]['barcode'];
        $response = $this->api->request('POST', '/standardcmdapi/createOrder', $payload);
        if (is_wp_error($response)) {
            $order->update_meta_data(DHLWC_Constants::META_ERROR, wp_json_encode($response->get_error_data(), JSON_UNESCAPED_UNICODE));
            $order->save();
            return $response;
        }

        $order->update_meta_data(DHLWC_Constants::META_SENT, 'yes');
        $order->update_meta_data(DHLWC_Constants::META_REFERENCE_ID, $reference);
        $order->update_meta_data(DHLWC_Constants::META_PIECE_BARCODE, $piece_barcode);
        $order->update_meta_data(DHLWC_Constants::META_RESPONSE, wp_json_encode($response, JSON_UNESCAPED_UNICODE));
        $order->update_meta_data(DHLWC_Constants::META_TRACKING_ACTIVE, 'yes');
        $order->update_meta_data(DHLWC_Constants::META_ORDER_CREATED_AT, time());
        $order->update_status('dhl-ready', 'DHL eCommerce siparişi oluşturuldu.');
        $order->save();

        if ($this->settings['tracking_email_enabled'] === 'yes') {
            (new DHLWC_Email($this->settings))->send_stage($order, 'prepared');
        }
        if ($this->settings['auto_barcode'] === 'yes') {
            $this->schedule_barcode_retry($order->get_id(), 600);
        }
        return $response;
    }

    public function create_barcode(WC_Order $order, $retry_on_20011 = true) {
        $reference = $order->get_meta(DHLWC_Constants::META_REFERENCE_ID);
        if (!$reference) {
            $created = $this->send_order($order, true);
            if (is_wp_error($created)) { return $created; }
            $reference = $order->get_meta(DHLWC_Constants::META_REFERENCE_ID);
        }

        $payload = $this->builder->create_barcode($order, $reference);
        $response = $this->api->request('POST', '/barcodecmdapi/createbarcode', $payload);
        if (is_wp_error($response)) {
            $order->update_meta_data(DHLWC_Constants::META_ERROR, wp_json_encode($response->get_error_data(), JSON_UNESCAPED_UNICODE));
            $attempts = (int) $order->get_meta(DHLWC_Constants::META_BARCODE_ATTEMPTS) + 1;
            $order->update_meta_data(DHLWC_Constants::META_BARCODE_ATTEMPTS, $attempts);
            $order->save();
            if ($retry_on_20011 && $attempts < 5 && $this->is_api_error_code($response, '20011')) {
                $this->schedule_barcode_retry($order->get_id(), 300 * $attempts);
            }
            return $response;
        }

        $first = isset($response[0]) && is_array($response[0]) ? $response[0] : $response;
        $barcode_info = $this->extract_barcode_info($first);
        $has_shipment = !empty($first['shipmentId']);
        $barcode_type = $has_shipment ? 'shipment' : 'reference';

        $order->update_meta_data(DHLWC_Constants::META_BARCODE_CREATED, 'yes');
        $order->update_meta_data(DHLWC_Constants::META_BARCODE_TYPE, $barcode_type);
        $order->update_meta_data(DHLWC_Constants::META_BARCODE_RESPONSE, wp_json_encode($response, JSON_UNESCAPED_UNICODE));
        if (!empty($barcode_info['zpl'])) { $order->update_meta_data(DHLWC_Constants::META_BARCODE_ZPL, $barcode_info['zpl']); }
        if (!empty($barcode_info['barcode'])) { $order->update_meta_data(DHLWC_Constants::META_BARCODE_VALUE, $barcode_info['barcode']); }
        if (!empty($first['shipmentId'])) { $order->update_meta_data(DHLWC_Constants::META_SHIPMENT_ID, $first['shipmentId']); }
        if (!empty($first['invoiceId'])) { $order->update_meta_data(DHLWC_Constants::META_INVOICE_ID, $first['invoiceId']); }

        if ($has_shipment) {
            $order->update_status('dhl-shipped', 'DHL gönderi barkodu oluşturuldu.');
            if ($this->settings['tracking_email_enabled'] === 'yes') {
                (new DHLWC_Email($this->settings))->send_stage($order, 'shipped');
            }
        } else {
            $order->add_order_note('DHL referans/sipariş barkodu oluşturuldu. Gönderi barkodu henüz oluşmadı; etiket şubede okutularak işleme alınabilir.');
        }

        $order->save();
        return $response;
    }

    public function retry_create_barcode($order_id) {
        $order = wc_get_order($order_id);
        if ($order instanceof WC_Order && !$order->get_meta(DHLWC_Constants::META_BARCODE_CREATED)) {
            $this->create_barcode($order, true);
        }
    }

    private function schedule_barcode_retry($order_id, $delay) {
        if (!wp_next_scheduled(DHLWC_Constants::BARCODE_RETRY_HOOK, array($order_id))) {
            wp_schedule_single_event(time() + max(60, (int) $delay), DHLWC_Constants::BARCODE_RETRY_HOOK, array($order_id));
        }
    }

    private function is_api_error_code($error, $code) {
        if (!is_wp_error($error)) { return false; }
        $data = $error->get_error_data();
        return isset($data['body']['error']['Code']) && (string) $data['body']['error']['Code'] === (string) $code;
    }

    private function extract_barcode_info($response) {
        $barcodes = isset($response['barcodes']) && is_array($response['barcodes']) ? $response['barcodes'] : array();
        $first = isset($barcodes[0]) && is_array($barcodes[0]) ? $barcodes[0] : array();
        return array(
            'zpl' => isset($first['value']) ? (string) $first['value'] : '',
            'barcode' => isset($first['barcode']) ? (string) $first['barcode'] : '',
        );
    }

    public function sync_tracking_cron() {
        if ($this->settings['tracking_enabled'] !== 'yes') { return; }
        $orders = wc_get_orders(array('limit'=>20,'status'=>array('dhl-ready','dhl-shipped','dhl-branch','dhl-delivery'),'meta_key'=>DHLWC_Constants::META_TRACKING_ACTIVE,'meta_value'=>'yes'));
        foreach ($orders as $order) { $this->sync_order_tracking($order); }
    }

    public function sync_order_tracking(WC_Order $order) {
        $reference = $order->get_meta(DHLWC_Constants::META_REFERENCE_ID);
        if (!$reference) { return false; }
        $response = $this->api->request('GET', '/standardqueryapi/getshipmentstatus/' . rawurlencode($reference));
        if (is_wp_error($response)) { return $response; }
        $order->update_meta_data(DHLWC_Constants::META_STATUS_RESPONSE, wp_json_encode($response, JSON_UNESCAPED_UNICODE));
        if (!empty($response['trackingUrl'])) { $order->update_meta_data(DHLWC_Constants::META_TRACKING_URL, $response['trackingUrl']); }
        if (!empty($response['shipmentId'])) { $order->update_meta_data(DHLWC_Constants::META_SHIPMENT_ID, $response['shipmentId']); }
        $stage = $this->map_stage($response);
        if ($stage) { $this->apply_stage($order, $stage, $response); }
        $order->save();
        return $response;
    }

    private function map_stage($response) {
        $text = strtoupper(wp_json_encode($response, JSON_UNESCAPED_UNICODE));
        if (!empty($response['isDelivered']) || strpos($text, 'TESLIM') !== false || strpos($text, 'DELIVERED') !== false) { return 'delivered'; }
        if (strpos($text, 'DAGIT') !== false || strpos($text, 'DAĞIT') !== false) { return 'delivery'; }
        if (strpos($text, 'VARI') !== false || strpos($text, 'ŞUBE') !== false || strpos($text, 'SUBE') !== false) { return 'branch'; }
        if (strpos($text, 'KARGO') !== false || strpos($text, 'SHIPMENT') !== false) { return 'shipped'; }
        return '';
    }

    private function apply_stage(WC_Order $order, $stage, $response) {
        if ($order->get_meta(DHLWC_Constants::META_LAST_STAGE) === $stage) { return; }
        $order->update_meta_data(DHLWC_Constants::META_LAST_STAGE, $stage);
        if ($stage === 'shipped') { $order->update_status('dhl-shipped'); }
        if ($stage === 'branch') { $order->update_status('dhl-branch'); }
        if ($stage === 'delivery') { $order->update_status('dhl-delivery'); }
        if ($stage === 'delivered') { $order->update_status('completed'); }
        (new DHLWC_Email($this->settings))->send_stage($order, $stage, array('tracking_url'=>$order->get_meta(DHLWC_Constants::META_TRACKING_URL), 'message'=>wp_json_encode($response, JSON_UNESCAPED_UNICODE)));
    }

    public function add_order_metabox() {
        add_meta_box('dhlwc_order_box', 'DHL eCommerce', array($this, 'render_order_metabox'), 'shop_order', 'side', 'default');
        add_meta_box('dhlwc_order_box_hpos', 'DHL eCommerce', array($this, 'render_order_metabox'), 'woocommerce_page_wc-orders', 'side', 'default');
    }

    public function render_order_metabox($post_or_order) {
        $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order($post_or_order->ID);
        if (!$order) { return; }
        $barcode_type = $order->get_meta(DHLWC_Constants::META_BARCODE_TYPE);
        $barcode_zpl = $order->get_meta(DHLWC_Constants::META_BARCODE_ZPL);
        $barcode_value = $order->get_meta(DHLWC_Constants::META_BARCODE_VALUE);
        echo '<p><strong>Referans:</strong> ' . esc_html($order->get_meta(DHLWC_Constants::META_REFERENCE_ID) ?: '-') . '</p>';
        echo '<p><strong>Parça Barkodu:</strong> ' . esc_html($order->get_meta(DHLWC_Constants::META_PIECE_BARCODE) ?: '-') . '</p>';
        echo '<p><strong>Gönderi No:</strong> ' . esc_html($order->get_meta(DHLWC_Constants::META_SHIPMENT_ID) ?: '-') . '</p>';
        if ($barcode_type) {
            $label = $barcode_type === 'shipment' ? 'Gönderi barkodu' : 'Referans / Sipariş barkodu';
            echo '<p><strong>Barkod Tipi:</strong> ' . esc_html($label) . '</p>';
        }
        if ($barcode_value) { echo '<p><strong>DHL Barkod:</strong> <code>' . esc_html($barcode_value) . '</code></p>'; }
        if ($barcode_zpl) { echo '<p><strong>ZPL Etiket:</strong> Hazır</p>'; }
        echo '<div class="dhlwc-order-actions" style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:12px;">';
        echo wp_kses_post($this->order_button($order, 'dhlwc_send_order', 'Gönderi Oluştur', 'button-primary dhlwc-action-button'));
        echo wp_kses_post($this->order_button($order, 'dhlwc_create_barcode', 'Barkod Oluştur', 'dhlwc-action-button'));
        echo wp_kses_post(DHLWC_Label::print_button($order));
        echo $barcode_zpl ? wp_kses_post(DHLWC_Label::zpl_download_button($order)) : '<span></span>';
        echo '</div>';
        echo '<p style="margin-top:8px;">' . wp_kses_post($this->order_button($order, 'dhlwc_check_tracking', 'Kargo Durumunu Kontrol Et', 'dhlwc-action-button dhlwc-full-button')) . '</p>';
        echo '<style>#dhlwc_order_box .dhlwc-order-actions,#dhlwc_order_box_hpos .dhlwc-order-actions{display:grid!important;grid-template-columns:1fr 1fr!important;gap:8px!important;margin-top:12px!important;} #dhlwc_order_box .dhlwc-action-button,#dhlwc_order_box_hpos .dhlwc-action-button{width:100%;text-align:center;box-sizing:border-box;min-height:32px;line-height:30px;padding:0 6px;} #dhlwc_order_box .dhlwc-full-button,#dhlwc_order_box_hpos .dhlwc-full-button{display:block;width:100%;}</style>';
    }

    private function order_button(WC_Order $order, $action, $label, $class = '') {
        $url = wp_nonce_url(add_query_arg(array('action'=>$action,'order_id'=>$order->get_id()), admin_url('admin-post.php')), $action . '_' . $order->get_id());
        return '<a class="button ' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }

    public function handle_send_order_post() { $order = $this->get_admin_post_order('dhlwc_send_order'); $result = $this->send_order($order, true); $this->redirect_to_order($order, is_wp_error($result) ? 'error' : 'sent'); }
    public function handle_create_barcode_post() { $order = $this->get_admin_post_order('dhlwc_create_barcode'); $result = $this->create_barcode($order, true); $this->redirect_to_order($order, is_wp_error($result) ? 'barcode_error' : 'barcode'); }
    public function handle_check_tracking_post() { $order = $this->get_admin_post_order('dhlwc_check_tracking'); $result = $this->sync_order_tracking($order); $this->redirect_to_order($order, is_wp_error($result) ? 'tracking_error' : 'tracking'); }

    private function get_admin_post_order($action) {
        if (!current_user_can('manage_woocommerce')) { wp_die('Yetkisiz işlem.'); }
        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        if (!$order_id || !check_admin_referer($action . '_' . $order_id)) { wp_die('Güvenlik doğrulaması başarısız.'); }
        $order = wc_get_order($order_id);
        if (!$order) { wp_die('Sipariş bulunamadı.'); }
        return $order;
    }

    private function redirect_to_order(WC_Order $order, $status) { wp_safe_redirect(add_query_arg('dhlwc_status', sanitize_key($status), $order->get_edit_order_url())); exit; }

    public function email_order_meta_fields($fields, $sent_to_admin, $order) {
        if ($order instanceof WC_Order && $order->get_meta(DHLWC_Constants::META_REFERENCE_ID)) {
            $fields['dhlwc_reference'] = array('label'=>'DHL Referans No','value'=>$order->get_meta(DHLWC_Constants::META_REFERENCE_ID));
            if ($order->get_meta(DHLWC_Constants::META_SHIPMENT_ID)) { $fields['dhlwc_shipment'] = array('label'=>'DHL Gönderi No','value'=>$order->get_meta(DHLWC_Constants::META_SHIPMENT_ID)); }
            if ($order->get_meta(DHLWC_Constants::META_BARCODE_TYPE)) { $fields['dhlwc_barcode_type'] = array('label'=>'DHL Barkod Tipi','value'=>$order->get_meta(DHLWC_Constants::META_BARCODE_TYPE) === 'shipment' ? 'Gönderi barkodu' : 'Referans / Sipariş barkodu'); }
        }
        return $fields;
    }
}
