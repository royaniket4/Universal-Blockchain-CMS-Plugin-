<?php
// includes/verify-badge.php
if (!defined('ABSPATH')) { exit; }

// Verify-on-view badge with key fallbacks and on-chain indicator
add_filter('the_content', function ($content) {
    if (!is_singular('post') || !in_the_loop() || !is_main_query()) {
        return $content;
    }

    global $post;

    // Prefer canonical, then legacy aliases
    $stored = get_post_meta($post->ID, 'bcpcontentsha256hash', true);
    if (!$stored) {
        $stored = get_post_meta($post->ID, 'bcp_content_sha256_hash', true);
    }
    if (!$stored) {
        $stored = get_post_meta($post->ID, 'bcpsha256', true);
    }
    if (!$stored) {
        return $content;
    }

    $current = hash('sha256', (string) $post->post_content);
    $ok = hash_equals($stored, $current);

    // Optional on-chain success flag (set to 1 after successful txn)
    $is_onchain = intval(get_post_meta($post->ID, 'bcpverified', true)) === 1;

    $badge = $ok
        ? '<p class="bcp-verified" style="color:#16a34a;margin:8px 0;">✓ Verified (SHA‑256)'.($is_onchain ? ' · On-chain' : '').'</p>'
        : '<p class="bcp-unverified" style="color:#dc2626;margin:8px 0;">⚠ Not verified (content changed)</p>';

    return $badge . $content;
}, 9);
