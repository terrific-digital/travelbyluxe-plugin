<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Session Duration — fires `session_duration_<n>` dataLayer events when the
 * visitor's tab-session reaches each configured second-milestone (default
 * 15s / 60s / 120s / 180s). Replaces the equivalent GTM custom-HTML tag.
 *
 * Per-tab via sessionStorage: the counter survives same-tab navigations but
 * resets when the tab closes. Each milestone fires at most once per session
 * (even across multiple page loads in the same tab).
 *
 * Configurable on the Terrific Digital Settings screen:
 *   - Enable / disable
 *   - Milestones (comma-separated seconds)
 */
class TD_Session_Duration {

	const OPT             = 'td_session_duration';
	const DEFAULT_MARKS   = '15, 60, 120, 180';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'terrific_digital/settings_fields', array( __CLASS__, 'settings_fields' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/* --------------------------------------------------------------- Settings */

	public static function register() {
		register_setting( 'tf_settings_group', self::OPT, array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
	}

	public static function sanitize( $in ) {
		$enabled = ! empty( $in['enabled'] ) ? 1 : 0;
		$raw     = isset( $in['milestones'] ) ? (string) $in['milestones'] : self::DEFAULT_MARKS;
		return array(
			'enabled'    => $enabled,
			'milestones' => self::normalize_marks( $raw ),
		);
	}

	/** Parse "15, 60, 120, 180" → "15,60,120,180" (unique, ascending, positive ints). */
	public static function normalize_marks( $raw ) {
		$parts = preg_split( '/[\s,]+/', (string) $raw );
		$nums  = array();
		foreach ( (array) $parts as $p ) {
			$n = (int) $p;
			if ( $n > 0 ) { $nums[] = $n; }
		}
		$nums = array_values( array_unique( $nums ) );
		sort( $nums, SORT_NUMERIC );
		return implode( ',', $nums );
	}

	public static function get( $key, $default = '' ) {
		$o = get_option( self::OPT, null );
		// Defaults when the option has never been saved — feature ships ON to match GTM parity.
		if ( null === $o ) {
			$defaults = array( 'enabled' => 1, 'milestones' => self::normalize_marks( self::DEFAULT_MARKS ) );
			return isset( $defaults[ $key ] ) ? $defaults[ $key ] : $default;
		}
		return isset( $o[ $key ] ) ? $o[ $key ] : $default;
	}

	public static function is_enabled() {
		return (bool) self::get( 'enabled', 0 );
	}

	/** Milestones as int[] (sorted ascending). */
	public static function milestones() {
		$csv = (string) self::get( 'milestones', self::DEFAULT_MARKS );
		$csv = self::normalize_marks( $csv ); // tolerate legacy/whitespaced storage
		if ( '' === $csv ) { return array(); }
		return array_map( 'intval', explode( ',', $csv ) );
	}

	/** Renders inside the shared Settings <form> (see TF_Settings::screen). */
	public static function settings_fields() {
		$enabled = self::is_enabled();
		$marks   = (string) self::get( 'milestones', self::DEFAULT_MARKS );
		?>
		<hr style="margin:2.5em 0 1.5em">
		<h2 class="title">Session Duration events</h2>
		<p class="description" style="max-width:760px">
			Pushes a <code>session_duration_&lt;n&gt;</code> dataLayer event when the visitor reaches each
			milestone (in seconds) within the current tab session. Each milestone fires at most once per
			session.
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Enable</th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( self::OPT ); ?>[enabled]" value="1" <?php checked( $enabled ); ?>>
						Fire session-duration events for front-end visitors
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="td_sd_marks">Milestones (seconds)</label></th>
				<td>
					<input type="text" id="td_sd_marks" class="regular-text"
						name="<?php echo esc_attr( self::OPT ); ?>[milestones]"
						value="<?php echo esc_attr( $marks ); ?>"
						placeholder="<?php echo esc_attr( self::DEFAULT_MARKS ); ?>">
					<p class="description">
						Comma-separated, in seconds. Each fires <code>session_duration_&lt;n&gt;</code> when reached
						(e.g. <code>15</code> → <code>session_duration_15</code>). Default:
						<code><?php echo esc_html( self::DEFAULT_MARKS ); ?></code>.
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/* -------------------------------------------------------------- Front-end */

	public static function enqueue() {
		if ( is_admin() || ! self::is_enabled() ) { return; }
		$marks = self::milestones();
		if ( empty( $marks ) ) { return; }
		wp_enqueue_script( 'td-session-duration', TF_URL . 'assets/session-duration.js', array(), TF_VERSION, true );
		wp_localize_script( 'td-session-duration', 'TD_SESSION_DURATION', array(
			'milestones' => array_values( $marks ),
		) );
	}
}
