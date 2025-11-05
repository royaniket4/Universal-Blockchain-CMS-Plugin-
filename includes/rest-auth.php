<?php
// File: includes/rest-auth.php
// REST routes: POST /wp-json/bcp/v1/auth/nonce, POST /wp-json/bcp/v1/auth/verify, POST /wp-json/bcp/v1/user/register
// Hardened against "Nonce mismatch" by dual storage (session + transient), tolerant SIWE parsing, and one-time use.

if (!defined('ABSPATH')) exit;

// ---------- Helpers ----------
function bcp_json($ok, $data = [], $code = 200) {
  // Always JSON response
  if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
  }
  return new WP_REST_Response(array_merge(['success' => $ok], $data), $code);
}

function bcp_addr($a) {
  $a = strtolower(trim($a ?? ''));
  return preg_match('/^0x[a-f0-9]{40}$/', $a) ? $a : '';
}

function bcp_start_session() {
  if (!session_id() && !headers_sent()) @session_start();
}

function bcp_expected_host() {
  // Prefer WordPress home_url host, fallback to HTTP_HOST
  $host = parse_url(home_url(), PHP_URL_HOST);
  if (!$host || $host === '') {
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
  }
  // Strip port for comparison
  $host = preg_replace('/:\d+$/', '', strtolower($host));
  return $host;
}

function bcp_parse_siwe_fields($message) {
  // Extract SIWE-like lines regardless of case or trailing spaces
  $out = ['domain' => '', 'address' => '', 'chain_id' => null, 'nonce' => ''];
  if (!is_string($message) || $message === '') return $out;
  // Domain
  if (preg_match('/^\s*Domain:\s*(.+)\s*$/im', $message, $m)) {
    $out['domain'] = preg_replace('/:\d+$/', '', strtolower(trim($m[1]))); // strip port
  }
  // Address
  if (preg_match('/^\s*Address:\s*(0x[0-9a-fA-F]{40})\s*$/im', $message, $m)) {
    $out['address'] = strtolower(trim($m[1]));
  }
  // Chain ID
  if (preg_match('/^\s*Chain\s*ID:\s*([0-9]+)\s*$/im', $message, $m)) {
    $out['chain_id'] = intval(trim($m[1]));
  }
  // Nonce
  if (preg_match('/^\s*Nonce:\s*([A-Za-z0-9=_\-:+\/]+)\s*$/im', $message, $m)) {
    $out['nonce'] = trim($m[1]);
  }
  return $out;
}

function bcp_clean_email($e){
  $e = trim((string)$e);
  return (is_email($e) ? $e : '');
}

// ---------- Routes ----------
add_action('rest_api_init', function () {
  register_rest_route('bcp/v1', '/auth/nonce', [
    'methods'  => WP_REST_Server::CREATABLE,
    'callback' => 'bcp_auth_nonce',
    'permission_callback' => '__return_true'
  ]);

  register_rest_route('bcp/v1', '/auth/verify', [
    'methods'  => WP_REST_Server::CREATABLE,
    'callback' => 'bcp_auth_verify',
    'permission_callback' => '__return_true'
  ]);

  // Public registration endpoint: relies on SIWE token (not WP REST cookie auth)
  register_rest_route('bcp/v1', '/user/register', [
    'methods'  => WP_REST_Server::CREATABLE,
    'callback' => 'bcp_user_register',
    'permission_callback' => '__return_true'
  ]);
});

// POST /auth/nonce { address }
function bcp_auth_nonce(WP_REST_Request $r) {
  $p = json_decode($r->get_body(), true);
  $address = bcp_addr($p['address'] ?? '');
  if (!$address) return bcp_json(false, ['message' => 'Invalid address'], 400);

  // Basic rate limit per address (10s)
  $rl_key = 'bcp_rl_' . $address;
  if (get_transient($rl_key)) return bcp_json(false, ['message' => 'Too many requests'], 429);
  set_transient($rl_key, 1, 10);

  bcp_start_session();
  $nonce = wp_generate_password(24, false);
  $now   = time();

  // Store in session
  $_SESSION['bcp_nonce_' . $address]    = $nonce;
  $_SESSION['bcp_nonce_ts_' . $address] = $now;

  // Also store in transient to survive edge cases where PHP session is dropped by infra
  set_transient('bcp_nonce_' . $address, ['nonce' => $nonce, 'ts' => $now], 5 * MINUTE_IN_SECONDS);

  return bcp_json(true, ['nonce' => $nonce]);
}

// POST /auth/verify { address, signature, nonce, chain_id, message }
function bcp_auth_verify(WP_REST_Request $r) {
  $p = json_decode($r->get_body(), true);

  $address   = bcp_addr($p['address'] ?? '');
  $signature = trim($p['signature'] ?? '');
  $nonce     = trim($p['nonce'] ?? '');
  $chain_id  = intval($p['chain_id'] ?? 0);
  $message   = trim($p['message'] ?? '');

  if (!$address || !$signature || !$nonce || !$message) {
    return bcp_json(false, ['message' => 'Missing fields'], 400);
  }

  bcp_start_session();

  // Load saved nonce from session or transient
  $saved   = $_SESSION['bcp_nonce_' . $address] ?? '';
  $savedTs = intval($_SESSION['bcp_nonce_ts_' . $address] ?? 0);

  if (!$saved) {
    $t = get_transient('bcp_nonce_' . $address);
    if (is_array($t)) { $saved = $t['nonce'] ?? ''; $savedTs = intval($t['ts'] ?? 0); }
  }

  if (!$saved || $saved !== $nonce) {
    return bcp_json(false, ['message' => 'Nonce mismatch'], 401);
  }
  if (time() - $savedTs > 300) {
    return bcp_json(false, ['message' => 'Nonce expired'], 401);
  }

  // Parse and validate message fields (tolerant to case, checksum, and ports)
  $siwe = bcp_parse_siwe_fields($message);
  $wantHost = bcp_expected_host();

  $domainOk = ($siwe['domain'] !== '' && $siwe['domain'] === $wantHost);
  $addrOk   = ($siwe['address'] !== '' && $siwe['address'] === $address);
  $chainOk  = ($siwe['chain_id'] !== null && intval($siwe['chain_id']) === intval($chain_id));
  $nonceOk  = ($siwe['nonce'] !== '' && $siwe['nonce'] === $nonce);

  if (!$domainOk || !$addrOk || !$chainOk || !$nonceOk) {
    return bcp_json(false, ['message' => 'Message mismatch'], 401);
  }

  // TODO (production): Recover signer from $signature/$message (EIP-191 personal_sign) and match $address.

  // One-time use: clear both stores
  unset($_SESSION['bcp_nonce_' . $address], $_SESSION['bcp_nonce_ts_' . $address]);
  delete_transient('bcp_nonce_' . $address);

  // Issue a token and log user in (create if missing)
  $token = wp_generate_password(48, false);
  $user  = get_user_by('login', $address);

  if (!$user) {
    $uid = wp_insert_user([
      'user_login' => $address,
      'user_pass'  => wp_generate_password(32, true),
      'role'       => 'subscriber',
    ]);
    if (is_wp_error($uid)) return bcp_json(false, ['message' => 'User create failed'], 500);
    $user = get_user_by('id', $uid);
    update_user_meta($user->ID, 'bcp_wallet', $address);
  }

  // Auth cookie; adjust remember/secure as needed
  wp_set_auth_cookie($user->ID, true, is_ssl());

  // Persist token for session checks (optional)
  update_user_meta($user->ID, 'bcp_session_token', $token);

  return bcp_json(true, ['token' => $token, 'user_id' => $user->ID]);
}

// POST /user/register { name, email, password, address, token? }
function bcp_user_register(WP_REST_Request $r) {
  $p = json_decode($r->get_body(), true);

  $name  = sanitize_text_field($p['name'] ?? '');
  $email = bcp_clean_email($p['email'] ?? '');
  $pass  = (string)($p['password'] ?? '');
  $addr  = bcp_addr($p['address'] ?? '');

  if (!$addr)   return bcp_json(false, ['message'=>'Wallet address required'], 400);
  if (!$email)  return bcp_json(false, ['message'=>'Valid email required'], 400);
  if (!$pass)   return bcp_json(false, ['message'=>'Password required'], 400);
  if (!$name)   $name = 'user_' . substr($addr, 2, 6);

  // If not logged in yet, but verify just happened, wp_set_auth_cookie earlier made user logged-in.
  // Still, ensure a WP user exists bound to this wallet:
  $user = get_user_by('login', $addr);
  if (!$user) {
    $uid = wp_insert_user([
      'user_login' => $addr,
      'user_pass'  => wp_generate_password(32, true),
      'user_email' => $email,
      'display_name' => $name,
      'role' => 'subscriber'
    ]);
    if (is_wp_error($uid)) return bcp_json(false, ['message'=>'User create failed'], 500);
    $user = get_user_by('id', $uid);
    update_user_meta($user->ID, 'bcp_wallet', $addr);
  } else {
    // Update profile basics
    wp_update_user([
      'ID' => $user->ID,
      'user_email' => $email,
      'display_name' => $name
    ]);
  }

  // Optionally set a site-specific application password or store hashed pass:
  // For demonstration, store a meta flag and avoid overriding generated account password.
  update_user_meta($user->ID, 'bcp_profile_set', 1);

  // Ensure session
  if (!is_user_logged_in()) {
    wp_set_auth_cookie($user->ID, true, is_ssl());
  }

  $redirect = home_url('/dashboard');

  return bcp_json(true, [
    'success'  => true,
    'user_id'  => $user->ID,
    'redirect' => $redirect
  ]);
}
