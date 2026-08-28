<?php
namespace Woo2Nostr\Nostr;

defined('ABSPATH') || exit;

final class RelayPublisher {

    const DEFAULT_RELAYS = "wss://relay.damus.io\nwss://nos.lol\nwss://relay.nostr.band\nwss://relay.primal.net";
    const PAID_RELAYS = ['wss://relay.nostr.wine', 'wss://eden.nostr.land'];

    public static function getRelays(): array {
        $raw = get_option('woo2nostr_relays', self::DEFAULT_RELAYS);
        $lines = preg_split('/[\r\n,]+/', (string) $raw);
        $out = [];
        foreach ((array) $lines as $l) {
            $l = Utils::normalizeRelay(trim($l));
            $hasPrefix = function_exists('str_starts_with') ? str_starts_with($l, 'wss://') : strpos($l, 'wss://') === 0;
            if ($l && $hasPrefix) $out[] = $l;
        }
        if (get_option('woo2nostr_paid_relays', 0)) {
            foreach (self::PAID_RELAYS as $pr) if (!in_array($pr, $out, true)) $out[] = $pr;
        }
        return array_values(array_unique($out));
    }

    public static function publish(array $signedEvent): array {
        $relays = self::getRelays();
        if (empty($relays)) return ['ok' => false, 'error' => 'No relays configured'];
        $results = [];
        foreach ($relays as $relay) {
            $res = self::publishToRelay($relay, $signedEvent);
            $results[$relay] = $res;
        }
        $okCount = count(array_filter($results, fn($r) => !empty($r['ok'])));
        if ($okCount > 0 && get_option('woo2nostr_shopstr', 1) && !empty(get_option('woo2nostr_shopstr_cache_url'))) {
            self::postToShopstrCache($signedEvent);
        } elseif ($okCount > 0 && get_option('woo2nostr_shopstr', 1)) {
            self::postToShopstrCache($signedEvent);
        }
        return ['ok' => $okCount > 0, 'results' => $results, 'relays_ok' => $okCount];
    }

    private static function publishToRelay(string $relay, array $event): array {
        $payload = json_encode(['EVENT', $event], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $wsUrl = $relay;

        $host = parse_url($relay, PHP_URL_HOST);
        if (!$host) return ['ok' => false, 'error' => 'Invalid relay URL'];

        $args = [
            'timeout' => 8,
            'headers' => ['Content-Type' => 'application/json'],
        ];

        $httpUrl = str_replace('wss://', 'https://', str_replace('ws://', 'http://', $relay));

        if (class_exists(\WebSocket\Client::class)) {
            try {
                $client = new \WebSocket\Client($relay, ['timeout' => 6]);
                $client->send($payload);
                $resp = $client->receive();
                $client->close();
                $decoded = json_decode($resp, true);
                if (is_array($decoded) && $decoded[0] === 'OK') {
                    return ['ok' => (bool) $decoded[2], 'msg' => $decoded[3] ?? ''];
                }
                return ['ok' => true, 'raw' => $resp];
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => $e->getMessage()];
            }
        }

        $useSocket = function_exists('fsockopen');
        if ($useSocket) {
            $res = self::wsViaFsockopen($relay, $payload);
            if ($res !== null) return $res;
        }

        $resp = wp_remote_post($httpUrl, [
            'timeout' => 8,
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/nostr+json'],
            'body' => $payload,
        ]);
        if (is_wp_error($resp)) return ['ok' => false, 'error' => $resp->get_error_message()];
        $code = wp_remote_retrieve_response_code($resp);
        return ['ok' => $code >= 200 && $code < 300, 'http_code' => $code, 'body' => wp_remote_retrieve_body($resp)];
    }

    private static function wsViaFsockopen(string $relay, string $payload): ?array {
        $parts = parse_url($relay);
        $host = $parts['host'] ?? '';
        $port = $parts['port'] ?? 443;
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        if (!$host) return null;
        $key = base64_encode(random_bytes(16));
        $headers = "GET {$path} HTTP/1.1\r\nHost: {$host}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\nOrigin: https://woo2nostr.local\r\n\r\n";
        $fp = @fsockopen('ssl://' . $host, (int) $port, $errno, $errstr, 5);
        if (!$fp) return ['ok' => false, 'error' => "fsockopen: $errstr"];
        stream_set_timeout($fp, 6);
        fwrite($fp, $headers);
        $resp = fread($fp, 2048);
        if (!str_contains($resp, '101')) {
            fclose($fp);
            return ['ok' => false, 'error' => 'WebSocket handshake failed'];
        }
        $frame = self::wsEncode($payload);
        fwrite($fp, $frame);
        $data = fread($fp, 8192);
        fclose($fp);
        $decoded = self::wsDecode($data);
        if ($decoded) {
            $j = json_decode($decoded, true);
            if (is_array($j) && $j[0] === 'OK') return ['ok' => (bool) $j[2], 'msg' => $j[3] ?? ''];
            return ['ok' => true, 'raw' => $decoded];
        }
        return ['ok' => false, 'note' => 'no OK ack parsed (fsockopen path is unreliable — verify on relays before trusting status)'];
    }

    private static function wsEncode(string $payload): string {
        $len = strlen($payload);
        $header = chr(0x81);
        if ($len < 126) $header .= chr($len);
        elseif ($len < 65536) $header .= chr(126) . pack('n', $len);
        else $header .= chr(127) . pack('J', $len);
        return $header . $payload;
    }

    private static function wsDecode(string $data): string {
        if (strlen($data) < 2) return '';
        $payload = substr($data, strpos($data, "\r\n\r\n") !== false ? strpos($data, "\r\n\r\n") + 4 : 0);
        if (strlen($payload) < 2) return '';
        $opcode = ord($payload[0]) & 0x0f;
        $masked = (ord($payload[1]) >> 7) & 1;
        $len = ord($payload[1]) & 0x7f;
        $offset = 2;
        if ($len === 126) { $len = unpack('n', substr($payload, 2, 2))[1]; $offset = 4; }
        elseif ($len === 127) { $len = unpack('J', substr($payload, 2, 8))[1]; $offset = 10; }
        if ($masked) $offset += 4;
        return substr($payload, $offset, $len);
    }

    public static function postCache(array $event): void {
        self::postToShopstrCache($event);
    }

    private static function postToShopstrCache(array $event): void {
        $urls = [
            'https://shopstr.store/api/db/cache-event',
            get_option('woo2nostr_shopstr_cache_url', ''),
        ];
        foreach (array_unique(array_filter($urls)) as $url) {
            wp_remote_post($url, [
                'timeout' => 6,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode($event),
                'blocking' => false,
            ]);
        }
    }

    public static function fetchEvents(array $filter): array {
        $relays = self::getRelays();
        if (empty($relays)) return [];
        $relay = $relays[0];
        $subId = 'woo2nostr-' . time();
        $req = json_encode(['REQ', $subId, $filter]);
        $close = json_encode(['CLOSE', $subId]);

        if (class_exists(\WebSocket\Client::class)) {
            try {
                $c = new \WebSocket\Client($relay, ['timeout' => 8]);
                $c->send($req);
                $events = [];
                $start = time();
                while (time() - $start < 6) {
                    try { $msg = $c->receive(); } catch (\Throwable $e) { break; }
                    $j = json_decode($msg, true);
                    if (!is_array($j)) continue;
                    if ($j[0] === 'EVENT' && $j[1] === $subId) $events[] = $j[2];
                    if ($j[0] === 'EOSE') break;
                }
                $c->send($close); $c->close();
                return $events;
            } catch (\Throwable $e) { return []; }
        }
        return [];
    }
}
