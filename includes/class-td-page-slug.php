<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Adds a "Slug" column to the admin Pages list table so it's easy to tell exactly
 * which page each row is. Sortable (by post_name), and percent-decoded so non-ASCII
 * (e.g. Hebrew) slugs read cleanly.
 */
class TD_Page_Slug {

	const COL = 'td_slug';

	public static function init() {
		add_filter( 'manage_pages_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'manage_pages_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_filter( 'manage_edit-page_sortable_columns', array( __CLASS__, 'sortable' ) );
		add_action( 'admin_print_styles-edit.php', array( __CLASS__, 'column_css' ) );
	}

	/** Insert the Slug column right after Title. */
	public static function add_column( $cols ) {
		$out = array();
		foreach ( $cols as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) { $out[ self::COL ] = 'Slug'; }
		}
		// Fallback if there's no Title column for some reason.
		if ( ! isset( $out[ self::COL ] ) ) { $out[ self::COL ] = 'Slug'; }
		return $out;
	}

	public static function render_column( $col, $post_id ) {
		if ( self::COL !== $col ) { return; }
		$slug = get_post_field( 'post_name', $post_id );
		if ( '' === $slug ) {
			echo '<span style="color:#a7aaad">—</span>'; // not assigned yet (e.g. a draft)
			return;
		}
		echo '<code class="td-slug">' . esc_html( urldecode( $slug ) ) . '</code>';
	}

	/** Make the column sortable (orderby=name → post_name, handled by WP core). */
	public static function sortable( $cols ) {
		$cols[ self::COL ] = 'name';
		return $cols;
	}

	public static function column_css() {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-page' !== $screen->id ) { return; }
		echo '<style>.column-' . esc_attr( self::COL ) . '{width:20%}'
			. '.column-' . esc_attr( self::COL ) . ' code.td-slug{background:#f0f0f1;color:#3c434a;padding:1px 6px;border-radius:3px;font-size:12px}</style>';
	}
}
