<?php
// includes/content-hash.php
if (!defined('ABSPATH')) { exit; }

/**
 * On save_post: compute SHA-256 of raw post_content and store in meta.
 * Canonical for current UI is 'bcpcontentsha256hash' (used by verify-badge),
 * with aliases 'bcp_content_sha256_hash' and 'bcpsha256' for compatibility.
 */
add_action('save_post', function ($post_ID, $post, $update) {
  // Ignore autosaves and revisions
  if (wp_is_post_autosave($post_ID) || wp_is_post_revision($post_ID)) {
    return;
  }
  // Only target standard posts (adjust if needed)
  if ($post->post_type !== 'post') {
    return;
  }

  // Compute SHA-256 over the same payload the badge checks (raw post_content)
  $content = (string) $post->post_content;
  $sha256hash = hash('sha256', $content);

  // Primary key used by verify-badge
  update_post_meta($post_ID, 'bcpcontentsha256hash', $sha256hash);

  // Aliases for REST/other modules
  update_post_meta($post_ID, 'bcp_content_sha256_hash', $sha256hash);
  update_post_meta($post_ID, 'bcpsha256', $sha256hash);

  // Initialize verification flag if unset (client or cron can set to 1 after on-chain)
  if (get_post_meta($post_ID, 'bcpverified', true) === '') {
    update_post_meta($post_ID, 'bcpverified', 0);
  }

  // Fire an action for async jobs (IPFS pinning, keccak of CID, on-chain record, etc.)
  do_action('bcp_hash_generated', $post_ID, $sha256hash, ['source' => 'save_post']);
}, 10, 3);
