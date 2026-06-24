<?php
if (!defined('ABSPATH')) { exit; }

final class DHLWC_Admin {
    public function register_hooks() {
        add_action('admin_menu', array($this, 'register_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_post_dhlwc_test_connection', array($this, 'handle_test_connection'));
        add_filter('plugin_action_links_' . plugin_basename(DHLWC_FILE), array($this, 'plugin_action_links'));
    }

    public function register_menu() {
        add_menu_page('DHL eCommerce', 'DHL eCommerce', 'manage_woocommerce', 'dhl-ecommerce-for-woocommerce', array($this, 'render_settings_page'), 'dashicons-location-alt', 56);
    }

    public function register_settings() {
        register_setting('dhlwc_settings_group', DHLWC_Constants::OPTION_KEY, array('DHLWC_Settings', 'sanitize'));
    }

    public function plugin_action_links($links) {
        array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=woocommerce-dhl-ecommerce')) . '">Ayarlar</a>');
        return $links;
    }

    public function render_settings_page() {
        if (!current_user_can('manage_woocommerce')) { return; }
        $s = DHLWC_Settings::get();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin tab navigation.
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'settings';
        echo '<div class="wrap"><h1>DHL eCommerce for WooCommerce</h1>';
        echo '<nav class="nav-tab-wrapper">';
        foreach (array('settings'=>'API Ayarları','emails'=>'Müşteri Mailleri','barcode'=>'Barkod Entegrasyonu','label'=>'Etiket Tasarımı','help'=>'Yardım') as $key => $label) {
            echo '<a class="nav-tab ' . ($tab === $key ? 'nav-tab-active' : '') . '" href="' . esc_url(admin_url('admin.php?page=woocommerce-dhl-ecommerce&tab=' . $key)) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
        if ($tab === 'emails') { $this->render_emails($s); }
        elseif ($tab === 'barcode') { $this->render_barcode($s); }
        elseif ($tab === 'label') { $this->render_label($s); }
        elseif ($tab === 'help') { $this->render_help(); }
        else { $this->render_main($s); }
        echo '</div>';
    }

    private function render_main($s) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag after admin-post redirect.
        if (isset($_GET['dhlwc_test'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag after admin-post redirect.
            $status = sanitize_key(wp_unslash($_GET['dhlwc_test']));
            if ($status === 'ok') {
                echo '<div class="notice notice-success"><p>Token testi başarılı. Bu test gerçek DHL/MNG token isteği gönderir.</p></div>';
            } elseif ($status === 'fail') {
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice text after admin-post redirect.
                $message = isset($_GET['dhlwc_message']) ? sanitize_text_field(wp_unslash($_GET['dhlwc_message'])) : 'Token testi başarısız.';
                echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
            }
        }
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('dhlwc_settings_group'); ?>
            <table class="form-table" role="presentation">
                <tr><th>Ortam</th><td><select name="<?php echo esc_attr(DHLWC_Constants::OPTION_KEY); ?>[environment]"><option value="test" <?php selected($s['environment'], 'test'); ?>>Sandbox / Test</option><option value="production" <?php selected($s['environment'], 'production'); ?>>Production / Canlı</option></select></td></tr>
                <tr><th>DHL Müşteri No</th><td><input class="regular-text" name="<?php echo esc_attr(DHLWC_Constants::OPTION_KEY); ?>[customer_number]" value="<?php echo esc_attr($s['customer_number']); ?>"></td></tr>
                <tr><th>DHL Şifre</th><td><input type="password" class="regular-text" name="<?php echo esc_attr(DHLWC_Constants::OPTION_KEY); ?>[customer_password]" value="<?php echo esc_attr($s['customer_password']); ?>"></td></tr>
                <tr><th>Client ID</th><td><input class="regular-text" name="<?php echo esc_attr(DHLWC_Constants::OPTION_KEY); ?>[client_id]" value="<?php echo esc_attr($s['client_id']); ?>"></td></tr>
                <tr><th>Client Secret</th><td><input type="password" class="regular-text" name="<?php echo esc_attr(DHLWC_Constants::OPTION_KEY); ?>[client_secret]" value="<?php echo esc_attr($s['client_secret']); ?>"></td></tr>
                <tr><th>Otomatik gönderi oluştur</th><td><label><input type="checkbox" name="<?php echo esc_attr(DHLWC_Constants::OPTION_KEY); ?>[auto_send]" value="yes" <?php checked($s['auto_send'], 'yes'); ?>> Processing durumunda DHL'e aktar</label></td></tr>
                <tr><th>createRecipient kullan</th><td><label><input type="checkbox" name="<?php echo esc_attr(DHLWC_Constants::OPTION_KEY); ?>[prepare_recipient]" value="yes" <?php checked($s['prepare_recipient'], 'yes'); ?>> Barkoddan önce varış şube tespiti için alıcıyı hazırla</label></td></tr>
                <tr><th>Takip senkronizasyonu</th><td><label><input type="checkbox" name="<?php echo esc_attr(DHLWC_Constants::OPTION_KEY); ?>[tracking_enabled]" value="yes" <?php checked($s['tracking_enabled'], 'yes'); ?>> Aktif</label></td></tr>
                <tr><th>Varsayılan Desi / Kg</th><td><input type="number" min="1" name="<?php echo esc_attr(DHLWC_Constants::OPTION_KEY); ?>[default_desi]" value="<?php echo esc_attr($s['default_desi']); ?>" style="width:80px"> <input type="number" min="1" name="<?php echo esc_attr(DHLWC_Constants::OPTION_KEY); ?>[default_kg]" value="<?php echo esc_attr($s['default_kg']); ?>" style="width:80px"></td></tr>
                <tr><th>Paket Tipi</th><td><select name="<?php echo esc_attr(DHLWC_Constants::OPTION_KEY); ?>[packaging_type]"><option value="1" <?php selected($s['packaging_type'], '1'); ?>>Dosya</option><option value="2" <?php selected($s['packaging_type'], '2'); ?>>Mİ</option><option value="3" <?php selected($s['packaging_type'], '3'); ?>>Paket</option></select></td></tr>
                <tr><th>İçerik</th><td><input class="regular-text" name="<?php echo esc_attr(DHLWC_Constants::OPTION_KEY); ?>[content_text]" value="<?php echo esc_attr($s['content_text']); ?>"></td></tr>
            </table>
            <?php $this->hidden_defaults($s, array('environment','customer_number','customer_password','client_id','client_secret','auto_send','prepare_recipient','tracking_enabled','default_desi','default_kg','packaging_type','content_text')); submit_button('Kaydet'); ?>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('dhlwc_test_connection'); ?><input type="hidden" name="action" value="dhlwc_test_connection"><?php submit_button('Token Bağlantısını Test Et', 'secondary'); ?></form>
    <?php }

    private function render_barcode($s) { ?>
        <form method="post" action="options.php">
            <?php settings_fields('dhlwc_settings_group'); ?>
            <p><strong>Son Barkod Entegrasyonu</strong></p>
            <p>Bu modül DHL tarafından Son Barkod Entegrasyonu yetkisi verilen hesaplarda kullanılmalıdır. Doğru akış: createRecipient → createOrder → getorder/getshipment doğrulama → createbarcode.</p>
            <table class="form-table" role="presentation">
                <tr><th>Otomatik barkod</th><td><label><input type="checkbox" name="<?php echo esc_attr(DHLWC_Constants::OPTION_KEY); ?>[auto_barcode]" value="yes" <?php checked($s['auto_barcode'], 'yes'); ?>> Gönderi oluşturulduktan sonra gecikmeli barkod dene</label></td></tr>
                <tr><th>Not</th><td>Parça barkodu createOrder ve createbarcode içinde birebir aynı tutulur.</td></tr>
            </table>
            <?php $this->hidden_defaults($s, array('auto_barcode')); submit_button('Kaydet'); ?>
        </form>
    <?php }


    private function render_label($s) { ?>
        <form method="post" action="options.php">
            <?php settings_fields('dhlwc_settings_group'); ?>
            <p><strong>A4 Etiket Tasarımı</strong></p>
            <p>Bu ayarlar sipariş ekranındaki <strong>Etiket Yazdır</strong> çıktısında kullanılır. Çıktı A4 sayfaya göre hazırlanır.</p>
            <table class="form-table" role="presentation">
                <tr><th>Logo URL</th><td><input class="regular-text" name="<?php echo esc_attr(DHLWC_Constants::OPTION_KEY); ?>[label_logo_url]" value="<?php echo esc_attr($s['label_logo_url']); ?>"><p class="description">Boş bırakılırsa metin başlığı kullanılır.</p></td></tr>
                <tr><th>Gönderen adı</th><td><input class="regular-text" name="<?php echo esc_attr(DHLWC_Constants::OPTION_KEY); ?>[label_sender_name]" value="<?php echo esc_attr($s['label_sender_name']); ?>"></td></tr>
                <tr><th>Gönderen adresi</th><td><textarea class="large-text" rows="3" name="<?php echo esc_attr(DHLWC_Constants::OPTION_KEY); ?>[label_sender_address]"><?php echo esc_textarea($s['label_sender_address']); ?></textarea></td></tr>
                <tr><th>Gönderen telefonu</th><td><input class="regular-text" name="<?php echo esc_attr(DHLWC_Constants::OPTION_KEY); ?>[label_sender_phone]" value="<?php echo esc_attr($s['label_sender_phone']); ?>"></td></tr>
                <tr><th>Vurgu rengi</th><td><input type="color" name="<?php echo esc_attr(DHLWC_Constants::OPTION_KEY); ?>[label_accent_color]" value="<?php echo esc_attr($s['label_accent_color']); ?>"></td></tr>
                <tr><th>Alt not</th><td><textarea class="large-text" rows="3" name="<?php echo esc_attr(DHLWC_Constants::OPTION_KEY); ?>[label_note]"><?php echo esc_textarea($s['label_note']); ?></textarea></td></tr>
            </table>
            <?php $this->hidden_defaults($s, array('label_logo_url','label_sender_name','label_sender_address','label_sender_phone','label_note','label_accent_color')); submit_button('Etiket Tasarımını Kaydet'); ?>
        </form>
    <?php }

    private function render_emails($s) { ?>
        <form method="post" action="options.php">
            <?php settings_fields('dhlwc_settings_group'); ?>
            <p>DHL mailleri WooCommerce'in kendi e-posta header/footer/stil sistemini kullanır. Burada sadece konu ve içerik düzenlenir.</p>
            <table class="form-table" role="presentation">
                <tr><th>Mail gönderimi</th><td><label><input type="checkbox" name="<?php echo esc_attr(DHLWC_Constants::OPTION_KEY); ?>[tracking_email_enabled]" value="yes" <?php checked($s['tracking_email_enabled'], 'yes'); ?>> Aktif</label></td></tr>
                <?php foreach (array('prepared'=>'Hazırlandı','shipped'=>'Kargoya teslim','branch'=>'Varış şubesi','delivery'=>'Dağıtım','delivered'=>'Teslim edildi') as $stage => $label): ?>
                    <tr><th><?php echo esc_html($label); ?> konusu</th><td><input class="large-text" name="<?php echo esc_attr(DHLWC_Constants::OPTION_KEY); ?>[email_subject_<?php echo esc_attr($stage); ?>]" value="<?php echo esc_attr($s['email_subject_' . $stage]); ?>"></td></tr>
                    <tr><th><?php echo esc_html($label); ?> içeriği</th><td><textarea class="large-text" rows="5" name="<?php echo esc_attr(DHLWC_Constants::OPTION_KEY); ?>[email_body_<?php echo esc_attr($stage); ?>]"><?php echo esc_textarea($s['email_body_' . $stage]); ?></textarea></td></tr>
                <?php endforeach; ?>
            </table>
            <?php $this->hidden_defaults($s, array('tracking_email_enabled','email_subject_prepared','email_body_prepared','email_subject_shipped','email_body_shipped','email_subject_branch','email_body_branch','email_subject_delivery','email_body_delivery','email_subject_delivered','email_body_delivered')); submit_button('Kaydet'); ?>
        </form>
    <?php }

    private function render_help() { ?>
        <h2>Kurulum Yardımı</h2>
        <p><a class="button button-primary" href="https://sandbox.mngkargo.com.tr/" target="_blank" rel="noopener">Sandbox Portal</a> <a class="button" href="https://apizone.mngkargo.com.tr/" target="_blank" rel="noopener">Apizone Production Portal</a></p>
        <ol>
            <li>Sandbox veya Apizone üzerinde uygulama oluşturun.</li>
            <li>Identity, Plus Command, Standard Command, Standard Query ve Barcode Command API ürünlerine abone olun.</li>
            <li>Client ID ve Client Secret değerlerini eklenti ayarlarına girin.</li>
            <li>DHL müşteri numarası ve API/Online Şube şifresi ile token testi yapın.</li>
            <li>Barkod için DHL'in önerdiği akış: createRecipient → createOrder → createbarcode.</li>
        </ol>
        <p><strong>Yaygın hata:</strong> 20011 genellikle barkod servisi için varış şube tespiti veya sipariş/parça barkodu eşleşmesi hazır olmadığında görülür.</p>
    <?php }

    private function hidden_defaults($s, $skip = array()) {
        $skip = array_flip(array_merge($skip, array('customer_password', 'client_secret')));
        foreach (DHLWC_Settings::defaults() as $key => $default) {
            if (isset($skip[$key])) { continue; }
            $value = isset($s[$key]) ? $s[$key] : $default;
            echo '<input type="hidden" name="' . esc_attr(DHLWC_Constants::OPTION_KEY) . '[' . esc_attr($key) . ']" value="' . esc_attr($value) . '">';
        }
    }

    public function handle_test_connection() {
        if (!current_user_can('manage_woocommerce') || !check_admin_referer('dhlwc_test_connection')) { wp_die('Yetkisiz işlem.'); }
        $client = new DHLWC_API_Client(DHLWC_Settings::get());
        $result = $client->token(true);
        $args = array('page' => 'dhl-ecommerce-for-woocommerce', 'dhlwc_test' => is_wp_error($result) ? 'fail' : 'ok');
        if (is_wp_error($result)) {
            $args['dhlwc_message'] = rawurlencode($result->get_error_message());
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }
}
