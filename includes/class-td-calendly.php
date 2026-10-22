<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Calendly listener — replaces the GTM custom-HTML tag we used to track
 * Calendly's embedded scheduler widget. Listens to `postMessage` events from
 * Calendly and pushes dataLayer events that fit our standard form taxonomy
 * (form_type / form_name / form_id / form_intent), so Calendly bookings
 * surface in the same analytics funnel as regular form submissions.
 *
 * Its own submenu page under Travel by Luxe — feature is on/off here
 * (separate from the global Settings tab) and the four form_* values are
 * editable in case different sites want to tag their Calendly bookings
 * differently.
 */
class TD_Calendly {

	const OPT  = 'td_calendly';
	const SLUG = 'td-calendly';

	const DEFAULTS = array(
		'enabled'     => 0,
		'form_type'   => 'calendly_scheduler_widget',
		'form_name'   => 'Calendly Scheduler Widget',
		'form_id'     => 'calendly_scheduler_widget',
		'form_intent' => 'meeting_booked',
	);

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 11 );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/* --------------------------------------------------------------- Settings */

	public static function menu() {
		add_submenu_page( TD_MENU, 'Calendly Listener', 'Calendly', 'edit_pages', self::SLUG, array( __CLASS__, 'screen' ) );
	}

	public static function register() {
		register_setting( 'td_calendly_group', self::OPT, array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
		// Match the cap we use on the screen so options.php doesn't block edit_pages users.
		add_filter( 'option_page_capability_td_calendly_group', function () { return 'edit_pages'; } );
	}

	public static function sanitize( $in ) {
		$d = self::DEFAULTS;
		return array(
			'enabled'     => ! empty( $in['enabled'] ) ? 1 : 0,
			'form_type'   => isset( $in['form_type'] )   && '' !== trim( (string) $in['form_type'] )   ? sanitize_text_field( $in['form_type'] )   : $d['form_type'],
			'form_name'   => isset( $in['form_name'] )   && '' !== trim( (string) $in['form_name'] )   ? sanitize_text_field( $in['form_name'] )   : $d['form_name'],
			'form_id'     => isset( $in['form_id'] )     && '' !== trim( (string) $in['form_id'] )     ? sanitize_text_field( $in['form_id'] )     : $d['form_id'],
			'form_intent' => isset( $in['form_intent'] ) && '' !== trim( (string) $in['form_intent'] ) ? sanitize_text_field( $in['form_intent'] ) : $d['form_intent'],
		);
	}

	public static function get( $key ) {
		$o = get_option( self::OPT, array() );
		if ( isset( $o[ $key ] ) && '' !== $o[ $key ] ) { return $o[ $key ]; }
		return isset( self::DEFAULTS[ $key ] ) ? self::DEFAULTS[ $key ] : '';
	}

	public static function is_enabled() {
		$o = get_option( self::OPT, array() );
		return ! empty( $o['enabled'] );
	}

	/* ----------------------------------------------------------------- Screen */

	public static function screen() {
		?>
		<div class="wrap">
			<h1>Calendly Listener</h1>
			<p style="font-size:14px;color:#555;max-width:760px">
				Tracks <a href="https://calendly.com" target="_blank" rel="noopener">Calendly</a>'s embedded
				scheduler widget by listening to its <code>postMessage</code> events and pushing matching
				<code>dataLayer</code> events — so Calendly bookings flow through the same analytics funnel
				as TBL Forms submissions.
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'td_calendly_group' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Enable</th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPT ); ?>[enabled]" value="1" <?php checked( self::is_enabled() ); ?>>
								Listen for Calendly events and push them to <code>dataLayer</code>
							</label>
							<p class="description">Loads a small front-end script (~1KB) on every page. No effect on pages without a Calendly widget — the listener simply never receives a matching message.</p>
						</td>
					</tr>
				</table>

				<h2 class="title" style="margin-top:2em">Form metadata</h2>
				<p class="description" style="max-width:760px">These values are sent with every Calendly event so reporting can treat bookings like a form. Defaults match what we used in the GTM tag — only change them if a specific site needs different tagging.</p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="td_cal_type">form_type</label></th>
						<td><input type="text" id="td_cal_type" class="regular-text"
							name="<?php echo esc_attr( self::OPT ); ?>[form_type]"
							value="<?php echo esc_attr( self::get( 'form_type' ) ); ?>"
							placeholder="<?php echo esc_attr( self::DEFAULTS['form_type'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="td_cal_name">form_name</label></th>
						<td><input type="text" id="td_cal_name" class="regular-text"
							name="<?php echo esc_attr( self::OPT ); ?>[form_name]"
							value="<?php echo esc_attr( self::get( 'form_name' ) ); ?>"
							placeholder="<?php echo esc_attr( self::DEFAULTS['form_name'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="td_cal_id">form_id</label></th>
						<td><input type="text" id="td_cal_id" class="regular-text"
							name="<?php echo esc_attr( self::OPT ); ?>[form_id]"
							value="<?php echo esc_attr( self::get( 'form_id' ) ); ?>"
							placeholder="<?php echo esc_attr( self::DEFAULTS['form_id'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="td_cal_intent">form_intent</label></th>
						<td><input type="text" id="td_cal_intent" class="regular-text"
							name="<?php echo esc_attr( self::OPT ); ?>[form_intent]"
							value="<?php echo esc_attr( self::get( 'form_intent' ) ); ?>"
							placeholder="<?php echo esc_attr( self::DEFAULTS['form_intent'] ); ?>"></td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<h2 class="title" style="margin-top:2em">Events fired</h2>
			<table class="widefat striped" style="max-width:860px">
				<thead><tr><th>Calendly event</th><th>dataLayer event</th><th>Notes</th></tr></thead>
				<tbody>
					<tr><td><code>profile_page_viewed</code></td><td><code>meeting_scheduler_loaded</code></td><td>Widget rendered.</td></tr>
					<tr><td><code>event_type_viewed</code></td><td><code>meeting_type_selected</code></td><td>Visitor picked a meeting type.</td></tr>
					<tr><td><code>date_and_time_selected</code></td><td><code>meeting_time_selected</code></td><td>Visitor picked a slot.</td></tr>
					<tr><td><code>event_scheduled</code></td><td><code>form_submission</code>, then <code><?php echo esc_html( self::get( 'form_intent' ) ); ?></code></td><td>Booking confirmed. <code>entry_key</code> carries Calendly's scheduled-event UUID. The intent fires as its own event right after <code>form_submission</code> — same pattern as TBL Forms (e.g. <code>generate_lead</code> after a lead form).</td></tr>
				</tbody>
			</table>
			<p class="description" style="max-width:760px;margin-top:8px">Each Calendly source-event fires at most once per page-load. All events are stamped <code>event_origin: "td_wp_plugin"</code> like the rest of the plugin.</p>
		</div>
		<?php
	}

	/* -------------------------------------------------------------- Front-end */

	public static function enqueue() {
		if ( is_admin() || ! self::is_enabled() ) { return; }
		wp_enqueue_script( 'td-calendly', TF_URL . 'assets/calendly.js', array(), TF_VERSION, true );
		wp_localize_script( 'td-calendly', 'TD_CALENDLY', array(
			'form_type'   => self::get( 'form_type' ),
			'form_name'   => self::get( 'form_name' ),
			'form_id'     => self::get( 'form_id' ),
			'form_intent' => self::get( 'form_intent' ),
		) );
	}
}
