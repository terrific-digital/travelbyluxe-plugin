<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Single source of truth for FAQ list data.
 *
 * Mirrors the architecture introduced for forms in v2.45.0: each `td_faq` CPT
 * post still exists in wp_posts (so the Edit screen, list table, capability
 * checks, trash/restore, etc. work as before) — but the FAQ's configuration
 * (stable faq_id, items, open-one-at-a-time toggle) lives in its own table
 * `{prefix}td_faqs`. That lets you mirror FAQ lists between environments
 * (e.g. Cloudways "Selected Tables" staging→prod) by copying one focused
 * table instead of the whole wp_postmeta.
 *
 *   faq_id        stable cross-environment identifier (faq_<post>_<hash>)
 *   items         JSON [ { q, a } ]
 *   open_single   1 = opening an item closes the others
 *
 * The render-tracking map (_td_faq_seen) intentionally STAYS in postmeta —
 * it's per-environment runtime telemetry, not configuration, and you don't
 * want it travelling with staging→prod copies.
 *
 * Reads fall back to postmeta when a row isn't found yet — a soft upgrade
 * path that turns into a no-op once migration has run.
 */
class TD_FAQ_Store {

	const DB_VERSION = '1.0';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'td_faqs';
	}

	public static function init() {
		// Run before TD_FAQ::register (init:default) so the table exists by then.
		add_action( 'init', array( __CLASS__, 'maybe_install' ), 5 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_post_delete' ) );
	}

	/* ----------------------------------------------------------------- Install */

	public static function maybe_install() {
		if ( get_option( 'td_faqs_db_version' ) === self::DB_VERSION ) { return; }
		self::create_table();
		self::migrate_from_postmeta();
		update_option( 'td_faqs_db_version', self::DB_VERSION );
	}

	public static function create_table() {
		global $wpdb;
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE $table (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT(20) UNSIGNED NOT NULL,
			faq_id VARCHAR(64) NOT NULL DEFAULT '',
			items LONGTEXT NULL,
			open_single TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY post_id (post_id),
			KEY faq_id (faq_id)
		) $charset;";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public static function migrate_from_postmeta() {
		if ( ! class_exists( 'TD_FAQ' ) ) { return; }
		$posts = get_posts( array(
			'post_type'      => TD_FAQ::CPT,
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
		self::upsert( $post_id, array(
			'faq_id'      => (string) get_post_meta( $post_id, '_td_faq_id', true ),
			'items'       => (string) get_post_meta( $post_id, '_td_faq_items', true ),
			'open_single' => get_post_meta( $post_id, '_td_faq_single', true ) ? 1 : 0,
		), current_time( 'mysql', true ) );
	}

	/* --------------------------------------------------------------- Reads */

	public static function get_row( $post_id ) {
		global $wpdb;
		$post_id = (int) $post_id;
		if ( ! $post_id ) { return null; }
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE post_id = %d LIMIT 1', $post_id ), ARRAY_A );
		if ( $row ) { return $row; }
		// Lazy backfill during the upgrade window — when we know this is a FAQ list.
		if ( class_exists( 'TD_FAQ' ) && get_post_type( $post_id ) === TD_FAQ::CPT ) {
			self::backfill_from_postmeta( $post_id );
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE post_id = %d LIMIT 1', $post_id ), ARRAY_A );
		}
		return $row;
	}

	public static function get_faq_id( $post_id )      { $r = self::get_row( $post_id ); return $r ? (string) $r['faq_id'] : ''; }
	public static function get_items_json( $post_id )  { $r = self::get_row( $post_id ); return $r ? (string) $r['items'] : ''; }
	public static function get_open_single( $post_id ) { $r = self::get_row( $post_id ); return $r ? (bool) $r['open_single'] : false; }

	public static function get_items( $post_id ) {
		$json  = self::get_items_json( $post_id );
		$items = $json ? json_decode( $json, true ) : array();
		return is_array( $items ) ? $items : array();
	}

	public static function find_post_id_by_faq_id( $faq_id ) {
		global $wpdb;
		$faq_id = (string) $faq_id;
		if ( '' === $faq_id ) { return 0; }
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT post_id FROM ' . self::table() . ' WHERE faq_id = %s LIMIT 1', $faq_id ) );
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
		$table    = self::table();
		$columns  = array( 'faq_id', 'items', 'open_single' );
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE post_id = %d LIMIT 1", $post_id ), ARRAY_A );

		if ( ! $existing ) {
			$data = array(
				'post_id'     => $post_id,
				'created_at'  => $now,
				'updated_at'  => $now,
				'faq_id'      => isset( $fields['faq_id'] ) ? (string) $fields['faq_id'] : '',
				'items'       => isset( $fields['items'] ) ? (string) $fields['items'] : '',
				'open_single' => isset( $fields['open_single'] ) ? (int) (bool) $fields['open_single'] : 0,
			);
			$wpdb->insert( $table, $data ); // phpcs:ignore
			return;
		}

		$update = array( 'updated_at' => $now );
		foreach ( $columns as $c ) {
			if ( array_key_exists( $c, $fields ) ) {
				$update[ $c ] = ( 'open_single' === $c ) ? (int) (bool) $fields[ $c ] : (string) $fields[ $c ];
			}
		}
		if ( count( $update ) === 1 ) { return; }
		$wpdb->update( $table, $update, array( 'post_id' => $post_id ) ); // phpcs:ignore
	}

	public static function delete( $post_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'post_id' => (int) $post_id ), array( '%d' ) ); // phpcs:ignore
	}

	public static function on_post_delete( $post_id ) {
		if ( class_exists( 'TD_FAQ' ) && get_post_type( $post_id ) === TD_FAQ::CPT ) {
			self::delete( $post_id );
		}
	}
}
