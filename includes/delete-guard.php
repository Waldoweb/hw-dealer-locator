<?php
/**
 * Deletion safety (PlayMor lost 13 dealers to permanent deletes in 2025–26).
 *
 * - Trashing asks "Are you sure you want to delete <name>?" (assets/admin.js).
 * - Permanent deletes need an administrator (manage_options), a second confirm,
 *   and the dealer to be in the trash first.
 * - WordPress's daily trash purge skips dealers, so the trash keeps them until an
 *   administrator empties it.
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'pre_delete_post',
	function ( $check, $post ) {
		if ( ! $post || 'hw_dealer' !== $post->post_type || 'auto-draft' === $post->post_status ) {
			return $check;
		}
		if ( wp_doing_cron() ) {
			return false; // The scheduled trash purge.
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return $check;
		}
		if ( 'trash' !== $post->post_status || ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		return $check;
	},
	10,
	2
);

// Hide "Delete Permanently" and "Empty Trash" from people who can't use them.
add_filter(
	'post_row_actions',
	function ( $actions, $post ) {
		if ( 'hw_dealer' === $post->post_type && ! current_user_can( 'manage_options' ) ) {
			unset( $actions['delete'] );
		}
		return $actions;
	},
	10,
	2
);

add_filter(
	'bulk_actions-edit-hw_dealer',
	function ( $actions ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			unset( $actions['delete'] );
		}
		return $actions;
	}
);

add_action(
	'admin_head-edit.php',
	function () {
		if ( 'hw_dealer' === get_current_screen()->post_type && ! current_user_can( 'manage_options' ) ) {
			echo '<style>#delete_all{display:none}</style>';
		}
	}
);

// If a delete was refused, say why instead of WordPress's generic error.
add_action(
	'admin_notices',
	function () {
		$screen = get_current_screen();
		if ( $screen && 'edit-hw_dealer' === $screen->id && 'trash' === ( isset( $_GET['post_status'] ) ? sanitize_key( $_GET['post_status'] ) : '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-info"><p>Dealers stay in the trash until an administrator deletes them; WordPress won\'t empty it automatically.</p></div>';
		}
	}
);

add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || 'hw_dealer' !== $screen->post_type || ! in_array( $hook, array( 'edit.php', 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		if ( 'edit.php' === $hook ) {
			wp_enqueue_script( 'hw-dealer-admin', plugins_url( 'assets/admin.js', HW_DEALER_FILE ), array( 'jquery', 'jquery-ui-sortable' ), HW_DEALER_VERSION, true );
		}
		wp_add_inline_script( 'hw-dealer-admin', 'window.hwDealerDelete = true;', 'before' );
	},
	20
);
