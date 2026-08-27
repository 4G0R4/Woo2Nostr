<?php
namespace Woo2Nostr\Admin;

use Woo2Nostr\Nostr\EventBuilder;
use Woo2Nostr\Nostr\SignerFactory;
use Woo2Nostr\Sync\Queue;

defined('ABSPATH') || exit;

final class ProductMetaBox {
    public static function init(): void {
        add_action('add_meta_boxes', [self::class, 'boxes']);
        add_action('save_post_product', [self::class, 'save'], 10, 2);
        add_action('wp_ajax_woo2nostr_publish_single', [self::class, 'ajaxPublish']);
        add_action('wp_ajax_woo2nostr_preview', [self::class, 'ajaxPreview']);
        add_filter('manage_edit-product_columns', [self::class, 'columnHeader']);
        add_action('manage_product_posts_custom_column', [self::class, 'columnContent'], 10, 2);
    }

    public static function boxes(): void {
        add_meta_box('woo2nostr', 'Nostr (NIP-99)', [self::class, 'render'], 'product', 'side', 'default');
    }

    public static function render(\WP_Post $post): void {
        $enabled = get_post_meta($post->ID, '_woo2nostr_enabled', true);
        $status = get_post_meta($post->ID, '_woo2nostr_status', true);
        $last = get_post_meta($post->ID, '_woo2nostr_last_sync', true);
        $eventId = get_post_meta($post->ID, '_woo2nostr_last_event_id', true);
        $mode = get_option('woo2nostr_key_mode','server');
        wp_nonce_field('woo2nostr_mb','_woo2nostr_mb');
        echo '<p><label><input type="checkbox" name="_woo2nostr_enabled" value="yes" '.checked($enabled,'yes',false).'> Enable Nostr mirror</label></p>';
        echo '<p class="description">One card per variation; bundles as single composite.</p>';
        if ($status) echo '<p>Status: <code>'.esc_html($status).'</code></p>';
        if ($last) echo '<p>Last sync: '.esc_html(gmdate('Y-m-d H:i', (int)$last)).' UTC</p>';
        if ($eventId) echo '<p>Event: <code style="word-break:break-all">'.esc_html(substr($eventId,0,16)).'…</code></p>';
        echo '<p><button type="button" class="button button-primary" id="woo2nostr-publish" data-id="'.esc_attr((string)$post->ID).'">Publish to Nostr</button> <button type="button" class="button" id="woo2nostr-preview" data-id="'.esc_attr((string)$post->ID).'">Preview tags</button></p>';
        echo '<pre id="woo2nostr-preview-out" style="display:none;max-height:240px;overflow:auto;background:#f6f8fa;padding:8px;font-size:11px"></pre>';
        if ($mode === 'nip07') echo '<p class="description">NIP-07 mode: signing happens in browser.</p>';
        if ($mode === 'bunker') echo '<p class="description">Bunker mode: browser will request signature via bunker relay.</p>';
        echo '<p><a href="'.esc_url(admin_url('admin.php?page=woo2nostr')).'">Settings</a> · Currency: <code>'.esc_html(get_woocommerce_currency()).'</code></p>';
    }

    public static function save(int $postId, \WP_Post $post): void {
        if (!isset($_POST['_woo2nostr_mb']) || !wp_verify_nonce($_POST['_woo2nostr_mb'],'woo2nostr_mb')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        update_post_meta($postId, '_woo2nostr_enabled', isset($_POST['_woo2nostr_enabled']) ? 'yes' : 'no');
    }

    public static function ajaxPreview(): void {
        check_ajax_referer('woo2nostr','nonce');
        if (!current_user_can('edit_products')) wp_send_json_error('forbidden');
        $id = (int) ($_POST['product_id'] ?? 0);
        $p = wc_get_product($id);
        if (!$p) wp_send_json_error('not found');
        $events = EventBuilder::buildForProduct($p, ['shopstr'=>(bool)get_option('woo2nostr_shopstr',1)]);
        wp_send_json_success($events);
    }

    public static function ajaxPublish(): void {
        check_ajax_referer('woo2nostr','nonce');
        if (!current_user_can('edit_products')) wp_send_json_error('forbidden');
        $id = (int) ($_POST['product_id'] ?? 0);
        $mode = get_option('woo2nostr_key_mode','server');
        if ($mode !== 'server') {
            $p = wc_get_product($id);
            if (!$p) wp_send_json_error('not found');
            $events = EventBuilder::buildForProduct($p, ['shopstr'=>(bool)get_option('woo2nostr_shopstr',1)]);
            wp_send_json_success(['need_sign'=>true,'events'=>$events,'mode'=>$mode]);
        }
        $res = Queue::syncProduct($id);
        $res['ok'] ? wp_send_json_success($res) : wp_send_json_error($res);
    }

    public static function columnHeader(array $cols): array {
        $cols['woo2nostr'] = 'Nostr';
        return $cols;
    }
    public static function columnContent(string $col, int $postId): void {
        if ($col !== 'woo2nostr') return;
        $s = get_post_meta($postId,'_woo2nostr_status',true);
        if ($s === 'synced') echo '<span style="color:green">● synced</span>';
        elseif ($s) echo '<span style="color:#d63638">● '.esc_html($s).'</span>';
        else echo '<span style="color:#999">—</span>';
    }
}
