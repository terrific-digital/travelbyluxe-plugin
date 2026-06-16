<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Submission storage (custom table), AJAX capture, admin list table
 * with filter-by-form, CSV export, and delete (single + bulk).
 */
class TF_Submissions {

	public static function init() {
		add_action( 'wp_ajax_tf_submit', array( __CLASS__, 'handle' ) );
		add_action( 'wp_ajax_nopriv_tf_submit', array( __CLASS__, 'handle' ) );
		add_action( 'wp_ajax_tf_step', array( __CLASS__, 'handle_step' ) );
		add_action( 'wp_ajax_nopriv_tf_step', array( __CLASS__, 'handle_step' ) );
		add_action( 'wp_ajax_tf_geo', array( __CLASS__, 'handle_geo' ) );
		add_action( 'wp_ajax_nopriv_tf_geo', array( __CLASS__, 'handle_geo' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_create_table' ) );
		add_action( 'admin_post_tf_export', array( __CLASS__, 'export_csv' ) );
		add_action( 'admin_post_tf_delete', array( __CLASS__, 'delete_single' ) );
	}

	/** Honeypot tripped? Bots fill the hidden tf_hp field; humans never see it. */
	protected static function is_spam() {
		return isset( $_POST['hp'] ) && '' !== trim( (string) wp_unslash( $_POST['hp'] ) );
	}

	/**
	 * reCAPTCHA gate. True when the feature is off, or the posted token verifies
	 * at/above the score threshold. A missing token (when enabled) or a low score
	 * returns false → the request is treated like spam (silent drop).
	 */
	protected static function passes_recaptcha() {
		if ( ! tf_recaptcha_enabled() ) { return true; }
		$token = isset( $_POST['recaptcha_token'] ) ? sanitize_text_field( wp_unslash( $_POST['recaptcha_token'] ) ) : '';
		if ( '' === $token ) { return false; }
		return tf_recaptcha_verify( $token );
	}

	/** Public: return the visitor's country code (for phone-field auto-select). Not page-cached. */
	public static function handle_geo() {
		nocache_headers();
		$geo = tf_geo_lookup( tf_client_ip() );
		wp_send_json_success( array( 'country_code' => $geo['country_code'] ) );
	}

	/**
	 * Sanitize a posted fields JSON blob into a key=>value array. List values
	 * (multi-select/checkbox) collapse to a comma string; associative values
	 * (e.g. a date range's { start_date, end_date, duration }) keep their shape.
	 */
	protected static function clean_fields() {
		$raw    = isset( $_POST['fields'] ) ? json_decode( wp_unslash( $_POST['fields'] ), true ) : array();
		$fields = array();
		if ( is_array( $raw ) ) {
			foreach ( $raw as $k => $v ) {
				$fields[ sanitize_key( $k ) ] = self::clean_field_value( $v );
			}
		}
		return $fields;
	}

	/** Sanitize one field value (scalar, list, or associative object). */
	protected static function clean_field_value( $v ) {
		if ( ! is_array( $v ) ) {
			return sanitize_textarea_field( $v );
		}
		// Numeric-keyed list (multi-select / checkboxes) → comma string, as before.
		if ( array_keys( $v ) === range( 0, count( $v ) - 1 ) ) {
			return implode( ', ', array_map( 'sanitize_text_field', $v ) );
		}
		// Associative object (date range, etc.) → keep keys, sanitize scalars.
		$out = array();
		foreach ( $v as $sk => $sv ) {
			$out[ sanitize_key( $sk ) ] = is_scalar( $sv ) ? sanitize_text_field( (string) $sv ) : '';
		}
		return $out;
	}

	/** Flatten a stored field value to a readable string (for CSV / detail tables). */
	protected static function flatten_value( $v ) {
		if ( ! is_array( $v ) ) { return (string) $v; }
		// Date-range shape.
		if ( isset( $v['start_date'] ) || isset( $v['end_date'] ) ) {
			$s   = isset( $v['start_date'] ) ? $v['start_date'] : '';
			$e   = isset( $v['end_date'] ) ? $v['end_date'] : '';
			$d   = isset( $v['duration'] ) ? $v['duration'] : '';
			$out = trim( $s . ' → ' . $e, ' →' );
			if ( '' !== (string) $d ) { $out .= ' (' . $d . ' days)'; }
			return $out;
		}
		// Generic list / assoc fallback.
		$parts = array();
		foreach ( $v as $k => $vv ) {
			$vv      = is_array( $vv ) ? implode( ' ', $vv ) : $vv;
			$parts[] = is_int( $k ) ? (string) $vv : ( $k . ': ' . $vv );
		}
		return implode( ', ', $parts );
	}

	/** The client's td_session_data ({timestamp,url,ref}), posted with the form. Empty if not collected. */
	protected static function session_data() {
		$raw = isset( $_POST['session'] ) ? json_decode( wp_unslash( $_POST['session'] ), true ) : null;
		if ( ! is_array( $raw ) ) { return array(); }
		$out = array();
		if ( isset( $raw['timestamp'] ) ) { $out['timestamp'] = sanitize_text_field( $raw['timestamp'] ); }
		if ( isset( $raw['url'] ) ) { $out['url'] = esc_url_raw( $raw['url'] ); }
		if ( isset( $raw['ref'] ) ) { $out['ref'] = esc_url_raw( $raw['ref'] ); }
		return $out;
	}

	/** Build the request context (page meta, device, geo, cookies, session, intent). */
	protected static function request_context( $post_id ) {
		$page_url = isset( $_POST['page_url'] ) ? esc_url_raw( wp_unslash( $_POST['page_url'] ) ) : '';
		if ( '' === $page_url && ! empty( $_SERVER['HTTP_REFERER'] ) ) { $page_url = esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ); }
		$ref = isset( $_POST['page_ref'] ) ? esc_url_raw( wp_unslash( $_POST['page_ref'] ) ) : '';
		$ua  = tf_user_agent();
		$ip  = tf_client_ip();
		$geo = tf_geo_lookup( $ip );
		return array(
			'intent'     => tf_form_intent_param( $post_id ),
			'page_url'   => $page_url,
			'page_title' => isset( $_POST['page_title'] ) ? sanitize_text_field( wp_unslash( $_POST['page_title'] ) ) : '',
			'page_ref'   => $ref,
			'device'     => array(
				'user_agent'      => $ua,
				'user_ip'         => $ip,
				'category'        => tf_device_category( $ua ),
				'ip_country'      => $geo['country'],
				'ip_country_code' => $geo['country_code'],
				'platform'        => isset( $_POST['platform'] ) ? sanitize_text_field( wp_unslash( $_POST['platform'] ) ) : '',
				'language'        => isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : '',
				'os'              => tf_device_os( $ua ),
				'browser'         => tf_device_browser( $ua ),
			),
			'cookies'    => tf_collect_cookies(),
			'session'    => self::session_data(),
		);
	}

	/** Assemble the webhook payload. $step = null | [current, max, final]. */
	protected static function webhook_payload( $event, $fields, $def, $post_id, $ctx, $sub_id = 0, $step = null ) {
		$submission = array( 'id' => $sub_id ? (int) $sub_id : null );
		$submission['timestamp'] = gmdate( 'c' );
		if ( $sub_id ) { $submission['submission_link'] = self::page_url( array( 'view' => $sub_id ) ); }
		$submission['page_url']   = $ctx['page_url'];
		$submission['page_title'] = $ctx['page_title'];
		$submission['page_ref']   = $ctx['page_ref'];
		if ( is_array( $step ) ) {
			$submission['current_step'] = (int) $step['current'];
			$submission['max_step']     = (int) $step['max'];
			$submission['final_step']   = (bool) $step['final'];
		}
		$payload = array(
			'event'      => $event,
			'data'       => $fields,
			'form'       => array( 'id' => $def['form_id'], 'name' => $def['form_name'], 'intent' => $ctx['intent'], 'wp_id' => $post_id ),
			'submission' => $submission,
			'device'     => $ctx['device'],
			'cookies'    => $ctx['cookies'],
		);
		if ( ! empty( $ctx['session'] ) ) { $payload['session'] = $ctx['session']; }
		return $payload;
	}

	protected static function post_webhook( $url, $payload ) {
		wp_remote_post( $url, array(
			'timeout'  => 6,
			'blocking' => false, // fire-and-forget
			'headers'  => array( 'Content-Type' => 'application/json' ),
			'body'     => wp_json_encode( $payload ),
		) );
	}

	/**
	 * Insert a submission, or update the existing one matching $token (so a
	 * multi-step fill keeps writing to the same row — partial leads included).
	 * Returns the submission id.
	 */
	protected static function store_submission( $post_id, $def, $fields, $ctx, $status, $last_step, $token ) {
		global $wpdb;
		$now    = current_time( 'mysql' );
		$common = array(
			'data'       => wp_json_encode( $fields ),
			'page_url'   => $ctx['page_url'],
			'user_ip'    => $ctx['device']['user_ip'],
			'user_agent' => $ctx['device']['user_agent'],
			'intent'     => $ctx['intent'],
			'meta'       => wp_json_encode( array(
				'intent'     => $ctx['intent'],
				'submission' => array( 'page_url' => $ctx['page_url'], 'page_title' => $ctx['page_title'], 'page_ref' => $ctx['page_ref'] ),
				'device'     => $ctx['device'],
				'cookies'    => $ctx['cookies'],
				'session'    => $ctx['session'],
			) ),
			'status'     => $status,
			'last_step'  => (int) $last_step,
			'updated_at' => $now,
		);

		$existing = $token
			? $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE token = %s AND form_post_id = %d LIMIT 1', $token, $post_id ) )
			: 0;

		if ( $existing ) {
			$wpdb->update( self::table(), $common, array( 'id' => (int) $existing ) );
			return (int) $existing;
		}

		$wpdb->insert( self::table(), array_merge( $common, array(
			'form_post_id' => $post_id,
			'form_id'      => $def['form_id'],
			'form_name'    => $def['form_name'],
			'token'        => $token,
			'created_at'   => $now,
		) ) );
		return (int) $wpdb->insert_id;
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'tf_submissions';
	}

	public static function maybe_create_table() {
		if ( get_option( 'tf_db_version' ) !== TF_VERSION ) { self::create_table(); }
	}

	public static function create_table() {
		global $wpdb;
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE $table (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			form_post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			form_id VARCHAR(64) NOT NULL DEFAULT '',
			form_name VARCHAR(191) NOT NULL DEFAULT '',
			data LONGTEXT NULL,
			page_url TEXT NULL,
			user_ip VARCHAR(64) NOT NULL DEFAULT '',
			user_agent TEXT NULL,
			intent VARCHAR(64) NOT NULL DEFAULT '',
			meta LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'completed',
			last_step INT NOT NULL DEFAULT 0,
			token VARCHAR(64) NOT NULL DEFAULT '',
			updated_at DATETIME NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY (id),
			KEY form_post_id (form_post_id),
			KEY token (token),
			KEY status (status),
			KEY created_at (created_at)
		) $charset;";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		update_option( 'tf_db_version', TF_VERSION );
	}

	/* ----------------------------------------------------------------- AJAX */
	public static function handle() {
		check_ajax_referer( 'tf_submit', 'nonce' );
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		if ( ! $post_id || get_post_type( $post_id ) !== TF_CPT ) {
			wp_send_json_error( array( 'message' => 'Invalid form' ), 400 );
		}
		$def = tf_get_form( $post_id );

		// Honeypot or reCAPTCHA: pretend success but store nothing and fire no actions.
		if ( self::is_spam() || ! self::passes_recaptcha() ) {
			wp_send_json_success( array( 'submission_id' => 0, 'message' => $def['success_message'], 'redirect' => '' ) );
		}

		$fields = self::clean_fields();
		$ctx    = self::request_context( $post_id );
		$token  = isset( $_POST['submission_token'] ) ? sanitize_text_field( wp_unslash( $_POST['submission_token'] ) ) : '';
		$total  = count( $def['steps'] );

		// Complete the submission (updates the partial row from earlier steps, if any).
		$sub_id = self::store_submission( $post_id, $def, $fields, $ctx, 'completed', $total, $token );
		do_action( 'terrific_forms/submission', $fields, $def, $post_id, $sub_id, $ctx );

		// --- Actions: gather webhooks (+ trigger) and the redirect destination ---
		$webhooks = array();
		$redirect = '';
		foreach ( tf_get_actions( $post_id ) as $action ) {
			if ( empty( $action['enabled'] ) ) { continue; }
			$type = isset( $action['type'] ) ? $action['type'] : '';
			if ( 'webhook' === $type ) {
				$url = tf_resolve_webhook_url( $action );
				if ( $url ) {
					$webhooks[] = array( 'url' => $url, 'trigger' => isset( $action['config']['trigger'] ) ? $action['config']['trigger'] : 'final' );
				}
			} elseif ( 'redirect' === $type && '' === $redirect ) {
				$redirect = tf_resolve_redirect_url( $action );
			}
		}

		if ( $webhooks ) {
			$is_multi = ( 'multistep' === $def['type'] && $total > 1 );
			foreach ( $webhooks as $wh ) {
				// On the final submit, step-mode webhooks report the last step (final).
				$step = ( $is_multi && 'every' === $wh['trigger'] ) ? array( 'current' => $total, 'max' => $total, 'final' => true ) : null;
				self::post_webhook( $wh['url'], self::webhook_payload( 'form_submission', $fields, $def, $post_id, $ctx, $sub_id, $step ) );
			}
		}

		wp_send_json_success( array(
			'submission_id' => $sub_id,
			'message'       => $def['success_message'],
			'redirect'      => $redirect,
		) );
	}

	/**
	 * Step ping (multi-step forms): stores/updates a PARTIAL submission so
	 * abandoned forms are still collected, and fires any "every"-trigger webhook.
	 */
	public static function handle_step() {
		check_ajax_referer( 'tf_submit', 'nonce' );
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		if ( ! $post_id || get_post_type( $post_id ) !== TF_CPT ) {
			wp_send_json_error( array( 'message' => 'Invalid form' ), 400 );
		}
		if ( self::is_spam() || ! self::passes_recaptcha() ) { wp_send_json_success( array( 'skipped' => 'spam' ) ); }
		$def = tf_get_form( $post_id );
		if ( 'multistep' !== $def['type'] || count( $def['steps'] ) < 2 ) {
			wp_send_json_success( array( 'skipped' => 'not_multistep' ) );
		}
		$total  = count( $def['steps'] );
		$step   = isset( $_POST['step'] ) ? max( 1, min( $total, (int) $_POST['step'] ) ) : 1;
		$token  = isset( $_POST['submission_token'] ) ? sanitize_text_field( wp_unslash( $_POST['submission_token'] ) ) : '';
		$fields = self::clean_fields();
		$ctx    = self::request_context( $post_id );

		// Create on first step / update on later steps (status stays "partial").
		$sub_id = self::store_submission( $post_id, $def, $fields, $ctx, 'partial', $step, $token );
		do_action( 'terrific_forms/step', $fields, $def, $post_id, $sub_id, $ctx, $step );

		// Fire any "every"-trigger webhooks for this step.
		$payload = null;
		foreach ( tf_get_actions( $post_id ) as $action ) {
			if ( empty( $action['enabled'] ) || ( isset( $action['type'] ) ? $action['type'] : '' ) !== 'webhook' ) { continue; }
			if ( ( isset( $action['config']['trigger'] ) ? $action['config']['trigger'] : 'final' ) !== 'every' ) { continue; }
			$url = tf_resolve_webhook_url( $action );
			if ( ! $url ) { continue; }
			if ( null === $payload ) {
				$payload = self::webhook_payload( 'form_step', $fields, $def, $post_id, $ctx, $sub_id, array(
					'current' => $step,
					'max'     => $total,
					'final'   => ( $step >= $total ),
				) );
			}
			self::post_webhook( $url, $payload );
		}

		wp_send_json_success( array( 'submission_id' => $sub_id ) );
	}

	/* ------------------------------------------------------------- Admin menu */
	public static function menu() {
		$hook = add_submenu_page(
			TD_MENU,
			'Submissions', 'Submissions', 'edit_pages', 'tf-submissions',
			array( __CLASS__, 'screen' )
		);
		add_action( "load-$hook", array( __CLASS__, 'screen_options' ) );
	}

	/** Base admin URL of the Submissions screen (now nested under Terrific Digital). */
	public static function page_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => 'tf-submissions' ), $args ), admin_url( 'admin.php' ) );
	}

	public static function screen_options() {
		add_screen_option( 'per_page', array( 'label' => 'Submissions per page', 'default' => 30, 'option' => 'tf_subs_per_page' ) );
	}

	/* --------------------------------------------------------------- Helpers */
	public static function forms_dropdown( $selected ) {
		$forms = get_posts( array( 'post_type' => TF_CPT, 'numberposts' => -1, 'post_status' => 'any', 'orderby' => 'title', 'order' => 'ASC' ) );
		echo '<select name="form">';
		echo '<option value="0">All forms</option>';
		foreach ( $forms as $f ) {
			printf( '<option value="%d"%s>%s</option>', $f->ID, selected( $selected, $f->ID, false ), esc_html( get_the_title( $f ) ) );
		}
		echo '</select>';
	}

	/** Union of field keys across a result set, in first-seen order. */
	protected static function union_keys( $rows ) {
		$keys = array();
		foreach ( $rows as $r ) {
			$d = json_decode( $r->data, true );
			if ( is_array( $d ) ) {
				foreach ( $d as $k => $v ) { $keys[ $k ] = true; }
			}
		}
		return array_keys( $keys );
	}

	/* ------------------------------------------------------------ Delete (single) */
	public static function delete_single() {
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		if ( ! current_user_can( 'edit_pages' ) || ! $id ) { wp_die( 'Not allowed' ); }
		check_admin_referer( 'tf_delete_' . $id );
		global $wpdb;
		$wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
		wp_safe_redirect( self::page_url( array( 'deleted' => 1 ) ) );
		exit;
	}

	/* --------------------------------------------------------------- CSV export */
	public static function export_csv() {
		if ( ! current_user_can( 'edit_pages' ) ) { wp_die( 'Not allowed' ); }
		check_admin_referer( 'tf_export' );
		global $wpdb;
		$table = self::table();
		$form  = isset( $_GET['form'] ) ? (int) $_GET['form'] : 0;
		$where = $form ? $wpdb->prepare( 'WHERE form_post_id = %d', $form ) : '';
		$rows  = $wpdb->get_results( "SELECT * FROM $table $where ORDER BY created_at DESC" ); // phpcs:ignore

		$keys = self::union_keys( $rows );
		$file = 'terrific-forms-submissions-' . gmdate( 'Y-m-d-His' ) . '.csv';
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $file );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array_merge(
			array( 'ID', 'Date', 'Status', 'Last Step', 'Form', 'Form ID', 'Intent', 'Page URL', 'Page Title', 'Referrer', 'IP', 'Country', 'Country Code', 'Device', 'OS', 'Browser', 'Platform', 'Language', 'User Agent', 'Session Start', 'Session URL', 'Session Ref' ),
			$keys
		) );
		foreach ( $rows as $r ) {
			$d   = json_decode( $r->data, true );
			$d   = is_array( $d ) ? $d : array();
			$m   = json_decode( $r->meta, true );
			$dev = ( is_array( $m ) && isset( $m['device'] ) ) ? $m['device'] : array();
			$sm  = ( is_array( $m ) && isset( $m['submission'] ) ) ? $m['submission'] : array();
			$ss  = ( is_array( $m ) && isset( $m['session'] ) ) ? $m['session'] : array();
			$g   = function ( $a, $k ) { return isset( $a[ $k ] ) ? $a[ $k ] : ''; };
			$row = array(
				$r->id, $r->created_at, ( isset( $r->status ) ? $r->status : '' ), ( isset( $r->last_step ) ? $r->last_step : '' ),
				$r->form_name, $r->form_id, $r->intent,
				$r->page_url, $g( $sm, 'page_title' ), $g( $sm, 'page_ref' ),
				$r->user_ip, $g( $dev, 'ip_country' ), $g( $dev, 'ip_country_code' ),
				$g( $dev, 'category' ), $g( $dev, 'os' ), $g( $dev, 'browser' ), $g( $dev, 'platform' ), $g( $dev, 'language' ),
				$r->user_agent,
				$g( $ss, 'timestamp' ), $g( $ss, 'url' ), $g( $ss, 'ref' ),
			);
			foreach ( $keys as $k ) { $row[] = isset( $d[ $k ] ) ? self::flatten_value( $d[ $k ] ) : ''; }
			fputcsv( $out, $row );
		}
		fclose( $out );
		exit;
	}

	/* ----------------------------------------------------------------- Screen */
	public static function screen() {
		if ( isset( $_GET['view'] ) ) {
			self::detail_screen( (int) $_GET['view'] );
			return;
		}
		if ( ! class_exists( 'WP_List_Table' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}
		require_once TF_DIR . 'includes/class-tf-list-table.php';

		$table = new TF_List_Table();
		$table->prepare_items();
		$form_filter = isset( $_REQUEST['form'] ) ? (int) $_REQUEST['form'] : 0;

		$export_url = wp_nonce_url(
			add_query_arg( array( 'action' => 'tf_export', 'form' => $form_filter ), admin_url( 'admin-post.php' ) ),
			'tf_export'
		);
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">Form Submissions</h1>
			<a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action">Export CSV</a>
			<hr class="wp-header-end">
			<?php if ( isset( $_GET['deleted'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Submission(s) deleted.</p></div>
			<?php endif; ?>
			<form method="get">
				<input type="hidden" name="page" value="tf-submissions">
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	/** Render a key => value table; skips empty values unless $keep_empty. */
	protected static function kv_table( $rows, $keep_empty = false ) {
		if ( empty( $rows ) ) { echo '<p><em>—</em></p>'; return; }
		echo '<table class="widefat striped" style="max-width:760px"><tbody>';
		foreach ( $rows as $k => $v ) {
			if ( ! $keep_empty && ( '' === $v || null === $v ) ) { continue; }
			if ( is_array( $v ) ) { $v = self::flatten_value( $v ); }
			echo '<tr><th style="width:200px">' . esc_html( $k ) . '</th><td>' . esc_html( (string) $v ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/* ------------------------------------------------------------- Detail view */
	public static function detail_screen( $id ) {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) ); // phpcs:ignore
		$back = self::page_url();
		echo '<div class="wrap">';
		if ( ! $r ) {
			echo '<h1>Submission not found</h1><a href="' . esc_url( $back ) . '" class="button">&larr; Back to submissions</a></div>';
			return;
		}
		$data    = json_decode( $r->data, true );
		$meta    = json_decode( $r->meta, true );
		$device  = ( is_array( $meta ) && isset( $meta['device'] ) ) ? $meta['device'] : array();
		$smeta   = ( is_array( $meta ) && isset( $meta['submission'] ) ) ? $meta['submission'] : array();
		$cookies = ( is_array( $meta ) && isset( $meta['cookies'] ) ) ? $meta['cookies'] : array();
		$session = ( is_array( $meta ) && isset( $meta['session'] ) ) ? $meta['session'] : array();

		$del = wp_nonce_url( add_query_arg( array( 'action' => 'tf_delete', 'id' => $r->id ), admin_url( 'admin-post.php' ) ), 'tf_delete_' . $r->id );
		?>
		<h1 class="wp-heading-inline">Submission #<?php echo (int) $r->id; ?></h1>
		<a href="<?php echo esc_url( $back ); ?>" class="page-title-action">&larr; Back to submissions</a>
		<a href="<?php echo esc_url( $del ); ?>" class="page-title-action" style="color:#b32d2e" onclick="return confirm('Delete this submission?')">Delete</a>
		<hr class="wp-header-end">

		<h2>Form data</h2>
		<?php self::kv_table( is_array( $data ) ? $data : array(), true ); ?>

		<h2>Form</h2>
		<?php
		$edit = get_edit_post_link( $r->form_post_id );
		self::kv_table( array(
			'Name'    => $r->form_name . ( $edit ? '' : '' ),
			'Form ID' => $r->form_id,
			'Intent'  => $r->intent,
			'WP ID'   => $r->form_post_id,
		) );
		if ( $edit ) { echo '<p><a href="' . esc_url( $edit ) . '">Edit this form ↗</a></p>'; }
		?>

		<h2>Submission</h2>
		<?php
		$is_partial = isset( $r->status ) && 'completed' !== $r->status;
		self::kv_table( array(
			'Status'     => ( $is_partial ? 'Partial (abandoned)' : 'Completed' ) . ( ! empty( $r->last_step ) ? ' — reached step ' . (int) $r->last_step : '' ),
			'Date'       => $r->created_at,
			'Updated'    => isset( $r->updated_at ) ? $r->updated_at : '',
			'Page URL'   => isset( $smeta['page_url'] ) ? $smeta['page_url'] : $r->page_url,
			'Page title' => isset( $smeta['page_title'] ) ? $smeta['page_title'] : '',
			'Referrer'   => isset( $smeta['page_ref'] ) ? $smeta['page_ref'] : '',
		) ); ?>

		<h2>Device</h2>
		<?php self::kv_table( array(
			'Category'     => isset( $device['category'] ) ? $device['category'] : '',
			'Country'      => isset( $device['ip_country'] ) ? trim( $device['ip_country'] . ' (' . ( isset( $device['ip_country_code'] ) ? $device['ip_country_code'] : '' ) . ')', ' ()' ) : '',
			'OS'           => isset( $device['os'] ) ? $device['os'] : '',
			'Browser'      => isset( $device['browser'] ) ? $device['browser'] : '',
			'Platform'     => isset( $device['platform'] ) ? $device['platform'] : '',
			'Language'     => isset( $device['language'] ) ? $device['language'] : '',
			'IP'           => $r->user_ip,
			'User agent'   => $r->user_agent,
		) ); ?>

		<?php if ( ! empty( $session ) ) : ?>
		<h2>Session</h2>
		<?php self::kv_table( array(
			'Started'     => isset( $session['timestamp'] ) ? $session['timestamp'] : '',
			'Landing URL' => isset( $session['url'] ) ? $session['url'] : '',
			'Referrer'    => isset( $session['ref'] ) ? $session['ref'] : '',
		) ); ?>
		<?php endif; ?>

		<h2>Cookies <span style="color:#999;font-weight:400">(<?php echo is_array( $cookies ) ? count( $cookies ) : 0; ?>)</span></h2>
		<?php self::kv_table( is_array( $cookies ) ? $cookies : array(), true ); ?>
		</div>
		<?php
	}
}
