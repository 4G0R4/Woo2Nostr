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
        add_action('woocommerce_product_set_stock', [self::class, 'onStockChange']);
        add_action('woocommerce_variation_set_stock', [self::class, 'onStockChange']);
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
        if (get_option('woo2nostr_auto_sync', 0) && get_post_meta($postId, '_woo2nostr_enabled', true) === 'yes') {
            self::enqueueSingle($postId);
        }
    }

    public static function onStockChange(int $stock): void {
        if (!get_option('woo2nostr_auto_sync', 0)) return;
    }
}
