<?php
namespace Woo2Nostr\Admin;

use Woo2Nostr\Nostr\ProfileSync;
use Woo2Nostr\Nostr\Signer;

defined('ABSPATH') || exit;

final class Settings {
    const PAGE = 'woo2nostr';
    const OPTION_GROUP = 'woo2nostr_settings';

    public static function init(): void {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [self::class, 'register']);
        add_action('wp_ajax_woo2nostr_pull_profile', [self::class, 'ajaxPullProfile']);
        add_action('wp_ajax_woo2nostr_verify', [self::class, 'ajaxVerify']);
        add_action('wp_ajax_woo2nostr_test_relay', [self::class, 'ajaxTestRelay']);
        add_action('wp_ajax_woo2nostr_nip07_publish', [self::class, 'ajaxNip07Publish']);
        add_action('wp_ajax_woo2nostr_nip07_connect', [self::class, 'ajaxNip07Connect']);
    }

    public static function menu(): void {
        add_submenu_page('woocommerce', 'Woo2Nostr', 'Nostr', 'manage_woocommerce', self::PAGE, [self::class, 'render']);
    }

    public static function register(): void {
        $keys = ['woo2nostr_key_mode','woo2nostr_relays','woo2nostr_paid_relays','woo2nostr_shopstr','woo2nostr_shopstr_cache_url','woo2nostr_auto_sync','woo2nostr_location','woo2nostr_bunker_uri','woo2nostr_lud16','woo2nostr_payment_preference','woo2nostr_poll_enabled'];
        foreach ($keys as $k) register_setting(self::OPTION_GROUP, $k);
        register_setting(self::OPTION_GROUP, 'woo2nostr_nsec_enc');
    }

    private static function handleSave(): void {
        if (!isset($_POST['_woo2nostr_nonce']) || !wp_verify_nonce($_POST['_woo2nostr_nonce'], 'woo2nostr_save')) return;
        if (!current_user_can('manage_woocommerce')) return;
        $mode = sanitize_text_field($_POST['woo2nostr_key_mode'] ?? 'server');
        update_option('woo2nostr_key_mode', in_array($mode, ['server','nip07','bunker'], true) ? $mode : 'server');
        if (isset($_POST['woo2nostr_nsec'])) {
            $nsec = trim((string) $_POST['woo2nostr_nsec']);
            if ($nsec !== '' && $nsec !== '••••••••••••••••') {
                $hex = self::parseNsec($nsec);
                if ($hex) {
                    update_option('woo2nostr_nsec_enc', \Woo2Nostr\Nostr\ServerSigner::encryptNsec($hex));
                    $pub = self::derivePubkeyFromHex($hex);
                    if ($pub) update_option('woo2nostr_pubkey', strtolower($pub));
                } else {
                    add_settings_error('woo2nostr', 'nsec', __('Invalid nsec / hex private key.', 'woo2nostr'), 'error');
                }
            }
        }
        update_option('woo2nostr_relays', sanitize_textarea_field($_POST['woo2nostr_relays'] ?? ''));
        update_option('woo2nostr_paid_relays', isset($_POST['woo2nostr_paid_relays']) ? 1 : 0);
        update_option('woo2nostr_shopstr', isset($_POST['woo2nostr_shopstr']) ? 1 : 0);
        update_option('woo2nostr_shopstr_cache_url', esc_url_raw($_POST['woo2nostr_shopstr_cache_url'] ?? ''));
        update_option('woo2nostr_auto_sync', isset($_POST['woo2nostr_auto_sync']) ? 1 : 0);
        update_option('woo2nostr_location', sanitize_text_field($_POST['woo2nostr_location'] ?? ''));
        $bunker = trim((string) ($_POST['woo2nostr_bunker_uri'] ?? ''));
        if ($bunker !== '' && str_starts_with($bunker, 'nostrconnect://')) $bunker = 'bunker://' . substr($bunker, 15);
        update_option('woo2nostr_bunker_uri', sanitize_text_field($bunker));
        update_option('woo2nostr_lud16', sanitize_text_field($_POST['woo2nostr_lud16'] ?? ''));
        update_option('woo2nostr_payment_preference', sanitize_text_field($_POST['woo2nostr_payment_preference'] ?? 'manual'));
        update_option('woo2nostr_poll_enabled', isset($_POST['woo2nostr_poll_enabled']) ? 1 : 0);
        update_option('woo2nostr_shipping_templates', array_map('sanitize_title', (array) ($_POST['woo2nostr_shipping_templates'] ?? [])));

        if (isset($_POST['woo2nostr_sync_profile']) && !empty($_POST['woo2nostr_lud16'])) {
            $pubkey = get_option('woo2nostr_pubkey', '');
            if ($pubkey && get_option('woo2nostr_key_mode') === 'server') {
                $ev = ProfileSync::buildKind0($pubkey, ['lud16' => $_POST['woo2nostr_lud16'], 'payment_preference' => $_POST['woo2nostr_payment_preference'] ?? 'manual']);
                $signer = new \Woo2Nostr\Nostr\ServerSigner();
                $signed = $signer->sign($ev);
                if ($signed) \Woo2Nostr\Nostr\RelayPublisher::publish($signed);
            }
        }
        add_settings_error('woo2nostr', 'saved', __('Settings saved.', 'woo2nostr'), 'updated');
    }

    public static function render(): void {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') self::handleSave();
        settings_errors('woo2nostr');
        $mode = get_option('woo2nostr_key_mode', 'server');
        $relays = get_option('woo2nostr_relays', \Woo2Nostr\Nostr\RelayPublisher::DEFAULT_RELAYS);
        $pubkey = get_option('woo2nostr_pubkey', '');
        $hasNsec = (bool) get_option('woo2nostr_nsec_enc', '');
        $npub = '—';
        if ($pubkey && preg_match('/^[0-9a-f]{64}$/i', $pubkey)) {
            try { $npub = \Woo2Nostr\Nostr\Utils::npub($pubkey); } catch (\Throwable $e) { $npub = 'invalid pubkey'; }
        } elseif ($pubkey) { $npub = 'invalid pubkey'; }
        ?>
        <div class="wrap woo2nostr-wrap">
            <h1>Woo2Nostr <small style="font-weight:normal">NIP-99 · Gamma Markets</small></h1>
            <p><?php esc_html_e('Mirror WooCommerce products to Nostr. Choose key mode, relays, and Shopstr behavior. Price/currency mirrors WooCommerce settings.', 'woo2nostr'); ?></p>
            <?php if ($pubkey): ?><p><strong>npub:</strong> <code><?php echo esc_html($npub); ?></code> &nbsp; <strong>hex:</strong> <code><?php echo esc_html($pubkey); ?></code></p><?php endif; ?>
            <?php
            $hasGmp = extension_loaded('gmp');
            $hasSecp = extension_loaded('secp256k1');
            $hasBc = extension_loaded('bcmath');
            $siteUrl = home_url();
            if (!$hasGmp && !$hasSecp): ?><div class="notice notice-error inline"><p><strong><?php esc_html_e('Signing extensions missing — all server publishes will fail.', 'woo2nostr'); ?></strong><br>
                <?php esc_html_e('Install php-gmp (pure PHP BIP-340 schnorr fallback):', 'woo2nostr'); ?><br>
                <code>sudo apt install php-gmp && sudo systemctl restart php8.2-fpm</code> (Ubuntu/Debian)<br>
                <code>dnf install php-gmp && systemctl restart php-fpm</code> (Fedora/RHEL)<br>
                <?php esc_html_e('Or switch to NIP-07 browser extension mode.', 'woo2nostr'); ?></p></div><?php endif; ?>
            <?php if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON): ?><div class="notice notice-warning inline"><p><strong><?php esc_html_e('WP-Cron disabled — background jobs may stall.', 'woo2nostr'); ?></strong><br>
                <?php esc_html_e('Add a real cron to run every minute (required for Action Scheduler and order polling):', 'woo2nostr'); ?><br>
                <code>* * * * * curl -s "<?php echo esc_url($siteUrl); ?>/wp-cron.php" > /dev/null 2>&1</code><br>
                <a href="https://developer.wordpress.org/plugins/cron/#why-wp-cron-is-bad-for-performance" target="_blank"><?php esc_html_e('Learn more about WP-Cron limitations', 'woo2nostr'); ?></a></p></div><?php endif; ?>
            <?php if (!$hasNsec && $mode === 'server'): ?><div class="notice notice-warning inline"><p><?php esc_html_e('Server mode requires an nsec. Background sync & polling disabled until set.', 'woo2nostr'); ?></p></div><?php endif; ?>
            <?php if ($mode !== 'server'): ?><div class="notice notice-info inline"><p><?php esc_html_e('NIP-07 / Bunker modes require browser signing. Background auto-sync and polling are disabled in these modes.', 'woo2nostr'); ?></p></div><?php endif; ?>
            <form method="post">
                <?php wp_nonce_field('woo2nostr_save', '_woo2nostr_nonce'); ?>
                <h2><?php esc_html_e('Key custody (merchant choice)', 'woo2nostr'); ?></h2>
                <table class="form-table">
                    <tr><th><?php esc_html_e('Mode', 'woo2nostr'); ?></th><td>
                        <label><input type="radio" name="woo2nostr_key_mode" value="server" <?php checked($mode,'server'); ?>> <?php esc_html_e('Server nsec (encrypted, enables background sync + polling)', 'woo2nostr'); ?></label><br>
                        <label><input type="radio" name="woo2nostr_key_mode" value="nip07" <?php checked($mode,'nip07'); ?>> <?php esc_html_e('NIP-07 browser extension (window.nostr)', 'woo2nostr'); ?></label><br>
                        <label><input type="radio" name="woo2nostr_key_mode" value="bunker" <?php checked($mode,'bunker'); ?>> <?php esc_html_e('NIP-46 bunker (bunker:// — also accepts nostrconnect://)', 'woo2nostr'); ?></label>
                    </td></tr>
                    <tr class="woo2nostr-row-server"><th><label for="woo2nostr_nsec"><?php esc_html_e('Private key (nsec or 64-hex)', 'woo2nostr'); ?></label></th><td>
                        <input type="password" id="woo2nostr_nsec" name="woo2nostr_nsec" value="<?php echo $hasNsec ? '••••••••••••••••' : ''; ?>" placeholder="nsec1… or hex" class="regular-text" autocomplete="off">
                        <p class="description"><?php esc_html_e('Stored encrypted with AUTH_KEY. Leave masked to keep existing.', 'woo2nostr'); ?></p>
                    </td></tr>
                    <tr class="woo2nostr-row-bunker"><th><label for="woo2nostr_bunker_uri"><?php esc_html_e('Bunker URI', 'woo2nostr'); ?></label></th><td>
                        <input type="text" id="woo2nostr_bunker_uri" name="woo2nostr_bunker_uri" value="<?php echo esc_attr(get_option('woo2nostr_bunker_uri','')); ?>" class="large-text" placeholder="bunker://...">
                    </td></tr>
                    <tr class="woo2nostr-row-nip07"><th><?php esc_html_e('Browser extension', 'woo2nostr'); ?></th><td>
                        <button type="button" class="button button-primary" id="woo2nostr-connect-nip07"><?php esc_html_e('Connect with Extension', 'woo2nostr'); ?></button>
                        <span id="woo2nostr-connect-result" style="margin-left:8px"></span>
                        <p class="description"><?php esc_html_e('Triggers window.nostr.getPublicKey(). Saves pubkey for publishing without server nsec.', 'woo2nostr'); ?></p>
                        <p id="woo2nostr-nip07-pubkey" style="display:none"><code style="word-break:break-all"></code></p>
                    </td></tr>
                </table>

                <h2><?php esc_html_e('Relays & Shopstr', 'woo2nostr'); ?></h2>
                <table class="form-table">
                    <tr><th><label for="woo2nostr_relays"><?php esc_html_e('Relays (one per line)', 'woo2nostr'); ?></label></th><td>
                        <textarea id="woo2nostr_relays" name="woo2nostr_relays" rows="5" class="large-text code"><?php echo esc_textarea($relays); ?></textarea>
                        <p class="description"><?php esc_html_e('Default: relay.primal.net, nos.lol, relay.nostr.net, auth.nostr1.com, relay.damus.io (free, good NIP-99 retention). Coracle has no dedicated relay — it uses your relays. Paid relays below are opt-in.', 'woo2nostr'); ?></p>
                        <button type="button" class="button" id="woo2nostr-test-relay"><?php esc_html_e('Test relays', 'woo2nostr'); ?></button> <span id="woo2nostr-test-result"></span>
                    </td></tr>
                    <tr><th><?php esc_html_e('Paid relays (opt-in)', 'woo2nostr'); ?></th><td>
                        <label><input type="checkbox" name="woo2nostr_paid_relays" value="1" <?php checked((bool) get_option('woo2nostr_paid_relays', 0)); ?>> <?php esc_html_e('Also publish to wss://relay.nostr.wine + wss://eden.nostr.land (indefinite retention, NIP-99 supported)', 'woo2nostr'); ?></label>
                        <p class="description"><?php esc_html_e('Requires paid membership (NIP-42 auth): nostr.wine ~18k sats one-time, eden/nostr.land ~4M msats/month. Without subscription writes will fail (payment required).', 'woo2nostr'); ?></p>
                    </td></tr>
                    <tr><th><?php esc_html_e('Shopstr compatibility', 'woo2nostr'); ?></th><td>
                        <label><input type="checkbox" name="woo2nostr_shopstr" value="1" <?php checked((bool)get_option('woo2nostr_shopstr',1)); ?>> <?php esc_html_e('Enabled by default (adds t=shopstr + image tag + cache POST)', 'woo2nostr'); ?></label>
                    </td></tr>
                    <tr><th><label for="woo2nostr_shopstr_cache_url"><?php esc_html_e('Custom Shopstr cache URL', 'woo2nostr'); ?></label></th><td>
                        <input type="url" id="woo2nostr_shopstr_cache_url" name="woo2nostr_shopstr_cache_url" value="<?php echo esc_attr(get_option('woo2nostr_shopstr_cache_url','')); ?>" class="large-text" placeholder="https://shopstr.store/api/db/cache-event">
                    </td></tr>
                    <tr><th><label for="woo2nostr_location"><?php esc_html_e('Default location', 'woo2nostr'); ?></label></th><td>
                        <input type="text" id="woo2nostr_location" name="woo2nostr_location" value="<?php echo esc_attr(get_option('woo2nostr_location','')); ?>" class="regular-text" placeholder="Austin, TX">
                    </td></tr>
                    <tr><th><?php esc_html_e('Auto-sync on update', 'woo2nostr'); ?></th><td>
                        <label><input type="checkbox" name="woo2nostr_auto_sync" value="1" <?php checked((bool)get_option('woo2nostr_auto_sync',0)); ?>> <?php esc_html_e('Enabled — any update to a Nostr-enabled product republishes to relays (price, stock, images, status, deletion → draft 30403/deletion 5). Debounced, server mode only.', 'woo2nostr'); ?></label>
                        <p class="description"><?php esc_html_e('Hooks: save, variation save, stock/price change, status/trash/delete. NIP-07/Bunker modes still require manual publish.', 'woo2nostr'); ?></p>
                    </td></tr>
                </table>

                <h2><?php esc_html_e('Profile & payments (pulled from kind:0)', 'woo2nostr'); ?></h2>
                <p><button type="button" class="button" id="woo2nostr-pull-profile"><?php esc_html_e('Pull from relays (kind:0)', 'woo2nostr'); ?></button> <span id="woo2nostr-pull-result"></span></p>
                <table class="form-table">
                    <tr><th><label for="woo2nostr_lud16"><?php esc_html_e('Lightning address (lud16)', 'woo2nostr'); ?></label></th><td>
                        <input type="text" id="woo2nostr_lud16" name="woo2nostr_lud16" value="<?php echo esc_attr(get_option('woo2nostr_lud16','')); ?>" class="regular-text" placeholder="you@getalby.com">
                    </td></tr>
                    <tr><th><label for="woo2nostr_payment_preference"><?php esc_html_e('Payment preference', 'woo2nostr'); ?></label></th><td>
                        <select id="woo2nostr_payment_preference" name="woo2nostr_payment_preference">
                            <?php foreach (['manual'=>'manual','ecash'=>'ecash','lud16'=>'lud16'] as $v=>$l): ?>
                                <option value="<?php echo esc_attr($v); ?>" <?php selected(get_option('woo2nostr_payment_preference','manual'), $v); ?>><?php echo esc_html($l); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td></tr>
                    <tr><th><?php esc_html_e('Sync profile back to relays', 'woo2nostr'); ?></th><td>
                        <label><input type="checkbox" name="woo2nostr_sync_profile" value="1"> <?php esc_html_e('Publish updated kind:0 on save (server mode)', 'woo2nostr'); ?></label>
                    </td></tr>
                </table>

                <h2><?php esc_html_e('Orders inbox', 'woo2nostr'); ?></h2>
                <table class="form-table">
                    <tr><th><?php esc_html_e('Polling', 'woo2nostr'); ?></th><td>
                        <label><input type="checkbox" name="woo2nostr_poll_enabled" value="1" <?php checked((bool)get_option('woo2nostr_poll_enabled',1)); ?>> <?php esc_html_e('Poll relays every 2 min for NIP-17 orders (server mode only, creates WC orders)', 'woo2nostr'); ?></label>
                        <p class="description"><?php esc_html_e('Inbox at WooCommerce > Nostr Orders. Requires server nsec.', 'woo2nostr'); ?></p>
                    </td></tr>
                </table>

                <?php submit_button(__('Save settings', 'woo2nostr')); ?>
            </form>
            <hr>
            <h2><?php esc_html_e('Bulk sync', 'woo2nostr'); ?></h2>
            <p><?php esc_html_e('Use Products → bulk actions “Publish to Nostr”, or the dedicated tool:', 'woo2nostr'); ?> <a href="<?php echo esc_url(admin_url('admin.php?page=woo2nostr-bulk')); ?>" class="button">Open bulk sync</a></p>
            <p class="description">WooCommerce currency: <code><?php echo esc_html(get_woocommerce_currency()); ?></code> — published price tags use this.</p>
            <hr>
            <h2><?php esc_html_e('Diagnostics', 'woo2nostr'); ?></h2>
            <?php
            $last = get_option('woo2nostr_last_publish', null);
            $hasGmp = extension_loaded('gmp') ? 'yes' : 'no';
            $hasSecp = extension_loaded('secp256k1') ? 'yes' : 'no';
            $hasSodium = function_exists('sodium_crypto_secretbox') ? 'yes' : 'no';
            $cronDisabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? 'YES (queue may stall)' : 'no';
            echo '<p>PHP: gmp='.$hasGmp.' secp256k1='.$hasSecp.' sodium='.$hasSodium.' | WP-Cron disabled: '.$cronDisabled.' | Mode: '.esc_html($mode).' | Pubkey: '.($pubkey?substr($pubkey,0,8).'…':'none').'</p>';
            if ($last) {
                echo '<p>Last publish: '.esc_html(gmdate('Y-m-d H:i:s', (int)$last['time'])).' product #'.(int)$last['product_id'].' ok='.($last['ok']?'yes':'NO').'</p>';
                echo '<pre style="max-height:200px;overflow:auto;background:#f6f8fa;padding:8px;font-size:11px">'.esc_html(wp_json_encode($last['results'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)).'</pre>';
            } else { echo '<p>No publish yet — use bulk queue then check Woo &gt; Status &gt; Scheduled Actions for woo2nostr jobs.</p>'; }
            global $wpdb;
            $synced = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_woo2nostr_status' AND meta_value='synced'");
            $failed = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_woo2nostr_status' AND meta_value='failed'");
            $pending = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_woo2nostr_status' AND meta_value='pending'");
            $pendingNip = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_woo2nostr_status' AND meta_value='pending_nip07'");
            echo '<p><strong>Nostr products:</strong> synced='.(int)$synced.' failed='.(int)$failed.' pending='.(int)$pending.' pending_nip07='.(int)$pendingNip.'</p>';
            if ((int)$failed > 0) {
                $sample = $wpdb->get_col("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_woo2nostr_status' AND meta_value='failed' LIMIT 3");
                echo '<p><strong>⚠ Recent errors (sample #'.implode(',', $sample).'):</strong></p>';
                foreach ($sample as $sid) {
                    $err = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_woo2nostr_last_error' LIMIT 1", $sid));
                    if ($err) echo '<pre style="max-height:120px;overflow:auto;background:#fef2f2;padding:6px;font-size:10px">#'.$sid.': '.esc_html(wp_json_encode(json_decode($err,true) ?: $err, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)).'</pre>';
                }
                if (!$hasGmp && !$hasSecp) echo '<p style="color:#d63638"><strong>Root cause:</strong> php-gmp not installed. Install it then re-run bulk publish.</p>';
            }
            echo '<p>Listings (30402) appear on marketplace clients (Shopstr, Coracle) — not on nsite (nsite shows kind 30023). Verify at <a href="https://nostr.band/search?q=30402" target="_blank">nostr.band</a> or <a href="https://primal.net/search" target="_blank">Primal</a> with your npub.</p>';
            echo '<p><button type="button" class="button" id="woo2nostr-verify">Verify listings on relays</button> <span id="woo2nostr-verify-result"></span></p>';
            echo '<p><a href="'.esc_url(admin_url('admin.php?page=wc-status&tab=action-scheduler&s=woo2nostr')).'" class="button">View Action Scheduler queue</a> <a href="'.esc_url(admin_url('admin.php?page=wc-status&tab=logs')).'" class="button">Logs</a>';
            if ((int)$failed > 0 && $hasGmp): echo ' <a href="'.esc_url(admin_url('admin.php?page=woo2nostr-bulk')).'" class="button button-primary">Retry failed (bulk)</a>'; endif;
            echo '</p>';
            ?>
        </div>
        <?php
    }

    private static function parseNsec(string $input): ?string {
        $input = trim($input);
        if (preg_match('/^[0-9a-f]{64}$/i', $input)) return strtolower($input);
        if (str_starts_with($input, 'nsec1')) {
            $decoded = self::bech32Decode($input);
            if ($decoded && strlen($decoded) === 32) return bin2hex($decoded);
        }
        return null;
    }

    private static function bech32Decode(string $bech): ?string {
        $pos = strrpos($bech, '1');
        if ($pos === false) return null;
        $hrp = substr($bech, 0, $pos);
        if ($hrp !== 'nsec') return null;
        $chars = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
        $data = substr($bech, $pos + 1, -6);
        $bytes = [];
        for ($i=0; $i<strlen($data); $i++) {
            $p = strpos($chars, $data[$i]);
            if ($p === false) return null;
            $bytes[] = $p;
        }
        $bits = 0; $val = 0; $out = '';
        foreach ($bytes as $b) { $val = ($val << 5) | $b; $bits += 5; if ($bits >= 8) { $bits -= 8; $out .= chr(($val >> $bits) & 0xff); } }
        return $out;
    }

    private static function derivePubkeyFromHex(string $hex): ?string {
        if (extension_loaded('secp256k1') && function_exists('secp256k1_context_create')) {
            try {
                $ctx = secp256k1_context_create(SECP256K1_CONTEXT_SIGN);
                $pub=''; secp256k1_ec_pubkey_create($ctx,$pub, hex2bin($hex));
                $ser=''; secp256k1_ec_pubkey_serialize($ctx,$ser,$pub, SECP256K1_EC_COMPRESSED);
                return substr(bin2hex($ser),2);
            } catch (\Throwable $e) {}
        }
        if (extension_loaded('gmp')) {
            try { $pure = \Woo2Nostr\Nostr\SchnorrPure::derivePubkey(strtolower($hex)); if ($pure) return strtolower($pure); } catch (\Throwable $e) {}
        }
        return null;
    }

    public static function ajaxPullProfile(): void {
        check_ajax_referer('woo2nostr','nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error('forbidden');
        $pubkey = get_option('woo2nostr_pubkey','');
        if (!$pubkey) wp_send_json_error('No pubkey set — save nsec first');
        ProfileSync::pullToOptions($pubkey);
        wp_send_json_success(['lud16'=>get_option('woo2nostr_lud16',''),'pref'=>get_option('woo2nostr_payment_preference','manual')]);
    }

    public static function ajaxTestRelay(): void {
        check_ajax_referer('woo2nostr','nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error('forbidden');
        $relays = \Woo2Nostr\Nostr\RelayPublisher::getRelays();
        wp_send_json_success(['relays'=>$relays,'count'=>count($relays)]);
    }

    public static function ajaxNip07Publish(): void {
        check_ajax_referer('woo2nostr','nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error('forbidden');
        $signed = json_decode(stripslashes($_POST['signed'] ?? ''), true);
        if (!$signed || empty($signed['sig']) || empty($signed['pubkey'])) wp_send_json_error('Missing signed event');
        if (!preg_match('/^[0-9a-f]{64}$/i', $signed['pubkey'])) wp_send_json_error('Invalid pubkey');
        update_option('woo2nostr_pubkey', strtolower($signed['pubkey']));
        $record = !empty($_POST['record']) ? (bool) $_POST['record'] : false;
        if ($record) {
            $pid = (int) ($_POST['product_id'] ?? 0);
            if ($pid) { update_post_meta($pid,'_woo2nostr_last_event_id',$signed['id']); update_post_meta($pid,'_woo2nostr_status','synced'); update_post_meta($pid,'_woo2nostr_last_sync',time()); update_post_meta($pid,'_woo2nostr_last_d', self::extractD($signed)); delete_post_meta($pid,'_woo2nostr_last_error'); }
            if (get_option('woo2nostr_shopstr', 1)) \Woo2Nostr\Nostr\RelayPublisher::postCache($signed);
            wp_send_json_success(['recorded'=>true,'pubkey'=>strtolower($signed['pubkey'])]);
        }
        $res = \Woo2Nostr\Nostr\RelayPublisher::publish($signed);
        if (!empty($res['ok'])) {
            $pid = (int) ($_POST['product_id'] ?? 0);
            if ($pid) { update_post_meta($pid,'_woo2nostr_last_event_id',$signed['id']); update_post_meta($pid,'_woo2nostr_status','synced'); update_post_meta($pid,'_woo2nostr_last_sync',time()); update_post_meta($pid,'_woo2nostr_last_d', self::extractD($signed)); }
        }
        wp_send_json_success($res);
    }

    public static function ajaxVerify(): void {
        check_ajax_referer('woo2nostr','nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error('forbidden');
        $pubkey = get_option('woo2nostr_pubkey','');
        if (!$pubkey) wp_send_json_error('No pubkey set');
        $events = [];
        foreach ([30402, 30403] as $kind) {
            $e = \Woo2Nostr\Nostr\RelayPublisher::fetchEvents(['kinds'=>[$kind],'authors'=>[$pubkey],'limit'=>100]);
            $events = array_merge($events, $e);
        }
        $dTags = [];
        $times = [];
        foreach ($events as $ev) {
            foreach ($ev['tags'] ?? [] as $t) if (($t[0] ?? '') === 'd' && !in_array($t[1] ?? '', $dTags, true)) $dTags[] = $t[1];
            $times[] = $ev['created_at'];
        }
        wp_send_json_success(['count'=>count($events),'events'=>count(array_keys($events)), 'd_tags'=>array_slice($dTags,0,30), 'created_at'=>array_slice($times,0,10), 'relay'=>\Woo2Nostr\Nostr\RelayPublisher::getRelays()[0] ?? '']);
    }

    private static function extractD(array $ev): string {
        foreach ($ev['tags'] ?? [] as $t) if (($t[0] ?? '') === 'd') return $t[1] ?? '';
        return '';
    }

    public static function ajaxNip07Connect(): void {
        check_ajax_referer('woo2nostr','nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error('forbidden');
        $pubkey = strtolower(trim((string) ($_POST['pubkey'] ?? '')));
        if (!preg_match('/^[0-9a-f]{64}$/i', $pubkey)) wp_send_json_error('Invalid pubkey hex (expected 64 hex chars)');
        update_option('woo2nostr_pubkey', $pubkey);
        $npub = '';
        try { $npub = \Woo2Nostr\Nostr\Utils::npub($pubkey); } catch (\Throwable $e) {}
        wp_send_json_success(['pubkey'=>$pubkey,'npub'=>$npub]);
    }
}
