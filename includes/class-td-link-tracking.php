<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Automatic dataLayer events for outbound-style link clicks:
 *   - mailto: links            → event 'email_link_click'
 *   - tel:    links            → event 'phone_link_click'
 *   - known social-network URLs → event 'social_link_click' (+ social_platform)
 *
 * Always-on, no admin UI: enqueues one tiny JS file on every front-end page.
 * The listener runs in the capture phase, so dynamically-added links (and
 * links whose own handlers call stopPropagation) are still tracked.
 */
class TD_Link_Tracking {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function enqueue() {
		if ( is_admin() ) { return; }
		wp_enqueue_script(
			'td-link-tracking',
			TF_URL . 'assets/link-tracking.js',
			array(),
			TF_VERSION,
			true
		);
	}
}
