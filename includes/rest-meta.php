<?php
// rest-meta.php
if (!defined('ABSPATH')) { exit; }

add_action('rest_api_init', function () {
  register_rest_route('bcp/v1', '/posts/(?P<id>\d+)/meta', [
    [
      'methods'             => WP_REST_Server::READABLE, // GET
      'callback'            => 'bcp_get_post_integrity_meta',
      'permission_callback' => 'bcp_can_view_post_integrity_meta',
    ],
    [
      'methods'             => WP_REST_Server::CREATABLE, // POST
      'callback'            => 'bcp_update_post_integrity_meta',
      'permission_callback' => 'bcp_can_edit_post_integrity_meta',
    ],
  ]);
});

function bcp_can_view_post_integrity_meta(WP_REST_Request $request) {
  $post_id = (int) $request['id'];
  $post = get_post($post_id);
  if (!$post) {
    return new WP_Error('not_found', 'Post not found', ['status' => 404]);
  }
  // Public if published; otherwise editors only
  if ($post->post_status === 'publish') {
    return true;
  }
  return current_user_can('edit_post', $post_id);
}

function bcp_can_edit_post_integrity_meta(WP_REST_Request $request) {
  $post_id = (int) $request['id'];
  return current_user_can('edit_post', $post_id);
}

function bcp_get_post_integrity_meta(WP_REST_Request $request) {
  $post_id = (int) $request['id'];
  $post = get_post($post_id);
  if (!$post) {
    return new WP_Error('not_found', 'Post not found', ['status' => 404]);
  }

  // Support both legacy and canonical keys
  $sha = get_post_meta($post_id, 'bcp_content_sha256_hash', true);
  if (!$sha) { $sha = get_post_meta($post_id, 'bcpsha256', true); } // canonical in hashing module

  $cid = get_post_meta($post_id, 'bcpipfscid', true);
  if (!$cid) { $cid = get_post_meta($post_id, 'bcp_ipfs_cid', true); }

  $verified = get_post_meta($post_id, 'bcpverified', true);
  if ($verified === '') { $verified = get_post_meta($post_id, 'bcp_verified', true); }
  $verified = (int) (!!$verified);

  return rest_ensure_response([
    'success'         => true,
    'data' => [
      'sha256'        => $sha,
      'bcpipfscid'    => $cid,
      'bcpverified'   => $verified,
    ],
  ]);
}

function bcp_update_post_integrity_meta(WP_REST_Request $request) {
  $post_id = (int) $request['id'];
  $post = get_post($post_id);
  if (!$post) {
    return new WP_Error('not_found', 'Post not found', ['status' => 404]);
  }

  $p = $request->get_json_params() ?: [];

  // Accept aliases but write canonical keys
  $cid_in = isset($p['bcpipfscid']) ? $p['bcpipfscid'] :
            (isset($p['bcp_ipfs_cid']) ? $p['bcp_ipfs_cid'] : null);

  if ($cid_in !== null) {
    $cid = sanitize_text_field(wp_unslash($cid_in));
    // Optional basic CID guard (loose)
    if (!preg_match('/^[A-Za-z0-9+=/_-]{10,}$/', $cid)) {
      return new WP_Error('bad_request', 'Invalid IPFS CID format', ['status' => 400]);
    }
    update_post_meta($post_id, 'bcpipfscid', $cid);
  }

  $ver_in = array_key_exists('bcpverified', $p) ? $p['bcpverified'] :
            (array_key_exists('bcp_verified', $p) ? $p['bcp_verified'] : null);

  if ($ver_in !== null) {
    $verified = (int) (!!$ver_in);
    update_post_meta($post_id, 'bcpverified', $verified);
  }

  // Read back current state
  $resp = [
    'sha256'      => get_post_meta($post_id, 'bcpsha256', true) ?: get_post_meta($post_id, 'bcp_content_sha256_hash', true),
    'bcpipfscid'  => get_post_meta($post_id, 'bcpipfscid', true) ?: get_post_meta($post_id, 'bcp_ipfs_cid', true),
    'bcpverified' => (int) (!!get_post_meta($post_id, 'bcpverified', true)),
  ];

  return rest_ensure_response(['success' => true, 'data' => $resp]);
}
