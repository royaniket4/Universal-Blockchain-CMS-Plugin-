// [bcp_user_dashboard] — high-contrast, responsive, working tabs
add_shortcode('bcp_user_dashboard', function () {
    if (function_exists('bcp_enqueue_core_assets_for_shortcode')) {
        bcp_enqueue_core_assets_for_shortcode();
    }
    ob_start(); ?>
    <div class="bcp-app">
      <aside class="bcp-sidebar" id="bcpSidebar">
        <div class="bcp-brand">
          <div class="bcp-logo">BC</div>
          <div class="bcp-title">Dashboard</div>
        </div>
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
                <tr><td>Sample post</td><td>Draft</td><td><button class="bcp-btn sm">Edit</button></td></tr>
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

    <script>
    (function () {
      const sidebar = document.getElementById('bcpSidebar');
      const menuBtn = document.getElementById('bcpMenuToggle');
      const userBtn = document.getElementById('bcpUserBtn');
      const userMenu = document.getElementById('bcpUserMenu');
      const title = document.getElementById('bcpTopTitle');

      // Tabs
      const tabs = ['home','posts','create','settings'];
      function showTab(t) {
        tabs.forEach(k => {
          document.getElementById('tab-' + k).classList.toggle('active', k === t);
        });
        document.querySelectorAll('.bcp-vert-nav .nav-item').forEach(b => {
          b.classList.toggle('active', b.dataset.tab === t);
        });
        title.textContent = t.charAt(0).toUpperCase() + t.slice(1);
      }
      document.querySelectorAll('.bcp-vert-nav .nav-item').forEach(btn => {
        btn.addEventListener('click', () => showTab(btn.dataset.tab));
      });
      document.querySelectorAll('[data-tab-switch]').forEach(btn => {
        btn.addEventListener('click', () => showTab(btn.getAttribute('data-tab-switch')));
      });

      // Mobile sidebar toggle
      if (menuBtn) menuBtn.addEventListener('click', () => sidebar.classList.toggle('show'));

      // User menu
      if (userBtn && userMenu) {
        userBtn.addEventListener('click', () => userMenu.classList.toggle('show'));
        document.addEventListener('click', (e) => {
          if (!userBtn.contains(e.target)) userMenu.classList.remove('show');
        });
      }

      // Fake create handler -> toast
      const form = document.getElementById('bcpCreate');
      const toast = document.getElementById('bcpToast');
      if (form && toast) {
        form.addEventListener('submit', (e) => {
          e.preventDefault();
          toast.textContent = 'Post submitted (demo)';
          toast.style.display = 'block';
          toast.classList.add('show');
          setTimeout(() => { toast.classList.remove('show'); toast.style.display = 'none'; }, 1800);
        });
      }

      // Wallet connect button proxies to existing script if present
      const connect = document.getElementById('bcpConnect');
      if (connect && window.BCP) {
        connect.addEventListener('click', () => {
          // your bcp-wallet-login.js can hook this event
          window.dispatchEvent(new CustomEvent('bcp:connect-request'));
        });
      }
    })();
    </script>
    <?php
    return ob_get_clean();
});
