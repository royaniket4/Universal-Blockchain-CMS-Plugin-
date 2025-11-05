<?php
if (!defined('ABSPATH')) exit;

if (!function_exists('bcp_sanitize_address')) {
  function bcp_sanitize_address($addr) { return strtolower(sanitize_text_field($addr ?? '')); }
}

if (!function_exists('bcp_check_wp_nonce')) {
  function bcp_check_wp_nonce() {
    $nonce = $_SERVER['HTTP_X_WP_NONCE'] ?? '';
    if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
      return new WP_Error('unauthorized', 'Invalid WP nonce', ['status' => 401]);
    }
    return true;
  }
}

if (!function_exists('bcp_issue_session')) {
  function bcp_issue_session($address) {
    $token = wp_generate_password(20, false);
    set_transient('bcp_session_' . $address, $token, 2 * HOUR_IN_SECONDS);
    return $token;
  }
}

if (!function_exists('bcp_check_session')) {
  function bcp_check_session($address, $token) {
    $expected = get_transient('bcp_session_' . $address);
    if (!$expected) return false;
    return hash_equals($expected, sanitize_text_field($token ?? ''));
  }
}
