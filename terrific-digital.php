<?php
/**
 * Plugin Name:       Terrific Digital
 * Plugin URI:        https://terrific.co.il
 * Description:       Terrific Digital toolkit for WordPress. Includes Terrific Forms — a drag-and-drop form builder (regular + multi-step) with static IDs, dataLayer events, auto-captured metadata, and submission storage/export. Built to grow with more features.
 * Version:           2.16.0
 * Author:            Terrific Digital
 * License:           GPL-2.0+
 * Text Domain:       terrific-digital
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'TD_VERSION', '2.16.0' );
define( 'TD_MENU', 'terrific-digital' );      // top-level admin menu slug

// Terrific Forms feature constants (kept stable for back-compat with existing forms/data).
define( 'TF_VERSION', TD_VERSION );
define( 'TF_FILE', __FILE__ );
define( 'TF_DIR', plugin_dir_path( __FILE__ ) );
define( 'TF_URL', plugin_dir_url( __FILE__ ) );
define( 'TF_CPT', 'terrific_form' );

require_once TF_DIR . 'includes/class-td-menu.php';
require_once TF_DIR . 'includes/class-td-session.php';
require_once TF_DIR . 'includes/class-tf-settings.php';
require_once TF_DIR . 'includes/class-tf-cpt.php';
require_once TF_DIR . 'includes/class-tf-builder.php';
require_once TF_DIR . 'includes/class-tf-render.php';
require_once TF_DIR . 'includes/class-tf-submissions.php';
require_once TF_DIR . 'includes/class-tf-revisions.php';
require_once TF_DIR . 'includes/class-tf-block.php';

add_action( 'plugins_loaded', function () {
	TD_Menu::init();
	TD_Session::init();
	TF_Settings::init();
	TF_CPT_Manager::init();
	TF_Builder::init();
	TF_Render::init();
	TF_Submissions::init();
	TF_Revisions::init();
	TF_Block::init();
} );

register_activation_hook( __FILE__, function () {
	require_once TF_DIR . 'includes/class-tf-submissions.php';
	require_once TF_DIR . 'includes/class-tf-revisions.php';
	require_once TF_DIR . 'includes/class-tf-cpt.php';
	TF_Submissions::create_table();
	TF_Revisions::create_table();
	TF_CPT_Manager::register();
	flush_rewrite_rules();
} );
register_deactivation_hook( __FILE__, function () { flush_rewrite_rules(); } );

/* ------------------------------------------------------------------ Helpers */

function tf_get_form( $post_id ) {
	$json = get_post_meta( $post_id, '_tf_definition', true );
	$def  = $json ? json_decode( $json, true ) : null;
	if ( ! is_array( $def ) ) {
		$def = array( 'type' => 'regular', 'steps' => array( array( 'title' => '', 'fields' => array() ) ) );
	}
	$def['form_id']         = get_post_meta( $post_id, '_tf_form_id', true );
	$def['form_name']       = get_the_title( $post_id );
	$def['post_id']         = (int) $post_id;
	$def['submit_text']     = get_post_meta( $post_id, '_tf_submit_text', true ) ?: 'Submit';
	$def['success_message'] = get_post_meta( $post_id, '_tf_success', true ) ?: 'Thank you! Your submission has been received.';
	return $def;
}

function tf_ensure_form_id( $post_id ) {
	$id = get_post_meta( $post_id, '_tf_form_id', true );
	if ( $id ) { return $id; }
	$id = 'tf_' . $post_id . '_' . substr( md5( $post_id . wp_salt() ), 0, 6 );
	update_post_meta( $post_id, '_tf_form_id', $id );
	return $id;
}

/**
 * Best-effort real client IP, accounting for Cloudways/Varnish/CDN proxies.
 */
function tf_client_ip() {
	$candidates = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );
	foreach ( $candidates as $key ) {
		if ( empty( $_SERVER[ $key ] ) ) { continue; }
		$value = wp_unslash( $_SERVER[ $key ] );
		foreach ( explode( ',', $value ) as $ip ) {
			$ip = trim( $ip );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}
	}
	return '';
}

function tf_user_agent() {
	return isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 500 ) : '';
}

/* ---------------------------------------------------- Device / geo / cookies */

/** desktop | tablet | mobile from a user-agent string. */
function tf_device_category( $ua ) {
	$ua = (string) $ua;
	if ( preg_match( '/iPad|Tablet|PlayBook|Silk|Android(?!.*Mobile)/i', $ua ) ) { return 'tablet'; }
	if ( preg_match( '/Mobile|iP(hone|od)|BlackBerry|IEMobile|Opera Mini/i', $ua ) ) { return 'mobile'; }
	return 'desktop';
}

/** Best-effort OS name from a user-agent string. */
function tf_device_os( $ua ) {
	$ua = (string) $ua;
	if ( preg_match( '/Windows NT/i', $ua ) ) { return 'Windows'; }
	if ( preg_match( '/iPhone|iPad|iPod/i', $ua ) ) { return 'iOS'; }
	if ( preg_match( '/Mac OS X/i', $ua ) ) { return 'macOS'; }
	if ( preg_match( '/Android/i', $ua ) ) { return 'Android'; }
	if ( preg_match( '/CrOS/i', $ua ) ) { return 'Chrome OS'; }
	if ( preg_match( '/Linux/i', $ua ) ) { return 'Linux'; }
	return '';
}

/** Best-effort browser name from a user-agent string (order matters). */
function tf_device_browser( $ua ) {
	$ua = (string) $ua;
	if ( preg_match( '/Edg(e|A|iOS)?\//i', $ua ) ) { return 'Edge'; }
	if ( preg_match( '/OPR\/|Opera/i', $ua ) ) { return 'Opera'; }
	if ( preg_match( '/SamsungBrowser/i', $ua ) ) { return 'Samsung Internet'; }
	if ( preg_match( '/Firefox\/|FxiOS/i', $ua ) ) { return 'Firefox'; }
	if ( preg_match( '/Chrome\/|CriOS/i', $ua ) ) { return 'Chrome'; }
	if ( preg_match( '/Safari\//i', $ua ) ) { return 'Safari'; }
	if ( preg_match( '/MSIE|Trident/i', $ua ) ) { return 'Internet Explorer'; }
	return '';
}

/**
 * Geolocate an IP → [ country, country_code ]. Uses server geo headers when
 * present, otherwise a cached lookup against a free HTTPS API.
 */
function tf_geo_lookup( $ip ) {
	$empty = array( 'country' => '', 'country_code' => '' );
	if ( empty( $ip ) || ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
		return $empty;
	}
	// Some hosts/CDNs inject the country directly.
	if ( ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
		$cc = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) );
		if ( 'XX' !== $cc ) { return array( 'country' => '', 'country_code' => $cc ); }
	}
	$key    = 'tf_geo_' . md5( $ip );
	$cached = get_transient( $key );
	if ( false !== $cached ) { return $cached; }

	$out = $empty;
	$res = wp_remote_get( 'https://ipwho.is/' . rawurlencode( $ip ), array( 'timeout' => 3 ) );
	if ( ! is_wp_error( $res ) ) {
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( is_array( $body ) && ! empty( $body['success'] ) ) {
			$out = array(
				'country'      => isset( $body['country'] ) ? $body['country'] : '',
				'country_code' => isset( $body['country_code'] ) ? $body['country_code'] : '',
			);
		}
	}
	set_transient( $key, $out, WEEK_IN_SECONDS );
	return $out;
}

/** Country list for phone dial-code dropdowns: [ code, dial, name ]. */
function tf_countries() {
	static $list = null;
	if ( null !== $list ) { return $list; }
	$list = array(
		array( 'US', '+1', 'United States' ), array( 'GB', '+44', 'United Kingdom' ), array( 'CA', '+1', 'Canada' ),
		array( 'AU', '+61', 'Australia' ), array( 'IL', '+972', 'Israel' ), array( 'IE', '+353', 'Ireland' ),
		array( 'DE', '+49', 'Germany' ), array( 'FR', '+33', 'France' ), array( 'IT', '+39', 'Italy' ),
		array( 'ES', '+34', 'Spain' ), array( 'PT', '+351', 'Portugal' ), array( 'NL', '+31', 'Netherlands' ),
		array( 'BE', '+32', 'Belgium' ), array( 'CH', '+41', 'Switzerland' ), array( 'AT', '+43', 'Austria' ),
		array( 'SE', '+46', 'Sweden' ), array( 'NO', '+47', 'Norway' ), array( 'DK', '+45', 'Denmark' ),
		array( 'FI', '+358', 'Finland' ), array( 'PL', '+48', 'Poland' ), array( 'GR', '+30', 'Greece' ),
		array( 'CZ', '+420', 'Czechia' ), array( 'RO', '+40', 'Romania' ), array( 'HU', '+36', 'Hungary' ),
		array( 'RU', '+7', 'Russia' ), array( 'UA', '+380', 'Ukraine' ), array( 'TR', '+90', 'Turkey' ),
		array( 'AE', '+971', 'United Arab Emirates' ), array( 'SA', '+966', 'Saudi Arabia' ), array( 'QA', '+974', 'Qatar' ),
		array( 'IN', '+91', 'India' ), array( 'CN', '+86', 'China' ), array( 'HK', '+852', 'Hong Kong' ),
		array( 'JP', '+81', 'Japan' ), array( 'KR', '+82', 'South Korea' ), array( 'SG', '+65', 'Singapore' ),
		array( 'TH', '+66', 'Thailand' ), array( 'MY', '+60', 'Malaysia' ), array( 'ID', '+62', 'Indonesia' ),
		array( 'PH', '+63', 'Philippines' ), array( 'VN', '+84', 'Vietnam' ), array( 'NZ', '+64', 'New Zealand' ),
		array( 'ZA', '+27', 'South Africa' ), array( 'EG', '+20', 'Egypt' ), array( 'NG', '+234', 'Nigeria' ),
		array( 'BR', '+55', 'Brazil' ), array( 'MX', '+52', 'Mexico' ), array( 'AR', '+54', 'Argentina' ),
		array( 'CL', '+56', 'Chile' ), array( 'CO', '+57', 'Colombia' ),
	);
	$list = apply_filters( 'terrific_forms/countries', $list );
	return $list;
}

/** Regional-indicator flag emoji from a 2-letter ISO country code. */
function tf_flag( $cc ) {
	$cc = strtoupper( trim( (string) $cc ) );
	if ( 2 !== strlen( $cc ) || ! ctype_alpha( $cc ) ) { return ''; }
	return mb_chr( 0x1F1E6 + ( ord( $cc[0] ) - 65 ), 'UTF-8' ) . mb_chr( 0x1F1E6 + ( ord( $cc[1] ) - 65 ), 'UTF-8' );
}

/** All request cookies as key=>value, minus WordPress auth/internal cookies. */
function tf_collect_cookies() {
	$out = array();
	foreach ( (array) $_COOKIE as $k => $v ) {
		$k = (string) $k;
		if ( preg_match( '/^(wordpress|wp-|wp_|comment_)/i', $k ) ) { continue; } // never leak WP auth cookies
		if ( is_array( $v ) ) { continue; }
		$out[ sanitize_text_field( $k ) ] = sanitize_textarea_field( wp_unslash( $v ) );
	}
	return $out;
}

/** The plugin-wide global webhook URL (Settings page). */
function tf_global_webhook() {
	return TF_Settings::get( 'global_webhook' );
}

/**
 * Available form intents: key => [ label, param ].
 * `param` is the value sent as `form_intent` to the webhook + dataLayer.
 */
function tf_intents() {
	return apply_filters( 'terrific_forms/intents', array(
		'lead' => array( 'label' => 'Lead', 'param' => 'generate_lead' ),
	) );
}

/** A form's intent key (defaults to 'lead'). */
function tf_get_intent( $post_id ) {
	$intent = get_post_meta( $post_id, '_tf_intent', true );
	$map    = tf_intents();
	return ( $intent && isset( $map[ $intent ] ) ) ? $intent : 'lead';
}

/** The `form_intent` param value for a form (e.g. 'generate_lead'), or '' if none. */
function tf_form_intent_param( $post_id ) {
	$map    = tf_intents();
	$intent = tf_get_intent( $post_id );
	return isset( $map[ $intent ]['param'] ) ? $map[ $intent ]['param'] : '';
}

/**
 * A form's Actions list: ordered array of { type, enabled, config }.
 * Migrates the old single-value metas the first time if no list exists yet.
 */
function tf_get_actions( $post_id ) {
	$json    = get_post_meta( $post_id, '_tf_actions', true );
	$actions = $json ? json_decode( $json, true ) : array();
	if ( ! is_array( $actions ) ) { $actions = array(); }

	if ( empty( $actions ) ) {
		$legacy_hook = get_post_meta( $post_id, '_tf_webhook_url', true );
		$legacy_rt   = get_post_meta( $post_id, '_tf_redirect_type', true );
		if ( $legacy_hook ) {
			$actions[] = array( 'type' => 'webhook', 'enabled' => true, 'config' => array( 'source' => 'custom', 'url' => $legacy_hook ) );
		}
		if ( $legacy_rt && 'none' !== $legacy_rt ) {
			$cfg = ( 'page' === $legacy_rt )
				? array( 'mode' => 'page', 'page_id' => (int) get_post_meta( $post_id, '_tf_redirect_page', true ), 'url' => '' )
				: array( 'mode' => 'url', 'url' => get_post_meta( $post_id, '_tf_redirect_url', true ), 'page_id' => 0 );
			$actions[] = array( 'type' => 'redirect', 'enabled' => true, 'config' => $cfg );
		}
	}
	return $actions;
}

/** True if the form has an enabled webhook action set to fire on every step. */
function tf_form_has_step_webhook( $post_id ) {
	foreach ( tf_get_actions( $post_id ) as $a ) {
		if ( empty( $a['enabled'] ) || ( isset( $a['type'] ) ? $a['type'] : '' ) !== 'webhook' ) { continue; }
		if ( ( isset( $a['config']['trigger'] ) ? $a['config']['trigger'] : 'final' ) === 'every' ) { return true; }
	}
	return false;
}

/** Resolve the destination URL of a webhook action (global or custom). */
function tf_resolve_webhook_url( $action ) {
	$cfg = isset( $action['config'] ) ? $action['config'] : array();
	if ( isset( $cfg['source'] ) && 'global' === $cfg['source'] ) {
		return tf_global_webhook();
	}
	return isset( $cfg['url'] ) ? $cfg['url'] : '';
}

/** Resolve the destination URL of a redirect action (url or page). */
function tf_resolve_redirect_url( $action ) {
	$cfg = isset( $action['config'] ) ? $action['config'] : array();
	if ( isset( $cfg['mode'] ) && 'page' === $cfg['mode'] ) {
		$pid = isset( $cfg['page_id'] ) ? (int) $cfg['page_id'] : 0;
		return $pid ? get_permalink( $pid ) : '';
	}
	return isset( $cfg['url'] ) ? $cfg['url'] : '';
}
