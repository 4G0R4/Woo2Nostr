<?php
/**
 * Plugin Name: Woo2Nostr
 * Plugin URI: https://github.com/4G0R4/Woo2Nostr
 * Description: Mirror WooCommerce products to Nostr NIP-99 (kind 30402) at merchant discretion — selective or bulk, variations & bundles, optional Nostr order inbox.
 * Version: 0.1.0
 * Author: 4G0R4
 * Author URI: https://github.com/4G0R4
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: woo2nostr
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 8.0
 * WC tested up to: 9.5
 * Requires Plugins: woocommerce
 */

defined('ABSPATH') || exit;

define('WOO2NOSTR_VERSION', '0.1.0');
define('WOO2NOSTR_FILE', __FILE__);
define('WOO2NOSTR_DIR', plugin_dir_path(__FILE__));
define('WOO2NOSTR_URL', plugin_dir_url(__FILE__));
define('WOO2NOSTR_BASENAME', plugin_basename(__FILE__));

if (file_exists(WOO2NOSTR_DIR . 'vendor/autoload.php')) {
    require_once WOO2NOSTR_DIR . 'vendor/autoload.php';
}

add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

add_action('plugins_loaded', function () {
    load_plugin_textdomain('woo2nostr', false, dirname(WOO2NOSTR_BASENAME) . '/languages');
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>' . esc_html__('Woo2Nostr requires WooCommerce to be active.', 'woo2nostr') . '</p></div>';
        });
        return;
    }
    require_once WOO2NOSTR_DIR . 'includes/Plugin.php';
    \Woo2Nostr\Plugin::instance();
});

register_activation_hook(__FILE__, function () {
    if (!class_exists('WooCommerce')) {
        wp_die(esc_html__('Woo2Nostr requires WooCommerce.', 'woo2nostr'));
    }
    add_option('woo2nostr_version', WOO2NOSTR_VERSION);
    if (!wp_next_scheduled('woo2nostr_poll_inbox')) {
        wp_schedule_event(time() + 120, 'every_two_minutes', 'woo2nostr_poll_inbox');
    }
});

add_filter('cron_schedules', function ($s) {
    $s['every_two_minutes'] = ['interval' => 120, 'display' => 'Every 2 Minutes'];
    return $s;
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('woo2nostr_poll_inbox');
    if (function_exists('as_unschedule_all_actions')) {
        as_unschedule_all_actions('woo2nostr_sync_product', [], 'woo2nostr');
        as_unschedule_all_actions('woo2nostr_poll_inbox_action', [], 'woo2nostr');
    }
});
