<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * User-Provided Data ("upd") — a plugin-wide (forms) feature, ON by default.
 *
 * Whenever a visitor provides personal data through a TBL Form — on any
 * step or on the final submit — the front-end captures it into a single,
 * persisted `upd` object:
 *
 *   { "fn": "", "ln": "", "em": "", "ph": "", "co": "" }
 *     fn first name   (lowercase)
 *     ln last name    (lowercase)
 *     em email        (lowercase)
 *     ph phone        (digits only)
 *     co country      (2-letter ISO code, UPPERCASE)
 *
 * It is persisted in localStorage or a long-lived cookie (selectable in
 * Settings) so the latest known identity is available to tracking on every
 * page. Each time the object gains/changes a value the front-end pushes a
 * dataLayer event *before* any form_step / form_submission event:
 *
 *   window.dataLayer.push({ event: 'upd', upd: { fn, ln, em, ph, co } });
 *
 * This is a front-end / analytics identity feature: nothing is stored server
 * side here (form submissions are handled separately by TF_Submissions).
 */
class TD_Upd {

	const OPT       = 'td_upd';
	const EVENT     = 'upd';     // dataLayer event name + payload key
	const STORE_KEY = 'td_upd';  // localStorage key / cookie name

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'terrific_digital/settings_fields', array( __CLASS__, 'settings_fields' ) );
	}

	/* --------------------------------------------------------------- Settings */

	public static function register() {
		// Registered to the shared settings group so it saves with the Settings form.
		register_setting( 'tf_settings_group', self::OPT, array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
	}

	public static function sanitize( $in ) {
		$storage = ( isset( $in['storage'] ) && 'cookie' === $in['storage'] ) ? 'cookie' : 'local';
		return array(
			'enabled' => ! empty( $in['enabled'] ) ? 1 : 0,
			'storage' => $storage,
		);
	}

	/** ON by default: before the option is ever saved, treat the feature as enabled. */
	public static function is_enabled() {
		$o = get_option( self::OPT, null );
		if ( ! is_array( $o ) ) { return true; }
		return ! empty( $o['enabled'] );
	}

	public static function storage() {
		$o = get_option( self::OPT, array() );
		return ( isset( $o['storage'] ) && 'cookie' === $o['storage'] ) ? 'cookie' : 'local';
	}

	/** Front-end config injected into the TF_FORM JS global (see TF_Render). */
	public static function config() {
		return array(
			'enabled' => self::is_enabled() ? 1 : 0,
			'storage' => self::storage(),
			'key'     => self::STORE_KEY,
			'event'   => self::EVENT,
		);
	}

	/** Renders inside the shared Settings <form> (see TF_Settings::screen). */
	public static function settings_fields() {
		$enabled = self::is_enabled();
		$storage = self::storage();
		?>
		<hr style="margin:2.5em 0 1.5em">
		<h2 class="title">User-Provided Data</h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Enable</th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( self::OPT ); ?>[enabled]" value="1" <?php checked( $enabled ); ?>>
						Capture user-provided data from form fills
					</label>
					<p class="description">On by default.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">Storage</th>
				<td>
					<fieldset>
						<label style="display:block;margin-bottom:6px">
							<input type="radio" name="<?php echo esc_attr( self::OPT ); ?>[storage]" value="local" <?php checked( $storage, 'local' ); ?>>
							<strong>localStorage</strong> — kept on the device until cleared; not sent with requests.
						</label>
						<label style="display:block">
							<input type="radio" name="<?php echo esc_attr( self::OPT ); ?>[storage]" value="cookie" <?php checked( $storage, 'cookie' ); ?>>
							<strong>Cookie</strong> — set with the maximum expiry and shared across tabs; sent with requests.
						</label>
					</fieldset>
				</td>
			</tr>
		</table>
		<?php
	}
}
