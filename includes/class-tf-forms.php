<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Single source of truth for form data.
 *
 * Previously each form's definition, intent, actions, etc. lived in wp_postmeta.
 * That coupled form data to the (huge, multi-tenanted) postmeta table, which made
 * site-to-site mirroring (e.g. Cloudways "Selected Tables" staging→prod copies)
 * either too coarse (wipes all postmeta on prod) or impossible (you can't copy
 * "just the form rows").
 *
 * Forms now live in their own table `{prefix}td_forms`. The TBL Form CPT
 * post still exists in wp_posts (so the Edit screen, list table, capability
 * checks, trash/restore, etc. work as before) — this table holds everything
 * else, keyed by post_id (UNIQUE).
 *
 *   form_id          stable cross-environment identifier (tf_<post>_<hash>)
 *   definition       JSON: { type, steps, submission_step }
 *   intent           lead / contact_us / …
 *   submit_text      submit-button label
 *   success_message  thank-you message
 *   wrapper_class    per-form CSS wrapper class
 *   custom_css       per-form custom CSS
 *   actions          JSON: webhook / redirect configurations
 *
 * Reads fall back to the legacy postmeta if a row isn't found yet — a soft
 * upgrade path that turns into a no-op once migration has run. Writes always go
 * through this class and only touch the new table.
 */
class TF_Forms {

	const DB_VERSION = '2.0';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'td_forms';
	}

	public static function init() {
		// Run before TF_Block::register / TF_CPT_Manager::register (both at init:10) so
		// the table exists before anything tries to read it. Frontend hits init too,
		// so we install here rather than admin_init.
		add_action( 'init', array( __CLASS__, 'maybe_install' ), 5 );
		// When a form post is permanently deleted, drop its companion row.
		add_action( 'before_delete_post', array( __CLASS__, 'on_post_delete' ) );
	}

	/** Create the table on activation or upgrade; backfill rows from postmeta the first time. */
	public static function maybe_install() {
		if ( get_option( 'tf_forms_db_version' ) === self::DB_VERSION ) { return; }
		self::rename_legacy_table();
		self::create_table();
		self::migrate_from_postmeta();
		update_option( 'tf_forms_db_version', self::DB_VERSION );
	}

	/** v2.0 (plugin v2.48.0): wp_tf_forms → wp_td_forms. RENAME preserves data. */
	protected static function rename_legacy_table() {
		global $wpdb;
		$new = self::table();
		$old = $wpdb->prefix . 'tf_forms';
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
			form_id VARCHAR(64) NOT NULL DEFAULT '',
			definition LONGTEXT NULL,
			intent VARCHAR(64) NOT NULL DEFAULT '',
			submit_text VARCHAR(255) NOT NULL DEFAULT '',
			success_message TEXT NULL,
			wrapper_class VARCHAR(255) NOT NULL DEFAULT '',
			custom_css LONGTEXT NULL,
			actions LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY post_id (post_id),
			KEY form_id (form_id)
		) $charset;";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/** Copy every existing terrific_form's postmeta into a wp_td_forms row. Idempotent. */
	public static function migrate_from_postmeta() {
		$posts = get_posts( array(
			'post_type'      => TF_CPT,
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

	/** Migrate a single form's data from postmeta into the new table. */
	protected static function backfill_from_postmeta( $post_id ) {
		$now    = current_time( 'mysql', true );
		self::upsert( $post_id, array(
			'form_id'         => (string) get_post_meta( $post_id, '_tf_form_id', true ),
			'definition'      => (string) get_post_meta( $post_id, '_tf_definition', true ),
			'intent'          => (string) get_post_meta( $post_id, '_tf_intent', true ),
			'submit_text'     => (string) get_post_meta( $post_id, '_tf_submit_text', true ),
			'success_message' => (string) get_post_meta( $post_id, '_tf_success', true ),
			'wrapper_class'   => (string) get_post_meta( $post_id, '_tf_wrapper_class', true ),
			'custom_css'      => (string) get_post_meta( $post_id, '_tf_custom_css', true ),
			'actions'         => (string) get_post_meta( $post_id, '_tf_actions', true ),
		), $now );
	}

	/* --------------------------------------------------------------- Reads */

	/**
	 * Full row for a form post (or null). Falls back to postmeta when no row
	 * exists yet — so reads work the moment the plugin upgrades, before the
	 * migration sweeps the post.
	 */
	public static function get_row( $post_id ) {
		global $wpdb;
		$post_id = (int) $post_id;
		if ( ! $post_id ) { return null; }
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE post_id = %d LIMIT 1', $post_id ), ARRAY_A );
		if ( $row ) { return $row; }
		// Lazy backfill so we don't return blanks during the upgrade window.
		if ( get_post_type( $post_id ) === TF_CPT ) {
			self::backfill_from_postmeta( $post_id );
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE post_id = %d LIMIT 1', $post_id ), ARRAY_A );
		}
		return $row;
	}

	public static function get_form_id( $post_id )       { $r = self::get_row( $post_id ); return $r ? (string) $r['form_id'] : ''; }
	public static function get_definition_json( $post_id ) { $r = self::get_row( $post_id ); return $r ? (string) $r['definition'] : ''; }
	public static function get_intent( $post_id )        { $r = self::get_row( $post_id ); return $r ? (string) $r['intent'] : ''; }
	public static function get_submit_text( $post_id )   { $r = self::get_row( $post_id ); return $r ? (string) $r['submit_text'] : ''; }
	public static function get_success_message( $post_id ) { $r = self::get_row( $post_id ); return $r ? (string) $r['success_message'] : ''; }
	public static function get_wrapper_class( $post_id ) { $r = self::get_row( $post_id ); return $r ? (string) $r['wrapper_class'] : ''; }
	public static function get_custom_css( $post_id )    { $r = self::get_row( $post_id ); return $r ? (string) $r['custom_css'] : ''; }
	public static function get_actions_json( $post_id )  { $r = self::get_row( $post_id ); return $r ? (string) $r['actions'] : ''; }

	/** Find the form post-id for a given stable form_id (tf_<post>_<hash>). */
	public static function find_post_id_by_form_id( $form_id ) {
		global $wpdb;
		$form_id = (string) $form_id;
		if ( '' === $form_id ) { return 0; }
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT post_id FROM ' . self::table() . ' WHERE form_id = %s LIMIT 1', $form_id ) );
	}

	/* -------------------------------------------------------------- Writes */

	/**
	 * Update one or more fields on a form's row (creates the row if missing).
	 * Accepts a sparse array — only keys present in $fields are touched.
	 */
	public static function save( $post_id, $fields ) {
		$post_id = (int) $post_id;
		if ( ! $post_id ) { return false; }
		self::upsert( $post_id, $fields, current_time( 'mysql', true ) );
		return true;
	}

	protected static function upsert( $post_id, $fields, $now ) {
		global $wpdb;
		$table   = self::table();
		$columns = array( 'form_id', 'definition', 'intent', 'submit_text', 'success_message', 'wrapper_class', 'custom_css', 'actions' );
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE post_id = %d LIMIT 1", $post_id ), ARRAY_A );

		if ( ! $existing ) {
			$data = array(
				'post_id'    => $post_id,
				'created_at' => $now,
				'updated_at' => $now,
			);
			foreach ( $columns as $c ) {
				$data[ $c ] = isset( $fields[ $c ] ) ? (string) $fields[ $c ] : '';
			}
			$wpdb->insert( $table, $data ); // phpcs:ignore
			return;
		}

		$update = array( 'updated_at' => $now );
		foreach ( $columns as $c ) {
			if ( array_key_exists( $c, $fields ) ) { $update[ $c ] = (string) $fields[ $c ]; }
		}
		if ( count( $update ) === 1 ) { return; } // only updated_at — nothing to do
		$wpdb->update( $table, $update, array( 'post_id' => $post_id ) ); // phpcs:ignore
	}

	public static function delete( $post_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'post_id' => (int) $post_id ), array( '%d' ) ); // phpcs:ignore
	}

	public static function on_post_delete( $post_id ) {
		if ( get_post_type( $post_id ) === TF_CPT ) { self::delete( $post_id ); }
	}
}
