<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Terrific FAQ — reusable FAQ lists (question/answer accordions) that can be placed
 * on any page via a shortcode or a Gutenberg block. Restricted to users who can edit
 * pages (edit_pages — same model as the rest of the Terrific Digital plugin).
 *
 * Each FAQ list is a `td_faq` post:
 *   _td_faq_id     static id `faq_{postID}_{6hex}` (front-end data attribute)
 *   _td_faq_items  JSON [ { q, a } ]  (q = question text, a = answer HTML — wp_kses_post)
 *
 * Placement: [terrific_faq id="N"] and the "Terrific FAQ" block (server-rendered).
 * Front-end: an accessible accordion; opening an item fires a dataLayer
 *   { event:'faq_item_click', faq_title:'<question lowercased>' } once per item.
 */
class TD_FAQ {

	const CPT = 'td_faq';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 11 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_' . self::CPT, array( __CLASS__, 'save' ), 10, 1 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
		add_shortcode( 'terrific_faq', array( __CLASS__, 'shortcode' ) );
		// Keep the Terrific Digital menu highlighted while editing a FAQ list.
		add_filter( 'parent_file', array( __CLASS__, 'parent_file' ) );
		add_filter( 'submenu_file', array( __CLASS__, 'submenu_file' ) );
		// List-table column (item count).
		add_filter( 'manage_' . self::CPT . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::CPT . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		// Gutenberg block.
		add_filter( 'block_categories_all', array( __CLASS__, 'block_category' ), 10, 1 );
	}

	public static function register() {
		register_post_type( self::CPT, array(
			'labels' => array(
				'name'          => 'FAQ Lists',
				'singular_name' => 'FAQ List',
				'add_new_item'  => 'Add New FAQ List',
				'edit_item'     => 'Edit FAQ List',
				'new_item'      => 'New FAQ List',
				'search_items'  => 'Search FAQ Lists',
				'not_found'     => 'No FAQ lists yet',
				'menu_name'     => 'FAQ Lists',
			),
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => false, // nested under Terrific Digital.
			'supports'        => array( 'title' ), // classic meta-box editor (like the Forms/Snippets CPTs).
			'capability_type' => 'page', // edit_pages-family caps — same model as the Forms CPT.
			'map_meta_cap'    => true,
		) );

		// Register front-end + block-editor assets here (on init) so they're available in
		// both the front-end (shortcode) and the admin block editor.
		wp_register_style( 'td-faq', TF_URL . 'assets/faq.css', array(), TF_VERSION );
		wp_register_script( 'td-faq', TF_URL . 'assets/faq.js', array(), TF_VERSION, true );
		wp_register_script(
			'td-faq-block',
			TF_URL . 'assets/faq-block.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n', 'wp-server-side-render' ),
			TF_VERSION,
			true
		);
		$lists = get_posts( array( 'post_type' => self::CPT, 'numberposts' => -1, 'post_status' => 'publish', 'orderby' => 'title', 'order' => 'ASC' ) );
		$bl    = array();
		foreach ( $lists as $l ) {
			$bl[] = array(
				'id'       => (string) $l->ID,
				'title'    => get_the_title( $l ),
				'count'    => count( self::get_items( $l->ID ) ),
				'edit_url' => admin_url( 'post.php?post=' . $l->ID . '&action=edit' ),
			);
		}
		wp_localize_script( 'td-faq-block', 'TD_FAQ_BLOCK', array(
			'lists'  => $bl,
			'newUrl' => admin_url( 'post-new.php?post_type=' . self::CPT ),
		) );

		// The block (server-rendered; reuses the shortcode).
		if ( function_exists( 'register_block_type' ) ) {
			register_block_type( 'terrific-digital/faq', array(
				'api_version'     => 2,
				'editor_script'   => 'td-faq-block',
				'editor_style'    => 'td-faq',
				'attributes'      => array( 'faqId' => array( 'type' => 'string', 'default' => '' ) ),
				'render_callback' => array( __CLASS__, 'render_block' ),
			) );
		}
	}

	public static function menu() {
		add_submenu_page( TD_MENU, 'FAQ Lists', 'FAQ Lists', 'edit_pages', 'edit.php?post_type=' . self::CPT );
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

	/* ----------------------------------------------------------------- Data */

	/** A FAQ list's items: array of [ 'q' => question, 'a' => answer ]. */
	public static function get_items( $post_id ) {
		$json  = get_post_meta( $post_id, '_td_faq_items', true );
		$items = $json ? json_decode( $json, true ) : array();
		return is_array( $items ) ? $items : array();
	}

	/** Ensure (and return) the list's stable front-end id. */
	public static function ensure_id( $post_id ) {
		$id = get_post_meta( $post_id, '_td_faq_id', true );
		if ( $id ) { return $id; }
		$id = 'faq_' . $post_id . '_' . substr( md5( $post_id . wp_salt() ), 0, 6 );
		update_post_meta( $post_id, '_td_faq_id', $id );
		return $id;
	}

	/* ------------------------------------------------------------- List table */

	public static function columns( $cols ) {
		$new = array();
		foreach ( $cols as $k => $v ) {
			$new[ $k ] = $v;
			if ( 'title' === $k ) {
				$new['td_faq_count'] = 'Questions';
				$new['td_faq_sc']    = 'Shortcode';
			}
		}
		return $new;
	}

	public static function column( $col, $post_id ) {
		if ( 'td_faq_count' === $col ) {
			echo (int) count( self::get_items( $post_id ) );
		}
		if ( 'td_faq_sc' === $col ) {
			echo '<code>[terrific_faq id="' . (int) $post_id . '"]</code>';
		}
	}

	/* ---------------------------------------------------------------- Editor */

	public static function admin_assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || $screen->post_type !== self::CPT ) { return; }
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) { return; }
		wp_enqueue_style( 'td-faq-admin', TF_URL . 'assets/faq-admin.css', array(), TF_VERSION );
		wp_enqueue_script( 'td-faq-sortable', TF_URL . 'assets/sortable.min.js', array(), '1.15.2', true );
		wp_enqueue_script( 'td-faq-admin', TF_URL . 'assets/faq-admin.js', array( 'td-faq-sortable' ), TF_VERSION, true );
	}

	public static function meta_boxes() {
		add_meta_box( 'td-faq-items', 'FAQ Items', array( __CLASS__, 'box_items' ), self::CPT, 'normal', 'high' );
		add_meta_box( 'td-faq-usage', 'Shortcode & Usage', array( __CLASS__, 'box_usage' ), self::CPT, 'side', 'default' );
	}

	public static function box_items( $post ) {
		$json = wp_json_encode( self::get_items( $post->ID ) );
		wp_nonce_field( 'td_faq_save', 'td_faq_nonce' );
		?>
		<div id="td-faq-builder" data-items="<?php echo esc_attr( $json ); ?>">
			<div class="td-faq-loading" style="color:#777;padding:10px">Loading…</div>
		</div>
		<input type="hidden" name="td_faq_items" id="td_faq_items" value="<?php echo esc_attr( $json ); ?>">
		<noscript><p>The FAQ editor requires JavaScript.</p></noscript>
		<?php
	}

	public static function box_usage( $post ) {
		?>
		<p style="margin-top:0"><strong>Shortcode</strong><br>
			<code>[terrific_faq id="<?php echo (int) $post->ID; ?>"]</code></p>
		<p class="description">Or add the <strong>Terrific FAQ</strong> block to any page.</p>
		<hr style="margin:14px 0">
		<p><strong>Used on these pages</strong></p>
		<?php
		$usages = self::find_usages( $post->ID );
		if ( empty( $usages ) ) {
			echo '<p style="color:#777">Not embedded anywhere yet.</p>';
			return;
		}
		echo '<ul style="margin:0">';
		foreach ( $usages as $pid ) {
			$title = get_the_title( $pid );
			$title = '' !== $title ? $title : '(no title)';
			$edit  = get_edit_post_link( $pid );
			$view  = get_permalink( $pid );
			$pto   = get_post_type_object( get_post_type( $pid ) );
			echo '<li style="margin-bottom:8px;border-bottom:1px solid #f0f0f1;padding-bottom:8px">';
			if ( $edit ) {
				echo '<a href="' . esc_url( $edit ) . '"><strong>' . esc_html( $title ) . '</strong></a>';
			} else {
				echo '<strong>' . esc_html( $title ) . '</strong>';
			}
			if ( $pto ) {
				echo ' <span style="color:#999;font-size:11px">' . esc_html( $pto->labels->singular_name ) . '</span>';
			}
			$links = array();
			if ( $edit ) { $links[] = '<a href="' . esc_url( $edit ) . '">edit</a>'; }
			if ( $view ) { $links[] = '<a href="' . esc_url( $view ) . '" target="_blank">view ↗</a>'; }
			if ( $links ) {
				echo '<br><span style="font-size:12px">' . implode( ' · ', $links ) . '</span>';
			}
			echo '</li>';
		}
		echo '</ul>';
	}

	/** Find every page/post that embeds this FAQ list (block, shortcode, or Elementor). */
	public static function find_usages( $faq_post_id ) {
		global $wpdb;
		$faq_post_id = (int) $faq_post_id;
		$sc = '%' . $wpdb->esc_like( '[terrific_faq id="' . $faq_post_id . '"' ) . '%';
		$bl = '%' . $wpdb->esc_like( '"faqId":"' . $faq_post_id . '"' ) . '%';

		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type NOT IN ( 'revision', %s )
			   AND post_status NOT IN ( 'trash', 'auto-draft', 'inherit' )
			   AND ( post_content LIKE %s OR post_content LIKE %s )",
			self::CPT, $sc, $bl
		) );
		$meta_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data' AND meta_value LIKE %s",
			$sc
		) );

		// Plus pages where the FAQ actually rendered (custom templates / do_shortcode /
		// page builders store nothing scannable — render-time tracking catches those).
		$seen = self::prune_seen( $faq_post_id );

		$all = array_unique( array_map( 'intval', array_merge( $ids, $meta_ids, array_keys( $seen ) ) ) );
		$all = array_filter( $all, function ( $id ) {
			return $id && get_post_type( $id ) !== self::CPT
				&& ! in_array( get_post_status( $id ), array( 'trash', 'auto-draft', 'inherit' ), true );
		} );
		return array_values( $all );
	}

	/**
	 * Record (front-end) that this FAQ rendered on the current page — so the usage
	 * finder can report embeds that aren't stored as scannable content (custom
	 * templates, page builders, do_shortcode, etc.). Throttled to once/day per page.
	 */
	protected static function record_usage( $faq_post_id ) {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_preview() || is_feed() ) { return; }
		$page_id = (int) get_queried_object_id();
		if ( ! $page_id || $page_id === (int) $faq_post_id ) { return; }
		$seen = get_post_meta( $faq_post_id, '_td_faq_seen', true );
		if ( ! is_array( $seen ) ) { $seen = array(); }
		$now = time();
		if ( isset( $seen[ $page_id ] ) && ( $now - (int) $seen[ $page_id ] ) < DAY_IN_SECONDS ) { return; }
		$seen[ $page_id ] = $now;
		update_post_meta( $faq_post_id, '_td_faq_seen', $seen );
	}

	/** Read the render-tracking map, dropping stale (>90d) / deleted / FAQ-CPT entries (self-healing). */
	protected static function prune_seen( $faq_post_id ) {
		$seen = get_post_meta( $faq_post_id, '_td_faq_seen', true );
		if ( ! is_array( $seen ) ) { return array(); }
		$cutoff  = time() - ( 90 * DAY_IN_SECONDS );
		$changed = false;
		foreach ( $seen as $pid => $ts ) {
			$pid    = (int) $pid;
			$status = $pid ? get_post_status( $pid ) : false;
			if ( (int) $ts < $cutoff || ! $pid || false === $status
				|| in_array( $status, array( 'trash', 'auto-draft', 'inherit' ), true )
				|| get_post_type( $pid ) === self::CPT ) {
				unset( $seen[ $pid ] );
				$changed = true;
			}
		}
		if ( $changed ) { update_post_meta( $faq_post_id, '_td_faq_seen', $seen ); }
		return $seen;
	}

	public static function save( $post_id ) {
		if ( ! isset( $_POST['td_faq_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['td_faq_nonce'] ) ), 'td_faq_save' ) ) { return; }
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
		if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }

		if ( isset( $_POST['td_faq_items'] ) ) {
			$decoded = json_decode( wp_unslash( $_POST['td_faq_items'] ), true );
			$clean   = self::sanitize_items( is_array( $decoded ) ? $decoded : array() );
			// wp_slash so update_post_meta's internal wp_unslash doesn't strip JSON escapes.
			update_post_meta( $post_id, '_td_faq_items', wp_slash( wp_json_encode( $clean ) ) );
		}
		self::ensure_id( $post_id );
	}

	/** Sanitize the items list: q = plain text, a = post-safe HTML. Drops empty rows. */
	public static function sanitize_items( $items ) {
		$out = array();
		foreach ( (array) $items as $it ) {
			if ( ! is_array( $it ) ) { continue; }
			$q = isset( $it['q'] ) ? sanitize_text_field( $it['q'] ) : '';
			$a = isset( $it['a'] ) ? wp_kses_post( $it['a'] ) : '';
			if ( '' === trim( $q ) && '' === trim( wp_strip_all_tags( $a ) ) ) { continue; }
			$out[] = array( 'q' => $q, 'a' => $a );
		}
		return $out;
	}

	/* ------------------------------------------------------------ Front-end */

	public static function block_category( $categories ) {
		foreach ( $categories as $c ) {
			if ( isset( $c['slug'] ) && 'terrific-digital' === $c['slug'] ) { return $categories; }
		}
		array_unshift( $categories, array( 'slug' => 'terrific-digital', 'title' => 'Terrific Digital', 'icon' => null ) );
		return $categories;
	}

	public static function render_block( $attributes ) {
		$id = isset( $attributes['faqId'] ) ? (int) $attributes['faqId'] : 0;
		if ( ! $id || get_post_type( $id ) !== self::CPT ) {
			return current_user_can( 'edit_pages' ) ? '<p><em>Terrific FAQ: please select a FAQ list.</em></p>' : '';
		}
		return self::shortcode( array( 'id' => $id ) );
	}

	public static function shortcode( $atts ) {
		$atts    = shortcode_atts( array( 'id' => 0 ), $atts, 'terrific_faq' );
		$post_id = (int) $atts['id'];
		if ( ! $post_id || get_post_type( $post_id ) !== self::CPT ) {
			return current_user_can( 'edit_pages' ) ? '<!-- Terrific FAQ: invalid id -->' : '';
		}
		$items = self::get_items( $post_id );
		if ( empty( $items ) ) {
			return current_user_can( 'edit_pages' ) ? '<!-- Terrific FAQ: no items -->' : '';
		}
		$faq_id = self::ensure_id( $post_id );
		self::record_usage( $post_id ); // track where it actually renders (for the usage finder)

		wp_enqueue_style( 'td-faq' );
		wp_enqueue_script( 'td-faq' );

		$uid = 'tdfaq-' . $post_id;
		ob_start();
		echo '<div class="td-faq" data-faq-id="' . esc_attr( $faq_id ) . '">';
		foreach ( $items as $i => $it ) {
			$q = isset( $it['q'] ) ? $it['q'] : '';
			$a = isset( $it['a'] ) ? $it['a'] : '';
			if ( '' === trim( $q ) ) { continue; }
			$pid = $uid . '-' . $i;
			echo '<div class="td-faq-item" data-faq-title="' . esc_attr( $q ) . '">';
			echo '<button type="button" class="td-faq-q" aria-expanded="false" aria-controls="' . esc_attr( $pid ) . '">';
			echo '<span class="td-faq-q-text">' . esc_html( $q ) . '</span>';
			echo '<span class="td-faq-icon" aria-hidden="true"></span>';
			echo '</button>';
			echo '<div class="td-faq-a" id="' . esc_attr( $pid ) . '" role="region" hidden>';
			echo '<div class="td-faq-a-inner">' . wp_kses_post( $a ) . '</div>';
			echo '</div>';
			echo '</div>';
		}
		echo '</div>';
		return ob_get_clean();
	}
}
