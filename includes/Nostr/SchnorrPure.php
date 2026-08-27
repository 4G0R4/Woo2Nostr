<?php
namespace Woo2Nostr\Nostr;

defined('ABSPATH') || exit;

final class SchnorrPure {
    private const P_HEX = 'FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEFFFFFC2F';
    private const N_HEX = 'FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEBAAEDCE6AF48A03BBFD25E8CD0364141';
    private const GX_HEX = '79BE667EF9DCBBAC55A06295CE870B07029BFCDB2DCE28D959F2815B16F81798';
    private const GY_HEX = '483ADA7726A3C4655DA4FBFC0E1108A8FD17B448A68554199C47D08FFB10D4B8';
    private static $p = null;
    private static $n = null;
    private static $G = null;

    private static function init(): void {
        if (self::$p !== null) return;
        self::$p = gmp_init(self::P_HEX, 16);
        self::$n = gmp_init(self::N_HEX, 16);
        self::$G = ['x' => gmp_init(self::GX_HEX, 16), 'y' => gmp_init(self::GY_HEX, 16)];
    }

    private static function fe(\GMP $a): \GMP {
        $r = gmp_mod($a, self::$p);
        if (gmp_cmp($r, gmp_init(0)) < 0) $r = gmp_add($r, self::$p);
        return $r;
    }

    private static function point_add(?array $P, ?array $Q): ?array {
        if ($P === null) return $Q;
        if ($Q === null) return $P;
        if (gmp_cmp($P['x'], $Q['x']) === 0) {
            if (gmp_cmp($P['y'], $Q['y']) !== 0) return null;
            $num = self::fe(gmp_mul(gmp_mul(gmp_init(3), $P['x']), $P['x']));
            $den = self::fe(gmp_mul(gmp_init(2), $P['y']));
            $lam = self::fe(gmp_mul($num, gmp_invert($den, self::$p)));
        } else {
            $num = self::fe(gmp_sub($Q['y'], $P['y']));
            $den = self::fe(gmp_sub($Q['x'], $P['x']));
            $lam = self::fe(gmp_mul($num, gmp_invert($den, self::$p)));
        }
        $x3 = self::fe(gmp_sub(gmp_sub(gmp_mul($lam, $lam), $P['x']), $Q['x']));
        $y3 = self::fe(gmp_sub(gmp_mul($lam, gmp_sub($P['x'], $x3)), $P['y']));
        return ['x' => $x3, 'y' => $y3];
    }

    private static function point_mul(\GMP $scalar, array $P): ?array {
        $result = null; $addend = $P; $bits = gmp_strval($scalar, 2);
        for ($i = strlen($bits) - 1; $i >= 0; $i--) {
            if ($bits[$i] === '1') $result = self::point_add($result, $addend);
            $addend = self::point_add($addend, $addend);
        }
        return $result;
    }

    private static function lift_x(\GMP $x): ?array {
        $p = self::$p;
        if (gmp_cmp($x, $p) >= 0) return null;
        $y_sq = self::fe(gmp_add(gmp_powm($x, gmp_init(3), $p), gmp_init(7)));
        $exp = gmp_div(gmp_add($p, gmp_init(1)), gmp_init(4));
        $y = gmp_powm($y_sq, $exp, $p);
        if (gmp_cmp(self::fe(gmp_mul($y, $y)), $y_sq) !== 0) return null;
        if (gmp_cmp(gmp_mod($y, gmp_init(2)), gmp_init(0)) !== 0) $y = gmp_sub($p, $y);
        return ['x' => $x, 'y' => $y];
    }

    private static function tagged_hash(string $tag, string $msg): string {
        $tag_hash = hash('sha256', $tag, true);
        return hash('sha256', $tag_hash . $tag_hash . $msg, true);
    }

    private static function intTo32(\GMP $v): string {
        $hex = gmp_strval($v, 16);
        $hex = str_pad($hex, 64, '0', STR_PAD_LEFT);
        return hex2bin($hex);
    }

    public static function derivePubkey(string $privHex): ?string {
        if (!extension_loaded('gmp')) return null;
        self::init();
        $priv = gmp_init($privHex, 16);
        if (gmp_cmp($priv, gmp_init(0)) <= 0 || gmp_cmp($priv, self::$n) >= 0) return null;
        $P = self::point_mul($priv, self::$G);
        if ($P === null) return null;
        return str_pad(gmp_strval($P['x'], 16), 64, '0', STR_PAD_LEFT);
    }

    public static function sign(string $msgHex, string $privHex): ?string {
        if (!extension_loaded('gmp')) return null;
        self::init();
        $priv = gmp_init($privHex, 16);
        if (gmp_cmp($priv, gmp_init(0)) === 0 || gmp_cmp($priv, self::$n) >= 0) return null;
        $P = self::point_mul($priv, self::$G);
        if ($P === null) return null;
        if (gmp_cmp(gmp_mod($P['y'], gmp_init(2)), gmp_init(0)) !== 0) $priv = gmp_sub(self::$n, $priv);
        $privBytes = self::intTo32($priv);
        $pubXBytes = self::intTo32($P['x']);
        if (gmp_cmp(gmp_mod($P['y'], gmp_init(2)), gmp_init(0)) !== 0) {
            $P2 = self::point_mul($priv, self::$G);
            $pubXBytes = self::intTo32($P2['x']);
        } else {
            $pubXBytes = self::intTo32($P['x']);
        }
        $aux = random_bytes(32);
        $t = $privBytes ^ self::tagged_hash('BIP0340/aux', $aux);
        $rand = self::tagged_hash('BIP0340/nonce', $t . $pubXBytes . hex2bin($msgHex));
        $k = gmp_mod(gmp_init(bin2hex($rand), 16), self::$n);
        if (gmp_cmp($k, gmp_init(0)) === 0) return null;
        $R = self::point_mul($k, self::$G);
        if ($R === null) return null;
        if (gmp_cmp(gmp_mod($R['y'], gmp_init(2)), gmp_init(0)) !== 0) $k = gmp_sub(self::$n, $k);
        $R2 = self::point_mul($k, self::$G);
        $rBytes = self::intTo32($R2['x']);
        $e_input = $rBytes . $pubXBytes . hex2bin($msgHex);
        $e_hash = self::tagged_hash('BIP0340/challenge', $e_input);
        $e = gmp_mod(gmp_init(bin2hex($e_hash), 16), self::$n);
        $s = gmp_mod(gmp_add($k, gmp_mul($e, $priv)), self::$n);
        $sBytes = self::intTo32($s);
        return bin2hex($rBytes . $sBytes);
    }
}
