<?php
if (!defined('ABSPATH')) exit;
require_once __DIR__ . '/common.php';

// PROFILE
add_action('rest_api_init', function () {
  register_rest_route('bcp/v1', '/user/profile', [
    'methods'  => 'POST',
    'callback' => function ($req) {
      $nonce_ok = bcp_check_wp_nonce();
      if (is_wp_error($nonce_ok)) return $nonce_ok;
      $d = $req->get_json_params();
      $address = bcp_sanitize_address($d['address'] ?? '');
      $token   = sanitize_text_field($d['token'] ?? '');
      if (!bcp_check_session($address, $token)) return new WP_Error('unauthorized','Invalid session',['status'=>401]);

      $users = get_users(['meta_key'=>'bcp_wallet_address','meta_value'=>$address,'number'=>1]);
      if (!$users) return ['exists'=>false];
      $u = $users[0];
      return ['exists'=>true,'name'=>$u->display_name];
    },
    'permission_callback' => '__return_true',
  ]);

  // REGISTER
  register_rest_route('bcp/v1', '/user/register', [
    'methods'  => 'POST',
    'callback' => function ($req) {
      $nonce_ok = bcp_check_wp_nonce();
      if (is_wp_error($nonce_ok)) return $nonce_ok;
      $d = $req->get_json_params();
      $address = bcp_sanitize_address($d['address'] ?? '');
      $token   = sanitize_text_field($d['token'] ?? '');
      if (!bcp_check_session($address, $token)) return new WP_Error('unauthorized','Invalid session',['status'=>401]);

      $name  = sanitize_text_field($d['name'] ?? '');
      $email = sanitize_email($d['email'] ?? '');
      $pass  = $d['password'] ?? '';
      if (!$name || !$email || !$pass) return new WP_Error('bad_request','Missing fields',['status'=>400]);

      // create or update user
      $users = get_users(['meta_key'=>'bcp_wallet_address','meta_value'=>$address,'number'=>1]);
      if ($users) {
        $u = $users[0];
        wp_update_user(['ID'=>$u->ID,'display_name'=>$name,'user_email'=>$email]);
      } else {
        $uid = wp_insert_user([
          'user_login'   => $email ?: ('w3_' . substr($address,2,6)),
          'user_pass'    => wp_generate_password(20, true, true),
          'user_email'   => $email,
          'display_name' => $name,
        ]);
        if (is_wp_error($uid)) return $uid;
        update_user_meta($uid,'bcp_wallet_address',$address);
      }
      return ['success'=>true,'name'=>$name];
    },
    'permission_callback' => '__return_true',
  ]);

  // POSTS list/create/update/delete/seed are unchanged except they call bcp_check_wp_nonce and bcp_check_session at the top
  // If your current file already has these routes working, keep them and just ensure they require_once common.php and remove any duplicate helper definitions.
});
