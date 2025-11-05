<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'bcp_hash_storage_monitor_page' ) ) {
    function bcp_hash_storage_monitor_page() {
        // Block non-admins explicitly, prevents direct URL access too
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Access Denied: Administrator privileges required.' ) );
        }

        echo '<div class="wrap"><h1>Hash Storage Monitor</h1>';
        echo '<p>Admin-only interface to monitor content hash storage.</p>';

        global $wpdb;
        $table_name = $wpdb->prefix . 'bcp_content_hashes';

        // Check table existence safely to avoid fatal SQL errors on fresh sites
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
            DB_NAME, $table_name
        ) );

        if ( ! $exists ) {
            echo '<div class="notice notice-warning"><p>Table not found: ' . esc_html( $table_name ) . '</p></div>';
            echo '</div>';
            return;
        }

        $results = $wpdb->get_results( "SELECT * FROM {$table_name} ORDER BY published_at DESC LIMIT 50" );

        if ( $results ) {
            echo '<table class="widefat fixed striped">';
            echo '<thead><tr><th>ID</th><th>Hash</th><th>IPFS CID</th><th>Author</th><th>Published At</th></tr></thead><tbody>';
            foreach ( $results as $row ) {
                echo '<tr>';
                echo '<td>' . esc_html( $row->id ) . '</td>';
                echo '<td>' . esc_html( $row->content_hash ) . '</td>';
                echo '<td>' . esc_html( $row->ipfs_cid ) . '</td>';
                echo '<td>' . esc_html( $row->author ) . '</td>';
                $ts = is_numeric( $row->published_at ) ? (int) $row->published_at : strtotime( (string) $row->published_at );
                echo '<td>' . esc_html( $ts ? date( 'Y-m-d H:i:s', $ts ) : (string) $row->published_at ) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>No hashes published yet.</p>';
        }

        echo '</div>';
    }
}
