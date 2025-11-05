<?php
if (!defined('ABSPATH')) exit;
$base_url = plugin_dir_url(dirname(__FILE__));
get_header();
?>
<link rel="stylesheet" href="<?php echo esc_url($base_url); ?>assets/css/enhanced-bcp-styles.css">

<script>
  window.BCP = window.BCP || {};
  BCP.rest = '<?php echo esc_url_raw( rest_url('bcp/v1') ); ?>';
  BCP.urls = {
    login: '<?php echo esc_url( home_url("/login") ); ?>',
    signup: '<?php echo esc_url( home_url("/registration") ); ?>',
    dashboard: '<?php echo esc_url( home_url("/dashboard") ); ?>'
  };
</script>
<script src="<?php echo esc_url($base_url); ?>assets/js/bcp-wallet-login.js"></script>

<div class="bcp-auth-page">
  <div class="bcp-auth-card">
    <h3>🔐 Blockchain Login</h3>
    <p class="bcp-sub">Connect wallet to continue.</p>
    <button id="bcp-connect-btn" class="bcp-btn">🦊 Connect MetaMask</button>
    <div id="bcp-hint" class="bcp-hint"></div>

    <div class="bcp-alt">
      New here?
      <a class="bcp-link" href="<?php echo esc_url( home_url('/registration') ); ?>">Create account</a>
    </div>
  </div>
</div>

<script>
(function(){
  const hint = document.getElementById('bcp-hint');
  function toast(m,t='info'){ hint.textContent=m; hint.className='bcp-hint '+t; }

  document.getElementById('bcp-connect-btn')?.addEventListener('click', async (e)=>{
    e.preventDefault();
    if (!window.ethereum) { 
      alert('MetaMask not detected. Redirecting to install page.');
      window.location.href = 'https://metamask.io/download.html';
      return; 
    }
    try {
      await window.ethereum.request({ method:'eth_requestAccounts' }); // popup
      await window.BCPWallet.login(); // nonce -> sign -> verify (POST)
      toast('Authenticated. Redirecting...', 'success');
      setTimeout(()=> location.href = (window.BCP?.urls?.dashboard)||'<?php echo esc_url( home_url("/dashboard") ); ?>', 800);
    } catch (err) {
      console.error(err);
      toast(err?.message || 'Login failed', 'error');
    }
  });
})();
</script>

<?php get_footer(); ?>
