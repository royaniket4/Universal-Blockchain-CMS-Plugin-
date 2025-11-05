<?php
/**
 * Plugin Name: Blockchain CMS Plugin
 * Description: WordPress integration with blockchain features (wallet login, content integrity, admin tools).
 * Version: 1.1.0
 * Author: Group G3
 */
if (!defined('ABSPATH')) exit;

/* -------------------------------------------------
   1) CONSTANTS
--------------------------------------------------*/
define('BCP_DIR', plugin_dir_path(__FILE__));
define('BCP_URL', plugin_dir_url(__FILE__));

/* -------------------------------------------------
   1.a) SIGNUP + DASHBOARD RESOLVERS
--------------------------------------------------*/
function bcp_find_signup_page_id() {
  $pid = (int) get_option('bcp_signup_page_id');
  if ($pid) return $pid;

  $q = new WP_Query([
    'post_type'      => 'page',
    'posts_per_page' => 50,
    's'              => '[bcp_signup]',
    'fields'         => 'ids',
  ]);
  if ($q->have_posts()) {
    foreach ($q->posts as $id) {
      $post = get_post($id);
      if ($post && has_shortcode($post->post_content, 'bcp_signup')) {
        update_option('bcp_signup_page_id', (int) $id, false);
        return (int) $id;
      }
    }
  }
  return 0;
}
function bcp_get_signup_url() {
  $pid = bcp_find_signup_page_id();
  return $pid ? get_permalink($pid) : home_url('/registration/'); // fallback
}

// NEW: find page containing [bcp_user_dashboard] and cache its permalink
function bcp_find_dashboard_page_id() {
  $pid = (int) get_option('bcp_dashboard_page_id');
  if ($pid) return $pid;

  $q = new WP_Query([
    'post_type'      => 'page',
    'posts_per_page' => 50,
    's'              => '[bcp_user_dashboard]',
    'fields'         => 'ids',
  ]);
  if ($q->have_posts()) {
    foreach ($q->posts as $id) {
      $post = get_post($id);
      if ($post && has_shortcode($post->post_content, 'bcp_user_dashboard')) {
        update_option('bcp_dashboard_page_id', (int) $id, false);
        return (int) $id;
      }
    }
  }
  return 0;
}
function bcp_get_dashboard_url() {
  $pid = bcp_find_dashboard_page_id();
  return $pid ? get_permalink($pid) : home_url('/dashboard/'); // fallback slug
}

/* -------------------------------------------------
   2) AUTOLOAD/INCLUDES
--------------------------------------------------*/
add_action('plugins_loaded', function () {
  if (file_exists(BCP_DIR . 'vendor/autoload.php')) require_once BCP_DIR . 'vendor/autoload.php';
  if (file_exists(BCP_DIR . 'src/KeccakHelper.php')) require_once BCP_DIR . 'src/KeccakHelper.php';

  if (file_exists(BCP_DIR . 'includes/common.php')) require_once BCP_DIR . 'includes/common.php';
  if (file_exists(BCP_DIR . 'includes/rest-user.php')) require_once BCP_DIR . 'includes/rest-user.php';
  if (file_exists(BCP_DIR . 'includes/rest-auth.php')) require_once BCP_DIR . 'includes/rest-auth.php';
  if (file_exists(BCP_DIR . 'includes/admin-hash-monitor.php')) require_once BCP_DIR . 'includes/admin-hash-monitor.php';
});

/* -------------------------------------------------
   3) ASSETS (front-end)
--------------------------------------------------*/
add_action('wp_enqueue_scripts', function () {
  if (file_exists(BCP_DIR . 'assets/css/enhanced-bcp-styles.css')) {
    wp_enqueue_style('bcp-styles', BCP_URL . 'assets/css/enhanced-bcp-styles.css', [], '1.1.0');
  }

  // Detect if the current singular has auth shortcodes
  $has_auth_sc = false;
  if (is_singular()) {
    $post = get_post();
    if ($post) {
      $c = $post->post_content ?? '';
      $has_auth_sc = (has_shortcode($c, 'bcp_login') || has_shortcode($c, 'bcp_signup'));
    }
  }

  $is_login  = is_page('login') || is_page('signin') || is_page('log-in');
  $is_signup = is_page('registration') || is_page('register') || is_page('sign-up');

  // Dequeue any legacy handles that might double-load
  wp_dequeue_script('bcp-login');
  wp_dequeue_script('wallet-login');
  wp_dequeue_script('bcp-register');
  wp_dequeue_script('login-advanced');

  if (($is_login || $is_signup) && !$has_auth_sc) {
    // Pre-aliases to avoid AUTH_BUSY errors and ensure global state exists
    $pre = 'window.BCP=window.BCP||{};window.BCPState=window.BCPState||{authBusy:false};'
         . 'if(typeof window.AUTH_BUSY==="undefined"){window.AUTH_BUSY=false;}';

    // Register with cache-busting version using file mtime
    $ver = @filemtime(BCP_DIR . 'assets/js/bcp-wallet-login.js') ?: '1.1.0';
    wp_register_script('bcp-wallet-login', BCP_URL . 'assets/js/bcp-wallet-login.js', [], $ver, true);

    // Inject pre-aliases BEFORE script tag
    wp_add_inline_script('bcp-wallet-login', $pre, 'before');

    // Build runtime config and inject BEFORE script tag
    $urls = [
      'login'     => home_url('/login'),
      'signup'    => bcp_get_signup_url(),
      'dashboard' => bcp_get_dashboard_url(),
    ];
    $cfg = 'window.BCP=window.BCP||{};'
         . 'BCP.root='  . wp_json_encode(rest_url()) . ';'
         . 'BCP.rest='  . wp_json_encode(rest_url('bcp/v1')) . ';'
         . 'BCP.nonce=' . wp_json_encode(wp_create_nonce('wp_rest')) . ';'
         . 'BCP.site='  . wp_json_encode(home_url('/')) . ';'
         . 'BCP.urls='  . wp_json_encode($urls) . ';';
    wp_add_inline_script('bcp-wallet-login', $cfg, 'before');

    wp_enqueue_script('bcp-wallet-login');
  }
});

/* -------------------------------------------------
   3.a) GLOBAL WP LOGIN REDIRECT → DASHBOARD
--------------------------------------------------*/
add_filter('login_redirect', function ($redirect_to, $requested, $user) {
  $url = bcp_get_dashboard_url();
  return $url ?: $redirect_to;
}, 10, 3);

/* -------------------------------------------------
   4) HASHING + ROUTES
--------------------------------------------------*/
if (!function_exists('bcp_keccak256')) {
  function bcp_keccak256($data) {
    if (function_exists('\App\Crypto\keccak256_hex')) return \App\Crypto\keccak256_hex($data);
    if (class_exists('\kornrunner\Keccak')) return \kornrunner\Keccak::hash($data, 256);
    return hash('sha3-256', $data);
  }
}

add_action('save_post', function ($post_id, $post, $update) {
  if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
  if (wp_is_post_revision($post_id)) return;

  $content = (string) $post->post_content;
  $sha     = hash('sha256', $content);
  $keccak  = bcp_keccak256($sha);

  update_post_meta($post_id, 'bcp_sha256', $sha);
  update_post_meta($post_id, 'bcp_keccak256', $keccak);
  update_post_meta($post_id, 'bcp_verified', 1);
}, 10, 3);

add_action('rest_api_init', function () {

  // __health
  register_rest_route('bcp/v1', '/__health', [
    'methods' => 'GET',
    'permission_callback' => '__return_true',
    'callback' => fn() => new WP_REST_Response(['ok'=>true,'time'=>time()], 200)
  ]);

  // verify/post/{id}
  register_rest_route('bcp/v1', '/verify/post/(?P<id>\d+)', [
    'methods' => 'GET',
    'permission_callback' => '__return_true',
    'callback' => function (WP_REST_Request $req) {
      $id = (int) $req['id']; $p = get_post($id);
      if (!$p) return new WP_Error('bcp_not_found','Post not found',['status'=>404]);
      $sha_now = hash('sha256', (string) $p->post_content);
      $kec_now = bcp_keccak256($sha_now);
      $sha = (string) get_post_meta($id,'bcp_sha256',true);
      $kec = (string) get_post_meta($id,'bcp_keccak256',true);
      $ok = ($sha && $kec && $sha_now === $sha && $kec_now === $kec);
      update_post_meta($id,'bcp_verified',$ok?1:0);
      return ['post_id'=>$id,'verified'=>$ok,'sha256'=>$sha_now,'keccak256'=>$kec_now];
    }
  ]);

  // posts/create (TEMP open)
  register_rest_route('bcp/v1', '/posts/create', [
    'methods' => WP_REST_Server::CREATABLE,
    'permission_callback' => '__return_true',
    'args' => [
      'title'   => ['required'=>true,'type'=>'string'],
      'content' => ['required'=>false,'type'=>'string'],
      'status'  => ['required'=>false,'type'=>'string','default'=>'publish'],
    ],
    'callback' => function (WP_REST_Request $req) {
      $title = wp_strip_all_tags((string) $req->get_param('title'));
      if (!$title) return new WP_Error('bcp_bad_title','Title required',['status'=>400]);
      $id = wp_insert_post([
        'post_title'   => $title,
        'post_content' => (string) $req->get_param('content'),
        'post_status'  => (string) ($req->get_param('status') ?: 'publish'),
        'post_type'    => 'post',
      ], true);
      if (is_wp_error($id)) return $id;
      return ['id'=>$id,'title'=>get_the_title($id),'status'=>get_post_status($id),'url'=>get_permalink($id)];
    }
  ]);

  // posts/list
  register_rest_route('bcp/v1', '/posts/list', [
    'methods' => 'GET',
    'permission_callback' => '__return_true',
    'callback' => function () {
      $q = new WP_Query([
        'post_type'=>'post','post_status'=>['publish'],'posts_per_page'=>10,
        'orderby'=>'date','order'=>'DESC','no_found_rows'=>true
      ]);
      return array_map(fn($p)=>[
        'id'=>$p->ID,'title'=>get_the_title($p),'status'=>get_post_status($p),'url'=>get_permalink($p)
      ], $q->posts);
    }
  ]);
});

/* -------------------------------------------------
   5) ACTIVATION/DEACTIVATION
--------------------------------------------------*/
register_activation_hook(__FILE__, function () {
  if (file_exists(BCP_DIR . 'includes/rest-auth.php')) require_once BCP_DIR . 'includes/rest-auth.php';
  if (file_exists(BCP_DIR . 'includes/rest-user.php')) require_once BCP_DIR . 'includes/rest-user.php';
  bcp_find_signup_page_id();
  bcp_find_dashboard_page_id();
  flush_rewrite_rules();
});
register_deactivation_hook(__FILE__, function () {
  flush_rewrite_rules();
});

/* -------------------------------------------------
   6) SHORTCODES
--------------------------------------------------*/
function bcp_enqueue_core_assets_for_shortcode() {
  if (file_exists(BCP_DIR . 'assets/css/enhanced-bcp-styles.css')) {
    wp_enqueue_style('bcp-styles', BCP_URL.'assets/css/enhanced-bcp-styles.css', [], '1.1.0');
  }
  if (!wp_script_is('bcp-wallet-login', 'enqueued')) {

    // Pre aliases
    $pre = 'window.BCP=window.BCP||{};window.BCPState=window.BCPState||{authBusy:false};'
         . 'if(typeof window.AUTH_BUSY==="undefined"){window.AUTH_BUSY=false;}';

    // Register with cache-busting
    $ver = @filemtime(BCP_DIR . 'assets/js/bcp-wallet-login.js') ?: '1.1.0';
    wp_register_script('bcp-wallet-login', BCP_URL.'assets/js/bcp-wallet-login.js', [], $ver, true);

    // Inject pre and runtime config BEFORE the script
    wp_add_inline_script('bcp-wallet-login', $pre, 'before');

    $urls = [
      'login'     => home_url('/login'),
      'signup'    => bcp_get_signup_url(),
      'dashboard' => bcp_get_dashboard_url(),
    ];
    $cfg = 'window.BCP=window.BCP||{};'
         . 'BCP.root='  . wp_json_encode(rest_url()) . ';'
         . 'BCP.rest='  . wp_json_encode(rest_url('bcp/v1')) . ';'
         . 'BCP.nonce=' . wp_json_encode(wp_create_nonce('wp_rest')) . ';'
         . 'BCP.site='  . wp_json_encode(home_url('/')) . ';'
         . 'BCP.urls='  . wp_json_encode($urls) . ';';
    wp_add_inline_script('bcp-wallet-login', $cfg, 'before');

    wp_enqueue_script('bcp-wallet-login');
  }
}

// [bcp_login]
add_shortcode('bcp_login', function () {
  bcp_enqueue_core_assets_for_shortcode();
  ob_start(); ?>
  <div class="bcp-auth-card">
    <h3>🔐 Blockchain Login</h3>
    <p class="bcp-sub">Connect wallet to continue.</p>
    <button id="bcp-connect-btn" class="bcp-btn">🦊 Connect MetaMask</button>
    <div id="bcp-hint" class="bcp-hint"></div>
    <div class="bcp-alt">New here? <a class="bcp-link" href="<?php echo esc_url( bcp_get_signup_url() ); ?>">Create account</a></div>
  </div>
  <script>
  (function(){
    const hint = document.getElementById('bcp-hint');
    function toast(m,t){ if(!hint)return; hint.textContent=m; hint.className='bcp-hint '+(t||'info'); }
    document.getElementById('bcp-connect-btn')?.addEventListener('click', async (e)=>{
      e.preventDefault();
      if (!window.ethereum) { alert('MetaMask not detected'); return; }
      try{
        await window.ethereum.request({ method:'eth_requestAccounts' });
        await window.BCPWallet.login();
        toast('Authenticated. Redirecting...','success');
        const to = (window.BCP?.urls?.dashboard) || '<?php echo esc_js( bcp_get_dashboard_url() ); ?>';
        setTimeout(()=>location.href=to, 500);
      }catch(err){ console.error(err); toast(err?.message||'Login failed','error'); }
    });
  })();
  </script>
  <?php
  return ob_get_clean();
});

// [bcp_signup]
add_shortcode('bcp_signup', function () {
  bcp_enqueue_core_assets_for_shortcode();
  ob_start(); ?>
  <div class="bcp-auth-card">
    <h3>📝 Registration</h3>
    <p class="bcp-sub">Connect wallet to create account.</p>
    <button id="bcp-connect-btn" class="bcp-btn">🦊 Connect MetaMask</button>
    <div id="bcp-hint" class="bcp-hint"></div>
    <div class="bcp-alt">Already have an account? <a class="bcp-link" href="<?php echo esc_url( home_url('/login') ); ?>">Login</a></div>
  </div>

  <div id="bcp-step-details" style="display:none;max-width:540px;margin:16px auto 0">
    <form id="bcp-reg-form" class="bcp-form">
      <label>Full name <input class="form-control" type="text" name="name" placeholder="Your name" required /></label>
      <label>Email <input class="form-control" type="email" name="email" placeholder="you@example.com" required /></label>
      <label>Password <input class="form-control" type="password" name="password" minlength="6" required /></label>
      <input type="hidden" name="address" id="bcp-reg-address" />
      <button type="submit" class="bcp-btn">Create account</button>
    </form>
    <div id="bcp-reg-msg" class="bcp-hint" style="margin-top:8px"></div>
  </div>

  <script>window.BCP=window.BCP||{};window.BCP.ctx='signup';</script>
  <script>
  (function(){
    const hint = document.getElementById('bcp-hint');
    function toast(m,t){ if(!hint)return; hint.textContent=m; hint.className='bcp-hint '+(t||'info'); }
    document.getElementById('bcp-connect-btn')?.addEventListener('click', async (e)=>{
      e.preventDefault();
      if (!window.ethereum) { alert('MetaMask not detected'); return; }
      try{
        await window.ethereum.request({ method:'eth_requestAccounts' });
        await window.BCPWallet.login();
        toast('Wallet verified. Redirecting...','success');
        const to = (window.BCP?.urls?.dashboard) || '<?php echo esc_js( bcp_get_dashboard_url() ); ?>';
        setTimeout(()=>location.href=to, 500);
      }catch(err){ console.error(err); toast(err?.message||'Signup failed','error'); }
    });
  })();
  </script>
  <?php
  return ob_get_clean();
});

// [bcp_verify_badge id="123"]
add_shortcode('bcp_verify_badge', function ($atts) {
  $a = shortcode_atts(['id' => 0], $atts);
  $id = (int) $a['id'];
  if (!$id) return '';
  $sha  = (string) get_post_meta($id, 'bcp_sha256', true);
  $kec  = (string) get_post_meta($id, 'bcp_keccak256', true);
  $post = get_post($id);
  if (!$post) return '';
  $sha_now = hash('sha256', (string) $post->post_content);
  $kec_now = bcp_keccak256($sha_now);
  $ok = ($sha && $kec && $sha_now === $sha && $kec_now === $kec);
  $style = 'display:inline-flex;align-items:center;gap:6px;font-size:13px;';
  $dotOK = 'width:10px;height:10px;border-radius:50%;background:#22c55e;display:inline-block;';
  $dotNO = 'width:10px;height:10px;border-radius:50%;background:#ef4444;display:inline-block;';
  return sprintf('<span class="bcp-verify-badge" style="%s"><i style="%s"></i>%s</span>',
    esc_attr($style), esc_attr($ok ? $dotOK : $dotNO), esc_html($ok ? 'Verified' : 'Changed'));
});

// [bcp_dashboard]
add_shortcode('bcp_dashboard', function () {
  if (file_exists(BCP_DIR . 'assets/css/enhanced-bcp-styles.css')) {
    wp_enqueue_style('bcp-styles', BCP_URL.'assets/css/enhanced-bcp-styles.css', [], '1.1.0');
  }
  ob_start(); ?>
  <div class="bcp-dashboard">
    <h3>📊 Dashboard</h3>
    <p class="bcp-sub">Welcome to your portal.</p>
    <div id="bcp-dash-content"></div>
  </div>
  <?php
  return ob_get_clean();
});

/* -------------------------------------------------
   7) ADMIN NOTICE IF ROUTE MISSING
--------------------------------------------------*/
add_action('admin_notices', function () {
  if (!current_user_can('manage_options')) return;
  $res = wp_remote_get( rest_url('bcp/v1/__health') );
  if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
    echo '<div class="notice notice-error"><p>BCP: REST routes not reachable. Visit Settings → Permalinks → Save to refresh rewrites. Ensure includes/rest-auth.php is present.</p></div>';
  }
});

/* -------------------------------------------------
   8) USER DASHBOARD (LIVE)
--------------------------------------------------*/
add_shortcode('bcp_user_dashboard', function () {
  if (function_exists('bcp_enqueue_core_assets_for_shortcode')) {
    bcp_enqueue_core_assets_for_shortcode();
  }
  ob_start(); ?>
  <div class="bcp-app">
    <aside class="bcp-sidebar" id="bcpSidebar">
      <div class="bcp-brand"><div class="bcp-logo">BC</div><div class="bcp-title">Dashboard</div></div>
      <nav class="bcp-vert-nav">
        <button class="nav-item active" data-tab="home">Home</button>
        <button class="nav-item" data-tab="posts">All Posts</button>
        <button class="nav-item" data-tab="create">Create Post</button>
        <button class="nav-item" data-tab="settings">Settings</button>
      </nav>
      <div class="bcp-footer-note">Navigation</div>
    </aside>
    <main class="bcp-main">
      <div class="bcp-topbar">
        <h2 id="bcpTopTitle">Home</h2>
        <div style="display:flex;gap:10px;align-items:center">
          <button id="bcpMenuToggle" class="bcp-btn ghost sm" aria-label="Toggle menu">☰</button>
          <div class="bcp-userbox" id="bcpUserBtn">
            <div class="bcp-avatar" style="background:linear-gradient(135deg,#5b73ff,#7a8cff)"></div>
            <div class="bcp-userdrop">
              <div id="bcpUserMenu" class="bcp-menu">
                <a href="#" id="bcpProfile">Profile</a>
                <a href="#" id="bcpLogout">Logout</a>
              </div>
            </div>
          </div>
        </div>
      </div>

      <section id="tab-home" class="bcp-tab active">
        <div class="bcp-card-grid">
          <div class="bcp-card">
            <div class="bcp-card-body">
              <h3 class="bcp-card-title">Welcome</h3>
              <p class="bcp-meta">Latest posts will appear here.</p>
              <div class="bcp-actions">
                <button class="bcp-btn sm" data-tab-switch="create">Create</button>
                <button class="bcp-btn ghost sm" data-tab-switch="posts">View all</button>
              </div>
            </div>
          </div>
        </div>
      </section>

      <section id="tab-posts" class="bcp-tab">
        <div class="bcp-table-wrap">
          <table class="bcp-table">
            <thead><tr><th>Title</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody id="bcpPostRows">
              <tr><td colspan="3">No posts loaded yet.</td></tr>
            </tbody>
          </table>
        </div>
      </section>

      <section id="tab-create" class="bcp-tab">
        <form id="bcpCreate" class="bcp-form">
          <label>Title <input class="form-control" type="text" name="title" required></label>
          <label>Description <input class="form-control" type="text" name="description"></label>
          <label>Content <textarea class="form-control" name="content" rows="6"></textarea></label>
          <label>Document <input class="form-control" type="file" name="doc"></label>
          <label>External Link <input class="form-control" type="url" name="link"></label>
          <label>Category
            <select class="form-control" name="category">
              <option value="">Select</option>
              <option>General</option>
              <option>News</option>
            </select>
          </label>
          <button type="submit" class="bcp-btn">Publish</button>
        </form>
        <div id="bcpToast" class="bcp-toast" style="display:none"></div>
      </section>

      <section id="tab-settings" class="bcp-tab">
        <div class="bcp-settings">
          <div class="bcp-kv"><span>Theme</span><span>Blue/Violet</span></div>
          <div class="bcp-kv"><span>Wallet</span><span id="bcpWalletState">Not connected</span></div>
        </div>
        <div class="bcp-settings-actions">
          <button class="bcp-btn ghost" id="bcpConnect">Connect Wallet</button>
          <button class="bcp-btn">Save</button>
        </div>
      </section>
    </main>
  </div>

  <style>
    .bcp-topbar h2,.bcp-card-title,.bcp-vert-nav .nav-item{color:#eaf0ff}
    .bcp-vert-nav .nav-item.active{background:#1a2042;border-color:#2b356e}
    .bcp-card,.bcp-tab{background:rgba(26,32,66,.45)}
  </style>

  <?php
  // Inject REST base + nonce for dashboard too
  echo '<script>window.BCP=window.BCP||{};BCP.root="'.esc_js( rest_url() ).'";BCP.rest="'.esc_js( rest_url('bcp/v1') ).'";BCP.nonce="'.esc_js( wp_create_nonce('wp_rest') ).'";</script>';
  ?>
  <script>
  // Robust REST base resolver (uses injected BCP.rest, wpApiSettings.root, or discovery link)
  (function(){
    const apiLink = document.querySelector('link[rel="https://api.w.org/"]');
    const apiRoot = apiLink && apiLink.href ? apiLink.href.replace(/\/+$/, '') : '';
    const wpRoot  = (window.wpApiSettings && window.wpApiSettings.root) ? String(window.wpApiSettings.root).replace(/\/+$/, '') : '';
    const bcpFromPHP = (window.BCP && BCP.rest) ? String(BCP.rest).replace(/\/+$/, '') : '';
    const resolved = bcpFromPHP || (wpRoot ? (wpRoot + '/bcp/v1') : (apiRoot ? (apiRoot + '/bcp/v1') : ''));
    window.BCP = window.BCP || {};
    if (!window.BCP.rest || !String(window.BCP.rest).length) window.BCP.rest = resolved || (location.origin + '/wp-json/bcp/v1');
  })();
  </script>

  <script>
  (function(){
    const sidebar=document.getElementById('bcpSidebar');
    const menuBtn=document.getElementById('bcpMenuToggle');
    const userBtn=document.getElementById('bcpUserBtn');
    const userMenu=document.getElementById('bcpUserMenu');
    const titleEl=document.getElementById('bcpTopTitle');
    const tabs=['home','posts','create','settings'];
    function showTab(t){
      tabs.forEach(k=>{const el=document.getElementById('tab-'+k); if(el) el.classList.toggle('active',k===t);});
      document.querySelectorAll('.bcp-vert-nav .nav-item').forEach(b=>b.classList.toggle('active',b.dataset.tab===t));
      titleEl.textContent=t.charAt(0).toUpperCase()+t.slice(1);
    }
    document.querySelectorAll('.bcp-vert-nav .nav-item').forEach(btn=>btn.addEventListener('click',()=>showTab(btn.dataset.tab)));
    document.querySelectorAll('[data-tab-switch]').forEach(btn=>btn.addEventListener('click',()=>showTab(btn.getAttribute('data-tab-switch'))));
    if(menuBtn) menuBtn.addEventListener('click',()=>sidebar.classList.toggle('show'));
    if(userBtn&&userMenu){
      userBtn.addEventListener('click',()=>userMenu.classList.toggle('show'));
      document.addEventListener('click',e=>{ if(!userBtn.contains(e.target)) userMenu.classList.remove('show'); });
    }

    const REST = (window.BCP && BCP.rest) ? BCP.rest : '/wp-json/bcp/v1';
    const toastEl = document.getElementById('bcpToast');
    function toast(msg){ if(!toastEl) return; toastEl.textContent=msg; toastEl.style.display='block'; toastEl.classList.add('show'); setTimeout(()=>{ toastEl.classList.remove('show'); toastEl.style.display='none'; },1800); }

    async function makeVerifyBadge(id){
      try{
        const r = await fetch(REST + '/verify/post/' + id, { credentials:'include' });
        const d = await r.json();
        const span = document.createElement('span');
        span.textContent = d?.verified ? 'Verified' : 'Changed';
        span.style.cssText = 'margin-left:8px;font-weight:600;color:'+(d?.verified?'#22c55e':'#ef4444');
        return span;
      }catch(e){
        const span = document.createElement('span'); span.textContent='—'; return span;
      }
    }

    async function loadPosts(){
      const tbody = document.getElementById('bcpPostRows');
      if (!tbody) return;
      tbody.innerHTML = '<tr><td colspan="3">Loading…</td></tr>';
      try{
        const r = await fetch(REST + '/posts/list');
        const data = await r.json();
        tbody.innerHTML = '';
        (data||[]).forEach(async row=>{
          const tr = document.createElement('tr');
          tr.innerHTML = `<td><a href="${row.url}" target="_blank" rel="noopener">${row.title}</a></td>
                          <td>${row.status}</td>
                          <td class="bcp-actions-cell"></td>`;
          const cell = tr.querySelector('.bcp-actions-cell');
          cell.appendChild(await makeVerifyBadge(row.id));
          tbody.appendChild(tr);
        });
        if (!data || !data.length){
          tbody.innerHTML = '<tr><td colspan="3">No posts yet.</td></tr>';
        }
      }catch(e){
        tbody.innerHTML = '<tr><td colspan="3">Failed to load posts.</td></tr>';
      }
    }

    // Create -> publish via REST then refresh list
    const form=document.getElementById('bcpCreate');
    if(form){
      form.addEventListener('submit', async (e)=>{
        e.preventDefault();
        const fd = new FormData(form);
        const payload = {
          title:   (fd.get('title')||'').trim(),
          content: (fd.get('content')||'').trim(),
          status:  'publish'
        };
        if(!payload.title){ toast('Title required'); return; }
        try{
          const res = await fetch(REST + '/posts/create', {
            method:'POST',
            credentials:'include',
            headers:{ 'Content-Type':'application/json','Accept':'application/json' },
            body: JSON.stringify(payload)
          });
          let data = null, text = '';
          try { data = await res.json(); } catch { text = await res.text().catch(()=> ''); }
          if(!res.ok){ toast((data && data.message) || text || ('HTTP '+res.status)); return; }
          toast('Post published');
          form.reset();
          showTab('posts');
          loadPosts();
        }catch(err){
          toast(err?.message || 'Error creating post');
        }
      });
    }

    // initial load
    loadPosts();
  })();
  </script>
  <?php
  return ob_get_clean();
});
