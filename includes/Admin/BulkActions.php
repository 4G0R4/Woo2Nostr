<?php
namespace Woo2Nostr\Admin;

use Woo2Nostr\Sync\Queue;

defined('ABSPATH') || exit;

final class BulkActions {
    public static function init(): void {
        add_filter('bulk_actions-edit-product', [self::class, 'bulkActions']);
        add_filter('handle_bulk_actions-edit-product', [self::class, 'handleBulk'], 10, 3);
        add_action('admin_notices', [self::class, 'notice']);
        add_action('admin_menu', [self::class, 'bulkPage']);
        add_action('wp_ajax_woo2nostr_bulk_publish', [self::class, 'ajaxBulk']);
        add_action('wp_ajax_woo2nostr_preview_bulk', [self::class, 'ajaxPreviewBulk']);
    }

    public static function bulkActions(array $actions): array {
        $actions['woo2nostr_publish'] = __('Publish to Nostr', 'woo2nostr');
        $actions['woo2nostr_unpublish'] = __('Unpublish (draft 30403)', 'woo2nostr');
        return $actions;
    }

    public static function handleBulk(string $redirect, string $action, array $ids): string {
        if ($action === 'woo2nostr_publish') {
            $mode = get_option('woo2nostr_key_mode', 'server');
            if ($mode !== 'server') {
                foreach ($ids as $id) update_post_meta((int)$id, '_woo2nostr_status', 'pending_nip07');
                $redirect = add_query_arg(['woo2nostr_bulk'=>'nip07_bulk','woo2nostr_count'=>count($ids)], $redirect);
            } else {
                Queue::enqueueBulk($ids);
                $redirect = add_query_arg(['woo2nostr_bulk'=>'queued','woo2nostr_count'=>count($ids)], $redirect);
            }
        }
        if ($action === 'woo2nostr_unpublish') {
            foreach ($ids as $id) {
                update_post_meta((int)$id, '_woo2nostr_status', 'draft');
            }
            $redirect = add_query_arg(['woo2nostr_bulk'=>'draft','woo2nostr_count'=>count($ids)], $redirect);
        }
        return $redirect;
    }

    public static function notice(): void {
        if (empty($_GET['woo2nostr_bulk'])) return;
        $count = (int) ($_GET['woo2nostr_count'] ?? 0);
        $type = sanitize_text_field($_GET['woo2nostr_bulk']);
        if ($type === 'nip07_bulk') {
            echo '<div class="notice notice-warning is-dismissible"><p>'.esc_html(sprintf(__('%d products marked pending_nip07 — bulk queue requires server nsec (or php-gmp). In NIP-07 mode use Woo > Nostr Bulk > Publish all via Extension (browser) or per-product Publish button.', 'woo2nostr'), $count)).'</p></div>'; return;
        }
        $msg = $type === 'queued' ? sprintf(__('%d products queued for Nostr publish (Action Scheduler).', 'woo2nostr'), $count) : sprintf(__('%d products marked draft.', 'woo2nostr'), $count);
        echo '<div class="notice notice-success is-dismissible"><p>'.esc_html($msg).' <a href="'.esc_url(admin_url('admin.php?page=wc-status&tab=action-scheduler')).'">View queue</a></p></div>';
    }

    public static function bulkPage(): void {
        add_submenu_page('woocommerce', 'Nostr Bulk Sync', 'Nostr Bulk', 'manage_woocommerce', 'woo2nostr-bulk', [self::class, 'renderBulk']);
    }

    public static function renderBulk(): void {
        $mode = get_option('woo2nostr_key_mode', 'server');
        $isNip07 = $mode !== 'server';
        if (isset($_POST['_woo2nostr_bulk_nonce']) && wp_verify_nonce($_POST['_woo2nostr_bulk_nonce'],'woo2nostr_bulk')) {
            $scope = sanitize_text_field($_POST['scope'] ?? 'selected');
            $ids = [];
            if ($scope === 'all') {
                $q = wc_get_products(['limit'=>-1,'status'=>['publish'],'return'=>'ids']);
                $ids = $q;
            } else {
                $raw = sanitize_text_field($_POST['ids'] ?? '');
                $ids = array_filter(array_map('intval', explode(',', $raw)));
            }
            if ($ids) {
                if ($isNip07) {
                    echo '<div class="notice notice-warning inline"><p>'.esc_html__('NIP-07/Bunker mode: bulk must be published via browser extension — use the button below.', 'woo2nostr').'</p></div>';
                } else {
                    Queue::enqueueBulk($ids);
                    echo '<div class="notice notice-success inline"><p>'.esc_html(sprintf(__('%d products queued.', 'woo2nostr'), count($ids))).'</p></div>';
                }
            }
        }
        $hasGmp = extension_loaded('gmp'); $hasSecp = extension_loaded('secp256k1');
        ?>
        <div class="wrap">
            <h1>Nostr Bulk Sync</h1>
            <p>Publish many products at once. One Nostr card per variation; bundles as single composite.</p>
            <?php if ($isNip07): ?>
                <div class="notice notice-info inline"><p><?php esc_html_e('Mode is NIP-07/Bunker — server queue is disabled. Use the browser button below to publish via window.nostr. Server mode + php-gmp would allow background bulk.', 'woo2nostr'); ?></p></div>
                <p>
                    <button type="button" class="button button-primary" id="woo2nostr-bulk-nip07" data-scope="all"><?php esc_html_e('Publish all via Extension (browser)', 'woo2nostr'); ?></button>
                    <span id="woo2nostr-bulk-progress" style="margin-left:10px"></span>
                </p>
                <p class="description"><?php esc_html_e('Fetches each product preview, signs with extension, publishes to relays sequentially. Keep tab open.', 'woo2nostr'); ?></p>
                <pre id="woo2nostr-bulk-log" style="display:none;max-height:240px;overflow:auto;background:#f6f8fa;padding:8px;font-size:11px"></pre>
                <hr>
            <?php endif; ?>
            <?php if (!$hasGmp && !$hasSecp): ?>
                <div class="notice notice-error inline"><p><?php esc_html_e('PHP gmp/sec256k1 missing — server publish will fail. Ask host to enable php-gmp (sudo apt install php-gmp && restart php-fpm) or use NIP-07 mode.', 'woo2nostr'); ?></p></div>
            <?php endif; ?>
            <form method="post">
                <?php wp_nonce_field('woo2nostr_bulk','_woo2nostr_bulk_nonce'); ?>
                <table class="form-table">
                    <tr><th>Scope</th><td>
                        <label><input type="radio" name="scope" value="all" checked> All published products</label><br>
                        <label><input type="radio" name="scope" value="selected"> IDs (comma-separated) <input type="text" name="ids" id="woo2nostr-bulk-ids" placeholder="12,34,56" class="regular-text"></label>
                    </td></tr>
                </table>
                <?php submit_button($isNip07 ? 'Mark pending (NIP-07)' : 'Queue publish to Nostr (server)'); ?>
            </form>
            <p><a href="<?php echo esc_url(admin_url('edit.php?post_type=product')); ?>">Back to Products</a> · <a href="<?php echo esc_url(admin_url('admin.php?page=wc-status&tab=action-scheduler&s=woo2nostr')); ?>">View queue</a> · <a href="<?php echo esc_url(admin_url('admin.php?page=woo2nostr')); ?>">Settings & Diagnostics</a></p>
        </div>
        <?php
    }

    public static function ajaxBulk(): void {
        check_ajax_referer('woo2nostr','nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error('forbidden');
        $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
        if (!$ids) wp_send_json_error('no ids');
        Queue::enqueueBulk($ids);
        wp_send_json_success(['queued'=>count($ids)]);
    }

    public static function ajaxPreviewBulk(): void {
        check_ajax_referer('woo2nostr','nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error('forbidden');
        $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
        if (!$ids) {
            $ids = wc_get_products(['limit'=>-1,'status'=>['publish'],'return'=>'ids']);
            $ids = array_slice($ids, 0, 300);
        }
        wp_send_json_success(['ids'=>$ids,'count'=>count($ids)]);
    }
}
