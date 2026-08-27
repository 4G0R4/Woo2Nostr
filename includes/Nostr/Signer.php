<?php
namespace Woo2Nostr\Nostr;

defined('ABSPATH') || exit;

interface SignerInterface {
    public function canSign(): bool;
    public function sign(array $event): ?array;
}

final class ServerSigner implements SignerInterface {
    public function canSign(): bool {
        $nsec = get_option('woo2nostr_nsec_enc', '');
        return !empty($nsec) && get_option('woo2nostr_key_mode', 'server') === 'server';
    }

    public function sign(array $event): ?array {
        $enc = get_option('woo2nostr_nsec_enc', '');
        if (!$enc) return null;
        $hex = $this->decryptNsec($enc);
        if (!$hex || !preg_match('/^[0-9a-f]{64}$/i', $hex)) return null;
        $pubkey = $this->derivePubkey($hex);
        if (!$pubkey) return null;
        $event['pubkey'] = strtolower($pubkey);
        $event['id'] = Utils::eventId($event);
        $sig = $this->schnorrSign($event['id'], $hex);
        if (!$sig) return null;
        $event['sig'] = $sig;
        update_option('woo2nostr_pubkey', strtolower($pubkey));
        return $event;
    }

    private function decryptNsec(string $enc): ?string {
        $key = $this->cryptoKey();
        $raw = base64_decode($enc, true);
        if (!$raw) return null;
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ct = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        if (function_exists('sodium_crypto_secretbox_open')) {
            $plain = sodium_crypto_secretbox_open($ct, $nonce, $key);
            return $plain ?: null;
        }
        return null;
    }

    public static function encryptNsec(string $hex): string {
        $inst = new self();
        $key = $inst->cryptoKey();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ct = sodium_crypto_secretbox($hex, $nonce, $key);
        return base64_encode($nonce . $ct);
    }

    private function cryptoKey(): string {
        return hash('sha256', AUTH_KEY . SECURE_AUTH_KEY, true);
    }

    private function derivePubkey(string $privHex): ?string {
        if (function_exists('sodium_crypto_scalarmult_base')) {
            if (extension_loaded('secp256k1') && function_exists('secp256k1_context_create')) {
                try {
                    $ctx = secp256k1_context_create(SECP256K1_CONTEXT_SIGN);
                    $pub = ''; secp256k1_ec_pubkey_create($ctx, $pub, Utils::hexToBytes($privHex));
                    $serialized = ''; secp256k1_ec_pubkey_serialize($ctx, $serialized, $pub, SECP256K1_EC_COMPRESSED);
                    $hex = bin2hex($serialized);
                    return substr($hex, 2);
                } catch (\Throwable $e) {}
            }
        }
        return null;
    }

    private function schnorrSign(string $idHex, string $privHex): ?string {
        if (extension_loaded('secp256k1') && function_exists('secp256k1_schnorrsig_sign')) {
            try {
                $ctx = secp256k1_context_create(SECP256K1_CONTEXT_SIGN);
                $sig = ''; $rv = secp256k1_schnorrsig_sign($ctx, $sig, Utils::hexToBytes($idHex), Utils::hexToBytes($privHex));
                if ($rv === 1) return bin2hex($sig);
            } catch (\Throwable $e) {}
        }
        return null;
    }
}

final class Nip07Signer implements SignerInterface {
    public function canSign(): bool {
        return get_option('woo2nostr_key_mode', 'server') === 'nip07';
    }
    public function sign(array $event): ?array { return null; }
}

final class BunkerSigner implements SignerInterface {
    public function canSign(): bool {
        $uri = trim((string) get_option('woo2nostr_bunker_uri', ''));
        return get_option('woo2nostr_key_mode', 'server') === 'bunker' && $uri !== '' && (str_starts_with($uri, 'bunker://') || str_starts_with($uri, 'nostrconnect://'));
    }
    public function sign(array $event): ?array { return null; }

    public static function normalizeUri(string $uri): string {
        $uri = trim($uri);
        if (str_starts_with($uri, 'nostrconnect://')) return 'bunker://' . substr($uri, 15);
        return $uri;
    }
}

final class SignerFactory {
    public static function get(): SignerInterface {
        $mode = get_option('woo2nostr_key_mode', 'server');
        return match ($mode) {
            'nip07' => new Nip07Signer(),
            'bunker' => new BunkerSigner(),
            default => new ServerSigner(),
        };
    }

    public static function isServerMode(): bool {
        return get_option('woo2nostr_key_mode', 'server') === 'server' && (new ServerSigner())->canSign();
    }
}
