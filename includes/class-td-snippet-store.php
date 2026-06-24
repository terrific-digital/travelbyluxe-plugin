<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Single source of truth for Code Snippets data.
 *
 * Same architecture as TF_Forms / TD_FAQ_Store: the td_snippet CPT post stays
 * in wp_posts (for the Edit screen, list table, capability checks, etc.) and
 * the configuration moves into {prefix}td_code_snippets — so snippets can be
 * mirrored staging→prod via a single-table copy in Cloudways' "Selected Tables"
 * without touching wp_postmeta.
 *
 *   code_type       'html' | 'js'
 *   code            raw HTML or JS (JS is auto-wrapped in <script> at render)
 *   enabled         1 = active
 *   location        after_head_open | before_head_close | after_body_open | before_body_close
 *   condition_type  all | specific | url_contains | url_not_contains
 *   pages           JSON int[] (for 'specific')
 *   url_match       string (for url_contains / url_not_contains)
 *
 * Reads fall back to postmeta during the upgrade window.
 */
class TD_Snippet_Store {

	const DB_VERSION = '2.0';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'td_code_snippets';
	}

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_install' ), 5 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_post_delete' ) );
	}

	public static function maybe_install() {
		if ( get_option( 'td_snippets_db_version' ) === self::DB_VERSION ) { return; }
		self::rename_legacy_table();
		self::create_table();
		self::migrate_from_postmeta();
		update_option( 'td_snippets_db_version', self::DB_VERSION );
	}

	/** v2.0 (plugin v2.48.0): wp_td_snippets → wp_td_code_snippets. RENAME preserves data. */
	protected static function rename_legacy_table() {
		global $wpdb;
		$new = self::table();
		$old = $wpdb->prefix . 'td_snippets';
		if ( $new === $old ) { return; }
		$has_new = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new ) );
		$has_old = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old ) );
		if ( $has_old && ! $has_new ) {
			$wpdb->query( "RENAME TABLE `$old` TO `$new`" ); // phpcs:ignore
		}
	}

	public static function create_table() {
		global $wpdb;
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE $table (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT(20) UNSIGNED NOT NULL,
			enabled TINYINT(1) NOT NULL DEFAULT 0,
			code_type VARCHAR(8) NOT NULL DEFAULT 'html',
			code LONGTEXT NULL,
			location VARCHAR(32) NOT NULL DEFAULT 'before_head_close',
			condition_type VARCHAR(32) NOT NULL DEFAULT 'all',
			pages LONGTEXT NULL,
			url_match VARCHAR(255) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY post_id (post_id),
			KEY enabled (enabled)
		) $charset;";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public static function migrate_from_postmeta() {
		if ( ! class_exists( 'TD_Snippets' ) ) { return; }
		$posts = get_posts( array(
			'post_type'      => TD_Snippets::CPT,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) );
		foreach ( $posts as $post_id ) {
			if ( self::row_exists( $post_id ) ) { continue; }
			self::backfill_from_postmeta( $post_id );
		}
	}

	protected static function row_exists( $post_id ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE post_id = %d LIMIT 1', $post_id ) );
	}

	protected static function backfill_from_postmeta( $post_id ) {
		$pages = get_post_meta( $post_id, '_td_snippet_pages', true );
		$pages = is_array( $pages ) ? array_values( array_filter( array_map( 'intval', $pages ) ) ) : array();
		self::upsert( $post_id, array(
			'enabled'        => get_post_meta( $post_id, '_td_snippet_enabled', true ) ? 1 : 0,
			'code_type'      => (string) get_post_meta( $post_id, '_td_snippet_type', true ) ?: 'html',
			'code'           => (string) get_post_meta( $post_id, '_td_snippet_code', true ),
			'location'       => (string) get_post_meta( $post_id, '_td_snippet_location', true ) ?: 'before_head_close',
			'condition_type' => (string) get_post_meta( $post_id, '_td_snippet_condition', true ) ?: 'all',
			'pages'          => wp_json_encode( $pages ),
			'url_match'      => (string) get_post_meta( $post_id, '_td_snippet_url', true ),
		), current_time( 'mysql', true ) );
	}

	/* --------------------------------------------------------------- Reads */

	public static function get_row( $post_id ) {
		global $wpdb;
		$post_id = (int) $post_id;
		if ( ! $post_id ) { return null; }
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE post_id = %d LIMIT 1', $post_id ), ARRAY_A );
		if ( $row ) { return $row; }
		if ( class_exists( 'TD_Snippets' ) && get_post_type( $post_id ) === TD_Snippets::CPT ) {
			self::backfill_from_postmeta( $post_id );
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE post_id = %d LIMIT 1', $post_id ), ARRAY_A );
		}
		return $row;
	}

	public static function get_field( $post_id, $field, $default = '' ) {
		$row = self::get_row( $post_id );
		if ( ! $row || ! array_key_exists( $field, $row ) ) { return $default; }
		return $row[ $field ];
	}

	public static function get_pages( $post_id ) {
		$json = self::get_field( $post_id, 'pages', '' );
		$arr  = $json ? json_decode( $json, true ) : array();
		return is_array( $arr ) ? array_values( array_filter( array_map( 'intval', $arr ) ) ) : array();
	}

	/** Enabled rows only — used by the front-end injector. Returns rows as arrays. */
	public static function active_rows() {
		global $wpdb;
		return $wpdb->get_results( 'SELECT s.* FROM ' . self::table() . ' s INNER JOIN ' . $wpdb->posts . ' p ON p.ID = s.post_id WHERE s.enabled = 1 AND p.post_status = "publish" AND p.post_type = "' . TD_Snippets::CPT . '" ORDER BY p.post_date ASC', ARRAY_A );
	}

	/* -------------------------------------------------------------- Writes */

	public static function save( $post_id, $fields ) {
		$post_id = (int) $post_id;
		if ( ! $post_id ) { return false; }
		self::upsert( $post_id, $fields, current_time( 'mysql', true ) );
		return true;
	}

	protected static function upsert( $post_id, $fields, $now ) {
		global $wpdb;
		$table   = self::table();
		$columns = array( 'enabled', 'code_type', 'code', 'location', 'condition_type', 'pages', 'url_match' );
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE post_id = %d LIMIT 1", $post_id ), ARRAY_A );

		if ( ! $existing ) {
			$data = array( 'post_id' => $post_id, 'created_at' => $now, 'updated_at' => $now );
			foreach ( $columns as $c ) { $data[ $c ] = self::cast_field( $c, $fields[ $c ] ?? null ); }
			$wpdb->insert( $table, $data ); // phpcs:ignore
			return;
		}

		$update = array( 'updated_at' => $now );
		foreach ( $columns as $c ) {
			if ( array_key_exists( $c, $fields ) ) { $update[ $c ] = self::cast_field( $c, $fields[ $c ] ); }
		}
		if ( count( $update ) === 1 ) { return; }
		$wpdb->update( $table, $update, array( 'post_id' => $post_id ) ); // phpcs:ignore
	}

	protected static function cast_field( $col, $val ) {
		if ( 'enabled' === $col )   { return (int) (bool) $val; }
		if ( 'pages' === $col )     { return is_string( $val ) ? $val : wp_json_encode( is_array( $val ) ? $val : array() ); }
		return (string) ( $val ?? '' );
	}

	public static function delete( $post_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'post_id' => (int) $post_id ), array( '%d' ) ); // phpcs:ignore
	}

	public static function on_post_delete( $post_id ) {
		if ( class_exists( 'TD_Snippets' ) && get_post_type( $post_id ) === TD_Snippets::CPT ) {
			self::delete( $post_id );
		}
	}
}
