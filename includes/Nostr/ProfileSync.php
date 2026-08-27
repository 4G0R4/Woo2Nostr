<?php
namespace Woo2Nostr\Nostr;

defined('ABSPATH') || exit;

final class ProfileSync {

    public static function fetchKind0(string $pubkeyHex): ?array {
        if (!preg_match('/^[0-9a-f]{64}$/i', $pubkeyHex)) return null;
        $events = RelayPublisher::fetchEvents(['kinds' => [0], 'authors' => [strtolower($pubkeyHex)], 'limit' => 1]);
        if (empty($events)) return null;
        $e = $events[0];
        $content = json_decode($e['content'] ?? '{}', true);
        if (!is_array($content)) $content = [];
        return [
            'event' => $e,
            'content' => $content,
            'tags' => $e['tags'] ?? [],
        ];
    }

    public static function pullToOptions(string $pubkeyHex): void {
        $data = self::fetchKind0($pubkeyHex);
        if (!$data) return;
        $c = $data['content'];
        $tags = $data['tags'];
        $pref = null;
        foreach ($tags as $t) {
            if (($t[0] ?? '') === 'payment_preference') $pref = $t[1] ?? null;
        }
        if (isset($c['lud16']) && $c['lud16']) update_option('woo2nostr_lud16', sanitize_text_field($c['lud16']));
        if ($pref) update_option('woo2nostr_payment_preference', sanitize_text_field($pref));
        if (isset($c['name'])) update_option('woo2nostr_profile_name', sanitize_text_field($c['name']));
    }

    public static function buildKind0(string $pubkeyHex, array $overrides = []): array {
        $existing = self::fetchKind0($pubkeyHex);
        $content = $existing['content'] ?? [];
        $tags = $existing['tags'] ?? [];

        $content['lud16'] = $overrides['lud16'] ?? get_option('woo2nostr_lud16', $content['lud16'] ?? '');
        if (empty($content['lud16'])) unset($content['lud16']);
        $content['name'] = $overrides['name'] ?? $content['name'] ?? get_bloginfo('name');
        $content['about'] = $overrides['about'] ?? $content['about'] ?? get_bloginfo('description');

        $pref = $overrides['payment_preference'] ?? get_option('woo2nostr_payment_preference', 'manual');
        $hasPref = false;
        foreach ($tags as &$t) {
            if (($t[0] ?? '') === 'payment_preference') { $t[1] = $pref; $hasPref = true; }
        }
        unset($t);
        if (!$hasPref && $pref) $tags[] = ['payment_preference', $pref];

        return [
            'kind' => 0,
            'created_at' => time(),
            'tags' => $tags,
            'content' => wp_json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    }
}
