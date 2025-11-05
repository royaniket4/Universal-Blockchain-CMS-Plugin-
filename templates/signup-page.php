<?php
if (!defined('ABSPATH')) exit;
$base_url = plugin_dir_url(dirname(__FILE__));
get_header();
?>
<link rel="stylesheet" href="<?php echo esc_url($base_url); ?>assets/css/enhanced-bcp-styles.css">

<script>
  window.BCP = window.BCP || {};
  BCP.rest = '<?php echo esc_url_raw( rest_url('bcp/v1') ); ?>';
  BCP.ctx  = 'signup'; // page context for wallet JS
  BCP.nonce = '<?php echo esc_js( wp_create_nonce('wp_rest') ); ?>'; // WP REST nonce
  BCP.urls = {
    login: '<?php echo esc_url( home_url("/login") ); ?>',
    signup: '<?php echo esc_url( home_url("/registration") ); ?>',
    dashboard: '<?php echo esc_url( home_url("/dashboard") ); ?>'
  };
</script>
<script src="<?php echo esc_url($base_url); ?>assets/js/bcp-wallet-login.js"></script>

<div class="bcp-auth-page">
  <div class="bcp-auth-card">
    <h3>📝 Create Account</h3>
    <p class="bcp-sub">Connect wallet, then complete registration.</p>

    <button id="bcp-connect-btn" class="bcp-btn">🦊 Connect MetaMask</button>
    <div id="bcp-hint" class="bcp-hint"></div>

    <form id="bcp-register-form" class="bcp-form" style="display:none; margin-top:16px;">
      <?php wp_nonce_field('bcp_user_register','bcp_user_register_nonce'); ?>
      <label>Full Name
        <input type="text" id="reg_name" required>
      </label>
      <label>Username
        <input type="text" id="reg_username" required>
      </label>
      <label>Email
        <input type="email" id="reg_email" required>
      </label>
      <label>Password
        <input type="password" id="reg_password" required>
      </label>

      <div class="bcp-hint info" id="wallet-address-box" style="display:none;">
        Wallet: <span id="connected-wallet"></span>
      </div>

      <button type="submit" class="bcp-btn">Create Account</button>
    </form>

    <div class="bcp-alt">
      Already have an account?
      <a class="bcp-link" href="<?php echo esc_url( home_url('/login') ); ?>">Login</a>
    </div>
  </div>
</div>

<script>
(function(){
  const REST = (window.BCP && BCP.rest) ? BCP.rest : '/wp-json/bcp/v1';
  const connectBtn = document.getElementById('bcp-connect-btn');
  const form = document.getElementById('bcp-register-form');
  const hint = document.getElementById('bcp-hint');
  const wbox = document.getElementById('wallet-address-box');
  const wspan = document.getElementById('connected-wallet');

  function toast(m,t='info'){ if(hint){ hint.textContent=m; hint.className='bcp-hint '+t; } }

  // Show form as soon as wallet verification completes (also works if wallet JS dispatches event)
  document.addEventListener('bcp:wallet-verified', (ev)=>{
    const addr = ev.detail?.address || sessionStorage.getItem('bcp_address');
    if (!addr) return;
    form.style.display = 'block';
    wbox.style.display = 'block';
    wspan.textContent = addr.slice(0,6)+'...'+addr.slice(-4);
    toast('Wallet verified. Complete registration.', 'success');
  });

  connectBtn?.addEventListener('click', async (e)=>{
    e.preventDefault();
    if (!window.ethereum) { alert('MetaMask not detected'); return; }
    try {
      await window.ethereum.request({ method:'eth_requestAccounts' });
      // Secure POST-only login to bind address + token before registration
      await window.BCPWallet.login();
      const addr = sessionStorage.getItem('bcp_address');
      if (addr){
        form.style.display = 'block';
        wbox.style.display = 'block';
        wspan.textContent = addr.slice(0,6)+'...'+addr.slice(-4);
        toast('Wallet verified. Complete registration.', 'success');
      } else {
        toast('Wallet verification failed. Try again.', 'error');
      }
    } catch (err) {
      console.error(err);
      toast(err?.message || 'Wallet connect error', 'error');
    }
  });

  form?.addEventListener('submit', async (e)=>{
    e.preventDefault();
    const token = sessionStorage.getItem('bcp_token');
    const address = sessionStorage.getItem('bcp_address');
    if (!token || !address) return toast('Please connect wallet first.', 'warn');

    const payload = {
      name: document.getElementById('reg_name').value.trim(),
      username: document.getElementById('reg_username').value.trim(),
      email: document.getElementById('reg_email').value.trim(),
      password: document.getElementById('reg_password').value,
      token, address
    };
    try {
      const res = await fetch(REST + '/user/register', {
        method:'POST',
        headers:{
          'Content-Type':'application/json',
          'X-WP-Nonce': (window.BCP && BCP.nonce) || ''
        },
        body: JSON.stringify(payload),
        credentials:'include'
      });
      const data = await res.json();
      if (!res.ok || !data.success) throw new Error(data.message || 'Registration failed');
      toast('Registration successful. Redirecting...', 'success');
      setTimeout(()=> location.href = (window.BCP?.urls?.dashboard)||'<?php echo esc_url( home_url("/dashboard") ); ?>', 1200);
    } catch (e2){
      toast(e2.message, 'error');
    }
  });

  document.addEventListener('DOMContentLoaded', ()=>{
    const addr = sessionStorage.getItem('bcp_address');
    if (addr) {
      form.style.display = 'block';
      wbox.style.display = 'block';
      wspan.textContent = addr.slice(0,6)+'...'+addr.slice(-4);
      toast('Wallet already connected.', 'info');
    }
  });
})();
</script>
<?php get_footer(); ?>
