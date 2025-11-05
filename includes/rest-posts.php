<?php
// File: includes/rest-posts.php
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {

  // GET /bcp/v1/posts?search=&mine=1&page=1&per_page=10
  register_rest_route('bcp/v1', '/posts', [
    'methods'             => WP_REST_Server::READABLE,
    'permission_callback' => function () { return current_user_can('edit_posts'); },
    'callback'            => function (WP_REST_Request $req) {
      $args = [
        'post_type'      => 'post',
        'post_status'    => ['publish','draft','pending'],
        's'              => sanitize_text_field($req->get_param('search')),
        'paged'          => max(1, (int)$req->get_param('page')),
        'posts_per_page' => max(1, min(50, (int)$req->get_param('per_page') ?: 10)),
      ];
      if ($req->get_param('mine')) $args['author'] = get_current_user_id();
      $q = new WP_Query($args);
      $rows = [];
      foreach ($q->posts as $p) {
        $rows[] = bcp_rest_posts_format_post($p->ID);
      }
      return new WP_REST_Response([
        'total' => (int)$q->found_posts,
        'page'  => (int)$args['paged'],
        'rows'  => $rows,
      ], 200);
    },
  ]);

  // GET /bcp/v1/posts/{id}
  register_rest_route('bcp/v1', '/posts/(?P<id>\d+)', [
    'methods'             => WP_REST_Server::READABLE,
    'permission_callback' => function () { return current_user_can('edit_posts'); },
    'args'                => ['id' => ['validate_callback' => 'is_numeric']],
    'callback'            => function (WP_REST_Request $req) {
      $id = (int)$req['id'];
      if (!current_user_can('edit_post', $id)) return new WP_Error('forbidden', 'Insufficient rights', ['status'=>403]);
      if (get_post_status($id) === false) return new WP_Error('not_found','Post not found',['status'=>404]);
      return new WP_REST_Response(bcp_rest_posts_format_post($id), 200);
    },
  ]);

  // POST /bcp/v1/posts  (create or update with JSON or multipart)
  register_rest_route('bcp/v1', '/posts', [
    'methods'             => WP_REST_Server::CREATABLE,
    'permission_callback' => function () { return current_user_can('edit_posts'); },
    'callback'            => function (WP_REST_Request $req) {
      $params = $req->get_body_params();
      // Handle JSON or multipart
      $title   = sanitize_text_field($params['title'] ?? '');
      $content = wp_kses_post($params['content'] ?? '');
      $desc    = sanitize_text_field($params['description'] ?? ''); // optional
      $cat     = sanitize_text_field($params['category'] ?? 'General');
      $status  = sanitize_text_field($params['status'] ?? 'publish'); // or 'draft'
      $post_id = isset($params['id']) ? (int)$params['id'] : 0;

      $postarr = [
        'post_title'   => $title,
        'post_content' => $content,
        'post_status'  => in_array($status, ['publish','draft','pending'], true) ? $status : 'draft',
        'post_type'    => 'post',
      ];
      if ($post_id) {
        if (!current_user_can('edit_post', $post_id)) return new WP_Error('forbidden','Insufficient rights',['status'=>403]);
        $postarr['ID'] = $post_id;
        $post_id = wp_update_post($postarr, true);
      } else {
        $postarr['post_author'] = get_current_user_id();
        $post_id = wp_insert_post($postarr, true);
      }
      if (is_wp_error($post_id)) return $post_id;

      // Meta upserts (hashes/CID may be provided by client or computed below)
      $sha256   = sanitize_text_field($params['sha256'] ?? '');
      $keccak   = sanitize_text_field($params['keccak256'] ?? '');
      $ipfscid  = sanitize_text_field($params['ipfscid'] ?? '');
      $ext_link = esc_url_raw($params['link'] ?? '');
      if ($desc)    update_post_meta($post_id, 'bcp_description', $desc);
      if ($cat)     update_post_meta($post_id, 'bcp_category', $cat);
      if ($ext_link)update_post_meta($post_id, 'bcp_link', $ext_link);

      // If a file or content payload was submitted, optionally pin to IPFS server-side
      $did_upload = false;
      if (!empty($_FILES['document']) && !empty($_FILES['document']['tmp_name'])) {
        $bin   = file_get_contents($_FILES['document']['tmp_name']);
        $fname = sanitize_file_name($_FILES['document']['name']);
        $cid   = bcp_ipfs_pin_upload($bin, $fname); // implement below
        if ($cid) { $ipfscid = $cid; $did_upload = true; }
      }
      // If no SHA/Keccak provided, compute from canonical content JSON
      if (!$sha256 || !$keccak) {
        $payload = wp_json_encode([
          'title'       => $title,
          'description' => $desc,
          'content'     => $content,
          'category'    => $cat,
          'link'        => $ext_link,
          'hasFile'     => $did_upload,
        ], JSON_UNESCAPED_SLASHES);
        $sha256 = hash('sha256', $payload);
        if (!$keccak && function_exists('keccak_256')) {
          $keccak = keccak_256($payload);
        } else {
          // Fallback using PHP keccak via sodium if available or leave blank
          $keccak = $keccak ?: $sha256; // best-effort fallback
        }
      }
      if ($sha256)  update_post_meta($post_id, 'bcp_sha256', $sha256);
      if ($keccak)  update_post_meta($post_id, 'bcp_keccak256', $keccak);
      if ($ipfscid) update_post_meta($post_id, 'bcp_ipfs_cid', $ipfscid);

      return new WP_REST_Response(bcp_rest_posts_format_post($post_id), 200);
    },
  ]);

  // GET /bcp/v1/posts/{id}/meta and POST /bcp/v1/posts/{id}/meta/{key}
  register_rest_route('bcp/v1', '/posts/(?P<id>\d+)/meta', [
    'methods'             => WP_REST_Server::READABLE,
    'permission_callback' => function (WP_REST_Request $req) { return current_user_can('edit_post', (int)$req['id']); },
    'callback'            => function (WP_REST_Request $req) {
      $id = (int)$req['id'];
      $keys = ['bcp_sha256','bcp_keccak256','bcp_ipfs_cid','bcp_verified','bcp_contract_tx','bcp_category','bcp_description','bcp_link'];
      $meta = [];
      foreach ($keys as $k) $meta[$k] = get_post_meta($id, $k, true);
      return new WP_REST_Response($meta, 200);
    },
  ]);

  register_rest_route('bcp/v1', '/posts/(?P<id>\d+)/meta/(?P<key>[a-zA-Z0-9_\-]+)', [
    'methods'             => WP_REST_Server::CREATABLE,
    'permission_callback' => function (WP_REST_Request $req) { return current_user_can('edit_post', (int)$req['id']); },
    'callback'            => function (WP_REST_Request $req) {
      $id  = (int)$req['id'];
      $key = sanitize_key($req['key']);
      $val = $req->get_param('value');
      update_post_meta($id, $key, maybe_serialize($val));
      return new WP_REST_Response(['ok'=>true,'key'=>$key,'value'=>$val], 200);
    },
  ]);
});

// Helpers
function bcp_rest_posts_format_post($id) {
  return [
    'id'          => (int)$id,
    'title'       => get_the_title($id),
    'content'     => apply_filters('the_content', get_post_field('post_content', $id)),
    'date'        => get_post_time('Y-m-d', false, $id),
    'status'      => get_post_status($id),
    'category'    => get_post_meta($id, 'bcp_category', true) ?: 'General',
    'description' => get_post_meta($id, 'bcp_description', true) ?: '',
    'ipfs_cid'    => get_post_meta($id, 'bcp_ipfs_cid', true) ?: '',
    'sha256'      => get_post_meta($id, 'bcp_sha256', true) ?: '',
    'keccak256'   => get_post_meta($id, 'bcp_keccak256', true) ?: '',
    'verified'    => (bool)get_post_meta($id, 'bcp_verified', true),
    'contract_tx' => get_post_meta($id, 'bcp_contract_tx', true) ?: '',
  ];
}

/**
 * IPFS upload using configured gateway (e.g., Pinata/Web3.Storage).
 * Expect site options: bcp_ipfs_provider, bcp_ipfs_api_key, bcp_ipfs_secret.
 */
function bcp_ipfs_pin_upload($bytes, $filename='payload.txt') {
  $provider = get_option('bcp_ipfs_provider', 'pinata');
  $apiKey   = get_option('bcp_ipfs_api_key', '');
  $secret   = get_option('bcp_ipfs_secret', '');
  if (!$apiKey) return '';

  $ch = curl_init();
  if ($provider === 'pinata') {
    $url = 'https://api.pinata.cloud/pinning/pinFileToIPFS';
    $boundary = wp_generate_uuid4();
    $body = "--$boundary\r\n"
      . "Content-Disposition: form-data; name=\"file\"; filename=\"" . $filename . "\"\r\n"
      . "Content-Type: application/octet-stream\r\n\r\n"
      . $bytes . "\r\n--$boundary--\r\n";
    curl_setopt_array($ch, [
      CURLOPT_URL => $url,
      CURLOPT_POST => true,
      CURLOPT_HTTPHEADER => [
        "pinata_api_key: $apiKey",
        "pinata_secret_api_key: $secret",
        "Content-Type: multipart/form-data; boundary=$boundary",
      ],
      CURLOPT_POSTFIELDS => $body,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 30,
    ]);
  } else {
    // Web3.Storage
    $url = 'https://api.web3.storage/upload';
    curl_setopt_array($ch, [
      CURLOPT_URL => $url,
      CURLOPT_POST => true,
      CURLOPT_HTTPHEADER => [
        "Authorization: Bearer $apiKey",
        "Content-Type: application/octet-stream",
      ],
      CURLOPT_POSTFIELDS => $bytes,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 30,
    ]);
  }
  $res = curl_exec($ch);
  if (curl_errno($ch)) { curl_close($ch); return ''; }
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($code >= 200 && $code < 300) {
    $json = json_decode($res, true);
    // Pinata returns IpfsHash, Web3.Storage returns {cid: "..."}
    return $json['IpfsHash'] ?? $json['cid'] ?? '';
  }
  return '';
}
