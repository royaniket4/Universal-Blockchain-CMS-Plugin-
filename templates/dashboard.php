<?php
if (!defined('ABSPATH')) exit;
/*
  Dashboard Template (Vertical Nav): Home, All Posts, Add Post, Settings
*/
$current_user = wp_get_current_user();
$base_url = plugin_dir_url(dirname(__FILE__)); // templates/ se assets URL
get_header();
?>
<link rel="stylesheet" href="<?php echo esc_url($base_url); ?>assets/css/enhanced-bcp-styles.css">
<script>window.BCP = window.BCP || {}; BCP.rest = '<?php echo esc_url_raw( rest_url('bcp/v1') ); ?>';</script>

<div class="bcp-app">
  <aside class="bcp-sidebar">
    <div class="bcp-brand">
      <div class="bcp-logo">BCP</div>
      <span class="bcp-title">User Panel</span>
    </div>
    <nav class="bcp-vert-nav">
      <button class="nav-item active" data-tab="home">🏠 Home</button>
      <button class="nav-item" data-tab="all">🗂️ All Posts</button>
      <button class="nav-item" data-tab="add">➕ Add Post</button>
      <button class="nav-item" data-tab="settings">⚙️ Settings</button>
    </nav>
    <div class="bcp-footer-note">Secure • Web3 • CMS</div>
  </aside>

  <main class="bcp-main">
    <header class="bcp-topbar">
      <h2 class="bcp-page-head">Dashboard</h2>
      <div class="bcp-userbox">
        <img class="bcp-avatar" src="<?php echo esc_url( get_avatar_url($current_user->ID) ); ?>" alt="">
        <div class="bcp-userdrop">
          <span class="bcp-uname"><?php echo esc_html($current_user->display_name ?: 'User'); ?></span>
          <div class="bcp-menu">
            <a href="<?php echo esc_url( get_edit_profile_url($current_user->ID) ); ?>">Profile</a>
            <a href="<?php echo esc_url( wp_logout_url( home_url('/') ) ); ?>">Logout</a>
          </div>
        </div>
      </div>
    </header>

    <!-- Tabs -->
    <section id="tab-home" class="bcp-tab active">
      <h3>Latest Posts</h3>
      <div class="bcp-card-grid">
        <?php
        $latest = new WP_Query([
          'post_type' => 'post',
          'posts_per_page' => 6,
          'author' => $current_user->ID,
          'post_status' => ['publish','draft','pending']
        ]);
        if ($latest->have_posts()):
          while ($latest->have_posts()): $latest->the_post(); ?>
            <article class="bcp-card">
              <div class="bcp-card-media">
                <?php if (has_post_thumbnail()): the_post_thumbnail('medium'); else: ?>
                  <div class="bcp-placeholder">📝</div>
                <?php endif; ?>
              </div>
              <div class="bcp-card-body">
                <h4 class="bcp-card-title"><?php the_title(); ?></h4>
                <div class="bcp-meta"><?php echo esc_html( get_the_date() ); ?> • <?php echo esc_html( get_post_status() ); ?></div>
                <p class="bcp-excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 18 ) ); ?></p>
                <div class="bcp-actions">
                  <a class="bcp-btn sm" href="<?php the_permalink(); ?>">View</a>
                  <a class="bcp-btn sm ghost" href="<?php echo esc_url( get_edit_post_link(get_the_ID()) ); ?>">Edit</a>
                </div>
              </div>
            </article>
        <?php endwhile; wp_reset_postdata(); else: ?>
          <div class="bcp-empty">No posts yet. Create your first post from “Add Post”.</div>
        <?php endif; ?>
      </div>
    </section>

    <section id="tab-all" class="bcp-tab">
      <h3>All Posts</h3>
      <div class="bcp-table-wrap">
        <table class="bcp-table">
          <thead>
            <tr>
              <th>Title</th>
              <th>Status</th>
              <th>Date</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php
          $all = new WP_Query([
            'post_type'=>'post',
            'posts_per_page'=> -1,
            'author'=> $current_user->ID,
            'post_status'=> ['publish','draft','pending','private']
          ]);
          if ($all->have_posts()):
            while($all->have_posts()): $all->the_post(); ?>
            <tr>
              <td><?php the_title(); ?></td>
              <td><?php echo esc_html( get_post_status() ); ?></td>
              <td><?php echo esc_html( get_the_date() ); ?></td>
              <td class="bcp-row-actions">
                <a href="<?php echo esc_url( get_edit_post_link(get_the_ID()) ); ?>">Edit</a>
                <a href="<?php echo esc_url( get_delete_post_link(get_the_ID()) ); ?>" onclick="return confirm('Delete this post?');">Delete</a>
              </td>
            </tr>
          <?php endwhile; wp_reset_postdata(); else: ?>
            <tr><td colspan="4">No posts found.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section id="tab-add" class="bcp-tab">
      <h3>Add New Post</h3>
      <form id="bcp-add-form" class="bcp-form" method="post" enctype="multipart/form-data">
        <?php wp_nonce_field('bcp_add_post','bcp_add_nonce'); ?>
        <label>Title
          <input type="text" name="post_title" required>
        </label>
        <label>Description
          <textarea name="post_excerpt" rows="2" required></textarea>
        </label>
        <label>Content
          <div class="bcp-toolbar">
            <button type="button" data-cmd="bold"><b>B</b></button>
            <button type="button" data-cmd="italic"><em>I</em></button>
            <button type="button" data-cmd="h2">H2</button>
          </div>
          <div id="bcp-editor" class="bcp-editor" contenteditable="true"></div>
          <input type="hidden" name="post_content">
        </label>
        <label>Attach Documents
          <input type="file" name="post_files[]" multiple>
        </label>
        <label>External Link
          <input type="url" name="post_link" placeholder="https://">
        </label>
        <label>Category
          <?php
            wp_dropdown_categories([
              'name'=>'post_category',
              'show_option_none'=>'Select category',
              'hide_empty'=>0
            ]);
          ?>
        </label>
        <button class="bcp-btn" type="submit">Publish</button>
      </form>
      <?php
      if (!empty($_POST['bcp_add_nonce']) && wp_verify_nonce($_POST['bcp_add_nonce'],'bcp_add_post')) {
        $new_id = wp_insert_post([
          'post_title' => sanitize_text_field($_POST['post_title']),
          'post_excerpt' => sanitize_textarea_field($_POST['post_excerpt']),
          'post_content' => wp_kses_post($_POST['post_content']),
          'post_status' => 'pending',
          'post_author' => $current_user->ID,
          'post_category' => [intval($_POST['post_category'] ?? 0)]
        ]);
        if (!is_wp_error($new_id)) {
          if (!empty($_FILES['post_files']['name'][0])) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            foreach ($_FILES['post_files']['name'] as $i=>$name) {
              $file = [
                'name' => $_FILES['post_files']['name'][$i],
                'type' => $_FILES['post_files']['type'][$i],
                'tmp_name' => $_FILES['post_files']['tmp_name'][$i],
                'error' => $_FILES['post_files']['error'][$i],
                'size' => $_FILES['post_files']['size'][$i],
              ];
              $att_id = media_handle_sideload($file, $new_id);
              if (!is_wp_error($att_id)) {
                add_post_meta($new_id, '_bcp_attachment', $att_id);
              }
            }
          }
          if (!empty($_POST['post_link'])) {
            update_post_meta($new_id, '_bcp_external_link', esc_url_raw($_POST['post_link']));
          }
          echo '<div class="bcp-toast success">Post submitted for review!</div>';
        } else {
          echo '<div class="bcp-toast error">Error creating post.</div>';
        }
      }
      ?>
    </section>

    <section id="tab-settings" class="bcp-tab">
      <h3>Settings</h3>
      <div class="bcp-settings">
        <div class="bcp-kv"><span>Name</span><span><?php echo esc_html($current_user->display_name ?: '—'); ?></span></div>
        <div class="bcp-kv"><span>Email</span><span><?php echo esc_html($current_user->user_email ?: '—'); ?></span></div>
        <div class="bcp-kv"><span>Username</span><span><?php echo esc_html($current_user->user_login ?: '—'); ?></span></div>
        <div class="bcp-kv"><span>Role</span><span><?php echo esc_html( implode(', ', $current_user->roles) ?: '—'); ?></span></div>
      </div>
      <div class="bcp-settings-actions">
        <a class="bcp-btn ghost" href="<?php echo esc_url( get_edit_profile_url($current_user->ID) ); ?>">Profile</a>
        <a class="bcp-btn danger" href="<?php echo esc_url( wp_logout_url( home_url('/') ) ); ?>">Logout</a>
      </div>
    </section>
  </main>
</div>

<script src="<?php echo esc_url($base_url); ?>assets/js/dashboard.js"></script>
<?php get_footer(); ?>
