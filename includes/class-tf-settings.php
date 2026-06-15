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
		add_submenu_page( TD_MENU, 'Forms Settings', 'Settings', 'manage_options', 'tf-settings', array( __CLASS__, 'screen' ) );
	}

	public static function register() {
		register_setting( 'tf_settings_group', self::OPT, array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
	}

	public static function sanitize( $in ) {
		return array(
			'global_webhook' => isset( $in['global_webhook'] ) ? esc_url_raw( $in['global_webhook'] ) : '',
		);
	}

	public static function get( $key ) {
		$o = get_option( self::OPT, array() );
		return isset( $o[ $key ] ) ? $o[ $key ] : '';
	}

	public static function screen() {
		?>
		<div class="wrap">
			<h1>Terrific Forms — Settings</h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'tf_settings_group' ); ?>
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
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
