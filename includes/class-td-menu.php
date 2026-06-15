<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Top-level "Terrific Digital" admin menu. Forms is the first feature nested
 * beneath it; future features can register their own submenus under TD_MENU.
 */
class TD_Menu {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 9 );
		add_filter( 'parent_file', array( __CLASS__, 'parent_file' ) );
		add_filter( 'submenu_file', array( __CLASS__, 'submenu_file' ) );
	}

	public static function menu() {
		add_menu_page(
			'Terrific Digital', 'Terrific Digital', 'edit_pages',
			TD_MENU, array( __CLASS__, 'dashboard' ), 'dashicons-screenoptions', 26
		);
		// Rename the auto-created first submenu (defaults to "Terrific Digital").
		add_submenu_page( TD_MENU, 'Dashboard', 'Dashboard', 'edit_pages', TD_MENU, array( __CLASS__, 'dashboard' ) );
		// Forms feature.
		add_submenu_page( TD_MENU, 'Forms', 'Forms', 'edit_pages', 'edit.php?post_type=' . TF_CPT );
		add_submenu_page( TD_MENU, 'Add New Form', 'Add New', 'edit_pages', 'post-new.php?post_type=' . TF_CPT );
		// (TF_Submissions adds "Submissions" under TD_MENU at a later priority.)
	}

	/** Keep the Terrific Digital top-level highlighted on Forms CPT screens. */
	public static function parent_file( $parent_file ) {
		global $typenow;
		if ( TF_CPT === $typenow ) { return TD_MENU; }
		return $parent_file;
	}

	public static function submenu_file( $submenu_file ) {
		global $typenow, $pagenow;
		if ( TF_CPT === $typenow ) {
			return ( 'post-new.php' === $pagenow ) ? 'post-new.php?post_type=' . TF_CPT : 'edit.php?post_type=' . TF_CPT;
		}
		return $submenu_file;
	}

	public static function dashboard() {
		$forms = wp_count_posts( TF_CPT );
		$total_forms = isset( $forms->publish ) ? (int) $forms->publish : 0;
		global $wpdb;
		$subs = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'tf_submissions' );
		?>
		<div class="wrap">
			<h1>Terrific Digital</h1>
			<p style="font-size:14px;color:#555;max-width:720px">Terrific Digital toolkit for this site. More features will live here over time — for now it includes <strong>Terrific Forms</strong>.</p>
			<div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:20px">
				<div style="flex:1 1 260px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:20px">
					<h2 style="margin-top:0">📝 Terrific Forms</h2>
					<p>Drag-and-drop form builder — regular &amp; multi-step, static IDs, dataLayer events, submissions &amp; CSV export.</p>
					<p><strong><?php echo esc_html( $total_forms ); ?></strong> forms · <strong><?php echo esc_html( $subs ); ?></strong> submissions</p>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . TF_CPT ) ); ?>" class="button button-primary">Manage Forms</a>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . TF_CPT . '&page=tf-submissions' ) ); ?>" class="button">Submissions</a>
				</div>
				<div style="flex:1 1 260px;background:#f6f7f7;border:1px dashed #c3c4c7;border-radius:8px;padding:20px;color:#777">
					<h2 style="margin-top:0;color:#777">More coming soon</h2>
					<p>This is where future Terrific Digital features will appear.</p>
				</div>
			</div>
		</div>
		<?php
	}
}
