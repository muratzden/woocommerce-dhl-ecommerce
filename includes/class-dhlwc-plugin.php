<?php
if (!defined('ABSPATH')) { exit; }

final class DHLWC_Plugin {
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        add_action('plugins_loaded', array($this, 'boot'));
    }

    public static function activate() {
        add_filter('cron_schedules', array(__CLASS__, 'cron_schedules'));
        if (!wp_next_scheduled(DHLWC_Constants::CRON_HOOK)) {
            wp_schedule_event(time() + 300, 'dhlwc_15min', DHLWC_Constants::CRON_HOOK);
        }
    }

    public static function deactivate() {
        $timestamp = wp_next_scheduled(DHLWC_Constants::CRON_HOOK);
        if ($timestamp) { wp_unschedule_event($timestamp, DHLWC_Constants::CRON_HOOK); }
    }

    public function boot() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }
        add_filter('cron_schedules', array(__CLASS__, 'cron_schedules'));
        (new DHLWC_Admin())->register_hooks();
        (new DHLWC_Label())->register_hooks();
        (new DHLWC_Orders())->register_hooks();
    }

    public static function cron_schedules($schedules) {
        $schedules['dhlwc_15min'] = array('interval' => 15 * MINUTE_IN_SECONDS, 'display' => 'Every 15 minutes');
        return $schedules;
    }

    public function woocommerce_missing_notice() {
        echo '<div class="notice notice-error"><p><strong>ShipPilot for WooCommerce</strong> requires WooCommerce to be active.</p></div>';
    }
}
