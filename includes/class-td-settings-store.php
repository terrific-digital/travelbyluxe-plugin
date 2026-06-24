<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Plugin-wide settings store.
 *
 * Settings live in wp_options by default — a multi-tenanted table full of
 * every other plugin's settings, so mirroring "just our settings" via a
 * Cloudways "Selected Tables" copy is impossible there. This store moves
 * them into a focused {prefix}td_settings table, leaving wp_options as a
 * dual-write backup so anything that reads via get_option() keeps working.
 *
 * Managed option keys (the entire surface of the "Settings" + "Calendly"
 * admin screens):
 *   tf_settings           Forms settings + reCAPTCHA keys/threshold
 *   td_session            "Store Session Data" feature
 *   td_upd                "User-Provided Data" feature
 *   td_session_duration   "Session Duration events" milestones
 *   td_calendly           Calendly listener config
 *
 * Hooks:
 *   pre_option_<key>         → return our table's value (shadowing wp_options)
 *   pre_update_option_<key>  → mirror the write into our table; let WP also
 *                              update wp_options (dual-write, safe rollback)
 *
 * The Settings page's options.php POST flow keeps working unchanged because
 * we hook the same WordPress options API — register_setting + sanitize
 * callbacks run, update_option fires, our filter intercepts.
 */
class TD_Settings_Store {

	const DB_VERSION = '1.0';

	/** Option keys this store owns. Keep in sync with register_setting() callers. */
	const MANAGED = array(
		'tf_settings',
		'td_session',
		'td_upd',
		'td_session_duration',
		'td_calendly',
	);

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'td_settings';
	}

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_install' ), 5 );
		// Hook every managed key. Filters use closures so the option name travels
		// with each callback (no need to inspect the current filter at runtime).
		foreach ( self::MANAGED as $key ) {
			$option = $key; // bind for closure capture
			add_filter( 'pre_option_' . $option, function ( $pre ) use ( $option ) {
				$value = self::read( $option );
				return $value === null ? $pre : $value;
			} );
			add_filter( 'pre_update_option_' . $option, function ( $value, $old, $opt ) {
				self::write( $opt, $value );
				return $value;
			}, 10, 3 );
			// First-save path: WP calls add_option, which triggers add_option_<key>.
			add_action( 'add_option_' . $option, function ( $opt, $value ) {
				self::write( $opt, $value );
			}, 10, 2 );
		}
	}

	/* ---------------------------------------------------------------- Install */

	public static function maybe_install() {
		if ( get_option( 'td_settings_db_version' ) === self::DB_VERSION ) { return; }
		self::create_table();
		self::migrate_from_wp_options();
		update_option( 'td_settings_db_version', self::DB_VERSION );
	}

	public static function create_table() {
		global $wpdb;
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE $table (
			option_name VARCHAR(191) NOT NULL,
			option_value LONGTEXT NULL,
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (option_name)
		) $charset;";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public static function migrate_from_wp_options() {
		global $wpdb;
		foreach ( self::MANAGED as $option ) {
			// Read directly from wp_options to bypass our own pre_option filter
			// (this runs during install, before reads should switch over).
			$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option ) );
			if ( $raw === null ) { continue; }
			// Skip if we already have a row (re-run safety).
			if ( self::read_raw( $option ) !== null ) { continue; }
			$wpdb->replace( self::table(), array(
				'option_name'  => $option,
				'option_value' => $raw,
				'updated_at'   => current_time( 'mysql', true ),
			) ); // phpcs:ignore
		}
	}

	/* ----------------------------------------------------------------- Reads */

	/** Returns the unserialized value, or null when we don't have a row. */
	public static function read( $option ) {
		$raw = self::read_raw( $option );
		return $raw === null ? null : maybe_unserialize( $raw );
	}

	protected static function read_raw( $option ) {
		global $wpdb;
		$v = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM ' . self::table() . ' WHERE option_name = %s LIMIT 1', $option ) );
		return $v === null ? null : (string) $v;
	}

	/* ---------------------------------------------------------------- Writes */

	public static function write( $option, $value ) {
		global $wpdb;
		$wpdb->replace( self::table(), array(
			'option_name'  => $option,
			'option_value' => maybe_serialize( $value ),
			'updated_at'   => current_time( 'mysql', true ),
		) ); // phpcs:ignore
	}
}
