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
        add_action('restrict_manage_posts', [self::class, 'statusFilter']);
        add_action('pre_get_posts', [self::class, 'filterProducts']);
    }

    public static function boxes(): void {
        add_meta_box('woo2nostr', 'Nostr (NIP-99)', [self::class, 'render'], 'product', 'side', 'default');
    }

    public static function render(\WP_Post $post): void {
        $enabled = get_post_meta($post->ID, '_woo2nostr_enabled', true);
        $excluded = get_post_meta($post->ID, '_woo2nostr_excluded', true);
        $status = get_post_meta($post->ID, '_woo2nostr_status', true);
        $last = get_post_meta($post->ID, '_woo2nostr_last_sync', true);
        $eventId = get_post_meta($post->ID, '_woo2nostr_last_event_id', true);
        $mode = get_option('woo2nostr_key_mode','server');
        wp_nonce_field('woo2nostr_mb','_woo2nostr_mb');
        echo '<p><label><input type="checkbox" name="_woo2nostr_enabled" value="yes" '.checked($enabled,'yes',false).'> Enable Nostr mirror</label></p>';
        echo '<p><label><input type="checkbox" name="_woo2nostr_excluded" value="yes" '.checked($excluded,'yes',false).'> Exclude from sync/bulk queue</label></p>';
        if ($excluded === 'yes') echo '<p style="color:#b26b00"><strong>Excluded</strong> — skipped by “All published products” and auto-sync, but the Publish button still works.</p>';
        $pubMode = get_post_meta($post->ID, '_woo2nostr_publish_mode', true) ?: 'all_variations';
        $groupAttr = get_post_meta($post->ID, '_woo2nostr_group_attribute', true);
        $product = wc_get_product($post->ID);
        if ($product && $product->is_type('variable')) {
            echo '<p><label>Publish mode<br><select name="_woo2nostr_publish_mode" id="woo2nostr-publish-mode">';
            echo '<option value="all_variations" '.selected($pubMode,'all_variations',false).'>One card per variation</option>';
            echo '<option value="per_attribute" '.selected($pubMode,'per_attribute',false).'>One card per attribute value (choose below)</option>';
            echo '<option value="one_per_product" '.selected($pubMode,'one_per_product',false).'>One card for the whole product</option>';
            echo '</select></label></p>';
            $attrs = $product->get_attributes();
            echo '<p id="woo2nostr-group-attr-wrap" style="'.($pubMode === 'per_attribute' ? '' : 'display:none').'"><label>Group attribute<br><select name="_woo2nostr_group_attribute">';
            echo '<option value="">— select attribute —</option>';
            foreach ((array) $attrs as $k => $a) {
                $name = $a instanceof \WC_Product_Attribute ? $a->get_name() : $k;
                echo '<option value="'.esc_attr((string) $k).'" '.selected($groupAttr, (string) $k, false).'>'.esc_html($name).'</option>';
            }
            echo '</select></label></p>';
        }
        echo '<p class="description">One card per variation; bundles as single composite.</p>';
        if ($status) echo '<p>Status: <code>'.esc_html($status).'</code></p>';
        if ($last) echo '<p>Last sync: '.esc_html(gmdate('Y-m-d H:i', (int)$last)).' UTC</p>';
        if ($eventId) echo '<p>Event: <code style="word-break:break-all">'.esc_html(substr($eventId,0,16)).'…</code></p>';
        echo '<p><button type="button" class="button button-primary" id="woo2nostr-publish" data-id="'.esc_attr((string)$post->ID).'">Publish to Nostr</button> <button type="button" class="button" id="woo2nostr-preview" data-id="'.esc_attr((string)$post->ID).'">Preview tags</button></p>';
        echo '<p id="woo2nostr-publish-status" style="display:none;margin-top:6px"></p>';
        echo '<pre id="woo2nostr-preview-out" style="display:none;max-height:240px;overflow:auto;background:#f6f8fa;padding:8px;font-size:11px"></pre>';
        if ($mode === 'nip07') {
            echo '<p><button type="button" class="button" id="woo2nostr-connect-nip07-mb">Connect with Extension</button> <span id="woo2nostr-connect-mb-result" style="margin-left:6px"></span></p>';
            echo '<p class="description">NIP-07 mode: signing happens in browser via window.nostr. Connect first, then publish.</p>';
        }
        if ($mode === 'bunker') echo '<p class="description">Bunker mode: browser will request signature via bunker relay.</p>';
        echo '<p><a href="'.esc_url(admin_url('admin.php?page=woo2nostr')).'">Settings</a> · Currency: <code>'.esc_html(get_woocommerce_currency()).'</code></p>';
    }

    public static function save(int $postId, \WP_Post $post): void {
        if (!isset($_POST['_woo2nostr_mb']) || !wp_verify_nonce($_POST['_woo2nostr_mb'],'woo2nostr_mb')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        update_post_meta($postId, '_woo2nostr_enabled', isset($_POST['_woo2nostr_enabled']) ? 'yes' : 'no');
        update_post_meta($postId, '_woo2nostr_excluded', isset($_POST['_woo2nostr_excluded']) ? 'yes' : 'no');
        if (isset($_POST['_woo2nostr_publish_mode'])) {
            $m = sanitize_key($_POST['_woo2nostr_publish_mode']);
            update_post_meta($postId, '_woo2nostr_publish_mode', in_array($m, ['all_variations', 'per_attribute', 'one_per_product'], true) ? $m : 'all_variations');
        }
        if (isset($_POST['_woo2nostr_group_attribute'])) {
            update_post_meta($postId, '_woo2nostr_group_attribute', sanitize_key($_POST['_woo2nostr_group_attribute']));
        }
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
        if (get_post_meta($id, '_woo2nostr_excluded', true) === 'yes') wp_send_json_error('excluded');
        if ($mode !== 'server') {
            $p = wc_get_product($id);
            if (!$p) wp_send_json_error('not found');
            $events = EventBuilder::buildForProduct($p, ['shopstr'=>(bool)get_option('woo2nostr_shopstr',1)]);
            wp_send_json_success(['need_sign'=>true,'events'=>$events,'mode'=>$mode,'previous_ds'=>Queue::publishedDs($id)]);
        }
        $res = Queue::syncProduct($id);
        $res['ok'] ? wp_send_json_success($res) : wp_send_json_error($res);
    }

    public static function columnHeader(array $cols): array {
        $cols['woo2nostr'] = 'Nostr';
        return $cols;
    }

    private static $publishedMap = null;

    private static function publishedMap(): array {
        if (self::$publishedMap === null) {
            self::$publishedMap = [];
            global $wpdb;
            $rows = $wpdb->get_results("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key='_woo2nostr_last_event_id' AND meta_value <> ''");
            foreach ($rows as $r) self::$publishedMap[(int) $r->post_id] = $r->meta_value;
        }
        return self::$publishedMap;
    }

    public static function columnContent(string $col, int $postId): void {
        if ($col !== 'woo2nostr') return;
        $s = get_post_meta($postId, '_woo2nostr_status', true);
        $err = get_post_meta($postId, '_woo2nostr_last_error', true);
        $map = self::publishedMap();
        $type = get_post_meta($postId, '_product_type', true);

        if ($type === 'variable') {
            $product = wc_get_product($postId);
            $children = $product ? (array) $product->get_children() : [];
            $total = count($children);
            $publishedKids = 0;
            foreach ($children as $vid) if (isset($map[(int) $vid])) $publishedKids++;
            $parentPublished = isset($map[$postId]) || $s === 'synced';
            if ($total > 0) {
                $label = sprintf('%d/%d vars', $publishedKids, $total);
                $color = $publishedKids === $total && $parentPublished ? 'green' : ($publishedKids > 0 ? '#b26b00' : '#999');
            } else {
                $label = $parentPublished ? 'synced' : '—';
                $color = $parentPublished ? 'green' : '#999';
            }
            if ($s === 'failed') { $color = '#d63638'; $label = 'failed · ' . $label; }
            if ($s === 'excluded') { $color = '#b26b00'; $label = 'excluded'; }
            echo '<span title="'.esc_attr($err).'" style="color:'.$color.'">● '.esc_html($label).'</span>';
            return;
        }

        if ($s === 'excluded') echo '<span style="color:#b26b00">● excluded</span>';
        elseif ($s === 'synced') echo '<span style="color:green">● synced</span>';
        elseif ($s === 'failed') echo '<span title="'.esc_attr($err).'" style="color:#d63638;cursor:help">● failed</span>';
        elseif ($s === 'no_key') echo '<span title="'.esc_attr($err).'" style="color:#d63638">● no_key</span>';
        elseif ($s) echo '<span title="'.esc_attr($err).'" style="color:#d63638">● '.esc_html($s).'</span>';
        else echo '<span style="color:#999">—</span>';
    }

    public static function statusFilter(string $postType): void {
        if ($postType !== 'product') return;
        $selected = sanitize_key($_GET['woo2nostr_status'] ?? '');
        echo '<select name="woo2nostr_status">';
        echo '<option value="">'.esc_html__('All Nostr statuses','woo2nostr').'</option>';
        echo '<option value="published" '.selected($selected,'published',false).'>● Published</option>';
        echo '<option value="unpublished" '.selected($selected,'unpublished',false).'>— Unpublished</option>';
        echo '<option value="failed" '.selected($selected,'failed',false).'>● failed</option>';
        echo '<option value="pending" '.selected($selected,'pending',false).'>● pending_nip07</option>';
        echo '<option value="excluded" '.selected($selected,'excluded',false).'>● excluded</option>';
        echo '</select>';
    }

    public static function filterProducts(\WP_Query $q): void {
        if (!is_admin() || $q->get('post_type') !== 'product') return;
        $v = sanitize_key($_GET['woo2nostr_status'] ?? '');
        if ($v === '') return;
        $mq = $q->get('meta_query') ?: [];
        if ($v === 'published') $mq[] = ['key' => '_woo2nostr_last_event_id', 'compare' => 'EXISTS'];
        elseif ($v === 'unpublished') $mq[] = ['key' => '_woo2nostr_last_event_id', 'compare' => 'NOT EXISTS'];
        elseif ($v === 'failed') $mq[] = ['key' => '_woo2nostr_status', 'value' => 'failed'];
        elseif ($v === 'pending') $mq[] = ['key' => '_woo2nostr_status', 'value' => 'pending_nip07'];
        elseif ($v === 'excluded') $mq[] = ['key' => '_woo2nostr_excluded', 'value' => 'yes'];
        else return;
        $q->set('meta_query', $mq);
    }
}
