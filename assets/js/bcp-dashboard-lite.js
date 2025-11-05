(function () {
  // Helpers
  const byId = (id) => document.getElementById(id);
  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  // REST base + URL helper (prefer localized BCP.root, fallback to prior BCPDATA/restBase or default namespace)
  const REST_ROOT = (window.BCP && BCP.root) ||
                    (window.BCPDATA && BCPDATA.restBase) ||
                    (window.location.origin + '/wp-json/bcp/v1/');
  const rest = (path = '') => new URL(path, REST_ROOT).toString();

  // Unified fetch helper: attaches X-WP-Nonce for state-changing requests
  async function bcpFetch(method, path, { body, headers = {}, json = false } = {}) {
    const opts = {
      method,
      credentials: 'same-origin', // cookie-based auth
      headers: { ...headers }
    };
    // Only add nonce on state-changing requests
    if (/^(POST|PUT|PATCH|DELETE)$/i.test(method)) {
      const nonce = (window.BCP && BCP.nonce) || '';
      if (nonce) opts.headers['X-WP-Nonce'] = nonce;
    }
    // Body handling
    if (body instanceof FormData) {
      opts.body = body; // let browser set multipart boundary
    } else if (body != null) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = json ? JSON.stringify(body) : body;
    }
    const res = await fetch(rest(path), opts);
    if (!res.ok) {
      const t = await res.text().catch(()=>'');
      throw new Error(`Fetch failed ${res.status} ${t}`);
    }
    const ct = res.headers.get('Content-Type') || '';
    if (ct.includes('application/json')) return res.json();
    return res.text();
  }

  // Fetch lists for Home + All Posts (GET is fine without nonce but uses helper for consistency)
  async function fetchPosts(query = '', page = 1, per = 8) {
    const u = new URL(rest('posts'));
    if (query) u.searchParams.set('search', query);
    u.searchParams.set('page', page);
    u.searchParams.set('per_page', per);
    const res = await fetch(u.toString(), { credentials: 'same-origin' });
    if (!res.ok) throw new Error('Fetch failed ' + res.status);
    return await res.json(); // { total, page, rows }
  }

  // Views + title switching
  const VIEWS = {
    home: byId('bcp-view-home'),
    posts: byId('bcp-view-posts'),
    create: byId('bcp-view-create'),
    settings: byId('bcp-view-settings'),
  };
  const TITLES = { home: 'Home', posts: 'All Posts', create: 'Create Post', settings: 'Settings' };
  const titleEl = byId('bcp-top-title');

  $$('.bcp-vert-nav .nav-item').forEach((btn) => {
    btn.addEventListener('click', () => {
      $$('.bcp-vert-nav .nav-item').forEach((b) => b.classList.remove('is-active'));
      btn.classList.add('is-active');
      Object.values(VIEWS).forEach((v) => (v.style.display = 'none'));
      const v = btn.dataset.view;
      if (VIEWS[v]) VIEWS[v].style.display = 'block';
      if (titleEl) titleEl.textContent = TITLES[v] || 'Dashboard';
      if (v === 'home') renderHome();
      if (v === 'posts') renderAllPosts();
      if (v === 'settings') loadSettings();
    });
  });

  // Profile menu
  const pmenu = byId('bcp-profile-menu');
  const pbtn = byId('bcp-profile-btn');
  pbtn?.addEventListener('click', () => {
    if (!pmenu) return;
    pmenu.style.display = pmenu.style.display === 'block' ? 'none' : 'block';
  });
  document.addEventListener('click', (e) => {
    if (!e.target.closest('.bcp-profile') && pmenu) pmenu.style.display = 'none';
  });

  // Home (latest 6 from REST)
  async function renderHome() {
    const list = byId('bcp-home-list');
    if (!list) return;
    list.innerHTML = '<div class="bcp-sub">Loading…</div>';
    try {
      const data = await fetchPosts('', 1, 6);
      const items = Array.isArray(data?.rows) ? data.rows : [];
      list.innerHTML =
        items.map(renderCard).join('') || '<div class="bcp-sub">No posts found.</div>';
    } catch (err) {
      list.innerHTML = `<div class="bcp-sub">Failed to load: ${escapeHtml(err?.message || 'Error')}</div>`;
    }
  }

  // All posts with search + pager (from REST)
  let currentPage = 1;
  async function renderAllPosts() {
    const per = 8;
    const q = (byId('bcp-search')?.value || '').trim().toLowerCase();
    const listEl = byId('bcp-posts-list');
    const pagerEl = byId('bcp-posts-pager');
    if (listEl) listEl.innerHTML = '<div class="bcp-sub">Loading…</div>';
    if (pagerEl) pagerEl.innerHTML = '';

    try {
      const data = await fetchPosts(q, currentPage, per);
      const items = Array.isArray(data?.rows) ? data.rows : [];
      if (listEl) {
        listEl.innerHTML =
          items.map(renderRow).join('') || '<div class="bcp-sub">No posts found.</div>';
      }
      const total = Number(data?.total || 0);
      const pages = Math.max(1, Math.ceil(total / per));
      if (pagerEl) {
        pagerEl.innerHTML =
          (currentPage > 1
            ? `<button class="bcp-btn" data-pg="${currentPage - 1}">Prev</button>`
            : '') +
          `<span style="align-self:center;padding:6px 8px">Page ${currentPage} of ${pages}</span>` +
          (currentPage < pages
            ? `<button class="bcp-btn" data-pg="${currentPage + 1}">Next</button>`
            : '');
      }
    } catch (err) {
      if (listEl)
        listEl.innerHTML = `<div class="bcp-sub">Failed to load: ${escapeHtml(
          err?.message || 'Error'
        )}</div>`;
    }
  }

  byId('bcp-refresh')?.addEventListener('click', () => {
    currentPage = 1;
    renderAllPosts();
  });
  byId('bcp-search')?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      currentPage = 1;
      renderAllPosts();
    }
  });
  byId('bcp-posts-pager')?.addEventListener('click', (e) => {
    const b = e.target.closest('button[data-pg]');
    if (!b) return;
    currentPage = Number(b.dataset.pg) || 1;
    renderAllPosts();
  });

  // Create post -> REST POST; server computes hashes/IPFS if needed
  byId('bcp-create-form')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const hint = byId('bcp-create-hint');
    if (hint) {
      hint.className = 'bcp-hint';
      hint.textContent = 'Publishing…';
    }
    try {
      const fd = new FormData(e.target);
      const title = String(fd.get('title') || '').trim();
      const description = String(fd.get('description') || '').trim();
      const content = String(fd.get('content') || '').trim();
      if (!title || !description || !content) {
        if (hint) {
          hint.className = 'bcp-hint error';
          hint.textContent = 'Please fill all required fields.';
        }
        return;
      }

      // Use helper so X-WP-Nonce is attached automatically
      await bcpFetch('POST', 'posts', { body: fd });

      if (hint) {
        hint.className = 'bcp-hint success';
        hint.textContent = 'Published.';
      }
      e.target.reset();
      await renderAllPosts();
      await renderHome();
      $$('.bcp-vert-nav .nav-item[data-view="posts"]')[0]?.click();
    } catch (err) {
      if (hint) {
        hint.className = 'bcp-hint error';
        hint.textContent = err?.message || 'Failed to publish';
      }
    }
  });

  byId('bcp-save-draft')?.addEventListener('click', () => {
    const hint = byId('bcp-create-hint');
    if (hint) {
      hint.className = 'bcp-hint';
      hint.textContent = 'Draft saved locally.';
    }
  });

  // Settings
  async function loadSettings() {
    const f = byId('bcp-settings-form');
    if (!f) return;
    const saved = JSON.parse(localStorage.getItem('BCP_USER') || '{}');
    if (f.username) f.username.value = saved.username || 'student';
    if (f.email) f.email.value = saved.email || 'student@example.com';
    if (f.wallet) f.wallet.value = saved.wallet || '';

    const host = byId('bcp-my-posts');
    if (!host) return;
    host.innerHTML = '<div class="bcp-sub">Loading…</div>';
    try {
      const data = await fetchPosts('', 1, 10);
      const rows = Array.isArray(data?.rows) ? data.rows : [];
      host.innerHTML =
        rows.map(renderRow).join('') || '<div class="bcp-sub">No posts yet.</div>';
    } catch {
      host.innerHTML = '<div class="bcp-sub">Could not load posts.</div>';
    }
  }

  byId('bcp-settings-form')?.addEventListener('submit', (e) => {
    e.preventDefault();
    const payload = Object.fromEntries(new FormData(e.target).entries());
    localStorage.setItem('BCP_USER', JSON.stringify(payload));
    const hint = byId('bcp-settings-hint');
    if (hint) {
      hint.className = 'bcp-hint success';
      hint.textContent = 'Settings saved.';
    }
  });

  byId('bcp-connect-wallet')?.addEventListener('click', async () => {
    const f = byId('bcp-settings-form');
    if (!window.ethereum) {
      alert('MetaMask not found');
      return;
    }
    try {
      const accs = await window.ethereum.request({ method: 'eth_requestAccounts' });
      if (f?.wallet) f.wallet.value = accs && accs[0] ? accs[0] : '';
    } catch {
      /* ignore */
    }
  });

  // Render helpers
  const badge = (ok) =>
    `<span class="bcp-badge ${ok ? '' : 'warn'}">${ok ? 'Verified' : 'Unverified'}</span>`;

  const renderCard = (p) => {
    const title = p?.title || p?.name || 'Untitled';
    const date = p?.date || p?.created_at || '';
    const category = p?.category || p?.category_name || 'General';
    const author = p?.author || p?.user || 'Author';
    const desc = p?.description || p?.excerpt || '';
    return `
      <div class="bcp-card">
        ${badge(!!p?.verified)}
        <h3>${escapeHtml(title)}</h3>
        <div class="bcp-sub">${escapeHtml(date)} · ${escapeHtml(category)} · by ${escapeHtml(author)}</div>
        <p>${escapeHtml(desc)}</p>
      </div>`;
  };

  const renderRow = (p) => {
    const title = p?.title || p?.name || 'Untitled';
    const date = p?.date || p?.created_at || '';
    const category = p?.category || p?.category_name || 'General';
    const author = p?.author || p?.user || 'Author';
    const link = p?.permalink || p?.link || '#';
    return `
      <div class="bcp-card">
        ${badge(!!p?.verified)}
        <div class="bcp-flex bcp-gap" style="justify-content:space-between;align-items:center">
          <div>
            <strong>${escapeHtml(title)}</strong>
            <div class="bcp-sub">${escapeHtml(date)} · ${escapeHtml(category)} · by ${escapeHtml(author)}</div>
          </div>
          <div class="bcp-flex bcp-gap">
            <a class="bcp-btn" href="${escapeAttr(link)}" target="_blank" rel="noopener">View</a>
          </div>
        </div>
      </div>`;
  };

  function escapeHtml(s) {
    return String(s || '').replace(/[&<>"']/g, (m) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#39;'
    }[m]));
  }
  function escapeAttr(s) {
    return String(s || '').replace(/"/g, '&quot;');
  }

  // Initial
  renderHome();
})();
