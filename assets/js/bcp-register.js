// File: assets/js/bcp-register.js
// Requires PHP to inject: window.BCP = { root: rest_url(), nonce: wp_create_nonce('wp_rest'), site: location.origin, urls:{dashboard:'/dashboard'} }

(function () {
  const $ = (s) => document.querySelector(s);
  const connectBtn = $('#bcp-connect');
  const form = $('#bcp-register-form');
  const addrEl = $('#bcp-address');
  const nameEl = $('#bcp-name');
  const emailEl = $('#bcp-email');
  const passEl = $('#bcp-pass');
  const statusEl = $('#bcp-status');

  // REST base (prefer PHP-localized BCP.root; fallback to old BCP.rest or default namespace)
  const API_ROOT =
    (window.BCP && (BCP.root || BCP.rest)) ||
    (window.location.origin + '/wp-json/bcp/v1/');

  let address = null;
  let token = null;

  function setStatus(t, kind = 'info') {
    if (!statusEl) return;
    statusEl.textContent = t;
    statusEl.className = 'bcp-status ' + kind;
  }

  function disable(el, on) {
    if (!el) return;
    // If a form is passed, disable its fields
    if (el.tagName === 'FORM') {
      Array.from(el.elements || []).forEach((i) => (i.disabled = !!on));
    } else {
      el.disabled = !!on;
    }
    el.classList.toggle('is-loading', !!on);
  }

  // Unified POST helper: always attaches X-WP-Nonce for REST (cookie-based auth)
  async function post(path, body) {
    const url = API_ROOT.replace(/\/$/, '') + '/' + String(path).replace(/^\//, '');
    const headers = {
      'Accept': 'application/json',
      'Content-Type': 'application/json'
    };
    const wpNonce = (window.BCP && BCP.nonce) || '';
    if (wpNonce) headers['X-WP-Nonce'] = wpNonce; // required for state-changing requests
    const res = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers,
      body: JSON.stringify(body || {})
    });
    let data = {};
    try { data = await res.json(); } catch (_) { /* non-JSON */ }
    if (!res.ok || data.success === false) {
      const msg = (data && (data.message || data.error)) || ('HTTP ' + res.status);
      throw new Error(msg);
    }
    return data;
  }

  // Build a compact SIWE-style message (aligns with backend parsing)
  function makeMessage(domain, address, chainId, nonce) {
    return `Sign in with Ethereum
Domain: ${domain}
Address: ${address}
Chain ID: ${chainId}
Nonce: ${nonce}`;
  }

  async function connectWallet() {
    try {
      if (!window.ethereum) {
        setStatus('MetaMask not detected', 'error');
        return;
      }
      disable(connectBtn, true);

      // Request account
      const [acct] = await window.ethereum.request({ method: 'eth_requestAccounts' });
      if (!acct) throw new Error('No account selected');
      address = acct;
      if (addrEl) addrEl.value = address;
      setStatus('Wallet connected. Generating nonce…', 'info');

      // 1) Request server nonce for this address
      const n = await post('auth/nonce', { address });
      const nonce = n.nonce;

      // 2) Compose message
      const chainHex = await window.ethereum.request({ method: 'eth_chainId' });
      const chainNum = parseInt(chainHex, 16) || 0;
      const domain = (() => {
        try { return new URL((window.BCP && BCP.site) || window.location.origin).host; }
        catch { return window.location.host; }
      })();
      const message = makeMessage(domain, address, chainNum, nonce);

      // 3) Sign the message
      setStatus('Please sign the login message in MetaMask…', 'info');
      const signature = await window.ethereum.request({
        method: 'personal_sign',
        params: [message, address]
      });

      // 4) Verify on server and receive session token
      const v = await post('auth/verify', {
        address, signature, nonce, chain_id: chainNum, message
      });
      token = v.token;

      // Persist session context
      try {
        sessionStorage.setItem('bcp_token', token);
        sessionStorage.setItem('bcp_address', address);
      } catch (_) {}

      // Notify and reveal form
      try {
        document.dispatchEvent(new CustomEvent('bcp:wallet-verified', { detail: { address } }));
      } catch (_) {}
      setStatus('Wallet verified. Fill the form to create account.', 'success');
      if (form) form.style.display = 'block';
    } catch (err) {
      console.error(err);
      // Help surface nonce problems (403/invalid)
      const msg = String(err?.message || 'Wallet connect/verify failed');
      setStatus(msg.includes('nonce') ? 'Invalid/expired nonce. Hard refresh the page and try again.' : msg, 'error');
    } finally {
      disable(connectBtn, false);
    }
  }

  async function onRegister(e) {
    e.preventDefault();
    if (!address || !token) {
      setStatus('Connect and verify wallet first.', 'error');
      return;
    }

    const name = (nameEl?.value || '').trim();
    const email = (emailEl?.value || '').trim();
    const pass = (passEl?.value || '');

    if (!name || !email || !pass) {
      setStatus('All fields are required.', 'error');
      return;
    }

    try {
      disable(form, true);
      setStatus('Creating account…', 'info');

      const rsp = await post('user/register', {
        address,
        token,
        name,
        email,
        password: pass
      });

      if (!rsp.success) throw new Error(rsp.message || 'Registration failed');

      setStatus('Account created. Redirecting to dashboard…', 'success');
      const dash = (window.BCP && BCP.urls && BCP.urls.dashboard) ? BCP.urls.dashboard : '/dashboard';
      setTimeout(() => { window.location.href = dash; }, 900);
    } catch (err) {
      console.error(err);
      const msg = String(err?.message || 'Registration failed');
      setStatus(msg.includes('nonce') ? 'Invalid/expired nonce. Refresh page and retry.' : msg, 'error');
    } finally {
      disable(form, false);
    }
  }

  // UI wiring
  if (connectBtn) connectBtn.addEventListener('click', (e) => { e.preventDefault(); connectWallet(); });
  if (form) form.addEventListener('submit', onRegister);

  // If wallet already verified elsewhere, reveal form and prefill
  document.addEventListener('DOMContentLoaded', () => {
    const a = sessionStorage.getItem('bcp_address');
    if (a) {
      address = a;
      if (addrEl) addrEl.value = a;
      if (form) form.style.display = 'block';
      setStatus('Wallet already connected.', 'info');
    }
  });

  // React to account/network changes
  if (window.ethereum) {
    window.ethereum.on?.('accountsChanged', (accs) => {
      const a = accs && accs[0];
      address = a || null;
      token = null;
      try {
        sessionStorage.removeItem('bcp_token');
        if (a) sessionStorage.setItem('bcp_address', a); else sessionStorage.removeItem('bcp_address');
      } catch (_) {}
      if (addrEl) addrEl.value = a || '';
      setStatus(a ? 'Account changed. Please re-verify.' : 'Wallet disconnected.', 'warning');
      if (form) form.style.display = a ? 'none' : 'none';
    });
    window.ethereum.on?.('chainChanged', () => {
      setStatus('Network changed. Reconnect wallet.', 'warning');
    });
  }
})();
