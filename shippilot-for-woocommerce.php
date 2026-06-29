<?php
/**
 * Plugin Name: ShipPilot for WooCommerce
 * Plugin URI: https://github.com/muratzden/shippilot-for-woocommerce
 * Description: Independent WooCommerce shipping integration for DHL eCommerce / MNG Kargo services: recipient preparation, order transfer, barcode generation, tracking sync and customer emails.
 * Version: 1.1.8
 * Author: Murat Özden
 * Author URI: https://profiles.wordpress.org/muratzden/
 * Text Domain: shippilot-for-woocommerce
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * Requires Plugins: woocommerce
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) { exit; }

define('DHLWC_VERSION', '1.1.8');
define('DHLWC_FILE', __FILE__);
define('DHLWC_DIR', plugin_dir_path(__FILE__));
define('DHLWC_URL', plugin_dir_url(__FILE__));

add_action('before_woocommerce_init', function () {
    if (class_exists('Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
        Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', DHLWC_FILE, true);
    }
});

require_once DHLWC_DIR . 'includes/class-dhlwc-constants.php';
require_once DHLWC_DIR . 'includes/class-dhlwc-settings.php';
require_once DHLWC_DIR . 'includes/class-dhlwc-api-client.php';
require_once DHLWC_DIR . 'includes/class-dhlwc-payload-builder.php';
require_once DHLWC_DIR . 'includes/class-dhlwc-email.php';
require_once DHLWC_DIR . 'includes/class-dhlwc-label.php';
require_once DHLWC_DIR . 'includes/class-dhlwc-orders.php';
require_once DHLWC_DIR . 'includes/class-dhlwc-admin.php';
require_once DHLWC_DIR . 'includes/class-dhlwc-plugin.php';

register_activation_hook(__FILE__, array('DHLWC_Plugin', 'activate'));
register_deactivation_hook(__FILE__, array('DHLWC_Plugin', 'deactivate'));

DHLWC_Plugin::instance();
