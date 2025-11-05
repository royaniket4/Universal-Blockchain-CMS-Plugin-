// Location: plugin/assets/js/dashboard.js
document.addEventListener('DOMContentLoaded', function () {
  // Cache lookups
  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  // WordPress REST base and nonce (prefer localized values)
  const REST_ROOT = (window.BCP && (BCP.root || BCP.rest || BCP.resturl))
    ? (BCP.root || BCP.rest || BCP.resturl).replace(/\/+$/, '') + '/'
    : window.location.origin.replace(/\/+$/, '') + '/wp-json/bcp/v1/';
  const WP_NONCE = (window.BCP && BCP.nonce) ? BCP.nonce : '';

  // Unified REST helper:
  // - GET uses cookies only
  // - POST/PUT/PATCH/DELETE attach X-WP-Nonce header
  async function bcpRest(method, path, { body, headers = {} } = {}) {
    const isWrite = /^(POST|PUT|PATCH|DELETE)$/i.test(method);
    const url = REST_ROOT + String(path).replace(/^\//, '');
    const h = { ...headers };
    if (!(body instanceof FormData) && isWrite && !h['Content-Type']) {
      h['Content-Type'] = 'application/json';
    }
    if (isWrite && WP_NONCE) h['X-WP-Nonce'] = WP_NONCE;

    const res = await fetch(url, {
      method,
      credentials: 'same-origin',
      headers: h,
      body: body instanceof FormData ? body : (body != null ? JSON.stringify(body) : undefined)
    });
    const ct = res.headers.get('Content-Type') || '';
    const data = ct.includes('application/json') ? await res.json().catch(()=>null) : await res.text().catch(()=>null);
    if (!res.ok) {
      const msg = (data && (data.message || data.code)) || `HTTP ${res.status}`;
      throw new Error(msg);
    }
    return data;
  }

  // Map tabs once (keys must match data-tab attributes)
  const TAB_MAP = {
    home: '#tab-home',
    all: '#tab-all',
    add: '#tab-add',
    settings: '#tab-settings',
  };

  // Activate one tab utility
  function activateTab(tabKey = 'home') {
    // Clear active classes
    $$('.bcp-vert-nav .nav-item').forEach((b) => b.classList.remove('active'));
    $$('.bcp-tab').forEach((t) => {
      t.classList.remove('active');
      t.setAttribute('aria-hidden', 'true');
    });

    // Apply active to matching panel and nav button
    const panelSel = TAB_MAP[tabKey] || TAB_MAP.home;
    const panel = $(panelSel);
    if (panel) {
      panel.classList.add('active');
      panel.setAttribute('aria-hidden', 'false');
    }

    const activeBtn = $(`.bcp-vert-nav .nav-item[data-tab="${tabKey}"]`);
    activeBtn?.classList.add('active');

    // Scroll to top for mobile
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  // Sidebar nav switching + keyboard support
  $$('.bcp-vert-nav .nav-item').forEach((btn) => {
    // Make buttons tabbable and ARIA-selected
    btn.setAttribute('role', 'tab');
    btn.setAttribute('tabindex', btn.classList.contains('active') ? '0' : '-1');
    btn.setAttribute('aria-selected', btn.classList.contains('active') ? 'true' : 'false');

    btn.addEventListener('click', () => {
      const key = btn.dataset.tab || 'home';
      activateTab(key);

      // Update ARIA and tabindex for all buttons
      $$('.bcp-vert-nav .nav-item').forEach((b) => {
        const isActive = b.dataset.tab === key;
        b.setAttribute('aria-selected', isActive ? 'true' : 'false');
        b.setAttribute('tabindex', isActive ? '0' : '-1');
      });
    });

    // Arrow key navigation (Left/Right)
    btn.addEventListener('keydown', (e) => {
      if (!['ArrowLeft', 'ArrowRight'].includes(e.key)) return;
      const items = $$('.bcp-vert-nav .nav-item');
      const idx = items.indexOf(btn);
      const next =
        e.key === 'ArrowRight'
          ? items[(idx + 1) % items.length]
          : items[(idx - 1 + items.length) % items.length];
      next?.focus();
      next?.click();
    });
  });

  // Ensure one tab is active on load
  const initiallyActive = $('.bcp-vert-nav .nav-item.active')?.dataset.tab || 'home';
  activateTab(initiallyActive);

  // Rich content editor controls
  const editor = document.getElementById('bcp-editor');
  $$('.bcp-toolbar button').forEach((b) => {
    b.addEventListener('click', () => {
      const cmd = b.dataset.cmd;
      try {
        if (!editor) return;
        // Basic commands; 'formatBlock' for headings
        if (cmd === 'h2') {
          document.execCommand('formatBlock', false, 'h2');
        } else if (cmd) {
          document.execCommand(cmd, false, null);
        }
        editor.focus();
      } catch {
        // Silently ignore unsupported commands
      }
    });
  });

  // Transfer editor HTML into hidden field on submit and POST via REST with nonce
  document.getElementById('bcp-add-form')?.addEventListener('submit', async function (e) {
    e.preventDefault();
    const hidden = this.querySelector('input[name="post_content"]');
    if (hidden && editor) {
      hidden.value = String(editor.innerHTML || '').trim();
    }

    const hint = $('#bcp-add-hint');
    try {
      if (hint) { hint.textContent = 'Publishing…'; hint.className = 'bcp-hint'; }

      // Build FormData to support file uploads (no Content-Type header override)
      const fd = new FormData(this);
      await bcpRest('POST', 'posts', { body: fd }); // server computes hashes on save_post

      if (hint) { hint.textContent = 'Published.'; hint.className = 'bcp-hint success'; }
      this.reset();
      if (editor) editor.innerHTML = '';

      // Optionally switch to All tab
      const allBtn = $('.bcp-vert-nav .nav-item[data-tab="all"]');
      allBtn?.click();
    } catch (err) {
      if (hint) { hint.textContent = err?.message || 'Failed to publish'; hint.className = 'bcp-hint error'; }
    }
  });

  // User dropdown toggle + outside/escape to close
  const ubox = document.querySelector('.bcp-userbox');
  const menu = document.querySelector('.bcp-userdrop .bcp-menu');

  ubox?.addEventListener('click', (e) => {
    e.stopPropagation();
    menu?.classList.toggle('show');
  });

  // Close on outside click
  document.addEventListener('click', () => menu?.classList.remove('show'));

  // Close on Escape
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') menu?.classList.remove('show');
  });

  // Close on focus leaving dropdown (accessibility)
  menu?.addEventListener('focusout', (e) => {
    if (!menu.contains(e.relatedTarget)) menu.classList.remove('show');
  });
});
