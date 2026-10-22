<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Top-level "Travel by Luxe" admin menu. Forms is the first feature nested
 * beneath it; future features can register their own submenus under TD_MENU.
 */
class TD_Menu {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 9 );
		add_action( 'admin_head', array( __CLASS__, 'icon_css' ) );
		add_action( 'in_admin_header', array( __CLASS__, 'brand_bar' ) );
		add_filter( 'parent_file', array( __CLASS__, 'parent_file' ) );
		add_filter( 'submenu_file', array( __CLASS__, 'submenu_file' ) );
	}

	/** Is the current admin screen part of the Travel by Luxe plugin? */
	protected static function is_td_screen() {
		if ( ! function_exists( 'get_current_screen' ) ) { return false; }
		$screen = get_current_screen();
		if ( ! $screen ) { return false; }
		$cpts = array( TF_CPT );
		if ( class_exists( 'TD_Snippets' ) ) { $cpts[] = TD_Snippets::CPT; }
		if ( class_exists( 'TD_FAQ' ) ) { $cpts[] = TD_FAQ::CPT; }
		// Our CPT list/edit screens, or any submenu page under the TD_MENU slug.
		return in_array( $screen->post_type, $cpts, true ) || false !== strpos( $screen->id, TD_MENU );
	}

	/**
	 * A shared brand bar (logo + version) printed at the top of every Terrific
	 * Digital admin screen — so the plugin's pages all carry the same identity,
	 * including the native Forms / Code Snippets list & edit screens.
	 */
	public static function brand_bar() {
		if ( ! self::is_td_screen() ) { return; }
		$logo    = TF_URL . 'assets/tbl-logo.svg';
		$updated = self::last_updated();
		echo '<div class="td-brand-bar" style="display:flex;align-items:center;gap:12px;margin:12px 20px 4px 0;padding:9px 16px;background:#fff;border:1px solid #dcdcde;border-radius:8px">'
			. '<img src="' . esc_url( $logo ) . '" alt="Travel by Luxe" style="height:24px;width:auto;display:block">'
			. '<span style="width:1px;height:18px;background:#e0e0e2;display:inline-block"></span>'
			. '<span style="background:#f0f0f1;color:#50575e;border-radius:12px;padding:3px 11px;font-size:12px;font-weight:600;line-height:1.4">v' . esc_html( TD_VERSION ) . '</span>';
		if ( $updated ) {
			echo '<span style="color:#787c82;font-size:12px;font-weight:400">Updated ' . esc_html( wp_date( get_option( 'date_format' ), $updated ) ) . '</span>';
		}
		echo '</div>';
	}

	/** The Travel by Luxe menu mark — an italic serif "L", single-colour so it can be masked white. */
	public static function icon_svg() {
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
			. '<text x="12" y="19" text-anchor="middle" font-family="Georgia, serif" font-size="22" font-style="italic" fill="#fff">L</text>'
			. '</svg>';
	}

	public static function icon_data_uri() {
		return 'data:image/svg+xml;base64,' . base64_encode( self::icon_svg() );
	}

	/**
	 * Recolour the menu icon to match native WordPress icons: a grey mask in the
	 * resting state that turns white when the menu is hovered or current.
	 */
	public static function icon_css() {
		$uri = self::icon_data_uri();
		echo '<style id="td-menu-icon">'
			. '#adminmenu #toplevel_page_' . TD_MENU . ' .wp-menu-image.svg{'
			. 'background-image:none !important;background-color:#a7aaad;'
			. '-webkit-mask:url(\'' . $uri . '\') no-repeat center;mask:url(\'' . $uri . '\') no-repeat center;'
			. '-webkit-mask-size:auto 18px;mask-size:auto 18px;}'
			. '#adminmenu #toplevel_page_' . TD_MENU . ':hover .wp-menu-image.svg,'
			. '#adminmenu #toplevel_page_' . TD_MENU . '.wp-has-current-submenu .wp-menu-image.svg,'
			. '#adminmenu #toplevel_page_' . TD_MENU . '.current .wp-menu-image.svg,'
			. '#adminmenu #toplevel_page_' . TD_MENU . '.wp-menu-open .wp-menu-image.svg{background-color:#fff;}'
			. '</style>';
	}

	public static function menu() {
		add_menu_page(
			'Travel by Luxe', 'Travel by Luxe', 'edit_pages',
			TD_MENU, array( __CLASS__, 'dashboard' ), self::icon_data_uri(), 26
		);
		// Rename the auto-created first submenu (defaults to "Travel by Luxe").
		add_submenu_page( TD_MENU, 'Dashboard', 'Dashboard', 'edit_pages', TD_MENU, array( __CLASS__, 'dashboard' ) );
		// Forms feature. (No "Add New" link — create forms from the Forms list screen.)
		add_submenu_page( TD_MENU, 'Forms', 'Forms', 'edit_pages', 'edit.php?post_type=' . TF_CPT );
		// (TF_Submissions adds "Submissions" under TD_MENU at a later priority.)
	}

	/** Keep the Travel by Luxe top-level highlighted on Forms CPT screens. */
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

	/** Most recent modification time across the plugin's own files (≈ last update). */
	protected static function last_updated() {
		$latest = 0;
		$globs  = array( TF_DIR . '*.php', TF_DIR . 'includes/*.php', TF_DIR . 'assets/*' );
		foreach ( $globs as $g ) {
			foreach ( (array) glob( $g ) as $file ) {
				$m = @filemtime( $file );
				if ( $m && $m > $latest ) { $latest = $m; }
			}
		}
		return $latest;
	}

	public static function dashboard() {
		$forms = wp_count_posts( TF_CPT );
		$total_forms = isset( $forms->publish ) ? (int) $forms->publish : 0;
		global $wpdb;
		$subs = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . TF_Submissions::table() );
		$snip_counts  = wp_count_posts( TD_Snippets::CPT );
		$total_snips  = isset( $snip_counts->publish ) ? (int) $snip_counts->publish : 0;
		$active_snips = count( get_posts( array( 'post_type' => TD_Snippets::CPT, 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_td_snippet_enabled', 'meta_value' => '1' ) ) );
		$faq_counts   = class_exists( 'TD_FAQ' ) ? wp_count_posts( TD_FAQ::CPT ) : null;
		$total_faqs   = ( $faq_counts && isset( $faq_counts->publish ) ) ? (int) $faq_counts->publish : 0;
		$cal_enabled  = class_exists( 'TD_Calendly' ) && TD_Calendly::is_enabled();
		?>
		<div class="wrap">
			<h1 style="margin:8px 0 12px">Dashboard</h1>
			<p style="font-size:14px;color:#555;max-width:720px">The Travel by Luxe site toolkit: <strong>TBL Forms</strong> with <strong>Lead Routing</strong> to the leads app, <strong>Code Snippets</strong>, <strong>FAQ Lists</strong>, and the <strong>Calendly Listener</strong>.</p>
			<div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:20px">
				<div style="flex:1 1 260px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:20px">
					<h2 style="margin-top:0">📝 TBL Forms</h2>
					<p>Drag-and-drop form builder — regular &amp; multi-step, static IDs, dataLayer events, submissions &amp; CSV export.</p>
					<p><strong><?php echo esc_html( $total_forms ); ?></strong> forms · <strong><?php echo esc_html( $subs ); ?></strong> submissions</p>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . TF_CPT ) ); ?>" class="button button-primary">Manage Forms</a>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . TF_CPT . '&page=tf-submissions' ) ); ?>" class="button">Submissions</a>
				</div>
				<div style="flex:1 1 260px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:20px">
					<h2 style="margin-top:0">✂️ Code Snippets</h2>
					<p>Inject custom HTML/JS into your pages at a chosen location (head/body), with page &amp; URL conditions.</p>
					<p><strong><?php echo esc_html( $total_snips ); ?></strong> snippets · <strong><?php echo esc_html( $active_snips ); ?></strong> active</p>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . TD_Snippets::CPT ) ); ?>" class="button button-primary">Manage Snippets</a>
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . TD_Snippets::CPT ) ); ?>" class="button">Add Snippet</a>
				</div>
				<div style="flex:1 1 260px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:20px">
					<h2 style="margin-top:0">❓ FAQ Lists</h2>
					<p>Reusable Q&amp;A accordions — place any list on a page via a shortcode or the TBL FAQ block.</p>
					<p><strong><?php echo esc_html( $total_faqs ); ?></strong> FAQ lists</p>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . TD_FAQ::CPT ) ); ?>" class="button button-primary">Manage FAQ Lists</a>
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . TD_FAQ::CPT ) ); ?>" class="button">Add FAQ List</a>
				</div>
				<div style="flex:1 1 260px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:20px">
					<h2 style="margin-top:0">📅 Calendly Listener</h2>
					<p>Push <code>dataLayer</code> events for the Calendly scheduler widget — <code>meeting_*</code> milestones plus <code>form_submission</code> / <code>meeting_booked</code> on confirmed bookings.</p>
					<p>Status:
						<?php if ( $cal_enabled ) : ?>
							<strong style="color:#1e7e34">● On</strong>
						<?php else : ?>
							<strong style="color:#b32d2e">● Off</strong>
						<?php endif; ?>
					</p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . TD_Calendly::SLUG ) ); ?>" class="button button-primary">
						<?php echo $cal_enabled ? 'Configure' : 'Set up Calendly'; ?>
					</a>
				</div>

				<div style="flex:1 1 260px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:20px">
					<h2 style="margin-top:0">🧭 Lead Routing</h2>
					<p>Sends each submission to the Travel by Luxe leads app, which picks the travel designer and creates the Zendesk ticket, then shows that designer's Calendly.</p>
					<p>Status:
						<?php if ( class_exists( 'TBL_LR_Settings' ) && TBL_LR_Settings::get( 'api_key' ) ) : ?>
							<strong style="color:#1e7e34">● Connected</strong>
						<?php else : ?>
							<strong style="color:#b32d2e">● No API key</strong>
						<?php endif; ?>
					</p>
					<a href="<?php echo esc_url( class_exists( 'TBL_LR_Settings' ) ? TBL_LR_Settings::url() : admin_url( 'admin.php?page=' . TD_MENU ) ); ?>" class="button button-primary">Settings</a>
				</div>

				<div style="flex:1 1 260px;background:#f6f7f7;border:1px dashed #c3c4c7;border-radius:8px;padding:20px;color:#777">
					<h2 style="margin-top:0;color:#777">More coming soon</h2>
					<p>Travel by Luxe features land here as the site grows.</p>
				</div>
			</div>
		</div>
		<?php
	}
}
