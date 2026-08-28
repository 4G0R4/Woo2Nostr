<?php
namespace Woo2Nostr;

defined('ABSPATH') || exit;

final class Plugin {
    private static ?self $instance = null;

    public static function instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->includes();
        add_action('init', [$this, 'init']);
        add_action('admin_enqueue_scripts', [$this, 'adminAssets']);
    }

    private function includes(): void {
        require_once WOO2NOSTR_DIR . 'includes/Admin/Settings.php';
        require_once WOO2NOSTR_DIR . 'includes/Admin/ProductMetaBox.php';
        require_once WOO2NOSTR_DIR . 'includes/Admin/BulkActions.php';
        require_once WOO2NOSTR_DIR . 'includes/Admin/OrderInbox.php';
        require_once WOO2NOSTR_DIR . 'includes/Nostr/Utils.php';
        require_once WOO2NOSTR_DIR . 'includes/Nostr/SchnorrPure.php';
        require_once WOO2NOSTR_DIR . 'includes/Nostr/EventBuilder.php';
        require_once WOO2NOSTR_DIR . 'includes/Nostr/Signer.php';
        require_once WOO2NOSTR_DIR . 'includes/Nostr/RelayPublisher.php';
        require_once WOO2NOSTR_DIR . 'includes/Nostr/ProfileSync.php';
        require_once WOO2NOSTR_DIR . 'includes/Sync/Queue.php';
        require_once WOO2NOSTR_DIR . 'includes/Orders/Inbox.php';
    }

    public function init(): void {
        $this->migrateRelays();
        Admin\Settings::init();
        Admin\ProductMetaBox::init();
        Admin\BulkActions::init();
        Admin\OrderInbox::init();
        Sync\Queue::init();
        Orders\Inbox::init();
    }

    private function migrateRelays(): void {
        if (get_option('woo2nostr_version') !== WOO2NOSTR_VERSION) {
            $oldDefault = "wss://relay.damus.io\nwss://nos.lol\nwss://relay.nostr.band\nwss://relay.primal.net";
            if (get_option('woo2nostr_relays', '') === $oldDefault) {
                update_option('woo2nostr_relays', \Woo2Nostr\Nostr\RelayPublisher::DEFAULT_RELAYS);
            }
            update_option('woo2nostr_version', WOO2NOSTR_VERSION);
        }
    }

    public function adminAssets(string $hook): void {
        $screen = get_current_screen();
        if (!$screen) return;
        $hookHit = function_exists('str_contains') ? str_contains($hook, 'woo2nostr') : strpos($hook, 'woo2nostr') !== false;
        $isProduct = isset($screen->post_type) && $screen->post_type === 'product';
        $isOrders = isset($screen->id) && $screen->id === 'woocommerce_page_wc-orders';
        $isPlugin = $hookHit || $isProduct || $isOrders;
        if (!$isPlugin) return;
        wp_enqueue_style('woo2nostr-admin', WOO2NOSTR_URL . 'assets/css/admin.css', [], WOO2NOSTR_VERSION);
        wp_enqueue_script('woo2nostr-admin', WOO2NOSTR_URL . 'assets/js/admin.js', ['jquery'], WOO2NOSTR_VERSION, true);
        wp_localize_script('woo2nostr-admin', 'woo2nostr', [
            'ajax' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('woo2nostr'),
            'mode' => get_option('woo2nostr_key_mode', 'server'),
            'relays' => \Woo2Nostr\Nostr\RelayPublisher::getRelays(),
            'i18n' => [
                'signing' => __('Signing with NIP-07…', 'woo2nostr'),
                'noExtension' => __('NIP-07 extension not found (window.nostr).', 'woo2nostr'),
                'connecting' => __('Connecting…', 'woo2nostr'),
                'connected' => __('Connected', 'woo2nostr'),
                'failed' => __('Failed', 'woo2nostr'),
                'noPubkey' => __('Extension did not return a public key.', 'woo2nostr'),
            ],
        ]);
    }
}
