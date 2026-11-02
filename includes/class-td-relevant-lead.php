<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * "Relevant lead" dataLayer event — a forms feature, ON by default.
 *
 * When a visitor finishes a multi-step form (the form_step event with final_step = true)
 * and the budget they gave is at least the minimum set here, the front-end pushes one
 * more dataLayer event right after form_step / form_complete: the same parameters, plus
 *
 *   { event: 'relevant_lead', currency: 'USD', est_budget: '<budget as entered>' }
 *
 * This replaces the GTM Custom HTML tag "[Script] relevant_lead DL Event" (budget >=
 * 5000, currency USD) — pause that tag when the plugin ships this, or it fires twice.
 * Settings → Relevant lead controls the event name, the budget field, the minimum and
 * the currency. Front-end only: nothing is stored server side.
 */
class TD_Relevant_Lead {

	const OPT = 'td_relevant_lead';

	const DEFAULTS = array(
		'enabled'  => 1,
		'event'    => 'relevant_lead',
		'field'    => 'budget',
		'min'      => 5000,
		'currency' => 'USD',
	);

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'terrific_digital/settings_fields', array( __CLASS__, 'settings_fields' ) );
	}

	/* --------------------------------------------------------------- Settings */

	public static function register() {
		// The shared settings group, so it saves with the Settings form.
		register_setting( 'tf_settings_group', self::OPT, array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
	}

	public static function sanitize( $in ) {
		$in    = is_array( $in ) ? $in : array();
		$event = isset( $in['event'] ) ? preg_replace( '/[^A-Za-z0-9_.-]/', '', (string) $in['event'] ) : '';
		$field = isset( $in['field'] ) ? preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $in['field'] ) : '';
		$cur   = isset( $in['currency'] ) ? strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $in['currency'] ) ) : '';
		$min   = isset( $in['min'] ) ? (float) str_replace( ',', '', (string) $in['min'] ) : self::DEFAULTS['min'];
		return array(
			'enabled'  => ! empty( $in['enabled'] ) ? 1 : 0,
			'event'    => '' !== $event ? $event : self::DEFAULTS['event'],
			'field'    => '' !== $field ? $field : self::DEFAULTS['field'],
			'min'      => $min >= 0 ? $min : 0,
			'currency' => 3 === strlen( $cur ) ? $cur : self::DEFAULTS['currency'],
		);
	}

	/** The saved settings over the defaults (ON until someone saves it off). */
	public static function settings() {
		$o = get_option( self::OPT, null );
		return is_array( $o ) ? array_merge( self::DEFAULTS, $o ) : self::DEFAULTS;
	}

	/** Front-end config injected into the TF_FORM JS global (see TF_Render). */
	public static function config() {
		$s = self::settings();
		return array(
			'enabled'  => ! empty( $s['enabled'] ) ? 1 : 0,
			'event'    => (string) $s['event'],
			'field'    => (string) $s['field'],
			'min'      => (float) $s['min'],
			'currency' => (string) $s['currency'],
		);
	}

	/** Renders inside the shared Settings <form> (see TF_Settings::screen). */
	public static function settings_fields() {
		$s = self::settings();
		$n = esc_attr( self::OPT );
		?>
		<hr style="margin:2.5em 0 1.5em">
		<h2 class="title">Relevant lead</h2>
		<p class="description" style="max-width:760px">
			When a visitor finishes a multi-step form and their budget is at least the minimum below,
			a dataLayer event fires right after the final <code>form_step</code> (and <code>form_complete</code>), with the same
			parameters plus <code>currency</code> and <code>est_budget</code>. Replaces the GTM tag
			“[Script] relevant_lead DL Event” — pause that tag, or the event fires twice.
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Enable</th>
				<td><label><input type="checkbox" name="<?php echo $n; ?>[enabled]" value="1" <?php checked( ! empty( $s['enabled'] ) ); ?>> Fire the relevant-lead event</label>
					<p class="description">On by default.</p></td>
			</tr>
			<tr>
				<th scope="row"><label for="td-rl-min">Minimum budget</label></th>
				<td><input type="number" id="td-rl-min" min="0" step="1" class="small-text" style="width:8em" name="<?php echo $n; ?>[min]" value="<?php echo esc_attr( (string) ( 0 + $s['min'] ) ); ?>">
					<p class="description">Fires when the budget is at least this amount (default 5000).</p></td>
			</tr>
			<tr>
				<th scope="row"><label for="td-rl-cur">Currency</label></th>
				<td><input type="text" id="td-rl-cur" maxlength="3" class="small-text" name="<?php echo $n; ?>[currency]" value="<?php echo esc_attr( $s['currency'] ); ?>">
					<p class="description">Sent as <code>currency</code> (3-letter code, e.g. USD, EUR).</p></td>
			</tr>
			<tr>
				<th scope="row"><label for="td-rl-field">Budget field key</label></th>
				<td><input type="text" id="td-rl-field" class="regular-text code" name="<?php echo $n; ?>[field]" value="<?php echo esc_attr( $s['field'] ); ?>">
					<p class="description">The form field the budget is read from (in <code>form_fields</code>). Default <code>budget</code>.</p></td>
			</tr>
			<tr>
				<th scope="row"><label for="td-rl-event">Event name</label></th>
				<td><input type="text" id="td-rl-event" class="regular-text code" name="<?php echo $n; ?>[event]" value="<?php echo esc_attr( $s['event'] ); ?>">
					<p class="description">Default <code>relevant_lead</code>.</p></td>
			</tr>
		</table>
		<?php
	}
}
