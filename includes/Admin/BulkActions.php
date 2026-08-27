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
    }

    public static function bulkActions(array $actions): array {
        $actions['woo2nostr_publish'] = __('Publish to Nostr', 'woo2nostr');
        $actions['woo2nostr_unpublish'] = __('Unpublish (draft 30403)', 'woo2nostr');
        return $actions;
    }

    public static function handleBulk(string $redirect, string $action, array $ids): string {
        if ($action === 'woo2nostr_publish') {
            Queue::enqueueBulk($ids);
            $redirect = add_query_arg(['woo2nostr_bulk'=>'queued','woo2nostr_count'=>count($ids)], $redirect);
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
        $msg = $type === 'queued' ? sprintf(__('%d products queued for Nostr publish (Action Scheduler).', 'woo2nostr'), $count) : sprintf(__('%d products marked draft.', 'woo2nostr'), $count);
        echo '<div class="notice notice-success is-dismissible"><p>'.esc_html($msg).' <a href="'.esc_url(admin_url('admin.php?page=wc-status&tab=action-scheduler')).'">View queue</a></p></div>';
    }

    public static function bulkPage(): void {
        add_submenu_page('woocommerce', 'Nostr Bulk Sync', 'Nostr Bulk', 'manage_woocommerce', 'woo2nostr-bulk', [self::class, 'renderBulk']);
    }

    public static function renderBulk(): void {
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
                Queue::enqueueBulk($ids);
                echo '<div class="notice notice-success inline"><p>'.esc_html(sprintf(__('%d products queued.', 'woo2nostr'), count($ids))).'</p></div>';
            }
        }
        ?>
        <div class="wrap">
            <h1>Nostr Bulk Sync</h1>
            <p>Publish many products at once. Uses Action Scheduler (background). One Nostr card per variation; bundles as single composite.</p>
            <form method="post">
                <?php wp_nonce_field('woo2nostr_bulk','_woo2nostr_bulk_nonce'); ?>
                <table class="form-table">
                    <tr><th>Scope</th><td>
                        <label><input type="radio" name="scope" value="all" checked> All published products</label><br>
                        <label><input type="radio" name="scope" value="selected"> IDs (comma-separated) <input type="text" name="ids" placeholder="12,34,56" class="regular-text"></label>
                    </td></tr>
                </table>
                <?php submit_button('Queue publish to Nostr'); ?>
            </form>
            <p><a href="<?php echo esc_url(admin_url('edit.php?post_type=product')); ?>">Back to Products</a> · <a href="<?php echo esc_url(admin_url('admin.php?page=wc-status&tab=action-scheduler&s=woo2nostr')); ?>">View queue</a></p>
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
}
