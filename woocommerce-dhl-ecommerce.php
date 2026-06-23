<?php
/**
 * Plugin Name: DHL eCommerce for WooCommerce
 * Plugin URI: https://github.com/muratzden/woocommerce-dhl-ecommerce
 * Description: WooCommerce orders to DHL eCommerce / MNG Kargo API: create shipment orders, barcode labels and synchronize shipment tracking emails.
 * Version: 0.9.9-beta
 * Author: Murat Özden
 * Author URI: https://tillacanta.com.tr
 * Text Domain: woocommerce-dhl-ecommerce
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 */

if (!defined('ABSPATH')) { exit; }

add_action('before_woocommerce_init', function () {
    if (class_exists('Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

register_activation_hook(__FILE__, array('DHL_Ecommerce_For_WooCommerce', 'activate'));
register_deactivation_hook(__FILE__, array('DHL_Ecommerce_For_WooCommerce', 'deactivate'));

final class DHL_Ecommerce_For_WooCommerce {
    const VERSION = '0.9.9-beta';
    const OPTION_KEY = 'dhlwc_settings';
    const CRON_HOOK = 'dhlwc_sync_tracking_cron';
    const BARCODE_RETRY_HOOK = 'dhlwc_retry_create_barcode';

    const META_SENT = '_dhlwc_sent';
    const META_REFERENCE_ID = '_dhlwc_reference_id';
    const META_RESPONSE = '_dhlwc_response';
    const META_ERROR = '_dhlwc_error';
    const META_TRACKING_URL = '_dhlwc_tracking_url';
    const META_BARCODE_CREATED = '_dhlwc_barcode_created';
    const META_BARCODE_RESPONSE = '_dhlwc_barcode_response';
    const META_SHIPMENT_ID = '_dhlwc_shipment_id';
    const META_INVOICE_ID = '_dhlwc_invoice_id';
    const META_LAST_STAGE = '_dhlwc_last_stage';
    const META_TRACKING_ACTIVE = '_dhlwc_tracking_active';
    const META_STATUS_RESPONSE = '_dhlwc_status_response';
    const META_ORDER_CREATED_AT = '_dhlwc_order_created_at';
    const META_BARCODE_ATTEMPTS = '_dhlwc_barcode_attempts';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) { self::$instance = new self(); }
        return self::$instance;
    }

    public static function activate() {
        add_filter('cron_schedules', array(__CLASS__, 'static_cron_schedules'));
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 300, 'dhlwc_15min', self::CRON_HOOK);
        }
    }

    public static function deactivate() {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) { wp_unschedule_event($timestamp, self::CRON_HOOK); }
    }

    private function __construct() { add_action('plugins_loaded', array($this, 'boot')); }

    public function boot() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }
        add_filter('cron_schedules', array($this, 'cron_schedules'));
        add_action(self::CRON_HOOK, array($this, 'sync_tracking_cron'));
        add_action(self::BARCODE_RETRY_HOOK, array($this, 'retry_create_barcode'), 10, 1);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'plugin_action_links'));
        add_action('init', array($this, 'register_order_statuses'));
        add_filter('wc_order_statuses', array($this, 'add_order_statuses'));
        add_action('admin_menu', array($this, 'register_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('woocommerce_order_status_processing', array($this, 'maybe_auto_send_order'), 20, 1);
        add_action('admin_post_dhlwc_send_order', array($this, 'handle_send_order_post'));
        add_action('admin_post_dhlwc_create_barcode', array($this, 'handle_create_barcode_post'));
        add_action('admin_post_dhlwc_check_tracking', array($this, 'handle_check_tracking_post'));
        add_action('admin_post_dhlwc_test_connection', array($this, 'handle_test_connection'));
        add_action('add_meta_boxes', array($this, 'add_order_metabox'));
        add_filter('woocommerce_email_order_meta_fields', array($this, 'email_order_meta_fields'), 10, 3);
    }

    public static function static_cron_schedules($schedules) {
        $schedules['dhlwc_15min'] = array('interval' => 15 * MINUTE_IN_SECONDS, 'display' => 'Every 15 minutes');
        return $schedules;
    }

    public function cron_schedules($schedules) {
        return self::static_cron_schedules($schedules);
    }

    public function plugin_action_links($links) {
        $settings = '<a href="' . esc_url(admin_url('admin.php?page=woocommerce-dhl-ecommerce')) . '">Ayarlar</a>';
        array_unshift($links, $settings);
        return $links;
    }

    public function woocommerce_missing_notice() {
        echo '<div class="notice notice-error"><p><strong>DHL eCommerce for WooCommerce</strong> için WooCommerce aktif olmalıdır.</p></div>';
    }

    public function register_order_statuses() {
        $statuses = array(
            'wc-dhl-ready' => 'Kargoya verilmeye hazır',
            'wc-dhl-shipped' => 'Kargoya teslim edildi',
            'wc-dhl-branch' => 'Varış şubesinde',
            'wc-dhl-delivery' => 'Dağıtımda',
        );
        foreach ($statuses as $key => $label) {
            register_post_status($key, array(
                'label' => $label,
                'public' => true,
                'exclude_from_search' => false,
                'show_in_admin_all_list' => true,
                'show_in_admin_status_list' => true,
                'label_count' => _n_noop($label . ' <span class="count">(%s)</span>', $label . ' <span class="count">(%s)</span>'),
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

    public static function default_settings() {
        return array(
            'environment' => 'production',
            'customer_number' => '',
            'customer_password' => '',
            'client_id' => '',
            'client_secret' => '',
            'auto_send' => 'yes',
            'auto_barcode' => 'yes',
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
            'email_subject_prepared' => '{site_name} - Kargonuz hazırlandı',
            'email_body_prepared' => 'Merhaba {customer_name},\n\n#{order_number} numaralı siparişiniz kargoya verilmeye hazırlandı.\n\n{tracking_line}\n\n{site_name}',
            'email_subject_shipped' => '{site_name} - Kargonuz teslim alındı',
            'email_body_shipped' => 'Merhaba {customer_name},\n\n#{order_number} numaralı siparişiniz DHL eCommerce/MNG Kargo sistemine aktarıldı.\n\n{tracking_line}\n\n{site_name}',
            'email_subject_branch' => '{site_name} - Kargonuz varış şubesinde',
            'email_body_branch' => 'Merhaba {customer_name},\n\n#{order_number} numaralı siparişiniz varış şubesine ulaştı.\n\n{tracking_line}\n\n{site_name}',
            'email_subject_delivery' => '{site_name} - Kargonuz dağıtıma çıktı',
            'email_body_delivery' => 'Merhaba {customer_name},\n\n#{order_number} numaralı siparişiniz dağıtıma çıktı.\n\n{tracking_line}\n\n{site_name}',
            'email_subject_delivered' => '{site_name} - Kargonuz teslim edildi',
            'email_body_delivered' => 'Merhaba {customer_name},\n\n#{order_number} numaralı siparişiniz teslim edildi. Siparişiniz tamamlandı olarak güncellendi.\n\n{site_name}',
            // Kept for backward compatibility with <= 0.9.8 settings. DHL emails now use the native WooCommerce email wrapper.
            'email_logo_url' => '',
            'email_brand_color' => '#ffcc66',
            'email_accent_color' => '#111827',
            'email_footer_text' => '{site_name}',
            'email_contact_text' => '',
        );
    }

    public function get_settings() {
        $saved = get_option(self::OPTION_KEY, array());
        return wp_parse_args(is_array($saved) ? $saved : array(), self::default_settings());
    }

    public function register_admin_menu() {
        add_menu_page('DHL eCommerce', 'DHL eCommerce', 'manage_woocommerce', 'woocommerce-dhl-ecommerce', array($this, 'render_settings_page'), 'dashicons-location-alt', 56);
    }

    public function register_settings() { register_setting('dhlwc_settings_group', self::OPTION_KEY, array($this, 'sanitize_settings')); }

    public function sanitize_settings($input) {
        $defaults = self::default_settings();
        $clean = array();
        foreach ($defaults as $key => $value) {
            $clean[$key] = isset($input[$key]) ? sanitize_text_field(wp_unslash($input[$key])) : $value;
        }
        $clean['environment'] = in_array($clean['environment'], array('test', 'production'), true) ? $clean['environment'] : 'production';
        foreach (array('auto_send', 'auto_barcode', 'tracking_enabled', 'tracking_email_enabled', 'debug_log') as $checkbox) {
            $clean[$checkbox] = isset($input[$checkbox]) && sanitize_text_field(wp_unslash($input[$checkbox])) === 'yes' ? 'yes' : 'no';
        }
        foreach ($defaults as $key => $value) {
            if (strpos($key, 'email_body_') === 0 && isset($input[$key])) {
                $clean[$key] = sanitize_textarea_field(wp_unslash($input[$key]));
            }
        }
        foreach (array('email_footer_text', 'email_contact_text') as $textarea_key) {
            if (isset($input[$textarea_key])) {
                $clean[$textarea_key] = sanitize_textarea_field(wp_unslash($input[$textarea_key]));
            }
        }
        if (isset($input['email_logo_url'])) { $clean['email_logo_url'] = esc_url_raw(wp_unslash($input['email_logo_url'])); }
        if (isset($input['email_brand_color'])) { $clean['email_brand_color'] = sanitize_hex_color(wp_unslash($input['email_brand_color'])) ?: $defaults['email_brand_color']; }
        if (isset($input['email_accent_color'])) { $clean['email_accent_color'] = sanitize_hex_color(wp_unslash($input['email_accent_color'])) ?: $defaults['email_accent_color']; }
        return $clean;
    }

    public function render_settings_page() {
        if (!current_user_can('manage_woocommerce')) { return; }
        $s = $this->get_settings();
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'settings';
        ?>
        <div class="wrap dhlwc-admin">
            <h1>DHL eCommerce for WooCommerce</h1>
            <p>WooCommerce siparişlerini DHL eCommerce / MNG Kargo API ile gönderiye çevirir, barkod oluşturur ve kargo hareketlerini müşteriye mail olarak iletir.</p>
            <h2 class="nav-tab-wrapper">
                <a class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=woocommerce-dhl-ecommerce&tab=settings')); ?>">Ayarlar</a>
                <a class="nav-tab <?php echo $tab === 'emails' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=woocommerce-dhl-ecommerce&tab=emails')); ?>">Müşteri Mailleri</a>
                <a class="nav-tab <?php echo $tab === 'help' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=woocommerce-dhl-ecommerce&tab=help')); ?>">Yardım</a>
            </h2>
            <?php if ($tab === 'help') { $this->render_help_page(); } elseif ($tab === 'emails') { $this->render_emails_page($s); } else { $this->render_main_settings($s); } ?>
        </div>
        <?php
    }

    private function render_main_settings($s) {
        if (isset($_GET['dhlwc_test'])) {
            echo $_GET['dhlwc_test'] === 'ok' ? '<div class="notice notice-success"><p>Token testi başarılı.</p></div>' : '<div class="notice notice-error"><p>Token testi başarısız. Bilgileri, abonelikleri ve IP yetkisini kontrol edin.</p></div>';
        }
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('dhlwc_settings_group'); ?>
            <table class="form-table" role="presentation">
                <tr><th>Ortam</th><td><select name="<?php echo esc_attr(self::OPTION_KEY); ?>[environment]"><option value="production" <?php selected($s['environment'], 'production'); ?>>Canlı / Apizone</option><option value="test" <?php selected($s['environment'], 'test'); ?>>Test / Sandbox</option></select></td></tr>
                <tr><th>DHL/MNG müşteri no</th><td><input class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[customer_number]" value="<?php echo esc_attr($s['customer_number']); ?>" autocomplete="off"></td></tr>
                <tr><th>DHL/MNG şifre</th><td><input class="regular-text" type="password" autocomplete="new-password" name="<?php echo esc_attr(self::OPTION_KEY); ?>[customer_password]" value="<?php echo esc_attr($s['customer_password']); ?>"></td></tr>
                <tr><th>Client ID</th><td><input class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[client_id]" value="<?php echo esc_attr($s['client_id']); ?>" autocomplete="off"></td></tr>
                <tr><th>Client Secret</th><td><input class="regular-text" type="password" autocomplete="new-password" name="<?php echo esc_attr(self::OPTION_KEY); ?>[client_secret]" value="<?php echo esc_attr($s['client_secret']); ?>"></td></tr>
                <tr><th>Otomatik gönderim</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[auto_send]" value="yes" <?php checked($s['auto_send'], 'yes'); ?>> Sipariş “Hazırlanıyor” durumuna geçince DHL/MNG siparişi oluştur ve “Kargoya verilmeye hazır” yap</label></td></tr>
                <tr><th>Otomatik takip</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[tracking_enabled]" value="yes" <?php checked($s['tracking_enabled'], 'yes'); ?>> Kargo hareketlerini 15 dakikada bir kontrol et</label><br><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[tracking_email_enabled]" value="yes" <?php checked($s['tracking_email_enabled'], 'yes'); ?>> Durum değişince müşteriye mail gönder</label></td></tr>
                <tr><th>Varsayılan desi / kg</th><td><input type="number" min="1" name="<?php echo esc_attr(self::OPTION_KEY); ?>[default_desi]" value="<?php echo esc_attr($s['default_desi']); ?>" style="width:80px"> / <input type="number" min="1" name="<?php echo esc_attr(self::OPTION_KEY); ?>[default_kg]" value="<?php echo esc_attr($s['default_kg']); ?>" style="width:80px"></td></tr>
                <tr><th>Log</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[debug_log]" value="yes" <?php checked($s['debug_log'], 'yes'); ?>> WooCommerce loglarına teknik kayıt yaz</label></td></tr>
            </table>
            <?php $this->hidden_defaults($s); submit_button('Ayarları Kaydet'); ?>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('dhlwc_test_connection'); ?><input type="hidden" name="action" value="dhlwc_test_connection"><?php submit_button('Token Bağlantısını Test Et', 'secondary'); ?></form>
        <?php
    }

    private function hidden_defaults($s) {
        foreach (array('shipment_service_type','packaging_type','payment_type','delivery_type','sms_preference_1','sms_preference_2','sms_preference_3','content_text','description_text') as $key) {
            echo '<input type="hidden" name="' . esc_attr(self::OPTION_KEY) . '[' . esc_attr($key) . ']" value="' . esc_attr($s[$key]) . '">';
        }
        foreach (self::default_settings() as $key => $value) {
            if (strpos($key, 'email_subject_') === 0 || strpos($key, 'email_body_') === 0 || strpos($key, 'email_') === 0) {
                echo '<input type="hidden" name="' . esc_attr(self::OPTION_KEY) . '[' . esc_attr($key) . ']" value="' . esc_attr($s[$key]) . '">';
            }
        }
    }

    private function render_emails_page($s) {
        $stages = $this->stage_labels();
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('dhlwc_settings_group'); ?>
            <p>Kullanılabilir değişkenler: <code>{site_name}</code>, <code>{order_number}</code>, <code>{customer_name}</code>, <code>{stage}</code>, <code>{message}</code>, <code>{tracking_url}</code>, <code>{tracking_line}</code>, <code>{shipment_id}</code></p>
            <div class="notice notice-info inline"><p><strong>Görsel tasarım WooCommerce mail ayarlarından alınır.</strong> Logo, renkler, üst bölüm ve footer için <em>WooCommerce → Ayarlar → E-postalar</em> ekranındaki mağaza şablonu kullanılır. Bu ekranda yalnızca DHL kargo maillerinin konu ve içerik metinleri düzenlenir.</p></div>
            <input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>[environment]" value="<?php echo esc_attr($s['environment']); ?>">
            <input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>[customer_number]" value="<?php echo esc_attr($s['customer_number']); ?>">
            <input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>[customer_password]" value="<?php echo esc_attr($s['customer_password']); ?>">
            <input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>[client_id]" value="<?php echo esc_attr($s['client_id']); ?>">
            <input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>[client_secret]" value="<?php echo esc_attr($s['client_secret']); ?>">
            <input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>[auto_send]" value="<?php echo esc_attr($s['auto_send']); ?>">
            <input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>[auto_barcode]" value="<?php echo esc_attr($s['auto_barcode']); ?>">
            <input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>[tracking_enabled]" value="<?php echo esc_attr($s['tracking_enabled']); ?>">
            <input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>[tracking_email_enabled]" value="<?php echo esc_attr($s['tracking_email_enabled']); ?>">
            <?php foreach (array('shipment_service_type','packaging_type','payment_type','delivery_type','sms_preference_1','sms_preference_2','sms_preference_3','default_desi','default_kg','content_text','description_text','debug_log') as $key) : ?>
                <input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($s[$key]); ?>">
            <?php endforeach; ?>
            <table class="form-table" role="presentation">
                <?php foreach ($stages as $stage => $label) : ?>
                    <tr><th colspan="2"><h2><?php echo esc_html($label); ?></h2></th></tr>
                    <tr><th>Konu</th><td><input class="large-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[email_subject_<?php echo esc_attr($stage); ?>]" value="<?php echo esc_attr($s['email_subject_' . $stage]); ?>"></td></tr>
                    <tr><th>İçerik</th><td><textarea class="large-text" rows="5" name="<?php echo esc_attr(self::OPTION_KEY); ?>[email_body_<?php echo esc_attr($stage); ?>]"><?php echo esc_textarea($s['email_body_' . $stage]); ?></textarea></td></tr>
                <?php endforeach; ?>
            </table>
            <?php submit_button('Mail Şablonlarını Kaydet'); ?>
        </form>
        <?php
    }

    private function render_help_page() { ?>
        <div class="card" style="max-width:980px;padding:18px 22px;margin-top:18px;">
            <h2>Sandbox ve Apizone Linkleri</h2>
            <p><a class="button button-primary" target="_blank" href="https://sandbox.mngkargo.com.tr/">Sandbox Portalını Aç</a> <a class="button" target="_blank" href="https://apizone.mngkargo.com.tr/">Apizone Portalını Aç</a></p>
            <h2>Kurulum Akışı</h2>
            <ol><li>Sandbox veya Apizone içinde uygulama oluşturun.</li><li>Identity, Standard Command, Standard Query ve Barcode Command API aboneliklerini aktif edin.</li><li>Client ID ve Client Secret değerlerini eklentiye girin.</li><li>Müşteri no ve şifreyi girin.</li><li>Token bağlantısını test edin.</li></ol>
            <h2>Otomatik takip nasıl çalışır?</h2><p>Eklenti DHL/MNG tarafına webhook beklemez. WordPress Cron ile 15 dakikada bir <code>getshipmentstatus</code> çağrısı yapar. Durum değişirse sipariş notu ekler, müşteriye mail gönderir ve teslim edildiğinde siparişi <strong>Tamamlandı</strong> durumuna alır.</p>
            <h2>Sık hata nedenleri</h2><ul><li><strong>401 subscription:</strong> İlgili API ürünü uygulamaya abone edilmemiştir.</li><li><strong>20011:</strong> Barkod için kullanılan referenceId ile DHL tarafında sipariş henüz bulunamadı. Eklenti otomatik yeniden dener; devam ederse önce kargo siparişini yeniden oluşturun.</li><li><strong>26060:</strong> Alıcı customerId boş gönderilmelidir.</li></ul>
        </div>
    <?php }

    public function handle_send_order_post() {
        $order = $this->get_admin_post_order('dhlwc_send_order');
        if (!$order) { wp_die('Sipariş bulunamadı.'); }
        $result = $this->send_order($order, true);
        if (is_wp_error($result)) { $order->add_order_note('DHL gönderim hatası: ' . $result->get_error_message()); $this->redirect_to_order($order, 'send_fail'); }
        $order->add_order_note('DHL kargo siparişi oluşturuldu. Referans: ' . $result['referenceId']);
        if ($this->get_settings()['auto_barcode'] === 'yes') {
            $this->schedule_barcode_retry($order->get_id(), 120);
            $order->add_order_note('DHL barkod oluşturma 2 dakika sonrasına planlandı. DHL tarafının siparişi işlemesi bekleniyor.');
        }
        $this->redirect_to_order($order, 'send_ok');
    }

    public function handle_create_barcode_post() {
        $order = $this->get_admin_post_order('dhlwc_create_barcode');
        if (!$order) { wp_die('Sipariş bulunamadı.'); }
        $result = $this->create_barcode($order);
        if (is_wp_error($result)) { $order->add_order_note('DHL barkod hatası: ' . $result->get_error_message()); $this->redirect_to_order($order, 'barcode_fail'); }
        $order->add_order_note('DHL barkod oluşturuldu. Gönderi No: ' . ($result['shipmentId'] ?? '-'));
        $this->redirect_to_order($order, 'barcode_ok');
    }

    public function handle_check_tracking_post() {
        $order = $this->get_admin_post_order('dhlwc_check_tracking');
        if (!$order) { wp_die('Sipariş bulunamadı.'); }
        $result = $this->sync_order_tracking($order, true);
        if (is_wp_error($result)) { $order->add_order_note('DHL durum kontrol hatası: ' . $result->get_error_message()); $this->redirect_to_order($order, 'tracking_fail'); }
        $this->redirect_to_order($order, 'tracking_ok');
    }

    public function maybe_auto_send_order($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) { return; }
        $s = $this->get_settings();
        if ($s['auto_send'] !== 'yes' || $order->get_meta(self::META_SENT) === 'yes') { return; }
        $result = $this->send_order($order, false);
        if (is_wp_error($result)) { $order->add_order_note('DHL otomatik gönderim hatası: ' . $result->get_error_message()); }
        else {
            $order->add_order_note('DHL otomatik kargo siparişi oluşturuldu. Referans: ' . $result['referenceId']);
            if ($s['auto_barcode'] === 'yes') {
                $this->schedule_barcode_retry($order->get_id(), 120);
                $order->add_order_note('DHL otomatik barkod oluşturma 2 dakika sonrasına planlandı.');
            }
        }
    }

    public function send_order(WC_Order $order, $force = false) {
        if (!$force && $order->get_meta(self::META_SENT) === 'yes') { return new WP_Error('already_sent', 'Bu sipariş daha önce DHL sistemine gönderilmiş.'); }
        $payload = $this->build_create_order_payload($order);
        $token = $this->get_token();
        if (is_wp_error($token)) { $order->update_meta_data(self::META_ERROR, $token->get_error_message()); $order->save(); return $token; }
        $response = $this->request('POST', '/standardcmdapi/createOrder', $payload, $token);
        if (is_wp_error($response)) { $order->update_meta_data(self::META_ERROR, $response->get_error_message()); $order->save(); return $response; }
        $reference_id = $payload['order']['referenceId'];
        $order->update_meta_data(self::META_SENT, 'yes');
        $order->update_meta_data(self::META_REFERENCE_ID, $reference_id);
        $order->update_meta_data(self::META_RESPONSE, wp_json_encode($response, JSON_UNESCAPED_UNICODE));
        $order->update_meta_data(self::META_ORDER_CREATED_AT, time());
        $order->update_meta_data(self::META_BARCODE_ATTEMPTS, 0);
        $order->update_meta_data(self::META_LAST_STAGE, 'prepared');
        $order->update_meta_data(self::META_TRACKING_ACTIVE, 'yes');
        $order->delete_meta_data(self::META_ERROR);
        $order->save();
        if ($order->get_status() !== 'dhl-ready') { $order->update_status('dhl-ready', 'DHL siparişi oluşturuldu; kargoya verilmeye hazır.'); }
        $this->maybe_send_stage_email($order, 'prepared', array('message' => 'Kargo siparişi oluşturuldu.'));
        return array('referenceId' => $reference_id, 'response' => $response);
    }

    public function create_barcode(WC_Order $order, $retry_on_20011 = true) {
        if ($order->get_meta(self::META_BARCODE_CREATED) === 'yes') {
            $saved = $this->decode_meta_json($order->get_meta(self::META_BARCODE_RESPONSE));
            return is_array($saved) && !empty($saved) ? $saved : array('referenceId' => $order->get_meta(self::META_REFERENCE_ID));
        }

        $reference_id = $order->get_meta(self::META_REFERENCE_ID);
        if (empty($reference_id) || $order->get_meta(self::META_SENT) !== 'yes') {
            $created = $this->send_order($order, true);
            if (is_wp_error($created)) { return $created; }
            $reference_id = $created['referenceId'];
            $this->schedule_barcode_retry($order->get_id(), 120);
            return new WP_Error('barcode_delayed', 'DHL siparişi yeni oluşturuldu. Barkod 2 dakika sonra otomatik denenecek.');
        }

        $created_at = (int) $order->get_meta(self::META_ORDER_CREATED_AT);
        if ($created_at > 0 && (time() - $created_at) < 120) {
            $remaining = 120 - (time() - $created_at);
            $this->schedule_barcode_retry($order->get_id(), max(60, $remaining));
            return new WP_Error('barcode_not_ready', 'DHL siparişi henüz barkod için bekleme süresini doldurmadı. Otomatik tekrar denenecek.');
        }

        $token = $this->get_token();
        if (is_wp_error($token)) { $order->update_meta_data(self::META_ERROR, $token->get_error_message()); $order->save(); return $token; }
        $response = $this->request('POST', '/barcodecmdapi/createbarcode', $this->build_create_barcode_payload($order, $reference_id), $token);
        if (is_wp_error($response)) {
            if ($retry_on_20011 && $this->is_api_error_code($response, '20011')) {
                $attempts = (int) $order->get_meta(self::META_BARCODE_ATTEMPTS);
                $attempts++;
                $order->update_meta_data(self::META_BARCODE_ATTEMPTS, $attempts);
                if ($attempts < 5) {
                    $delay = min(900, 120 + ($attempts * 180));
                    $this->schedule_barcode_retry($order->get_id(), $delay);
                    $order->add_order_note('DHL barkod 20011 döndü. DHL tarafında sipariş henüz barkod için hazır değil; otomatik tekrar denenecek. Deneme: ' . $attempts . '/5');
                } else {
                    $order->add_order_note('DHL barkod 20011 hatası 5 denemeye ulaştı. Apizone logu ile DHL/MNG teknik ekibe iletin.');
                }
            }
            $order->update_meta_data(self::META_ERROR, $response->get_error_message()); $order->save(); return $response;
        }
        $order->update_meta_data(self::META_BARCODE_CREATED, 'yes');
        $order->update_meta_data(self::META_BARCODE_ATTEMPTS, 0);
        $order->update_meta_data(self::META_BARCODE_RESPONSE, wp_json_encode($response, JSON_UNESCAPED_UNICODE));
        if (!empty($response['shipmentId'])) { $order->update_meta_data(self::META_SHIPMENT_ID, sanitize_text_field($response['shipmentId'])); }
        if (!empty($response['invoiceId'])) { $order->update_meta_data(self::META_INVOICE_ID, sanitize_text_field($response['invoiceId'])); }
        $order->update_meta_data(self::META_LAST_STAGE, 'shipped');
        $order->update_meta_data(self::META_TRACKING_ACTIVE, 'yes');
        $order->delete_meta_data(self::META_ERROR);
        $order->save();
        if ($order->get_status() !== 'dhl-shipped') { $order->update_status('dhl-shipped', 'DHL barkod oluşturuldu; gönderi kargo sisteminde.'); }
        $this->maybe_send_stage_email($order, 'shipped', array('shipment_id' => $order->get_meta(self::META_SHIPMENT_ID), 'message' => 'Barkod oluşturuldu.'));
        return $response;
    }

    public function retry_create_barcode($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_meta(self::META_BARCODE_CREATED) === 'yes') { return; }
        $result = $this->create_barcode($order, true);
        if (is_wp_error($result)) { $order->add_order_note('DHL barkod otomatik tekrar denemesi başarısız: ' . $result->get_error_message()); }
        else { $order->add_order_note('DHL barkod otomatik tekrar denemesinde oluşturuldu. Gönderi No: ' . ($result['shipmentId'] ?? '-')); }
    }

    private function schedule_barcode_retry($order_id, $delay = 120) {
        if (!wp_next_scheduled(self::BARCODE_RETRY_HOOK, array($order_id))) {
            wp_schedule_single_event(time() + max(60, (int) $delay), self::BARCODE_RETRY_HOOK, array($order_id));
        }
    }

    private function is_api_error_code($error, $code) {
        if (!is_wp_error($error)) { return false; }
        $data = $error->get_error_data();
        return isset($data['body']['error']['Code']) && (string) $data['body']['error']['Code'] === (string) $code;
    }

    public function sync_tracking_cron() {
        $s = $this->get_settings();
        if ($s['tracking_enabled'] !== 'yes') { return; }
        $orders = wc_get_orders(array('limit' => 20, 'status' => array('dhl-ready','dhl-shipped','dhl-branch','dhl-delivery','processing'), 'meta_key' => self::META_TRACKING_ACTIVE, 'meta_value' => 'yes', 'return' => 'objects'));
        foreach ($orders as $order) { $this->sync_order_tracking($order, false); }
    }

    public function sync_order_tracking(WC_Order $order, $manual = false) {
        $reference_id = $order->get_meta(self::META_REFERENCE_ID);
        if (!$reference_id) { return new WP_Error('missing_reference', 'DHL referans numarası yok.'); }
        $token = $this->get_token();
        if (is_wp_error($token)) { return $token; }
        $response = $this->request('GET', '/standardqueryapi/getshipmentstatus/' . rawurlencode($reference_id), null, $token);
        if (is_wp_error($response)) { $order->update_meta_data(self::META_ERROR, $response->get_error_message()); $order->save(); return $response; }
        $order->update_meta_data(self::META_STATUS_RESPONSE, wp_json_encode($response, JSON_UNESCAPED_UNICODE));
        if (!empty($response['trackingUrl'])) { $order->update_meta_data(self::META_TRACKING_URL, esc_url_raw($response['trackingUrl'])); }
        if (!empty($response['shipmentId'])) { $order->update_meta_data(self::META_SHIPMENT_ID, sanitize_text_field($response['shipmentId'])); }
        $stage = $this->map_tracking_stage($response);
        $last = $order->get_meta(self::META_LAST_STAGE);
        if ($stage && $stage !== $last) {
            $order->update_meta_data(self::META_LAST_STAGE, $stage);
            $order->save();
            $this->apply_stage_to_order($order, $stage, $response);
            $this->maybe_send_stage_email($order, $stage, array('message' => $response['shipmentStatus'] ?? '', 'tracking_url' => $response['trackingUrl'] ?? '', 'shipment_id' => $response['shipmentId'] ?? ''));
        } else {
            $order->save();
            if ($manual) { $order->add_order_note('DHL durum kontrol edildi. Yeni durum değişikliği yok.'); }
        }
        return $response;
    }

    private function map_tracking_stage($response) {
        if (!empty($response['isDelivered']) || (!empty($response['shipmentStatus']) && $this->contains_any($response['shipmentStatus'], array('TESLIM', 'TESLİM', 'DELIVERED')))) { return 'delivered'; }
        $status = isset($response['shipmentStatus']) ? $response['shipmentStatus'] : '';
        if ($this->contains_any($status, array('DAGITIM', 'DAĞITIM', 'OUT_FOR_DELIVERY', 'DISTRIBUTION'))) { return 'delivery'; }
        if ($this->contains_any($status, array('VARIS', 'VARIŞ', 'SUBE', 'ŞUBE', 'BRANCH'))) { return 'branch'; }
        if (!empty($response['shipmentId']) || !empty($response['shipmentDateTime'])) { return 'shipped'; }
        return '';
    }

    private function contains_any($text, $needles) {
        $text = $this->normalize_compare($text);
        foreach ($needles as $needle) { if (strpos($text, $this->normalize_compare($needle)) !== false) { return true; } }
        return false;
    }

    private function apply_stage_to_order(WC_Order $order, $stage, $response) {
        $labels = $this->stage_labels();
        $note = 'DHL kargo durumu güncellendi: ' . ($labels[$stage] ?? $stage);
        if (!empty($response['shipmentStatus'])) { $note .= ' (' . $response['shipmentStatus'] . ')'; }
        if ($stage === 'branch') { $order->update_status('dhl-branch', $note); }
        elseif ($stage === 'delivery') { $order->update_status('dhl-delivery', $note); }
        elseif ($stage === 'shipped') { $order->update_status('dhl-shipped', $note); }
        elseif ($stage === 'delivered') { $order->update_meta_data(self::META_TRACKING_ACTIVE, 'no'); $order->save(); $order->update_status('completed', $note); }
        else { $order->add_order_note($note); }
    }

    private function stage_labels() { return array('prepared' => 'Kargo hazırlandı', 'shipped' => 'Kargo teslim alındı', 'branch' => 'Varış şubesine ulaştı', 'delivery' => 'Dağıtıma çıktı', 'delivered' => 'Teslim edildi'); }

    private function maybe_send_stage_email(WC_Order $order, $stage, $data = array()) {
        $s = $this->get_settings();
        if ($s['tracking_email_enabled'] !== 'yes' || !$order->get_billing_email()) { return; }
        $subject_key = 'email_subject_' . $stage;
        $body_key = 'email_body_' . $stage;
        $subject = $this->replace_email_vars($s[$subject_key] ?? '', $order, $stage, $data);
        $plain_body = $this->replace_email_vars($s[$body_key] ?? '', $order, $stage, $data);
        $body = $this->build_customer_email_html($order, $stage, $subject, $plain_body, $data);
        $headers = array('Content-Type: text/html; charset=UTF-8');
        wp_mail($order->get_billing_email(), $subject, $body, $headers);
        $order->add_order_note('DHL müşteri maili gönderildi: ' . ($this->stage_labels()[$stage] ?? $stage));
    }

    private function build_customer_email_html(WC_Order $order, $stage, $subject, $plain_body, $data = array()) {
        $tracking_url = $data['tracking_url'] ?? $order->get_meta(self::META_TRACKING_URL);
        $shipment_id = $data['shipment_id'] ?? $order->get_meta(self::META_SHIPMENT_ID);
        $stage_label = $this->stage_labels()[$stage] ?? $stage;
        $email_heading = $subject ?: $stage_label;

        ob_start();
        ?>
        <div class="email-introduction">
            <p>
                <?php
                $first_name = $order->get_billing_first_name();
                if (!empty($first_name)) {
                    printf(esc_html__('Merhaba %s,', 'woocommerce-dhl-ecommerce'), esc_html($first_name));
                } else {
                    esc_html_e('Merhaba,', 'woocommerce-dhl-ecommerce');
                }
                ?>
            </p>
            <?php echo wpautop(wp_kses_post($plain_body)); ?>
        </div>

        <?php if ($tracking_url || $shipment_id) : ?>
            <table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation" style="margin: 0 0 24px 0;">
                <tr>
                    <td style="border: 1px solid #e5e5e5; padding: 16px;">
                        <p style="margin: 0 0 8px 0;"><strong><?php esc_html_e('Kargo durumu:', 'woocommerce-dhl-ecommerce'); ?></strong> <?php echo esc_html($stage_label); ?></p>
                        <?php if ($shipment_id) : ?>
                            <p style="margin: 0 0 8px 0;"><strong><?php esc_html_e('Gönderi No:', 'woocommerce-dhl-ecommerce'); ?></strong> <?php echo esc_html($shipment_id); ?></p>
                        <?php endif; ?>
                        <?php if ($tracking_url) : ?>
                            <p style="margin: 0;"><a href="<?php echo esc_url($tracking_url); ?>"><?php esc_html_e('Kargomu takip et', 'woocommerce-dhl-ecommerce'); ?></a></p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        <?php endif; ?>
        <?php

        do_action('woocommerce_email_order_details', $order, false, false, null);
        do_action('woocommerce_email_order_meta', $order, false, false, null);
        do_action('woocommerce_email_customer_details', $order, false, false, null);

        $content = ob_get_clean();

        if (function_exists('WC') && WC()->mailer()) {
            $mailer = WC()->mailer();
            $message = method_exists($mailer, 'wrap_message') ? $mailer->wrap_message($email_heading, $content) : $content;
            if (method_exists($mailer, 'style_inline')) {
                $message = $mailer->style_inline($message);
            }
            return $message;
        }

        ob_start();
        do_action('woocommerce_email_header', $email_heading, null);
        echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        do_action('woocommerce_email_footer', null);
        return ob_get_clean();
    }

    private function replace_email_vars($text, WC_Order $order, $stage, $data) {
        $tracking_url = $data['tracking_url'] ?? $order->get_meta(self::META_TRACKING_URL);
        $tracking_line = $tracking_url ? 'Takip linki: ' . $tracking_url : '';
        $vars = array('{site_name}' => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES), '{order_number}' => $order->get_order_number(), '{customer_name}' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()), '{stage}' => $this->stage_labels()[$stage] ?? $stage, '{message}' => $data['message'] ?? '', '{tracking_url}' => $tracking_url, '{tracking_line}' => $tracking_line, '{shipment_id}' => $data['shipment_id'] ?? $order->get_meta(self::META_SHIPMENT_ID));
        return strtr((string) $text, $vars);
    }

    private function build_create_barcode_payload(WC_Order $order, $reference_id) {
        $s = $this->get_settings(); $content = $this->build_content_text($order, $s['content_text']);
        return array('referenceId' => $reference_id, 'billOfLandingId' => 'WC-' . $order->get_order_number(), 'isCOD' => $this->is_cod($order) ? 1 : 0, 'codAmount' => $this->is_cod($order) ? (float) $order->get_total() : 0, 'packagingType' => (int) $s['packaging_type'], 'printReferenceBarcodeOnError' => 1, 'message' => 'WooCommerce', 'additionalContent1' => '', 'additionalContent2' => '', 'additionalContent3' => '', 'additionalContent4' => '', 'orderPieceList' => array(array('barcode' => $reference_id . '_P1', 'desi' => max(1, (int) $s['default_desi']), 'kg' => max(1, (int) $s['default_kg']), 'content' => $content)));
    }

    private function build_create_order_payload(WC_Order $order) {
        $s = $this->get_settings(); $reference = $this->make_reference_id($order);
        $full_name = trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name()); if ($full_name === '') { $full_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()); }
        $location = $this->resolve_tr_city_district($order); $address = trim(($order->get_shipping_address_1() ?: $order->get_billing_address_1()) . ' ' . ($order->get_shipping_address_2() ?: $order->get_billing_address_2())); $content = $this->build_content_text($order, $s['content_text']);
        return array('order' => array('referenceId' => $reference, 'barcode' => $reference, 'billOfLandingId' => 'WC-' . $order->get_order_number(), 'isCOD' => $this->is_cod($order) ? 1 : 0, 'codAmount' => $this->is_cod($order) ? (float) $order->get_total() : 0, 'shipmentServiceType' => (int) $s['shipment_service_type'], 'packagingType' => (int) $s['packaging_type'], 'content' => $content, 'smsPreference1' => (int) $s['sms_preference_1'], 'smsPreference2' => (int) $s['sms_preference_2'], 'smsPreference3' => (int) $s['sms_preference_3'], 'paymentType' => (int) $s['payment_type'], 'deliveryType' => (int) $s['delivery_type'], 'description' => $s['description_text'] . ' #' . $order->get_order_number(), 'marketPlaceShortCode' => '', 'marketPlaceSaleCode' => '', 'pudoId' => ''), 'orderPieceList' => array(array('barcode' => $reference . '_P1', 'desi' => max(1, (int) $s['default_desi']), 'kg' => max(1, (int) $s['default_kg']), 'content' => $content)), 'recipient' => array('customerId' => '', 'refCustomerId' => '', 'cityCode' => 0, 'cityName' => $this->limit_text($location['city'], 50), 'districtCode' => 0, 'districtName' => $this->limit_text($location['district'], 50), 'address' => $this->limit_text($address, 200), 'bussinessPhoneNumber' => '', 'email' => $this->limit_text($order->get_billing_email(), 50), 'taxOffice' => '', 'taxNumber' => '', 'fullName' => $this->limit_text($full_name, 150), 'homePhoneNumber' => '', 'mobilePhoneNumber' => $this->normalize_phone($order->get_billing_phone())));
    }

    private function resolve_tr_city_district(WC_Order $order) {
        $raw_city = trim((string) ($order->get_shipping_city() ?: $order->get_billing_city())); $raw_state = trim((string) ($order->get_shipping_state() ?: $order->get_billing_state())); $country = strtoupper((string) ($order->get_shipping_country() ?: $order->get_billing_country()));
        if ($country !== 'TR') { return array('city' => $raw_city, 'district' => $raw_state); }
        $states = function_exists('WC') && WC()->countries ? WC()->countries->get_states('TR') : array(); $state_city = '';
        if ($raw_state !== '' && isset($states[$raw_state])) { $state_city = $states[$raw_state]; } elseif ($raw_state !== '') { foreach ($states as $name) { if ($this->normalize_compare($name) === $this->normalize_compare($raw_state)) { $state_city = $name; break; } } }
        return $state_city !== '' ? array('city' => $this->uppercase_tr($state_city), 'district' => $this->uppercase_tr($raw_city)) : array('city' => $this->uppercase_tr($raw_city), 'district' => $this->uppercase_tr($raw_state));
    }

    private function normalize_compare($value) { $value = remove_accents((string) $value); return strtoupper(preg_replace('/[^A-Z0-9]/', '', $value)); }
    private function uppercase_tr($value) { $value = trim((string) $value); return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value); }
    private function make_reference_id(WC_Order $order) { return substr(strtoupper(preg_replace('/[^A-Z0-9]/', '', remove_accents('WC' . $order->get_order_number()))), 0, 20); }
    private function build_content_text(WC_Order $order, $fallback) { $text = trim((string) $fallback); if (max(1, (int) $order->get_item_count()) > 1) { $text .= ' - ' . (int) $order->get_item_count() . ' adet'; } return $this->limit_text($text, 150); }
    private function normalize_phone($phone) { $digits = preg_replace('/\D+/', '', (string) $phone); if (strlen($digits) > 10 && substr($digits, 0, 2) === '90') { $digits = substr($digits, 2); } if (strlen($digits) > 10 && substr($digits, 0, 1) === '0') { $digits = substr($digits, 1); } return substr($digits, -10); }
    private function is_cod(WC_Order $order) { return in_array($order->get_payment_method(), array('cod', 'kapida-odeme'), true); }
    private function limit_text($text, $limit) { $text = trim(wp_strip_all_tags((string) $text)); return function_exists('mb_substr') ? mb_substr($text, 0, $limit, 'UTF-8') : substr($text, 0, $limit); }
    private function get_base_url() { $s = $this->get_settings(); return $s['environment'] === 'test' ? 'https://testapi.mngkargo.com.tr/mngapi/api' : 'https://api.mngkargo.com.tr/mngapi/api'; }

    private function get_token() {
        $s = $this->get_settings();
        if (empty($s['customer_number']) || empty($s['customer_password']) || empty($s['client_id']) || empty($s['client_secret'])) { return new WP_Error('missing_settings', 'DHL müşteri numarası, şifre, client id ve client secret zorunludur.'); }
        $cache_key = 'dhlwc_token_' . md5($s['environment'] . $s['customer_number'] . $s['client_id']); $cached = get_transient($cache_key); if (!empty($cached)) { return $cached; }
        $response = wp_remote_post($this->get_base_url() . '/token', array('timeout' => 30, 'headers' => array('Content-Type' => 'application/json', 'x-ibm-client-id' => $s['client_id'], 'x-ibm-client-secret' => $s['client_secret']), 'body' => wp_json_encode(array('customerNumber' => $s['customer_number'], 'password' => $s['customer_password'], 'identityType' => 1))));
        $body = $this->parse_response($response); if (is_wp_error($body)) { return $body; } if (empty($body['jwt'])) { return new WP_Error('token_missing', 'Token cevabında jwt alanı bulunamadı.'); }
        set_transient($cache_key, $body['jwt'], 7 * HOUR_IN_SECONDS + 45 * MINUTE_IN_SECONDS); return $body['jwt'];
    }

    private function request($method, $path, $payload = null, $token = '') {
        $s = $this->get_settings(); $args = array('method' => $method, 'timeout' => 45, 'headers' => array('Content-Type' => 'application/json', 'Accept' => 'application/json', 'x-ibm-client-id' => $s['client_id'], 'x-ibm-client-secret' => $s['client_secret'], 'Authorization' => 'Bearer ' . $token));
        if ($payload !== null) { $args['body'] = wp_json_encode($payload, JSON_UNESCAPED_UNICODE); }
        $response = wp_remote_request($this->get_base_url() . $path, $args); $parsed = $this->parse_response($response);
        if ($s['debug_log'] === 'yes') { $this->log(array('path' => $path, 'payload' => $this->redact_log_payload($payload), 'response' => $parsed)); }
        return $parsed;
    }

    private function parse_response($response) {
        if (is_wp_error($response)) { return $response; }
        $code = (int) wp_remote_retrieve_response_code($response); $raw = wp_remote_retrieve_body($response); $json = json_decode($raw, true); $body = is_array($json) ? $json : array('raw' => $raw);
        if ($code < 200 || $code >= 300) { $message = $body['error']['description'] ?? $body['error']['message'] ?? $body['error']['Message'] ?? $body['moreInformation'] ?? $body['detail'] ?? $body['title'] ?? ('DHL API HTTP ' . $code); return new WP_Error('dhl_api_error', $message, array('status' => $code, 'body' => $body)); }
        return $body;
    }

    private function redact_log_payload($payload) { if (!is_array($payload)) { return $payload; } foreach (array('email','mobilePhoneNumber','address') as $key) { if (isset($payload['recipient'][$key])) { $payload['recipient'][$key] = '***'; } } return $payload; }
    private function log($data) { if (function_exists('wc_get_logger')) { wc_get_logger()->info(wp_json_encode($data, JSON_UNESCAPED_UNICODE), array('source' => 'woocommerce-dhl-ecommerce')); } }

    public function add_order_metabox() { add_meta_box('dhlwc_order_box', 'DHL eCommerce', array($this, 'render_order_metabox'), 'shop_order', 'side', 'default'); add_meta_box('dhlwc_order_box_hpos', 'DHL eCommerce', array($this, 'render_order_metabox'), 'woocommerce_page_wc-orders', 'side', 'default'); }

    public function render_order_metabox($post_or_order) {
        $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order($post_or_order->ID); if (!$order) { return; }
        $sent = $order->get_meta(self::META_SENT) === 'yes'; $barcode_created = $order->get_meta(self::META_BARCODE_CREATED) === 'yes'; $reference = $order->get_meta(self::META_REFERENCE_ID); $shipment_id = $order->get_meta(self::META_SHIPMENT_ID); $invoice_id = $order->get_meta(self::META_INVOICE_ID); $error = $order->get_meta(self::META_ERROR); $last_stage = $order->get_meta(self::META_LAST_STAGE); $tracking_url = $order->get_meta(self::META_TRACKING_URL); $barcode_response = $this->decode_meta_json($order->get_meta(self::META_BARCODE_RESPONSE));
        echo '<div style="border:1px solid #dcdcde;border-radius:8px;background:#fff;padding:12px;margin:0 0 10px;">';
        echo '<p style="margin-top:0;"><strong>Durum:</strong> ' . esc_html($last_stage ? ($this->stage_labels()[$last_stage] ?? $last_stage) : ($sent ? 'Kargo siparişi oluşturuldu' : 'Bekliyor')) . '</p><p><strong>Referans:</strong> ' . esc_html($reference ?: '-') . '</p><p><strong>Barkod:</strong> ' . esc_html($barcode_created ? 'Oluşturuldu' : 'Bekliyor') . '</p>';
        if ($shipment_id) { echo '<p><strong>Gönderi No:</strong> ' . esc_html($shipment_id) . '</p>'; } if ($invoice_id) { echo '<p><strong>Fatura No:</strong> ' . esc_html($invoice_id) . '</p>'; } if ($tracking_url) { echo '<p><a target="_blank" href="' . esc_url($tracking_url) . '">Kargo takip linki</a></p>'; }
        if (!empty($barcode_response['barcodes']) && is_array($barcode_response['barcodes'])) { echo '<hr><p><strong>Barkodlar</strong></p><ul style="margin-left:16px;">'; foreach ($barcode_response['barcodes'] as $barcode) { echo '<li>Parça ' . esc_html($barcode['pieceNumber'] ?? '-') . ': <code style="font-size:11px;word-break:break-all;">' . esc_html($barcode['value'] ?? '') . '</code></li>'; } echo '</ul>'; }
        echo '</div>'; if ($error) { echo '<div class="notice notice-error inline"><p><strong>Hata:</strong> ' . esc_html($error) . '</p></div>'; }
        echo '<p>Bu kart sipariş eylemleri menüsünden bağımsız çalışır.</p><p>' . $this->order_button($order, 'dhlwc_send_order', 'Kargo Siparişi Oluştur', 'primary', false) . '</p><p>' . $this->order_button($order, 'dhlwc_create_barcode', 'Barkod Oluştur', 'secondary', !$sent) . '</p><p>' . $this->order_button($order, 'dhlwc_check_tracking', 'Kargo Durumunu Kontrol Et', 'secondary', !$sent) . '</p>';
    }

    private function order_button(WC_Order $order, $action, $label, $class, $disabled = false) { if ($disabled) { return '<button type="button" class="button ' . esc_attr($class) . '" disabled="disabled">' . esc_html($label) . '</button>'; } $url = wp_nonce_url(add_query_arg(array('action' => $action, 'order_id' => $order->get_id()), admin_url('admin-post.php')), $action . '_' . $order->get_id()); return '<a class="button ' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>'; }
    private function get_admin_post_order($action) { if (!current_user_can('manage_woocommerce')) { wp_die('Yetkisiz işlem.'); } $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0; if (!$order_id || !check_admin_referer($action . '_' . $order_id)) { wp_die('Güvenlik doğrulaması başarısız.'); } return wc_get_order($order_id); }
    private function redirect_to_order(WC_Order $order, $status) { wp_safe_redirect(add_query_arg('dhlwc_status', sanitize_key($status), $order->get_edit_order_url())); exit; }
    private function decode_meta_json($value) { if (empty($value)) { return array(); } $decoded = json_decode((string) $value, true); return is_array($decoded) ? $decoded : array(); }

    public function handle_test_connection() { if (!current_user_can('manage_woocommerce') || !check_admin_referer('dhlwc_test_connection')) { wp_die('Yetkisiz işlem.'); } $s = $this->get_settings(); delete_transient('dhlwc_token_' . md5($s['environment'] . $s['customer_number'] . $s['client_id'])); $result = $this->get_token(); wp_safe_redirect(admin_url('admin.php?page=woocommerce-dhl-ecommerce&dhlwc_test=' . (is_wp_error($result) ? 'fail' : 'ok'))); exit; }
    public function email_order_meta_fields($fields, $sent_to_admin, $order) { if ($order instanceof WC_Order && $order->get_meta(self::META_REFERENCE_ID)) { $fields['dhlwc_reference'] = array('label' => 'DHL Referans No', 'value' => $order->get_meta(self::META_REFERENCE_ID)); if ($order->get_meta(self::META_SHIPMENT_ID)) { $fields['dhlwc_shipment'] = array('label' => 'DHL Gönderi No', 'value' => $order->get_meta(self::META_SHIPMENT_ID)); } $barcode_response = $this->decode_meta_json($order->get_meta(self::META_BARCODE_RESPONSE)); if (!empty($barcode_response['barcodes'][0]['value'])) { $fields['dhlwc_barcode'] = array('label' => 'DHL Barkod', 'value' => $barcode_response['barcodes'][0]['value']); } } return $fields; }
}

DHL_Ecommerce_For_WooCommerce::instance();
