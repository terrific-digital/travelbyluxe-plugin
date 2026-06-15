<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Store Session Data — a plugin-wide (non-forms) feature.
 *
 * When enabled, a small front-end script records, once per browsing session, a
 * `td_session_data` object capturing where/when the visitor's session began:
 *
 *   { "timestamp": <ISO 8601 session-start time>,
 *     "url":       <full URL the session started on>,
 *     "ref":       <referrer URL the session started with> }
 *
 * It is persisted either in `sessionStorage` or as a session cookie (the visitor's
 * browser clears both when the session ends), selectable in Settings.
 */
class TD_Session {

	const OPT = 'td_session';
	const KEY = 'td_session_data'; // storage key / cookie name + JS global object name

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'terrific_digital/settings_fields', array( __CLASS__, 'settings_fields' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/* --------------------------------------------------------------- Settings */

	public static function register() {
		// Registered to the shared settings group so it saves with the Settings form.
		register_setting( 'tf_settings_group', self::OPT, array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
	}

	public static function sanitize( $in ) {
		$storage = ( isset( $in['storage'] ) && 'cookie' === $in['storage'] ) ? 'cookie' : 'session';
		return array(
			'enabled' => ! empty( $in['enabled'] ) ? 1 : 0,
			'storage' => $storage,
		);
	}

	public static function get( $key, $default = '' ) {
		$o = get_option( self::OPT, array() );
		return isset( $o[ $key ] ) ? $o[ $key ] : $default;
	}

	public static function is_enabled() {
		return (bool) self::get( 'enabled', 0 );
	}

	public static function storage() {
		return 'cookie' === self::get( 'storage', 'session' ) ? 'cookie' : 'session';
	}

	/** Renders inside the shared Settings <form> (see TF_Settings::screen). */
	public static function settings_fields() {
		$enabled = self::is_enabled();
		$storage = self::storage();
		?>
		<h2 class="title">Store Session Data</h2>
		<p class="description" style="max-width:760px">
			Records a <code><?php echo esc_html( self::KEY ); ?></code> object once when a visitor's
			session begins — capturing the start <strong>timestamp</strong>, landing <strong>URL</strong>,
			and <strong>referrer</strong> — so it's available to your tracking/analytics on every page of the session.
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Enable</th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( self::OPT ); ?>[enabled]" value="1" <?php checked( $enabled ); ?>>
						Store session data for front-end visitors
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row">Storage</th>
				<td>
					<fieldset>
						<label style="display:block;margin-bottom:6px">
							<input type="radio" name="<?php echo esc_attr( self::OPT ); ?>[storage]" value="session" <?php checked( $storage, 'session' ); ?>>
							<strong>sessionStorage</strong> — kept per browser tab; cleared when the tab closes.
						</label>
						<label style="display:block">
							<input type="radio" name="<?php echo esc_attr( self::OPT ); ?>[storage]" value="cookie" <?php checked( $storage, 'cookie' ); ?>>
							<strong>Session cookie</strong> — shared across tabs; sent with requests; cleared when the browser closes.
						</label>
					</fieldset>
				</td>
			</tr>
		</table>
		<?php
	}

	/* -------------------------------------------------------------- Front-end */

	public static function enqueue() {
		if ( is_admin() || ! self::is_enabled() ) { return; }
		wp_enqueue_script( 'td-session', TF_URL . 'assets/session.js', array(), TF_VERSION, true );
		wp_localize_script( 'td-session', 'TD_SESSION', array(
			'key'     => self::KEY,
			'storage' => self::storage(),
		) );
	}
}
