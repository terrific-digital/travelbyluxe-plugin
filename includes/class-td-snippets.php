<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Code Snippets — inject custom HTML/JS into the page at a chosen location,
 * gated by URL/page conditions. Admin-only (snippets run arbitrary code).
 *
 * Each snippet is a `td_snippet` post:
 *   _td_snippet_code      raw HTML/JS
 *   _td_snippet_location  after_head_open | before_head_close | after_body_open | after_body_close
 *   _td_snippet_enabled   '1' | ''
 *   _td_snippet_condition all | specific | url_contains | url_not_contains
 *   _td_snippet_pages     int[]  (for 'specific')
 *   _td_snippet_url       string (for url_contains / url_not_contains)
 *
 * Injection is done with a single output buffer so all four positions — including
 * the two WordPress has no hook for ("after <head>" and "after </body>") — are exact.
 */
class TD_Snippets {

	const CPT = 'td_snippet';

	const LOCATIONS = array(
		'after_head_open'   => 'After opening &lt;head&gt;',
		'before_head_close' => 'Before closing &lt;/head&gt;',
		'after_body_open'   => 'After opening &lt;body&gt;',
		'after_body_close'  => 'After closing &lt;/body&gt;',
	);
	const CONDITIONS = array(
		'all'              => 'On all pages',
		'specific'         => 'Specific pages',
		'url_contains'     => 'URL contains…',
		'url_not_contains' => 'URL does not contain…',
	);

	protected static $queued = array();

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 11 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_' . self::CPT, array( __CLASS__, 'save' ), 10, 1 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_buffer' ), 1 );
		// Keep the Terrific Digital menu highlighted while editing a snippet.
		add_filter( 'parent_file', array( __CLASS__, 'parent_file' ) );
		add_filter( 'submenu_file', array( __CLASS__, 'submenu_file' ) );
		// List-table columns.
		add_filter( 'manage_' . self::CPT . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::CPT . '_posts_custom_column', array( __CLASS__, 'column', ), 10, 2 );
	}

	public static function register() {
		register_post_type( self::CPT, array(
			'labels' => array(
				'name'          => 'Code Snippets',
				'singular_name' => 'Code Snippet',
				'add_new_item'  => 'Add New Snippet',
				'edit_item'     => 'Edit Snippet',
				'new_item'      => 'New Snippet',
				'search_items'  => 'Search Snippets',
				'not_found'     => 'No snippets yet',
				'menu_name'     => 'Code Snippets',
			),
			'public'        => false,
			'show_ui'       => true,
			'show_in_menu'  => false, // nested under Terrific Digital.
			'supports'      => array( 'title' ),
			'map_meta_cap'  => true,
			// Admin-only: every capability maps to manage_options.
			'capabilities'  => array(
				'edit_post'           => 'manage_options',
				'read_post'           => 'manage_options',
				'delete_post'         => 'manage_options',
				'edit_posts'          => 'manage_options',
				'edit_others_posts'   => 'manage_options',
				'delete_posts'        => 'manage_options',
				'publish_posts'       => 'manage_options',
				'read_private_posts'  => 'manage_options',
				'create_posts'        => 'manage_options',
			),
		) );
	}

	public static function menu() {
		add_submenu_page( TD_MENU, 'Code Snippets', 'Code Snippets', 'manage_options', 'edit.php?post_type=' . self::CPT );
	}

	public static function parent_file( $parent_file ) {
		global $typenow;
		if ( self::CPT === $typenow ) { return TD_MENU; }
		return $parent_file;
	}

	public static function submenu_file( $submenu_file ) {
		global $typenow, $pagenow;
		if ( self::CPT === $typenow ) {
			return ( 'post-new.php' === $pagenow ) ? 'post-new.php?post_type=' . self::CPT : 'edit.php?post_type=' . self::CPT;
		}
		return $submenu_file;
	}

	/* ------------------------------------------------------------- List table */

	public static function columns( $cols ) {
		$new = array();
		foreach ( $cols as $k => $v ) {
			$new[ $k ] = $v;
			if ( 'title' === $k ) {
				$new['td_loc']  = 'Location';
				$new['td_cond'] = 'Condition';
				$new['td_on']   = 'Active';
			}
		}
		return $new;
	}

	public static function column( $col, $post_id ) {
		if ( 'td_loc' === $col ) {
			$loc = get_post_meta( $post_id, '_td_snippet_location', true );
			echo isset( self::LOCATIONS[ $loc ] ) ? wp_kses( self::LOCATIONS[ $loc ], array() ) : '—';
		}
		if ( 'td_cond' === $col ) {
			$c = get_post_meta( $post_id, '_td_snippet_condition', true );
			echo esc_html( isset( self::CONDITIONS[ $c ] ) ? self::CONDITIONS[ $c ] : 'On all pages' );
		}
		if ( 'td_on' === $col ) {
			$on = get_post_meta( $post_id, '_td_snippet_enabled', true );
			echo $on
				? '<span style="color:#1e7e34;font-weight:600">● On</span>'
				: '<span style="color:#b32d2e">● Off</span>';
		}
	}

	/* ---------------------------------------------------------------- Editor */

	public static function meta_boxes() {
		add_meta_box( 'td-snippet-code', 'Snippet Code (HTML / JS)', array( __CLASS__, 'box_code' ), self::CPT, 'normal', 'high' );
		add_meta_box( 'td-snippet-cfg', 'Placement & Conditions', array( __CLASS__, 'box_cfg' ), self::CPT, 'side', 'default' );
	}

	public static function box_code( $post ) {
		$code = get_post_meta( $post->ID, '_td_snippet_code', true );
		wp_nonce_field( 'td_snippet_save', 'td_snippet_nonce' );
		echo '<p class="description" style="margin-top:0">Raw HTML/JavaScript. Include your own <code>&lt;script&gt;</code> / <code>&lt;style&gt;</code> tags as needed. Injected verbatim — only administrators can edit snippets.</p>';
		echo '<textarea name="td_snippet_code" rows="16" style="width:100%;font-family:Menlo,Consolas,monospace;font-size:13px;line-height:1.5;white-space:pre" spellcheck="false">' . esc_textarea( $code ) . '</textarea>';
	}

	public static function box_cfg( $post ) {
		$enabled   = get_post_meta( $post->ID, '_td_snippet_enabled', true );
		$location  = get_post_meta( $post->ID, '_td_snippet_location', true ) ?: 'before_head_close';
		$condition = get_post_meta( $post->ID, '_td_snippet_condition', true ) ?: 'all';
		$pages     = (array) get_post_meta( $post->ID, '_td_snippet_pages', true );
		$url       = get_post_meta( $post->ID, '_td_snippet_url', true );
		?>
		<p><label><input type="checkbox" name="td_snippet_enabled" value="1" <?php checked( $enabled, '1' ); ?>> <strong>Enabled</strong></label></p>

		<p style="margin-bottom:4px"><strong>Inject location</strong></p>
		<?php foreach ( self::LOCATIONS as $val => $lbl ) : ?>
			<label style="display:block;margin:3px 0">
				<input type="radio" name="td_snippet_location" value="<?php echo esc_attr( $val ); ?>" <?php checked( $location, $val ); ?>>
				<?php echo wp_kses( $lbl, array() ); ?>
			</label>
		<?php endforeach; ?>

		<hr>
		<p style="margin-bottom:4px"><strong>Show on</strong></p>
		<?php foreach ( self::CONDITIONS as $val => $lbl ) : ?>
			<label style="display:block;margin:3px 0">
				<input type="radio" class="td-cond" name="td_snippet_condition" value="<?php echo esc_attr( $val ); ?>" <?php checked( $condition, $val ); ?>>
				<?php echo esc_html( $lbl ); ?>
			</label>
		<?php endforeach; ?>

		<div class="td-cond-pages" style="margin-top:8px">
			<label class="description">Pages (Ctrl/⌘-click to multi-select)</label><br>
			<select name="td_snippet_pages[]" multiple size="8" style="width:100%">
				<?php
				$items = get_posts( array( 'post_type' => array( 'page', 'post' ), 'post_status' => 'publish', 'numberposts' => 300, 'orderby' => 'title', 'order' => 'ASC' ) );
				foreach ( $items as $it ) {
					printf(
						'<option value="%d"%s>%s</option>',
						$it->ID,
						in_array( (string) $it->ID, array_map( 'strval', $pages ), true ) ? ' selected' : '',
						esc_html( get_the_title( $it ) . ' (#' . $it->ID . ')' )
					);
				}
				?>
			</select>
		</div>

		<div class="td-cond-url" style="margin-top:8px">
			<label class="description">URL contains / does not contain</label>
			<input type="text" name="td_snippet_url" class="widefat" value="<?php echo esc_attr( $url ); ?>" placeholder="e.g. /pricing  or  ?utm_source=">
		</div>

		<script>
		( function () {
			function refresh() {
				var c = document.querySelector( '.td-cond:checked' );
				var v = c ? c.value : 'all';
				var p = document.querySelector( '.td-cond-pages' );
				var u = document.querySelector( '.td-cond-url' );
				if ( p ) { p.style.display = ( v === 'specific' ) ? '' : 'none'; }
				if ( u ) { u.style.display = ( v === 'url_contains' || v === 'url_not_contains' ) ? '' : 'none'; }
			}
			document.querySelectorAll( '.td-cond' ).forEach( function ( el ) { el.addEventListener( 'change', refresh ); } );
			refresh();
		} )();
		</script>
		<?php
	}

	public static function save( $post_id ) {
		if ( ! isset( $_POST['td_snippet_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['td_snippet_nonce'] ) ), 'td_snippet_save' ) ) { return; }
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
		if ( ! current_user_can( 'manage_options' ) ) { return; }

		// Code stored raw (admin-only feature). wp_slash counters update_post_meta's unslash.
		if ( isset( $_POST['td_snippet_code'] ) ) {
			update_post_meta( $post_id, '_td_snippet_code', wp_slash( wp_unslash( $_POST['td_snippet_code'] ) ) );
		}
		update_post_meta( $post_id, '_td_snippet_enabled', empty( $_POST['td_snippet_enabled'] ) ? '' : '1' );

		$loc = isset( $_POST['td_snippet_location'] ) ? sanitize_key( wp_unslash( $_POST['td_snippet_location'] ) ) : 'before_head_close';
		update_post_meta( $post_id, '_td_snippet_location', isset( self::LOCATIONS[ $loc ] ) ? $loc : 'before_head_close' );

		$cond = isset( $_POST['td_snippet_condition'] ) ? sanitize_key( wp_unslash( $_POST['td_snippet_condition'] ) ) : 'all';
		update_post_meta( $post_id, '_td_snippet_condition', isset( self::CONDITIONS[ $cond ] ) ? $cond : 'all' );

		$pages = isset( $_POST['td_snippet_pages'] ) ? array_values( array_filter( array_map( 'intval', (array) wp_unslash( $_POST['td_snippet_pages'] ) ) ) ) : array();
		update_post_meta( $post_id, '_td_snippet_pages', $pages );

		update_post_meta( $post_id, '_td_snippet_url', isset( $_POST['td_snippet_url'] ) ? sanitize_text_field( wp_unslash( $_POST['td_snippet_url'] ) ) : '' );
	}

	/* ------------------------------------------------------------ Front-end */

	/** Current full request URL (scheme://host/path?query). */
	protected static function current_url() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? wp_unslash( $_SERVER['HTTP_HOST'] ) : '';
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		return ( is_ssl() ? 'https://' : 'http://' ) . $host . $uri;
	}

	/** Does a snippet's condition match the current request? */
	protected static function matches( $post_id ) {
		$cond = get_post_meta( $post_id, '_td_snippet_condition', true ) ?: 'all';
		if ( 'all' === $cond ) { return true; }
		if ( 'specific' === $cond ) {
			$pages = array_map( 'intval', (array) get_post_meta( $post_id, '_td_snippet_pages', true ) );
			return in_array( (int) get_queried_object_id(), $pages, true );
		}
		$needle = (string) get_post_meta( $post_id, '_td_snippet_url', true );
		if ( '' === $needle ) { return 'url_not_contains' === $cond; } // empty needle: "contains"→no, "not contains"→yes
		$hit = ( false !== stripos( self::current_url(), $needle ) );
		return ( 'url_contains' === $cond ) ? $hit : ! $hit;
	}

	/** Collect enabled + matching snippets for this request. */
	protected static function active_for_request() {
		$out  = array();
		$snips = get_posts( array( 'post_type' => self::CPT, 'post_status' => 'publish', 'numberposts' => 200, 'orderby' => 'date', 'order' => 'ASC' ) );
		foreach ( $snips as $s ) {
			if ( ! get_post_meta( $s->ID, '_td_snippet_enabled', true ) ) { continue; }
			if ( ! self::matches( $s->ID ) ) { continue; }
			$code = (string) get_post_meta( $s->ID, '_td_snippet_code', true );
			if ( '' === trim( $code ) ) { continue; }
			$out[] = array(
				'location' => get_post_meta( $s->ID, '_td_snippet_location', true ) ?: 'before_head_close',
				'code'     => $code,
			);
		}
		return $out;
	}

	public static function maybe_buffer() {
		if ( is_admin() || is_feed() || is_embed() ) { return; }
		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) { return; }
		$snips = self::active_for_request();
		if ( empty( $snips ) ) { return; }
		self::$queued = $snips;
		ob_start( array( __CLASS__, 'inject' ) );
	}

	/** Buffer callback: splice each snippet's code at its chosen position. */
	public static function inject( $html ) {
		if ( ! is_string( $html ) || stripos( $html, '</body>' ) === false ) { return $html; } // not a full HTML document

		$by = array( 'after_head_open' => '', 'before_head_close' => '', 'after_body_open' => '', 'after_body_close' => '' );
		foreach ( self::$queued as $s ) {
			if ( isset( $by[ $s['location'] ] ) ) { $by[ $s['location'] ] .= "\n" . $s['code'] . "\n"; }
		}

		$html = self::insert_after_open( $html, '/<head\b[^>]*>/i', $by['after_head_open'] );
		$html = self::insert_before( $html, '</head>', $by['before_head_close'] );
		$html = self::insert_after_open( $html, '/<body\b[^>]*>/i', $by['after_body_open'] );
		$html = self::insert_after_last( $html, '</body>', $by['after_body_close'] );
		return $html;
	}

	/** Insert $code right after the first tag matching $regex. */
	protected static function insert_after_open( $html, $regex, $code ) {
		if ( '' === $code ) { return $html; }
		if ( preg_match( $regex, $html, $m, PREG_OFFSET_CAPTURE ) ) {
			$pos = $m[0][1] + strlen( $m[0][0] );
			return substr( $html, 0, $pos ) . $code . substr( $html, $pos );
		}
		return $html;
	}

	/** Insert $code right before the first occurrence of $tag. */
	protected static function insert_before( $html, $tag, $code ) {
		if ( '' === $code ) { return $html; }
		$pos = stripos( $html, $tag );
		if ( false !== $pos ) { return substr( $html, 0, $pos ) . $code . substr( $html, $pos ); }
		return $html;
	}

	/** Insert $code right after the last occurrence of $tag. */
	protected static function insert_after_last( $html, $tag, $code ) {
		if ( '' === $code ) { return $html; }
		$pos = strripos( $html, $tag );
		if ( false !== $pos ) { $pos += strlen( $tag ); return substr( $html, 0, $pos ) . $code . substr( $html, $pos ); }
		return $html;
	}
}
