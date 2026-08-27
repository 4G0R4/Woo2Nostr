<?php
namespace Woo2Nostr\Sync;

use Woo2Nostr\Nostr\EventBuilder;
use Woo2Nostr\Nostr\RelayPublisher;
use Woo2Nostr\Nostr\SignerFactory;
use Woo2Nostr\Nostr\Utils;

defined('ABSPATH') || exit;

final class Queue {
    const GROUP = 'woo2nostr';
    const HOOK_SINGLE = 'woo2nostr_sync_product';
    const HOOK_BULK = 'woo2nostr_sync_bulk';

    public static function init(): void {
        add_action(self::HOOK_SINGLE, [self::class, 'runSingle'], 10, 1);
        add_action(self::HOOK_BULK, [self::class, 'runBulk'], 10, 1);
        add_action('save_post_product', [self::class, 'onProductSave'], 20, 3);
        add_action('save_post_product_variation', [self::class, 'onVariationSave'], 20, 3);
        add_action('woocommerce_after_product_object_save', [self::class, 'onProductObjectSave'], 20, 1);
        add_action('woocommerce_product_set_stock', [self::class, 'onStockChange'], 10, 2);
        add_action('woocommerce_variation_set_stock', [self::class, 'onStockChange'], 10, 2);
        add_action('transition_post_status', [self::class, 'onTransition'], 10, 3);
        add_action('wp_trash_post', [self::class, 'onTrashDelete']);
        add_action('before_delete_post', [self::class, 'onTrashDelete']);
    }

    private static function shouldAutoSync(int $productId): bool {
        if (!get_option('woo2nostr_auto_sync', 0)) return false;
        if (wp_is_post_revision($productId) || wp_is_post_autosave($productId)) return false;
        $type = get_post_type($productId);
        if ($type === 'product_variation') {
            $parent = wp_get_post_parent_id($productId);
            if ($parent && get_post_meta($parent, '_woo2nostr_enabled', true) === 'yes') return true;
            return get_post_meta($productId, '_woo2nostr_enabled', true) === 'yes';
        }
        if ($type !== 'product') return false;
        return get_post_meta($productId, '_woo2nostr_enabled', true) === 'yes';
    }

    private static function debounceEnqueue(int $productId): void {
        if (function_exists('as_has_scheduled_action') && as_has_scheduled_action(self::HOOK_SINGLE, ['product_id' => $productId], self::GROUP)) return;
        self::enqueueSingle($productId);
    }

    public static function enqueueSingle(int $productId): void {
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::HOOK_SINGLE, ['product_id' => $productId], self::GROUP);
        } else {
            wp_schedule_single_event(time() + 5, self::HOOK_SINGLE, ['product_id' => $productId]);
        }
    }

    public static function enqueueBulk(array $ids): void {
        $chunks = array_chunk(array_map('intval', $ids), 25);
        foreach ($chunks as $chunk) {
            if (function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action(self::HOOK_BULK, ['ids' => $chunk], self::GROUP);
            } else {
                wp_schedule_single_event(time() + 5, self::HOOK_BULK, ['ids' => $chunk]);
            }
        }
    }

    public static function runSingle($args): void {
        $pid = is_array($args) ? (int) ($args['product_id'] ?? 0) : (int) $args;
        if (!$pid) return;
        self::syncProduct($pid);
    }

    public static function runBulk($args): void {
        $ids = is_array($args) ? ($args['ids'] ?? []) : [];
        if (is_array($args) && isset($args[0]) && is_array($args[0])) $ids = $args[0];
        foreach ((array) $ids as $id) self::syncProduct((int) $id);
    }

    public static function syncProduct(int $productId): array {
        $product = wc_get_product($productId);
        if (!$product) return ['ok' => false, 'error' => 'Product not found'];
        $mode = get_option('woo2nostr_key_mode', 'server');
        if ($mode !== 'server') {
            update_post_meta($productId, '_woo2nostr_status', 'pending_nip07');
            return ['ok' => false, 'error' => 'NIP-07/Bunker mode requires browser signing'];
        }
        $signer = SignerFactory::get();
        if (!$signer->canSign()) {
            update_post_meta($productId, '_woo2nostr_status', 'no_key');
            return ['ok' => false, 'error' => 'No server nsec configured'];
        }
        $events = EventBuilder::buildForProduct($product, [
            'shopstr' => (bool) get_option('woo2nostr_shopstr', 1),
            'pubkey' => get_option('woo2nostr_pubkey', ''),
        ]);
        $results = [];
        foreach ($events as $ev) {
            $signed = $signer->sign($ev);
            if (!$signed) { $results[] = ['ok' => false, 'error' => 'Signing failed — check php secp256k1 extension or nsec']; continue; }
            $pub = RelayPublisher::publish($signed);
            $results[] = $pub;
            if (!empty($pub['ok'])) {
                $d = '';
                foreach ($signed['tags'] as $t) if (($t[0] ?? '') === 'd') { $d = $t[1] ?? ''; break; }
                update_post_meta($productId, '_woo2nostr_last_event_id', $signed['id']);
                update_post_meta($productId, '_woo2nostr_last_d', $d);
                update_post_meta($productId, '_woo2nostr_last_sync', time());
                update_post_meta($productId, '_woo2nostr_status', 'synced');
            }
        }
        $ok = count(array_filter($results, fn($r) => !empty($r['ok']))) > 0;
        return ['ok' => $ok, 'results' => $results];
    }

    public static function onProductSave(int $postId, \WP_Post $post, bool $update): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!self::shouldAutoSync($postId)) return;
        self::debounceEnqueue($postId);
    }

    public static function onVariationSave(int $postId, \WP_Post $post, bool $update): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!self::shouldAutoSync($postId)) return;
        self::debounceEnqueue($postId);
        $parent = wp_get_post_parent_id($postId);
        if ($parent && self::shouldAutoSync($parent)) self::debounceEnqueue($parent);
    }

    public static function onProductObjectSave(\WC_Product $product): void {
        $id = $product->get_id();
        if (!self::shouldAutoSync($id)) {
            if ($product->is_type('variation') && !self::shouldAutoSync($id)) return;
            $parent = $product->get_parent_id();
            if ($parent && self::shouldAutoSync($parent)) { self::debounceEnqueue($parent); return; }
            return;
        }
        self::debounceEnqueue($id);
        if ($product->is_type('variation')) {
            $parent = $product->get_parent_id();
            if ($parent) self::debounceEnqueue($parent);
        }
    }

    public static function onStockChange($stock, $product = null): void {
        $id = 0;
        if ($product instanceof \WC_Product) $id = $product->get_id();
        elseif (is_int($product)) $id = $product;
        elseif (is_int($stock) && $product === null) return;
        if (!$id && func_num_args() === 1 && is_object($stock) && $stock instanceof \WC_Product) $id = $stock->get_id();
        if (!$id) return;
        if (!self::shouldAutoSync($id)) return;
        self::debounceEnqueue($id);
    }

    public static function onTransition(string $newStatus, string $oldStatus, \WP_Post $post): void {
        if (!in_array($post->post_type, ['product', 'product_variation'], true)) return;
        $id = $post->ID;
        $wasEnabled = get_post_meta($id, '_woo2nostr_enabled', true) === 'yes';
        $hadSync = (bool) get_post_meta($id, '_woo2nostr_last_event_id', true);
        if (!$wasEnabled && !$hadSync) return;
        if ($newStatus === 'trash' || $newStatus === 'auto-draft') {
            if (get_option('woo2nostr_key_mode', 'server') !== 'server') return;
            self::enqueueDeletion($id);
            return;
        }
        if ($newStatus !== $oldStatus && self::shouldAutoSync($id)) {
            self::debounceEnqueue($id);
        }
        if ($newStatus === 'publish' && $oldStatus !== 'publish' && $hadSync && !self::shouldAutoSync($id)) {
            self::debounceEnqueue($id);
        }
    }

    public static function onTrashDelete(int $postId): void {
        $type = get_post_type($postId);
        if (!in_array($type, ['product', 'product_variation'], true)) return;
        $hadSync = (bool) get_post_meta($postId, '_woo2nostr_last_event_id', true);
        if (!$hadSync) return;
        if (get_option('woo2nostr_key_mode', 'server') !== 'server') return;
        self::enqueueDeletion($postId);
    }

    private static function enqueueDeletion(int $productId): void {
        $hook = 'woo2nostr_delete_product';
        if (function_exists('as_has_scheduled_action') && as_has_scheduled_action($hook, ['product_id' => $productId], self::GROUP)) return;
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action($hook, ['product_id' => $productId], self::GROUP);
        } else {
            wp_schedule_single_event(time() + 5, $hook, ['product_id' => $productId]);
        }
        add_action($hook, function ($args) {
            $pid = is_array($args) ? (int) ($args['product_id'] ?? 0) : (int) $args;
            if ($pid) self::publishDeletion($pid);
        });
    }

    public static function publishDeletion(int $productId): array {
        $signer = \Woo2Nostr\Nostr\SignerFactory::get();
        if (!$signer->canSign()) return ['ok' => false, 'error' => 'no signer'];
        $lastId = get_post_meta($productId, '_woo2nostr_last_event_id', true);
        $lastD = get_post_meta($productId, '_woo2nostr_last_d', true);
        if (!$lastD) $lastD = \Woo2Nostr\Nostr\Utils::dTagForProduct($productId);
        $pubkey = get_option('woo2nostr_pubkey', '');
        $events = [];
        if ($lastId) {
            $events[] = ['kind' => 5, 'created_at' => time(), 'tags' => [['e', $lastId], ['a', "30402:{$pubkey}:{$lastD}"]], 'content' => 'Removed from WooCommerce'];
            $events[] = ['kind' => 5, 'created_at' => time(), 'tags' => [['a', "30402:{$pubkey}:{$lastD}"]], 'content' => 'Removed from WooCommerce'];
        }
        $kind = 30403;
        $product = wc_get_product($productId);
        if ($product) {
            $evs = \Woo2Nostr\Nostr\EventBuilder::buildForProduct($product, ['shopstr' => (bool) get_option('woo2nostr_shopstr', 1), 'pubkey' => $pubkey]);
            foreach ($evs as &$ev) { $ev['kind'] = 30403; $events[] = $ev; }
        }
        $results = [];
        foreach ($events as $ev) {
            $signed = $signer->sign($ev);
            if (!$signed) continue;
            $results[] = \Woo2Nostr\Nostr\RelayPublisher::publish($signed);
        }
        update_post_meta($productId, '_woo2nostr_status', 'deleted');
        return ['ok' => true, 'results' => $results];
    }
}
