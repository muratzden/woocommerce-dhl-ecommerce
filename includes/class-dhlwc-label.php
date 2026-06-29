<?php
/**
 * Standalone shipping label renderer and ZPL download handler.
 *
 * @package ShipPilot_For_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

final class DHLWC_Label {
    public function register_hooks() {
        add_action('admin_post_dhlwc_print_label', array($this, 'handle_print_label'));
        add_action('admin_post_dhlwc_download_zpl', array($this, 'handle_download_zpl'));
    }

    public static function print_button(WC_Order $order) {
        $url = wp_nonce_url(
            add_query_arg(
                array(
                    'action'   => 'dhlwc_print_label',
                    'order_id' => $order->get_id(),
                ),
                admin_url('admin-post.php')
            ),
            'dhlwc_print_label_' . $order->get_id()
        );

        return '<a class="button dhlwc-action-button" target="_blank" rel="noopener noreferrer" href="' . esc_url($url) . '">' . esc_html__('Print Label', 'shippilot-for-woocommerce') . '</a>';
    }

    public static function zpl_download_button(WC_Order $order) {
        if (!$order->get_meta(DHLWC_Constants::META_BARCODE_ZPL)) {
            return '';
        }

        $url = wp_nonce_url(
            add_query_arg(
                array(
                    'action'   => 'dhlwc_download_zpl',
                    'order_id' => $order->get_id(),
                ),
                admin_url('admin-post.php')
            ),
            'dhlwc_download_zpl_' . $order->get_id()
        );

        return '<a class="button dhlwc-action-button" href="' . esc_url($url) . '">' . esc_html__('Download ZPL', 'shippilot-for-woocommerce') . '</a>';
    }

    public function handle_download_zpl() {
        $order = $this->get_order_from_request('dhlwc_download_zpl');
        $zpl   = (string) $order->get_meta(DHLWC_Constants::META_BARCODE_ZPL);

        if ('' === $zpl) {
            wp_die(esc_html__('No ZPL label is available for this order.', 'shippilot-for-woocommerce'));
        }

        $filename = 'shippilot-label-' . sanitize_file_name($order->get_order_number()) . '.zpl';

        nocache_headers();
        header('Content-Type: text/plain; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $zpl; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ZPL is downloaded as plain text generated/stored by the carrier flow.
        exit;
    }

    public function handle_print_label() {
        $order    = $this->get_order_from_request('dhlwc_print_label');
        $settings = DHLWC_Settings::get();
        $data     = $this->label_data($order, $settings);

        wp_register_style(
            'shippilot-label',
            DHLWC_URL . 'assets/css/shippilot-label.css',
            array(),
            DHLWC_VERSION
        );

        wp_register_script(
            'shippilot-label',
            DHLWC_URL . 'assets/js/shippilot-label.js',
            array(),
            DHLWC_VERSION,
            false
        );

        wp_enqueue_style('shippilot-label');
        wp_enqueue_script('shippilot-label');

        wp_localize_script(
            'shippilot-label',
            'shippilotLabelData',
            array(
                'reference'       => $data['reference'],
                'pieceBarcode'    => $data['piece_barcode'],
                'shipmentId'      => $data['shipment_id'] ? $data['shipment_id'] : '-',
                'invoiceId'       => $data['invoice_id'],
                'orderNumber'     => $order->get_order_number(),
                'billOfLandingId' => 'WC-' . $order->get_order_number(),
                'date'            => $data['date'],
                'sender'          => $data['sender'],
                'senderAddress'   => $data['sender_address'],
                'senderPhone'     => $data['sender_phone'],
                'customerNumber'  => $data['customer_number'],
                'recipient'       => $data['recipient_name'],
                'recipientAddress'=> $data['recipient_address'],
                'recipientPhone'  => $data['recipient_phone'],
                'content'         => $data['content_text'],
                'kg'              => $data['kg'],
                'desi'            => $data['desi'],
                'title'           => $data['barcode_title'],
                'type'            => 'shipment' === $data['barcode_type'] ? __('Shipment Barcode', 'shippilot-for-woocommerce') : __('Reference Order Barcode', 'shippilot-for-woocommerce'),
                'note'            => $data['note'],
                'accent'          => $data['accent'],
                'zplFilename'     => 'shippilot-label-' . sanitize_file_name($data['reference']) . '.zpl',
                'pngFilename'     => 'shippilot-label-' . sanitize_file_name($data['reference']) . '.png',
                'pdfFilename'     => 'shippilot-label-' . sanitize_file_name($data['reference']) . '.pdf',
                'strings'         => array(
                    'noZpl'     => __('No ZPL is available for this order.', 'shippilot-for-woocommerce'),
                    'zplCopied' => __('ZPL copied.', 'shippilot-for-woocommerce'),
                ),
            )
        );

        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');
        echo $this->render_label_page($order, $data); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Method escapes all dynamic HTML and only emits internally generated SVG.
        exit;
    }

    private function get_order_from_request($action) {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Unauthorized action.', 'shippilot-for-woocommerce'));
        }

        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        if (!$order_id || !check_admin_referer($action . '_' . $order_id)) {
            wp_die(esc_html__('Security verification failed.', 'shippilot-for-woocommerce'));
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_die(esc_html__('Order not found.', 'shippilot-for-woocommerce'));
        }

        return $order;
    }

    private function label_data(WC_Order $order, array $settings) {
        $reference     = $order->get_meta(DHLWC_Constants::META_REFERENCE_ID) ?: $this->make_reference_id($order);
        $piece_barcode = $order->get_meta(DHLWC_Constants::META_PIECE_BARCODE) ?: ($reference . '_P1');
        $barcode_type  = (string) $order->get_meta(DHLWC_Constants::META_BARCODE_TYPE);
        $sender        = isset($settings['label_sender_name']) ? trim((string) $settings['label_sender_name']) : '';

        if ('' === $sender) {
            $sender = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        }

        $accent = isset($settings['label_accent_color']) ? sanitize_hex_color($settings['label_accent_color']) : '';
        if (!$accent) {
            $accent = '#ffcc00';
        }

        return array(
            'reference'         => (string) $reference,
            'piece_barcode'     => (string) $piece_barcode,
            'shipment_id'       => (string) $order->get_meta(DHLWC_Constants::META_SHIPMENT_ID),
            'invoice_id'        => (string) $order->get_meta(DHLWC_Constants::META_INVOICE_ID),
            'barcode_type'      => $barcode_type,
            'barcode_title'     => 'shipment' === $barcode_type ? __('DHL ECOM SHIPMENT BARCODE', 'shippilot-for-woocommerce') : __('DHL ECOM ORDER BARCODE', 'shippilot-for-woocommerce'),
            'accent'           => $accent,
            'recipient_name'    => $this->recipient_name($order),
            'recipient_address' => $this->recipient_address($order),
            'recipient_phone'   => $this->normalize_phone($order->get_billing_phone()),
            'date'              => date_i18n('d/m/Y H:i', current_time('timestamp')),
            'sender'            => $sender,
            'sender_address'    => isset($settings['label_sender_address']) ? trim((string) $settings['label_sender_address']) : '',
            'sender_phone'      => isset($settings['label_sender_phone']) ? trim((string) $settings['label_sender_phone']) : '',
            'logo'              => isset($settings['label_logo_url']) ? trim((string) $settings['label_logo_url']) : '',
            'note'              => isset($settings['label_note']) ? trim((string) $settings['label_note']) : '',
            'zpl'               => (string) $order->get_meta(DHLWC_Constants::META_BARCODE_ZPL),
            'kg'                => max(1, isset($settings['default_kg']) ? (int) $settings['default_kg'] : 1),
            'desi'              => max(1, isset($settings['default_desi']) ? (int) $settings['default_desi'] : 1),
            'content_text'      => isset($settings['content_text']) ? (string) $settings['content_text'] : __('Product', 'shippilot-for-woocommerce'),
            'customer_number'   => isset($settings['customer_number']) ? (string) $settings['customer_number'] : '',
        );
    }

    private function render_label_page(WC_Order $order, array $data) {
        ob_start();
        ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php echo esc_attr(get_bloginfo('charset')); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>
	<?php
	/* translators: %s: Shipment reference number. */
	echo esc_html( sprintf( __( 'Shipping Label - %s', 'shippilot-for-woocommerce' ), $data['reference'] ) );
	?>
</title>
<?php wp_print_styles(array('shippilot-label')); ?>
</head>
<body class="paper-a5 landscape">
<div class="topbar">
    <h1><?php echo esc_html__('Print Shipping Label', 'shippilot-for-woocommerce'); ?></h1>
    <div class="actions">
        <button type="button" class="dark" data-action="copy-zpl"><?php echo esc_html__('Copy ZPL', 'shippilot-for-woocommerce'); ?></button>
        <button type="button" class="dark" data-action="download-zpl"><?php echo esc_html__('Download ZPL', 'shippilot-for-woocommerce'); ?></button>
        <button type="button" class="primary" data-action="print-label"><?php echo esc_html__('Print', 'shippilot-for-woocommerce'); ?></button>
    </div>
</div>
<div class="app">
    <aside class="sidebar">
        <div class="panel">
            <div class="field">
                <label for="paper-size"><?php echo esc_html__('PAPER SIZE', 'shippilot-for-woocommerce'); ?></label>
                <select class="select" id="paper-size">
                    <option value="a5" selected><?php echo esc_html__('A5 (148 x 210 mm)', 'shippilot-for-woocommerce'); ?></option>
                    <option value="a4"><?php echo esc_html__('A4 (210 x 297 mm)', 'shippilot-for-woocommerce'); ?></option>
                </select>
                <div class="info"><?php echo esc_html__('Recommended: A5 landscape. In the print dialog, select A5 paper size and narrow margins.', 'shippilot-for-woocommerce'); ?></div>
            </div>
            <div class="field">
                <span class="panel-title"><?php echo esc_html__('PRINT ORIENTATION', 'shippilot-for-woocommerce'); ?></span>
                <div class="option-grid">
                    <button type="button" id="orientation-portrait" class="choice" data-orientation="portrait"><?php echo esc_html__('Portrait', 'shippilot-for-woocommerce'); ?></button>
                    <button type="button" id="orientation-landscape" class="choice active" data-orientation="landscape"><?php echo esc_html__('Landscape', 'shippilot-for-woocommerce'); ?></button>
                </div>
            </div>
            <div class="field checks">
                <span class="panel-title"><?php echo esc_html__('DISPLAY', 'shippilot-for-woocommerce'); ?></span>
                <label><input type="checkbox" checked data-toggle="hide-logo"> <?php echo esc_html__('Logo', 'shippilot-for-woocommerce'); ?></label>
                <label><input type="checkbox" checked data-toggle="hide-sender"> <?php echo esc_html__('Sender Information', 'shippilot-for-woocommerce'); ?></label>
                <label><input type="checkbox" checked data-toggle="hide-recipient"> <?php echo esc_html__('Recipient Information', 'shippilot-for-woocommerce'); ?></label>
                <label><input type="checkbox" checked data-toggle="hide-barcode"> <?php echo esc_html__('Order Barcode Information', 'shippilot-for-woocommerce'); ?></label>
                <label><input type="checkbox" checked data-toggle="hide-footer"> <?php echo esc_html__('Footer Information', 'shippilot-for-woocommerce'); ?></label>
                <label><input type="checkbox" checked data-toggle="hide-qr"> <?php echo esc_html__('QR Code', 'shippilot-for-woocommerce'); ?></label>
            </div>
            <div class="field">
                <span class="panel-title"><?php echo esc_html__('ZOOM', 'shippilot-for-woocommerce'); ?></span>
                <div class="zoom-control">
                    <button type="button" data-zoom="-10"><?php echo esc_html__('Minus', 'shippilot-for-woocommerce'); ?></button>
                    <span id="zoom-label">100%</span>
                    <button type="button" data-zoom="10"><?php echo esc_html__('Plus', 'shippilot-for-woocommerce'); ?></button>
                </div>
            </div>
            <button type="button" class="primary full" data-action="print-label"><?php echo esc_html__('Print', 'shippilot-for-woocommerce'); ?></button>
            <button type="button" class="light full" data-action="download-pdf"><?php echo esc_html__('Download PDF', 'shippilot-for-woocommerce'); ?></button>
            <button type="button" class="light full" data-action="download-png"><?php echo esc_html__('Download as Image (PNG)', 'shippilot-for-woocommerce'); ?></button>
        </div>
    </aside>
    <main class="stage">
        <div class="stage-toolbar">
            <button type="button" aria-label="<?php echo esc_attr__('Previous label', 'shippilot-for-woocommerce'); ?>">&lt;</button>
            <button type="button" aria-label="<?php echo esc_attr__('Next label', 'shippilot-for-woocommerce'); ?>">&gt;</button>
            <span>1 / 1</span>
            <button type="button" data-zoom="-10"><?php echo esc_html__('Minus', 'shippilot-for-woocommerce'); ?></button>
            <span id="zoom-label-top">100%</span>
            <button type="button" data-zoom="10"><?php echo esc_html__('Plus', 'shippilot-for-woocommerce'); ?></button>
        </div>
        <div class="sheet" id="sheet">
            <div class="label" id="label">
                <div class="header">
                    <div>
                        <?php if ($data['logo']) : ?>
                            <img class="logo" src="<?php echo esc_url($data['logo']); ?>" alt="<?php echo esc_attr__('Logo', 'shippilot-for-woocommerce'); ?>">
                        <?php else : ?>
                            <div class="brand"><?php echo esc_html($data['sender']); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="title"><?php echo esc_html($data['barcode_title']); ?></div>
                </div>
                <div class="grid-two">
                    <div class="box">
                        <h3><?php echo esc_html__('SENDER', 'shippilot-for-woocommerce'); ?></h3>
                        <div class="name"><?php echo esc_html($data['sender']); ?></div>
                        <?php if ($data['sender_address']) : ?>
                            <p><?php echo nl2br(esc_html($data['sender_address'])); ?></p>
                        <?php endif; ?>
                        <?php if ($data['sender_phone']) : ?>
                            <p><strong><?php echo esc_html__('Tel:', 'shippilot-for-woocommerce'); ?></strong> <?php echo esc_html($data['sender_phone']); ?></p>
                        <?php endif; ?>
                        <p><strong><?php echo esc_html__('Customer No:', 'shippilot-for-woocommerce'); ?></strong> <?php echo esc_html($data['customer_number']); ?></p>
                    </div>
                    <div class="box">
                        <h3><?php echo esc_html__('RECIPIENT', 'shippilot-for-woocommerce'); ?></h3>
                        <div class="name"><?php echo esc_html($data['recipient_name']); ?></div>
                        <p><?php echo nl2br(esc_html($data['recipient_address'])); ?></p>
                        <?php if ($data['recipient_phone']) : ?>
                            <p><strong><?php echo esc_html__('Tel:', 'shippilot-for-woocommerce'); ?></strong> <?php echo esc_html($data['recipient_phone']); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="barcode-row">
                    <div class="barcode-main">
                        <h2><?php echo esc_html__('ORDER BARCODE', 'shippilot-for-woocommerce'); ?></h2>
                        <div class="barcode-holder"><?php echo $this->code39_svg($data['reference'], 640, 100); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Internal safe SVG generator escapes attributes. ?></div>
                        <div class="barcode-text">&gt;:<?php echo esc_html($data['reference']); ?></div>
                    </div>
                    <div class="side-info">
                        <div><strong><?php echo esc_html__('REFERENCE ID', 'shippilot-for-woocommerce'); ?></strong><span><?php echo esc_html($data['reference']); ?></span></div>
                        <div><strong><?php echo esc_html__('BILL OF LANDING ID', 'shippilot-for-woocommerce'); ?></strong><span><?php echo esc_html('WC-' . $order->get_order_number()); ?></span></div>
                        <div><strong><?php echo esc_html__('DATE / TIME', 'shippilot-for-woocommerce'); ?></strong><span><?php echo esc_html($data['date']); ?></span></div>
                    </div>
                </div>
                <div class="metrics">
                    <div class="metric"><strong><?php echo esc_html__('PIECE', 'shippilot-for-woocommerce'); ?></strong><span>1 / 1</span></div>
                    <div class="metric"><strong><?php echo esc_html__('KG/VOL.', 'shippilot-for-woocommerce'); ?></strong><span><?php echo esc_html($data['kg']); ?> / <?php echo esc_html($data['desi']); ?></span></div>
                    <div class="metric"><strong><?php echo esc_html__('SHIPMENT NO', 'shippilot-for-woocommerce'); ?></strong><span><?php echo esc_html($data['shipment_id'] ? $data['shipment_id'] : '-'); ?></span></div>
                </div>
                <div class="content">
                    <strong><?php echo esc_html__('CONTENT', 'shippilot-for-woocommerce'); ?></strong>
                    <p><?php echo esc_html($data['content_text']); ?></p>
                    <p>
                        <strong><?php echo esc_html__('PIECE BARCODE:', 'shippilot-for-woocommerce'); ?></strong> <?php echo esc_html($data['piece_barcode']); ?>
                        <?php if ($data['invoice_id']) : ?>
                            <strong><?php echo esc_html__('INVOICE NO:', 'shippilot-for-woocommerce'); ?></strong> <?php echo esc_html($data['invoice_id']); ?>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="footer">
                    <div><?php echo $this->qr_placeholder_svg($data['reference'], 80); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Internal safe SVG generator escapes attributes. ?></div>
                    <div>
                        <p><strong><?php echo esc_html__('Order No:', 'shippilot-for-woocommerce'); ?></strong> <?php echo esc_html($order->get_order_number()); ?></p>
                        <p><strong><?php echo esc_html__('Reference:', 'shippilot-for-woocommerce'); ?></strong> <?php echo esc_html($data['reference']); ?></p>
                        <p><strong><?php echo esc_html__('Created:', 'shippilot-for-woocommerce'); ?></strong> <?php echo esc_html($data['date']); ?></p>
                        <p><strong><?php echo esc_html__('Type:', 'shippilot-for-woocommerce'); ?></strong> <?php echo esc_html('shipment' === $data['barcode_type'] ? __('Shipment Barcode', 'shippilot-for-woocommerce') : __('Reference Order Barcode', 'shippilot-for-woocommerce')); ?></p>
                    </div>
                    <div class="notice"><?php echo nl2br(esc_html($data['note'])); ?></div>
                </div>
            </div>
        </div>
        <textarea id="dhlwc-zpl" class="zpl" readonly><?php echo esc_textarea($data['zpl']); ?></textarea>
    </main>
</div>
<?php wp_print_scripts(array('shippilot-label')); ?>
</body>
</html>
        <?php
        return ob_get_clean();
    }

    private function recipient_name(WC_Order $order) {
        $name = trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name());
        if ('' === $name) {
            $name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        }

        return $name ? $name : __('Recipient', 'shippilot-for-woocommerce');
    }

    private function recipient_address(WC_Order $order) {
        $address  = trim(($order->get_shipping_address_1() ?: $order->get_billing_address_1()) . ' ' . ($order->get_shipping_address_2() ?: $order->get_billing_address_2()));
        $city     = $order->get_shipping_city() ?: $order->get_billing_city();
        $state    = $order->get_shipping_state() ?: $order->get_billing_state();
        $postcode = $order->get_shipping_postcode() ?: $order->get_billing_postcode();

        return trim($address . "\n" . $city . ' ' . $state . ' ' . $postcode);
    }

    private function normalize_phone($phone) {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if (strlen($digits) > 10 && '90' === substr($digits, 0, 2)) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) > 10 && '0' === substr($digits, 0, 1)) {
            $digits = substr($digits, 1);
        }

        return substr($digits, -10);
    }

    private function make_reference_id(WC_Order $order) {
        return substr(strtoupper(preg_replace('/[^A-Z0-9]/', '', remove_accents('WC' . $order->get_order_number()))), 0, 20);
    }

    private function code39_svg($text, $width = 520, $height = 110) {
        $patterns = array(
            '0' => '101001101101', '1' => '110100101011', '2' => '101100101011', '3' => '110110010101', '4' => '101001101011',
            '5' => '110100110101', '6' => '101100110101', '7' => '101001011011', '8' => '110100101101', '9' => '101100101101',
            'A' => '110101001011', 'B' => '101101001011', 'C' => '110110100101', 'D' => '101011001011', 'E' => '110101100101',
            'F' => '101101100101', 'G' => '101010011011', 'H' => '110101001101', 'I' => '101101001101', 'J' => '101011001101',
            'K' => '110101010011', 'L' => '101101010011', 'M' => '110110101001', 'N' => '101011010011', 'O' => '110101101001',
            'P' => '101101101001', 'Q' => '101010110011', 'R' => '110101011001', 'S' => '101101011001', 'T' => '101011011001',
            'U' => '110010101011', 'V' => '100110101011', 'W' => '110011010101', 'X' => '100101101011', 'Y' => '110010110101',
            'Z' => '100110110101', '-' => '100101011011', '.' => '110010101101', ' ' => '100110101101', '$' => '100100100101',
            '/' => '100100101001', '+' => '100101001001', '%' => '101001001001', '*' => '100101101101', '_' => '100100101101',
        );

        $text = strtoupper((string) $text);
        $text = '*' . preg_replace('/[^A-Z0-9\-\. \$\/\+%_]/', '', $text) . '*';
        $bits = '';

        for ($i = 0; $i < strlen($text); $i++) {
            $char  = $text[$i];
            $bits .= isset($patterns[$char]) ? $patterns[$char] . '0' : '';
        }

        $unit  = max(1, $width / max(1, strlen($bits)));
        $x     = 0;
        $rects = '';

        for ($i = 0; $i < strlen($bits); $i++) {
            if ('1' === $bits[$i]) {
                $rects .= '<rect x="' . esc_attr(round($x, 2)) . '" y="0" width="' . esc_attr(round($unit, 2)) . '" height="' . esc_attr($height) . '"/>';
            }
            $x += $unit;
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . esc_attr($width) . ' ' . esc_attr($height) . '" preserveAspectRatio="none" role="img" aria-label="' . esc_attr__('Barcode', 'shippilot-for-woocommerce') . '"><g fill="#000">' . $rects . '</g></svg>';
    }

    private function qr_placeholder_svg($text, $size = 80) {
        $seed    = crc32((string) $text);
        $cell    = $size / 21;
        $out     = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . esc_attr($size) . ' ' . esc_attr($size) . '" role="img" aria-label="' . esc_attr__('QR', 'shippilot-for-woocommerce') . '"><rect width="' . esc_attr($size) . '" height="' . esc_attr($size) . '" fill="#fff"/><g fill="#111">';
        $finders = array(array(1, 1), array(13, 1), array(1, 13));

        foreach ($finders as $finder) {
            $out .= '<rect x="' . esc_attr($finder[0] * $cell) . '" y="' . esc_attr($finder[1] * $cell) . '" width="' . esc_attr(7 * $cell) . '" height="' . esc_attr(7 * $cell) . '"/>';
            $out .= '<rect fill="#fff" x="' . esc_attr(($finder[0] + 1) * $cell) . '" y="' . esc_attr(($finder[1] + 1) * $cell) . '" width="' . esc_attr(5 * $cell) . '" height="' . esc_attr(5 * $cell) . '"/>';
            $out .= '<rect x="' . esc_attr(($finder[0] + 2) * $cell) . '" y="' . esc_attr(($finder[1] + 2) * $cell) . '" width="' . esc_attr(3 * $cell) . '" height="' . esc_attr(3 * $cell) . '"/>';
        }

        for ($row = 0; $row < 21; $row++) {
            for ($column = 0; $column < 21; $column++) {
                if ($row < 8 && $column < 8) {
                    continue;
                }
                if ($row < 8 && $column > 12) {
                    continue;
                }
                if ($row > 12 && $column < 8) {
                    continue;
                }
                if ((($row * $column + $seed + $column) % 5) === 0) {
                    $out .= '<rect x="' . esc_attr($column * $cell) . '" y="' . esc_attr($row * $cell) . '" width="' . esc_attr($cell) . '" height="' . esc_attr($cell) . '"/>';
                }
            }
        }

        return $out . '</g><rect x="0.5" y="0.5" width="' . esc_attr($size - 1) . '" height="' . esc_attr($size - 1) . '" fill="none" stroke="#111"/></svg>';
    }
}
