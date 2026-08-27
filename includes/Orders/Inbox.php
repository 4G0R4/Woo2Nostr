<?php
namespace Woo2Nostr\Orders;

use Woo2Nostr\Nostr\RelayPublisher;
use Woo2Nostr\Nostr\Utils;

defined('ABSPATH') || exit;

final class Inbox {
    const CRON_HOOK = 'woo2nostr_poll_inbox';
    const META_LAST = 'woo2nostr_last_poll';

    public static function init(): void {
        add_action(self::CRON_HOOK, [self::class, 'poll']);
        add_action('woo2nostr_poll_inbox_action', [self::class, 'poll']);
        add_action('init', function () {
            if (get_option('woo2nostr_poll_enabled', 1) && get_option('woo2nostr_key_mode','server') === 'server' && !wp_next_scheduled(self::CRON_HOOK)) {
                wp_schedule_event(time()+120, 'every_two_minutes', self::CRON_HOOK);
            }
            if (!get_option('woo2nostr_poll_enabled', 1) && wp_next_scheduled(self::CRON_HOOK)) {
                wp_clear_scheduled_hook(self::CRON_HOOK);
            }
        });
    }

    public static function poll(): void {
        if (!get_option('woo2nostr_poll_enabled', 1)) return;
        if (get_option('woo2nostr_key_mode','server') !== 'server') return;
        $pubkey = get_option('woo2nostr_pubkey','');
        $enc = get_option('woo2nostr_nsec_enc','');
        if (!$pubkey || !$enc) return;
        $since = (int) get_option(self::META_LAST, time() - 3600);
        $privHex = self::decrypt($enc);
        if (!$privHex) return;

        $events = RelayPublisher::fetchEvents([
            'kinds' => [1059],
            '#p' => [strtolower($pubkey)],
            'since' => $since,
            'limit' => 50,
        ]);
        update_option(self::META_LAST, time());
        foreach ($events as $wrap) {
            $inner = self::unwrapGift($wrap, $privHex);
            if (!$inner) continue;
            self::handleInner($inner);
        }
    }

    private static function decrypt(string $enc): ?string {
        $key = hash('sha256', AUTH_KEY . SECURE_AUTH_KEY, true);
        $raw = base64_decode($enc, true);
        if (!$raw) return null;
        $nonce = substr($raw,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ct = substr($raw,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        if (!function_exists('sodium_crypto_secretbox_open')) return null;
        $plain = sodium_crypto_secretbox_open($ct,$nonce,$key);
        return $plain ?: null;
    }

    private static function unwrapGift(array $wrap, string $privHex): ?array {
        $content = $wrap['content'] ?? '';
        if (!$content) return null;
        try {
            if (function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')) {
                // NIP-44 / NIP-59: for MVP try plain JSON gift content (some relays return already-decrypted in test)
                $j = json_decode($content, true);
                if (is_array($j) && isset($j['kind'])) return $j;
            }
            $j = json_decode($content, true);
            if (is_array($j) && isset($j['kind'])) return $j;
        } catch (\Throwable $e) {}
        // Fallback: content may be nip44 encrypted seal; attempt to treat as JSON rumor if merchant uses plaintext in dev
        return null;
    }

    private static function handleInner(array $e): void {
        $kind = (int) ($e['kind'] ?? 0);
        $tags = $e['tags'] ?? [];
        $map = [];
        foreach ($tags as $t) if (isset($t[0],$t[1])) $map[$t[0]][] = $t;
        $orderId = $map['order'][0][1] ?? null;
        $type = $map['type'][0][1] ?? null;

        if ($kind === 16 && $type === '1' && $orderId) {
            self::createOrder($e, $orderId, $map);
        } elseif ($kind === 16 && $type === '3' && $orderId) {
            $status = $map['status'][0][1] ?? '';
            if ($status === 'cancelled') self::cancelOrder($orderId);
        } elseif ($kind === 17 && $orderId) {
            self::markPaid($orderId, $e);
        }
    }

    private static function createOrder(array $e, string $orderId, array $map): void {
        if (get_option('woo2nostr_order_' . $orderId)) return;
        $items = $map['item'] ?? [];
        if (empty($items)) return;
        $order = wc_create_order();
        $order->set_created_via('woo2nostr');
        $order->add_order_note('Nostr order ' . $orderId . ' from ' . substr($e['pubkey'] ?? '',0,8));
        foreach ($items as $it) {
            $ref = $it[1] ?? ''; $qty = (int) ($it[2] ?? 1);
            $dTag = substr(strrchr($ref, ':'), 1) ?: $ref;
            $pid = self::productIdByDTag($dTag);
            if (!$pid) continue;
            $product = wc_get_product($pid);
            if (!$product) continue;
            $order->add_product($product, $qty);
        }
        $addr = $map['address'][0][1] ?? '';
        if ($addr) $order->set_shipping_address_1($addr);
        $email = $map['email'][0][1] ?? '';
        if ($email) $order->set_billing_email($email);
        $phone = $map['phone'][0][1] ?? '';
        if ($phone) $order->set_billing_phone($phone);
        $order->update_meta_data('_woo2nostr_order_id', $orderId);
        $order->update_meta_data('_woo2nostr_buyer_pubkey', $e['pubkey'] ?? '');
        $order->calculate_totals();
        $order->update_status('pending', 'Created from Nostr (kind 16 type 1)');
        update_option('woo2nostr_order_' . $orderId, $order->get_id());
    }

    private static function cancelOrder(string $orderId): void {
        $oid = get_option('woo2nostr_order_' . $orderId);
        if (!$oid) return;
        $order = wc_get_order((int)$oid);
        if ($order && $order->get_status() !== 'cancelled') $order->update_status('cancelled','Cancelled via Nostr');
    }

    private static function markPaid(string $orderId, array $e): void {
        $oid = get_option('woo2nostr_order_' . $orderId);
        if (!$oid) return;
        $order = wc_get_order((int)$oid);
        if (!$order) return;
        $order->add_order_note('Nostr payment receipt received (kind 17)');
        $order->update_meta_data('_woo2nostr_paid_via_nostr', 1);
        $order->save();
    }

    private static function productIdByDTag(string $d): ?int {
        global $wpdb;
        $id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_woo2nostr_last_d' AND meta_value=%s LIMIT 1", $d));
        if ($id) return (int)$id;
        if (preg_match('/wc-(\d+)/', $d, $m)) return (int)$m[1];
        return null;
    }
}
