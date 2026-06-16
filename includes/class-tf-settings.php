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
