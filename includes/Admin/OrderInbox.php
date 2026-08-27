<?php
namespace Woo2Nostr\Admin;

defined('ABSPATH') || exit;

final class OrderInbox {
    public static function init(): void {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('add_meta_boxes', [self::class, 'orderBox']);
    }

    public static function menu(): void {
        add_submenu_page('woocommerce', 'Nostr Orders', 'Nostr Orders', 'manage_woocommerce', 'woo2nostr-orders', [self::class, 'render']);
    }

    public static function orderBox(): void {
        $screen = function_exists('wc_get_container') ? \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::custom_orders_table_usage_is_enabled() : false;
        $hook = $screen ? 'woocommerce_page_wc-orders' : 'shop_order';
        add_meta_box('woo2nostr_order', 'Nostr', [self::class, 'renderOrderBox'], $hook, 'side');
    }

    public static function renderOrderBox($postOrOrder): void {
        $order = $postOrOrder instanceof \WP_Post ? wc_get_order($postOrOrder->ID) : wc_get_order($postOrOrder);
        if (!$order) return;
        $nid = $order->get_meta('_woo2nostr_order_id');
        $buyer = $order->get_meta('_woo2nostr_buyer_pubkey');
        if (!$nid && !$buyer) { echo '<p>No Nostr data.</p>'; return; }
        echo '<p><strong>Nostr order:</strong> <code>'.esc_html($nid).'</code></p>';
        if ($buyer) echo '<p><strong>Buyer:</strong> <code style="word-break:break-all">'.esc_html(substr($buyer,0,16)).'…</code></p>';
        if ($order->get_meta('_woo2nostr_paid_via_nostr')) echo '<p style="color:green">Paid via Nostr receipt</p>';
    }

    public static function render(): void {
        $orders = wc_get_orders(['meta_key'=>'_woo2nostr_order_id','limit'=>50,'orderby'=>'date','order'=>'DESC']);
        echo '<div class="wrap"><h1>Nostr Orders</h1>';
        $mode = get_option('woo2nostr_key_mode','server');
        if ($mode !== 'server') echo '<div class="notice notice-info inline"><p>NIP-07/Bunker mode: polling disabled. Orders require server nsec polling.</p></div>';
        echo '<p>Polling inbox creates Woo orders from NIP-17 gift-wrapped kind 16/17. Last poll: '.esc_html(gmdate('Y-m-d H:i', (int)get_option('woo2nostr_last_poll',0))).' UTC</p>';
        echo '<p><a href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=woo2nostr_poll_now'), 'woo2nostr_poll')).'" class="button">Poll now</a></p>';
        add_action('admin_post_woo2nostr_poll_now', function(){ \Woo2Nostr\Orders\Inbox::poll(); wp_redirect(admin_url('admin.php?page=woo2nostr-orders')); exit; });
        if (empty($orders)) { echo '<p>No Nostr orders yet.</p></div>'; return; }
        echo '<table class="widefat"><thead><tr><th>Order</th><th>Nostr ID</th><th>Buyer</th><th>Date</th><th>Status</th></tr></thead><tbody>';
        foreach ($orders as $o) {
            echo '<tr><td><a href="'.esc_url($o->get_edit_order_url()).'">#'.esc_html($o->get_id()).'</a></td><td><code>'.esc_html($o->get_meta('_woo2nostr_order_id')).'</code></td><td><code>'.esc_html(substr($o->get_meta('_woo2nostr_buyer_pubkey'),0,8)).'</code></td><td>'.esc_html($o->get_date_created()->date('Y-m-d H:i')).'</td><td>'.esc_html($o->get_status()).'</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
