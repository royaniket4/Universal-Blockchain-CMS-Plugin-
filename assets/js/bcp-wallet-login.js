/* assets/js/bcp-wallet-login.js
   Fixes:
   - Define safe global state (AUTH_BUSY + BCPState.authBusy) to prevent "AUTH_BUSY is not defined"
   - One-time init guard to avoid double execution if enqueued twice
   - Robust REST base resolver with /wp-json and ?rest_route= fallbacks + health probe
   - Automatic X-WP-Nonce on writes
   - Always redirect to dashboard after successful verify
*/
(function () {
  'use strict';

  // One-time init guard (avoid double execution)
  if (window.__BCP_WALLET_INIT__) return;
  window.__BCP_WALLET_INIT__ = true;

  // Namespaces and state
  if (!window.BCP) window.BCP = {};
  window.BCPState = window.BCPState || { authBusy: false };

  // Back-compat global flag so any legacy code won't crash
  let AUTH_BUSY = false;
  if (typeof window.AUTH_BUSY === 'undefined') window.AUTH_BUSY = false;

  // -------- REST base resolution --------
  let REST_BASE = null;
  const CANDIDATES = (function () {
    const roots = [];
    const fromBCP = (window.BCP && (BCP.rest || BCP.root)) ? (BCP.rest || BCP.root) : '';
    const fromWP  = (window.wpApiSettings && wpApiSettings.root) ? wpApiSettings.root : '';
    const fromLink = (document.querySelector('link[rel="https://api.w.org/"]') || {}).href || '';
    const origin = window.location.origin.replace(/\/+$/, '');
    if (fromBCP) roots.push(fromBCP);
    if (fromWP)  roots.push(String(fromWP).replace(/\/+$/, '') + '/bcp/v1');
    if (fromLink) roots.push(String(fromLink).replace(/\/+$/, '') + '/bcp/v1');
    roots.push(origin + '/wp-json/bcp/v1');
    roots.push(origin + '/?rest_route=/bcp/v1');
    return Array.from(new Set(roots.map(u => String(u).replace(/\/+$/, ''))));
  })();

  async function probeRestBase() {
    for (const base of CANDIDATES) {
      try {
        const u = base + '/__health';
        const r = await fetch(u, { credentials: 'same-origin', cache: 'no-store' });
        if (r.ok) { REST_BASE = base; return base; }
      } catch (_) {}
    }
    throw new Error('BCP REST base not reachable');
  }
  function ensureBase() { return REST_BASE ? Promise.resolve(REST_BASE) : probeRestBase(); }

  const WPNONCE = (window.BCP && BCP.nonce) ? BCP.nonce : null;

  // -------- UI helpers --------
  function $(id){ return document.getElementById(id); }
  const hint = $('bcp-hint');
  function toast(msg, type = 'info'){
    if (!hint) return;
    hint.textContent = msg;
    hint.className = 'bcp-hint ' + type;
  }
  function getDomainHost() {
    try { return new URL(window.BCP?.site || window.location.origin).hostname; }
    catch { return window.location.hostname; }
  }

  // -------- JSON helper (POST) with fallback --------
  async function postJSON(path, payload) {
    const base = await ensureBase();
    const headers = { 'Accept': 'application/json', 'Content-Type': 'application/json' };
    if (WPNONCE) headers['X-WP-Nonce'] = WPNONCE;

    const url = base.replace(/\/+$/, '') + '/' + String(path).replace(/^\//, '');
    let res = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers, body: JSON.stringify(payload || {})
    });

    // Retry with ?rest_route= if first try was 404 and we weren’t already using it
    if (res.status === 404 && !base.includes('?rest_route=')) {
      const alt = window.location.origin.replace(/\/+$/, '') + '/?rest_route=/bcp/v1/' + String(path).replace(/^\//,'');
      res = await fetch(alt, { method: 'POST', credentials: 'same-origin', headers, body: JSON.stringify(payload||{}) });
    }

    let data = {};
    try { data = await res.json(); } catch {}
    if (!res.ok || data?.success === false) {
      const msg = data?.message || ('HTTP ' + res.status);
      throw new Error(msg);
    }
    return data;
  }

  // -------- Signup form handler (public endpoint) --------
  (function attachSignupSubmit(){
    const form = document.getElementById('bcp-reg-form');
    const msg  = document.getElementById('bcp-reg-msg');
    if (!form) return;
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(form);
      const payload = {
        name:     (fd.get('name') || '').trim(),
        email:    (fd.get('email') || '').trim(),
        password: String(fd.get('password') || ''),
        address:  (fd.get('address') || '').toLowerCase()
      };
      if (!payload.address) { if (msg){ msg.textContent='Wallet address missing'; msg.className='bcp-hint error'; } return; }
      try {
        if (msg){ msg.textContent = 'Creating account...'; msg.className='bcp-hint info'; }
        const data = await postJSON('user/register', payload);
        if (data?.success !== true) throw new Error(data?.message || 'Registration failed');
        const to = (window.BCP?.urls?.dashboard) || data?.redirect || '/dashboard';
        window.location.href = to;
      } catch (err) {
        console.error(err);
        if (msg){ msg.textContent = err?.message || 'Registration failed'; msg.className='bcp-hint error'; }
      }
    });
  })();

  // -------- Wallet module --------
  const BCPWallet = {
    async getAddressByRequest() {
      if (!window.ethereum) throw new Error('MetaMask not detected');
      const addrs = await window.ethereum.request({ method: 'eth_requestAccounts' });
      if (!addrs || !addrs.length) throw new Error('No account');
      return addrs[0];
    },

    async fetchNonce(address) {
      const d = await postJSON('auth/nonce', { address });
      if (!d?.nonce) throw new Error('Nonce fetch failed');
      return d.nonce;
    },

    makeMessage(domain, address, chainId, nonce) {
      return `Sign in with Ethereum
Domain: ${domain}
Address: ${address}
Chain ID: ${chainId}
Nonce: ${nonce}`;
    },

    async signMessage(address, nonce) {
      const chainIdHex = await window.ethereum.request({ method: 'eth_chainId' });
      const chainId = parseInt(chainIdHex, 16) || 0;
      const domain = getDomainHost();
      const message = this.makeMessage(domain, address, chainId, nonce);
      const signature = await window.ethereum.request({
        method: 'personal_sign',
        params: [message, address]
      });
      return { signature, chainId, message };
    },

    async verify(address, signature, nonce, chainId, message) {
      const data = await postJSON('auth/verify', { address, signature, nonce, chain_id: chainId, message });
      if (!data?.token) throw new Error(data?.message || 'Verify failed');
      try {
        sessionStorage.setItem('bcp_token', data.token);
        sessionStorage.setItem('bcp_address', address);
      } catch {}
      return true;
    },

    async login() {
      // Use both flags (legacy + new) to prevent races
      if (AUTH_BUSY || window.BCPState.authBusy) return false;
      AUTH_BUSY = true; window.BCPState.authBusy = true; window.AUTH_BUSY = true;
      try {
        const address = await this.getAddressByRequest();
        let nonce  = await this.fetchNonce(address);
        let signed = await this.signMessage(address, nonce);
        try {
          await this.verify(address, signed.signature, nonce, signed.chainId, signed.message);
        } catch (err) {
          // Auto-retry once on nonce mismatch/expired
          if (/nonce\s+(mismatch|expired)/i.test(err.message || '')) {
            nonce  = await this.fetchNonce(address);
            signed = await this.signMessage(address, nonce);
            await this.verify(address, signed.signature, nonce, signed.chainId, signed.message);
          } else {
            throw err;
          }
        }
        smartRedirectAfterSuccess();
        return true;
      } finally {
        AUTH_BUSY = false; window.BCPState.authBusy = false; window.AUTH_BUSY = false;
      }
    },

    logout() {
      try {
        sessionStorage.removeItem('bcp_token');
        sessionStorage.removeItem('bcp_address');
      } catch {}
    }
  };

  // Always go to dashboard after verify
  function smartRedirectAfterSuccess() {
    const dash = (window.BCP?.urls?.dashboard) || '/dashboard';
    const target = new URL(dash, window.location.origin).toString();
    if (window.location.href !== target) window.location.href = dash;
  }

  // Expose globally and bind connect button
  window.BCPWallet = BCPWallet;

  const connectBtn = $('bcp-connect-btn');
  if (connectBtn) {
    connectBtn.addEventListener('click', async (e) => {
      e.preventDefault();
      if (!window.ethereum) {
        alert('MetaMask not detected. Redirecting to install page.');
        window.location.href = 'https://metamask.io/download.html';
        return;
      }
      try {
        if (AUTH_BUSY || window.BCPState.authBusy) return;
        AUTH_BUSY = true; window.BCPState.authBusy = true; window.AUTH_BUSY = true;
        connectBtn.disabled = true;
        await window.ethereum.request({ method: 'eth_requestAccounts' });
        await window.BCPWallet.login();
        toast('Authenticated.', 'success');
      } catch (err) {
        console.error(err);
        toast(err?.message || 'Login failed', 'error');
      } finally {
        AUTH_BUSY = false; window.BCPState.authBusy = false; window.AUTH_BUSY = false;
        setTimeout(() => { connectBtn.disabled = false; }, 1000);
      }
    });
  }

  if (typeof window.ethereum === 'undefined') {
    toast('Tip: Install MetaMask to enable wallet login.', 'info');
  }
})();
