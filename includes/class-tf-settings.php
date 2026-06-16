<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Terrific Forms global settings (under the Terrific Digital menu).
 * Currently: a global webhook URL that any form can opt into via its Actions.
 */
class TF_Settings {

	const OPT = 'tf_settings';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 12 );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	public static function menu() {
		// edit_pages (not manage_options) to match the rest of the Terrific Digital menu.
		add_submenu_page( TD_MENU, 'Terrific Digital Settings', 'Settings', 'edit_pages', 'tf-settings', array( __CLASS__, 'screen' ) );
	}

	public static function register() {
		register_setting( 'tf_settings_group', self::OPT, array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
		// options.php enforces the option-group capability (defaults to manage_options); align it so
		// the same edit_pages users who can open the Settings screen can also save it.
		add_filter( 'option_page_capability_tf_settings_group', function () { return 'edit_pages'; } );
	}

	public static function sanitize( $in ) {
		$threshold = isset( $in['recaptcha_threshold'] ) ? (float) $in['recaptcha_threshold'] : 0.5;
		$threshold = ( $threshold > 0 && $threshold <= 1 ) ? round( $threshold, 2 ) : 0.5;
		return array(
			'global_webhook'       => isset( $in['global_webhook'] ) ? esc_url_raw( $in['global_webhook'] ) : '',
			'recaptcha_enabled'    => ! empty( $in['recaptcha_enabled'] ) ? 1 : 0,
			'recaptcha_site_key'   => isset( $in['recaptcha_site_key'] ) ? sanitize_text_field( $in['recaptcha_site_key'] ) : '',
			'recaptcha_secret_key' => isset( $in['recaptcha_secret_key'] ) ? sanitize_text_field( $in['recaptcha_secret_key'] ) : '',
			'recaptcha_threshold'  => $threshold,
		);
	}

	public static function get( $key ) {
		$o = get_option( self::OPT, array() );
		return isset( $o[ $key ] ) ? $o[ $key ] : '';
	}

	/**
	 * reCAPTCHA v3 section (spam protection). Renders inside the Settings <form>.
	 * The key fields are revealed only when the feature is enabled.
	 */
	public static function recaptcha_fields() {
		$enabled   = ! empty( self::get( 'recaptcha_enabled' ) );
		$site      = self::get( 'recaptcha_site_key' );
		$secret    = self::get( 'recaptcha_secret_key' );
		$threshold = self::get( 'recaptcha_threshold' );
		$threshold = ( '' === $threshold ) ? 0.5 : (float) $threshold;
		$opt       = self::OPT;
		?>
		<hr style="margin:2.5em 0 1.5em">
		<h2 class="title">Spam protection — reCAPTCHA v3</h2>
		<p class="description" style="max-width:760px">
			Add Google reCAPTCHA v3 to every form to silently score traffic and block bots. Create a
			<strong>reCAPTCHA v3</strong> site at
			<a href="https://www.google.com/recaptcha/admin/create" target="_blank" rel="noopener">google.com/recaptcha</a>
			and paste the keys below.
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Enable</th>
				<td>
					<label>
						<input type="checkbox" id="tf_rc_enabled" name="<?php echo esc_attr( $opt ); ?>[recaptcha_enabled]" value="1" <?php checked( $enabled ); ?>>
						Protect all forms with Google reCAPTCHA v3
					</label>
				</td>
			</tr>
		</table>
		<table class="form-table" role="presentation" id="tf_rc_keys" style="<?php echo $enabled ? '' : 'display:none'; ?>">
			<tr>
				<th scope="row"><label for="tf_rc_site">Site key</label></th>
				<td>
					<input type="text" id="tf_rc_site" class="regular-text" autocomplete="off" spellcheck="false"
						name="<?php echo esc_attr( $opt ); ?>[recaptcha_site_key]" value="<?php echo esc_attr( $site ); ?>">
					<p class="description">The public key — rendered on the page.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="tf_rc_secret">Secret key</label></th>
				<td>
					<input type="text" id="tf_rc_secret" class="regular-text" autocomplete="off" spellcheck="false"
						name="<?php echo esc_attr( $opt ); ?>[recaptcha_secret_key]" value="<?php echo esc_attr( $secret ); ?>">
					<p class="description">The private key — used server-side to verify each submission. Keep it secret.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="tf_rc_threshold">Score threshold</label></th>
				<td>
					<input type="number" id="tf_rc_threshold" step="0.1" min="0" max="1" style="width:90px"
						name="<?php echo esc_attr( $opt ); ?>[recaptcha_threshold]" value="<?php echo esc_attr( $threshold ); ?>">
					<p class="description">0.0–1.0. Submissions scoring <strong>below</strong> this are treated as bots and silently dropped (Google’s recommended default is 0.5).</p>
				</td>
			</tr>
		</table>
		<script>
			( function () {
				var cb = document.getElementById( 'tf_rc_enabled' ),
					keys = document.getElementById( 'tf_rc_keys' );
				if ( cb && keys ) {
					cb.addEventListener( 'change', function () { keys.style.display = cb.checked ? '' : 'none'; } );
				}
			} )();
		</script>
		<?php
	}

	public static function screen() {
		?>
		<div class="wrap">
			<h1>Terrific Digital — Settings</h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'tf_settings_group' ); ?>

				<h2 class="title">Terrific Forms</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="tf_global_webhook">Global webhook URL</label></th>
						<td>
							<input type="url" id="tf_global_webhook" class="regular-text"
								name="<?php echo esc_attr( self::OPT ); ?>[global_webhook]"
								value="<?php echo esc_attr( self::get( 'global_webhook' ) ); ?>"
								placeholder="https://hooks.example.com/…">
							<p class="description">Any form can send its submissions here by adding a <strong>Webhook</strong> action and choosing “global webhook”. Set it once, reuse everywhere.</p>
						</td>
					</tr>
				</table>

				<?php self::recaptcha_fields(); ?>

				<?php
				/**
				 * Other Terrific Digital features render their own settings sections here.
				 * Fields must belong to an option registered under the 'tf_settings_group'.
				 */
				do_action( 'terrific_digital/settings_fields' );
				?>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
