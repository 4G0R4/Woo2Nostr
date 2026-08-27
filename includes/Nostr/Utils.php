<?php
namespace Woo2Nostr\Nostr;

defined('ABSPATH') || exit;

final class Utils {
    public static function bech32Encode(string $hrp, string $hex): string {
        $data = self::hexToBytes($hex);
        $converted = self::convertBits($data, 8, 5, true);
        $checksum = self::bech32Checksum($hrp, $converted);
        $combined = array_merge($converted, $checksum);
        $chars = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
        $out = $hrp . '1';
        foreach ($combined as $c) $out .= $chars[$c];
        return $out;
    }

    public static function hexToBytes(string $hex): string {
        return hex2bin(strtolower($hex)) ?: '';
    }

    public static function bytesToHex(string $bytes): string {
        return bin2hex($bytes);
    }

    public static function sha256(string $data): string {
        return hash('sha256', $data, true);
    }

    private static function convertBits(string $data, int $from, int $to, bool $pad): array {
        $acc = 0; $bits = 0; $ret = []; $maxv = (1 << $to) - 1;
        for ($i = 0; $i < strlen($data); $i++) {
            $value = ord($data[$i]);
            $acc = ($acc << $from) | $value;
            $bits += $from;
            while ($bits >= $to) {
                $bits -= $to;
                $ret[] = ($acc >> $bits) & $maxv;
            }
        }
        if ($pad && $bits > 0) $ret[] = ($acc << ($to - $bits)) & $maxv;
        return $ret;
    }

    private static function bech32Checksum(string $hrp, array $data): array {
        $values = array_merge(self::hrpExpand($hrp), $data, [0,0,0,0,0,0]);
        $poly = self::polyMod($values) ^ 1;
        $ret = [];
        for ($i = 0; $i < 6; $i++) $ret[] = ($poly >> 5 * (5 - $i)) & 31;
        return $ret;
    }

    private static function hrpExpand(string $hrp): array {
        $ret = [];
        for ($i = 0; $i < strlen($hrp); $i++) $ret[] = ord($hrp[$i]) >> 5;
        $ret[] = 0;
        for ($i = 0; $i < strlen($hrp); $i++) $ret[] = ord($hrp[$i]) & 31;
        return $ret;
    }

    private static function polyMod(array $v): int {
        $c = 1;
        foreach ($v as $x) {
            $b = $c >> 25;
            $c = (($c & 0x1ffffff) << 5) ^ $x;
            if ($b & 1) $c ^= 0x3b6a57b2;
            if ($b & 2) $c ^= 0x26508e6d;
            if ($b & 4) $c ^= 0x1ea119fa;
            if ($b & 8) $c ^= 0x3d4233dd;
            if ($b & 16) $c ^= 0x2a1462b3;
        }
        return $c;
    }

    public static function normalizeRelay(string $url): string {
        $url = trim($url);
        $url = rtrim($url, '/');
        return $url;
    }

    public static function dTagForProduct(int $productId, ?int $variationId = null): string {
        return $variationId ? "wc-{$productId}-var-{$variationId}" : "wc-{$productId}";
    }

    public static function eventId(array $event): string {
        $payload = json_encode([0, $event['pubkey'], $event['created_at'], $event['kind'], $event['tags'], $event['content']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return hash('sha256', $payload);
    }

    public static function npub(string $hex): string {
        return self::bech32Encode('npub', strtolower($hex));
    }

    public static function nsec(string $hex): string {
        return self::bech32Encode('nsec', strtolower($hex));
    }
}
