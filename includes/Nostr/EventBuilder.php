<?php
namespace Woo2Nostr\Nostr;

defined('ABSPATH') || exit;

final class EventBuilder {

    public static function buildForProduct(\WC_Product $product, array $opts = []): array {
        $currency = get_woocommerce_currency();
        $shopstr = $opts['shopstr'] ?? (bool) get_option('woo2nostr_shopstr', 1);
        $pubkey = $opts['pubkey'] ?? '';

        if ($product->is_type('variable')) {
            $mode = $opts['publish_mode'] ?? (get_post_meta($product->get_id(), '_woo2nostr_publish_mode', true) ?: 'all_variations');
            $mode = in_array($mode, ['all_variations', 'per_attribute', 'one_per_product'], true) ? $mode : 'all_variations';
            $attrKey = (string) ($opts['group_attribute'] ?? (get_post_meta($product->get_id(), '_woo2nostr_group_attribute', true) ?: ''));
            if ($mode === 'per_attribute' && $attrKey === '') $mode = 'all_variations';
            $children = (array) $product->get_children();

            if ($mode === 'one_per_product') {
                $min = $product->get_variation_price('min');
                return [self::buildSimple($product, $currency, $shopstr, true, $min ? (string) $min : null)];
            }
            if ($mode === 'per_attribute') {
                return self::buildGroupedByAttribute($product, $children, $attrKey, $currency, $shopstr);
            }

            $events = [];
            $events[] = self::buildSimple($product, $currency, $shopstr, true);
            foreach ($children as $vid) {
                $var = wc_get_product($vid);
                if (!$var) continue;
                $events[] = self::buildVariation($var, $product, $currency, $shopstr);
            }
            return $events;
        }

        if ($product->is_type('variation')) {
            $parentId = $product->get_parent_id();
            $parent = wc_get_product($parentId);
            return [self::buildVariation($product, $parent, $currency, $shopstr)];
        }

        return [self::buildSimple($product, $currency, $shopstr, false)];
    }

    private static function buildSimple(\WC_Product $p, string $currency, bool $shopstr, bool $isVariableParent, ?string $minPrice = null): array {
        $id = $p->get_id();
        $d = Utils::dTagForProduct($id);
        $title = $p->get_name();
        $summary = wp_trim_words(wp_strip_all_tags($p->get_short_description() ?: $p->get_description()), 30);
        $content = self::markdownContent($p);
        $price = $p->get_price();
        $stockQty = $p->managing_stock() ? (string) $p->get_stock_quantity() : null;
        $status = $p->get_stock_status() === 'outofstock' ? 'sold' : 'active';
        $publishedAt = (string) ($p->get_date_created() ? $p->get_date_created()->getTimestamp() : time());

        $isDigital = $p->is_virtual() || $p->is_downloadable();
        $typeTag = $isVariableParent ? ['type', 'variable', $isDigital ? 'digital' : 'physical'] : ['type', 'simple', $isDigital ? 'digital' : 'physical'];

        $visibility = 'on-sale';
        if ($p->get_status() !== 'publish') $visibility = 'hidden';
        if ($p->is_on_sale() && !$isVariableParent) $visibility = 'on-sale';

        $weight = $p->get_weight();
        $length = $p->get_length(); $width = $p->get_width(); $height = $p->get_height();

        $tags = [
            ['d', $d],
            ['title', $title],
            $typeTag,
            ['visibility', $visibility],
            ['published_at', $publishedAt],
            ['status', $status],
        ];
        if ($summary) $tags[] = ['summary', $summary];
        if ($price !== '' && $price !== null && (!$isVariableParent || $minPrice !== null)) {
            $tags[] = ['price', (string) ($minPrice ?? $price), $currency];
        }
        if ($stockQty !== null) $tags[] = ['stock', $stockQty];
        if ($weight) $tags[] = ['weight', (string) $weight, 'kg'];
        if ($length && $width && $height) $tags[] = ['dim', "{$length}x{$width}x{$height}", 'cm'];

        foreach (self::images($p) as $img) $tags[] = $img;
        foreach (self::categories($p) as $t) $tags[] = $t;
        if ($shopstr) $tags[] = ['t', 'shopstr'];

        $specs = self::specs($p);
        foreach ($specs as $s) $tags[] = $s;

        $shipping = self::shippingOptions($p);
        foreach ($shipping as $sh) $tags[] = $sh;

        $location = get_option('woo2nostr_location', '');
        if ($location) $tags[] = ['location', $location];

        return self::wrapEvent($tags, $content, self::kindForProduct($p));
    }

    private static function buildVariation(\WC_Product $var, ?\WC_Product $parent, string $currency, bool $shopstr): array {
        $vid = $var->get_id();
        $pid = $parent ? $parent->get_id() : $var->get_parent_id();
        $d = Utils::dTagForProduct($pid, $vid);
        $title = $var->get_name();
        $summary = wp_trim_words(wp_strip_all_tags($var->get_short_description() ?: $var->get_description()), 30);
        $content = self::markdownContent($var);
        $price = $var->get_price();
        $stockQty = $var->managing_stock() ? (string) $var->get_stock_quantity() : null;
        $status = $var->get_stock_status() === 'outofstock' ? 'sold' : 'active';
        $publishedAt = (string) ($var->get_date_created() ? $var->get_date_created()->getTimestamp() : time());
        $isDigital = $var->is_virtual() || $var->is_downloadable();

        $tags = [
            ['d', $d],
            ['title', $title],
            ['type', 'variation', $isDigital ? 'digital' : 'physical'],
            ['visibility', $var->get_status() === 'publish' ? 'on-sale' : 'hidden'],
            ['published_at', $publishedAt],
            ['status', $status],
        ];
        if ($summary) $tags[] = ['summary', $summary];
        if ($price !== '' && $price !== null) $tags[] = ['price', (string) $price, $currency];
        if ($stockQty !== null) $tags[] = ['stock', $stockQty];
        if ($parent) {
            $parentD = Utils::dTagForProduct($pid);
            $pubkey = get_option('woo2nostr_pubkey', '');
            if ($pubkey) $tags[] = ['a', "30402:{$pubkey}:{$parentD}"];
            else $tags[] = ['a', "30402::{$parentD}"];
        }
        foreach ($var->get_attributes() as $k => $v) {
            $tags[] = ['spec', sanitize_title($k), (string) $v];
        }
        foreach (self::images($var) as $img) $tags[] = $img;
        foreach (self::categories($var) as $t) $tags[] = $t;
        if ($shopstr) $tags[] = ['t', 'shopstr'];
        $location = get_option('woo2nostr_location', '');
        if ($location) $tags[] = ['location', $location];
        return self::wrapEvent($tags, $content, self::kindForProduct($var));
    }

    private static function buildGroupedByAttribute(\WC_Product $product, array $children, string $attrKey, string $currency, bool $shopstr): array {
        $groups = [];
        foreach ($children as $vid) {
            $var = wc_get_product($vid);
            if (!$var) continue;
            if ($var->get_status() !== 'publish') continue;
            $attrs = $var->get_attributes();
            $val = trim((string) ($attrs[$attrKey] ?? $var->get_attribute($attrKey)));
            if ($val === '') $val = '—';
            $key = sanitize_title($val);
            if ($key === '') $key = 'var-' . $vid;
            $price = (float) $var->get_price();
            $inStock = $var->is_in_stock();
            $cur = $groups[$key] ?? null;
            if ($cur === null) {
                $groups[$key] = ['value' => $val, 'variation' => $var, 'price' => $price, 'inStock' => $inStock];
            } elseif ($inStock && !$cur['inStock']) {
                $groups[$key] = ['value' => $val, 'variation' => $var, 'price' => $price, 'inStock' => true];
            } elseif ($inStock === $cur['inStock'] && $price < $cur['price']) {
                $groups[$key] = ['value' => $val, 'variation' => $var, 'price' => $price, 'inStock' => $inStock];
            }
        }
        $events = [];
        foreach ($groups as $key => $g) {
            $ev = self::buildVariation($g['variation'], $product, $currency, $shopstr);
            $ev['tags'] = array_values(array_filter($ev['tags'], fn($t) => ($t[0] ?? '') !== 'd'));
            $d = 'wc-' . $product->get_id() . '-' . sanitize_title($attrKey) . '-' . $key;
            $ev['tags'][] = ['d', $d];
            $events[] = $ev;
        }
        return $events;
    }

    private static function wrapEvent(array $tags, string $content, int $kind = 30402): array {
        $tags = apply_filters('woo2nostr_event_tags', $tags);
        $content = apply_filters('woo2nostr_event_content', $content);
        return [
            'kind' => $kind,
            'created_at' => time(),
            'tags' => $tags,
            'content' => $content,
        ];
    }

    public static function kindForProduct(\WC_Product $p): int {
        $status = $p->get_status();
        if ($status !== 'publish') return 30403;
        $vis = get_post_meta($p->get_id(), '_visibility', true);
        return 30402;
    }

    private static function markdownContent(\WC_Product $p): string {
        $desc = $p->get_description();
        $desc = $desc ? wp_strip_all_tags($desc) : $p->get_name();
        $lines = [];
        $lines[] = $desc;
        $lines[] = '';
        if ($p->get_sku()) $lines[] = '**SKU:** ' . $p->get_sku();
        $price = $p->get_price_html();
        if ($price) $lines[] = '**Price:** ' . wp_strip_all_tags($price) . ' ' . get_woocommerce_currency();
        if ($p->is_type('bundle') || $p->get_type() === 'bundle') {
            $lines[] = '';
            $lines[] = '### Bundle contents';
            $bundled = $p->get_meta('_bundle_data') ?: $p->get_meta('_wpcbn_bundles') ?: [];
            if (is_array($bundled) && $bundled) {
                foreach ($bundled as $item) {
                    $bid = $item['product_id'] ?? $item['id'] ?? null;
                    if ($bid) {
                        $bp = wc_get_product((int) $bid);
                        if ($bp) $lines[] = '- ' . $bp->get_name() . ' × ' . ($item['quantity'] ?? 1);
                    }
                }
            }
            $regular = $p->get_regular_price();
            $sale = $p->get_sale_price();
            if ($regular && $sale && (float)$sale < (float)$regular) {
                $lines[] = '';
                $lines[] = '_Bundle discount applied._';
            }
        }
        $url = get_permalink($p->get_id());
        if ($url) {
            $lines[] = '';
            $lines[] = '[View in store](' . $url . ')';
        }
        return implode("\n", array_filter($lines, fn($l) => $l !== null));
    }

    private static function images(\WC_Product $p): array {
        $ids = [];
        if ($p->get_image_id()) $ids[] = $p->get_image_id();
        $ids = array_merge($ids, $p->get_gallery_image_ids());
        $tags = [];
        $order = 0;
        foreach (array_unique($ids) as $attId) {
            $url = wp_get_attachment_url($attId);
            if (!$url) continue;
            $meta = wp_get_attachment_metadata($attId);
            $dim = '';
            if (!empty($meta['width']) && !empty($meta['height'])) $dim = $meta['width'] . 'x' . $meta['height'];
            $tags[] = ['image', $url, $dim, (string) $order++];
        }
        return $tags;
    }

    private static function categories(\WC_Product $p): array {
        $terms = get_the_terms($p->get_id(), 'product_cat');
        if (is_wp_error($terms) || !$terms) return [];
        $tags = [];
        foreach ($terms as $t) $tags[] = ['t', sanitize_title($t->slug)];
        $tagTerms = get_the_terms($p->get_id(), 'product_tag');
        if (!is_wp_error($tagTerms) && $tagTerms) {
            foreach ($tagTerms as $t) $tags[] = ['t', sanitize_title($t->slug)];
        }
        return $tags;
    }

    private static function specs(\WC_Product $p): array {
        $specs = [];
        foreach ($p->get_attributes() as $tax => $attr) {
            if ($attr instanceof \WC_Product_Attribute) {
                if (!$attr->get_visible()) continue;
                $name = $attr->get_name();
                $vals = $attr->get_options();
                if (empty($vals) && $attr->is_taxonomy()) {
                    $terms = wp_get_post_terms($p->get_id(), $name, ['fields' => 'names']);
                    $vals = is_wp_error($terms) ? [] : $terms;
                }
                foreach ((array) $vals as $v) $specs[] = ['spec', sanitize_title($name), (string) $v];
            }
        }
        return $specs;
    }

    private static function shippingOptions(\WC_Product $p): array {
        if ($p->is_virtual() || $p->is_downloadable()) return [];
        $pubkey = get_option('woo2nostr_pubkey', '');
        $templates = get_option('woo2nostr_shipping_templates', []);
        if (empty($templates) || !$pubkey) return [];
        $tags = [];
        foreach ((array) $templates as $dTag) {
            $dTag = sanitize_title($dTag);
            if (!$dTag) continue;
            $tags[] = ['shipping_option', "30406:{$pubkey}:{$dTag}"];
        }
        return $tags;
    }
}
