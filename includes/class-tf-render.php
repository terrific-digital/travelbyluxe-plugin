<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Front-end rendering: [tbl_form id="POST_ID"] shortcode (alias [terrific_form]) + Gutenberg-friendly.
 */
class TF_Render {

	protected static $enqueued = false;
	protected static $instances = 0;
	/** Countries preset by the shortcode (country="…" / countries="…") for the form being rendered. */
	protected static $preset = array();
	/** Extra wrapper classes from the shortcode's class="…". */
	protected static $extra_class = '';

	public static function init() {
		add_shortcode( 'tbl_form', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'terrific_form', array( __CLASS__, 'shortcode' ) ); // alias: content written for the Terrific Digital plugin keeps working
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_action( 'wp_ajax_tf_preview', array( __CLASS__, 'ajax_preview' ) ); // admin-only live preview
	}

	/**
	 * Render a non-interactive preview of a (possibly unsaved) definition for the
	 * builder. Reuses the real renderer so it's WYSIWYG-accurate.
	 */
	public static function preview_markup( $def ) {
		self::$instances++;
		return self::markup( $def, 0, true );
	}

	/** AJAX: build a form definition from the builder's posted JSON and return preview HTML. */
	public static function ajax_preview() {
		check_ajax_referer( 'tf_preview', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) { wp_send_json_error( array( 'message' => 'Not allowed' ), 403 ); }

		$decoded = isset( $_POST['definition'] ) ? json_decode( wp_unslash( $_POST['definition'] ), true ) : array();
		$def     = TF_Builder::sanitize_def( is_array( $decoded ) ? $decoded : array() );
		$def['form_id']         = 'preview';
		$def['form_name']       = 'Preview';
		$def['post_id']         = 0;
		$def['submit_text']     = isset( $_POST['submit_text'] ) ? sanitize_text_field( wp_unslash( $_POST['submit_text'] ) ) : 'Submit';
		$def['success_message'] = '';

		wp_send_json_success( array( 'html' => self::preview_markup( $def ) ) );
	}

	/**
	 * Register the form's style/script handles (idempotent). Called from both
	 * wp_enqueue_scripts and the block's init.
	 */
	public static function register_handles() {
		if ( ! wp_style_is( 'tf-form', 'registered' ) ) {
			wp_register_style( 'tf-form', TF_URL . 'assets/form.css', array(), TF_VERSION );
		}
		if ( ! wp_script_is( 'tf-form', 'registered' ) ) {
			wp_register_script( 'tf-form', TF_URL . 'assets/form.js', array(), TF_VERSION, true );
		}
		// Travel by Luxe: the petrol/gold theme (scoped to the tbl-lux wrapper class) and the
		// behaviour of the countries / destinations / budget / consent field types.
		if ( ! wp_style_is( 'tf-tbl-theme', 'registered' ) ) {
			wp_register_style( 'tf-tbl-theme', TF_URL . 'assets/tbl-theme.css', array( 'tf-form' ), TF_VERSION );
		}
		if ( ! wp_script_is( 'tf-tbl-fields', 'registered' ) ) {
			wp_register_script( 'tf-tbl-fields', TF_URL . 'assets/tbl-fields.js', array( 'tf-form' ), TF_VERSION, true );
		}
		// Google reCAPTCHA v3 API (loaded only when a form actually renders, below).
		if ( ! wp_script_is( 'tf-recaptcha', 'registered' ) && tf_recaptcha_enabled() ) {
			wp_register_script( 'tf-recaptcha', 'https://www.google.com/recaptcha/api.js?render=' . rawurlencode( tf_recaptcha_site_key() ), array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Google CDN, version-less
		}
	}

	public static function register_assets() {
		self::register_handles();
		wp_localize_script( 'tf-form', 'TF_FORM', array(
			'ajax'      => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'tf_submit' ),
			'upd'       => TD_Upd::config(), // User-Provided Data capture (front-end identity object)
			'recaptcha' => tf_recaptcha_enabled() ? array( 'site_key' => tf_recaptcha_site_key() ) : null,
			// Front-end strings — translatable via the 'terrific_forms/i18n' filter or WP's
			// i18n (so Hebrew/other LPs don't have to patch text with a MutationObserver).
			'i18n'      => apply_filters( 'terrific_forms/i18n', array(
				'submit_error' => __( 'Something went wrong. Please try again.', 'travelbyluxe' ),
			) ),
		) );
		wp_localize_script( 'tf-tbl-fields', 'TF_TBL', array(
			'destinations' => tbl_destinations(),
			'openPills'    => tbl_destinations_open_pills(),
			'i18n'         => apply_filters( 'travelbyluxe/fields_i18n', array(
				'yourDestination' => __( 'your destination', 'travelbyluxe' ),
				'selectCountries' => __( 'Select one or more countries', 'travelbyluxe' ),
				'oneSelected'     => __( '1 country selected', 'travelbyluxe' ),
				'nSelected'       => __( 'countries selected', 'travelbyluxe' ),
				'budgetNote'      => __( 'Total trip investment based on', 'travelbyluxe' ),
				'over'            => __( 'over', 'travelbyluxe' ),
				'minimum'         => __( 'Minimum', 'travelbyluxe' ),
			) ),
		) );
	}

	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0, 'country' => '', 'countries' => '', 'class' => '' ), $atts, 'tbl_form' );
		$post_id = (int) $atts['id'];
		// country="Italy" / countries="Italy,France": pre-tick the page's own countries in a
		// `countries` field, render the `destinations` pills and {country} templates for them,
		// and let the budget floor see them. The guest can still change the selection.
		self::$preset      = tbl_valid_countries( '' !== $atts['countries'] ? $atts['countries'] : $atts['country'] );
		self::$extra_class = implode( ' ', array_filter( array_map( 'sanitize_html_class', preg_split( '/\s+/', (string) $atts['class'] ) ) ) );
		if ( ! $post_id || get_post_type( $post_id ) !== TF_CPT ) {
			return current_user_can( 'edit_posts' ) ? '<!-- TBL Forms: invalid form id -->' : '';
		}
		$def = tf_get_form( $post_id );
		tf_ensure_form_id( $post_id );
		$def['form_id'] = TF_Forms::get_form_id( $post_id );

		self::$enqueued = true;
		wp_enqueue_style( 'tf-form' );
		wp_enqueue_script( 'tf-form' );
		wp_enqueue_style( 'tf-tbl-theme' );
		wp_enqueue_script( 'tf-tbl-fields' );
		if ( tf_recaptcha_enabled() ) { wp_enqueue_script( 'tf-recaptcha' ); }
		// Ship the free-domains list to JS once per request, only when a form here blocks them.
		static $free_localized = false;
		if ( ! $free_localized && self::def_blocks_free( $def ) ) {
			$free_localized = true;
			wp_localize_script( 'tf-form', 'TF_FREE_DOMAINS', array_values( tf_free_email_domains() ) );
		}
		self::$instances++;

		$html = self::markup( $def, $post_id );
		self::$preset      = array();
		self::$extra_class = '';
		return $html;
	}

	/** Does this form definition have an email field set to block free domains? */
	protected static function def_blocks_free( $def ) {
		if ( empty( $def['steps'] ) ) { return false; }
		foreach ( $def['steps'] as $step ) {
			foreach ( ( isset( $step['fields'] ) ? $step['fields'] : array() ) as $f ) {
				if ( ( isset( $f['type'] ) ? $f['type'] : '' ) === 'email' && ! empty( $f['block_free'] ) ) { return true; }
			}
		}
		return false;
	}

	protected static function markup( $def, $post_id, $preview = false ) {
		$is_multi     = ( 'multistep' === $def['type'] && count( $def['steps'] ) > 1 );
		$total        = count( $def['steps'] );
		// Which step fires the form_submission event? Defaults to the last step
		// (preserves the historical behaviour). Clamp to a valid step index.
		$submit_step  = isset( $def['submission_step'] ) ? (int) $def['submission_step'] : $total;
		$submit_step  = max( 1, min( $total, $submit_step ) );
		$uid          = 'tf-' . $post_id . '-' . self::$instances;
		$intent       = $preview ? '' : tf_form_intent_param( $post_id );
		$step_webhook = ! $preview && $is_multi && tf_form_has_step_webhook( $post_id );
		$dl_events    = $preview ? array() : tf_form_datalayer_events( $post_id );
		// Per-form styling (settings fields): a wrapper class + scoped Custom CSS.
		$wrapper_class = trim( ( ( $post_id && ! $preview ) ? TF_Forms::get_wrapper_class( $post_id ) : '' ) . ' ' . self::$extra_class );
		$handoff       = ! $preview && $post_id && tf_form_has_handoff( $post_id ); // "Thank-you summary" action
		$preset        = self::$preset;
		$custom_css    = ( $post_id && ! $preview ) ? TF_Forms::get_custom_css( $post_id ) : '';

		ob_start();
		if ( '' !== trim( $custom_css ) ) {
			// '<' was stripped on save; strip again as a belt-and-braces guard.
			echo '<style id="' . esc_attr( $uid ) . '-css">' . str_replace( '<', '', $custom_css ) . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		// Front-end quick-edit affordance for logged-in editors only.
		if ( ! $preview && ! is_admin() && $post_id && current_user_can( 'edit_post', $post_id ) ) {
			$edit_url = get_edit_post_link( $post_id );
			if ( $edit_url ) {
				// The Travel by Luxe "t" brand mark (colour mosaic) so editors recognise it as a TBL Form.
				$tmark = '<svg class="tf-edit-ico" width="11" height="15" viewBox="0 0 24.33 33.95" aria-hidden="true">'
					. '<rect x="8.109" y="0.758" width="8.109" height="8.109" fill="#7249B8"/>'
					. '<rect x="0" y="8.867" width="8.109" height="8.109" fill="#FE3F4F"/>'
					. '<rect x="8.109" y="8.867" width="8.109" height="8.109" fill="#FD8602"/>'
					. '<rect x="16.217" y="8.867" width="8.109" height="8.109" fill="#C0DD32"/>'
					. '<rect x="8.109" y="16.975" width="8.109" height="8.109" fill="#3DD2CB"/>'
					. '<rect x="8.109" y="25.084" width="8.109" height="8.109" fill="#363C9F"/>'
					. '<rect x="16.217" y="25.084" width="8.109" height="8.109" fill="#F5417F"/>'
					. '</svg>';
				echo '<div class="tf-admin-edit"><a class="tf-edit-link" href="' . esc_url( $edit_url ) . '" target="_blank" rel="noopener">' . $tmark . '<span>Edit form</span></a></div>'; // phpcs:ignore
			}
		}
		?>
		<form class="tf-form<?php echo $is_multi ? ' tf-multistep' : ''; ?><?php echo $preview ? ' tf-preview' : ''; ?><?php echo $wrapper_class ? ' ' . esc_attr( $wrapper_class ) : ''; ?>"
			<?php if ( $preview ) : ?>onsubmit="return false"<?php endif; ?>
			id="<?php echo esc_attr( $uid ); ?>"
			data-form-id="<?php echo esc_attr( $def['form_id'] ); ?>"
			data-form-name="<?php echo esc_attr( $def['form_name'] ); ?>"
			<?php if ( $intent ) : ?>data-form-intent="<?php echo esc_attr( $intent ); ?>"<?php endif; ?>
			<?php if ( $step_webhook ) : ?>data-step-webhook="1"<?php endif; ?>
			<?php if ( ! empty( $dl_events ) ) : ?>data-dl-events="<?php echo esc_attr( wp_json_encode( $dl_events ) ); ?>"<?php endif; ?>
			<?php if ( $handoff ) : ?>data-handoff="1"<?php endif; ?>
			<?php if ( $preset ) : ?>data-preset-countries="<?php echo esc_attr( implode( ',', $preset ) ); ?>"<?php endif; ?>
			data-post-id="<?php echo (int) $post_id; ?>"
			data-multistep="<?php echo $is_multi ? '1' : '0'; ?>"
			data-total-steps="<?php echo (int) $total; ?>"
			<?php if ( $is_multi ) : ?>data-submission-step="<?php echo (int) $submit_step; ?>"<?php endif; ?>
			novalidate>

			<?php if ( $is_multi ) : ?>
			<div class="tf-progress" aria-hidden="true">
				<div class="tf-progress-meta">
					<span class="tf-progress-step">Step 1 of <?php echo (int) $total; ?></span>
					<span class="tf-progress-pct"><?php echo (int) round( 100 / $total ); ?>%</span>
				</div>
				<div class="tf-progress-bar"><span class="tf-progress-fill" style="width:<?php echo (int) round( 100 / $total ); ?>%"></span></div>
			</div>
			<?php endif; ?>

			<?php foreach ( $def['steps'] as $i => $step ) :
				$n = $i + 1; ?>
				<div class="tf-step<?php echo 0 === $i ? ' is-active' : ''; ?>" data-step="<?php echo (int) $n; ?>">
					<?php if ( ! empty( $step['title'] ) ) : ?>
						<h3 class="tf-step-title"<?php echo false !== strpos( $step['title'], '{country}' ) ? ' data-template="' . esc_attr( $step['title'] ) . '"' : ''; ?>><?php echo esc_html( tbl_template( $step['title'], $preset ) ); ?></h3>
					<?php endif; ?>
					<div class="tf-fields">
						<?php foreach ( $step['fields'] as $field ) {
							echo self::field( $field ); // phpcs:ignore -- escaped within.
						} ?>
					</div>

					<div class="tf-actions">
						<?php if ( $is_multi && $n > 1 ) : ?>
							<button type="button" class="tf-btn tf-back" data-step="<?php echo (int) $n; ?>">&larr; Back</button>
						<?php endif; ?>
						<?php if ( $is_multi && $n < $total ) :
							$next_label = ( isset( $step['next_text'] ) && '' !== trim( $step['next_text'] ) ) ? $step['next_text'] : 'Next →'; ?>
							<button type="button" class="tf-btn tf-next" data-step="<?php echo (int) $n; ?>"><?php echo esc_html( $next_label ); ?></button>
						<?php else : ?>
							<button type="submit" class="tf-btn tf-submit"><?php echo esc_html( $def['submit_text'] ); ?></button>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>

			<?php // Honeypot: hidden from people; a filled value flags the submission as spam.
			      // display:none (not just off-screen) + a non-semantic text field with a
			      // meaningless name + autocomplete="one-time-code" so browser contact-autofill
			      // (Chrome/Safari ignore autocomplete="off") never fills it and traps real leads. ?>
			<div class="tf-hp" aria-hidden="true" style="display:none !important">
				<label>Leave this field empty<input type="text" name="tf_extra_field" tabindex="-1" autocomplete="one-time-code" value=""></label>
			</div>

			<div class="tf-thankyou" hidden>
				<div class="tf-check">&#10003;</div>
				<p><?php echo esc_html( $def['success_message'] ); ?></p>
			</div>
		</form>
		<?php
		return ob_get_clean();
	}

	/** Region pills for the given countries plus the two "open" answers — mirrors pillsHtml() in tbl-fields.js. */
	public static function destination_pills_html( $names, $key ) {
		$data  = tbl_destinations();
		$multi = count( $names ) > 1;
		$pill  = function ( $value, $text, $group, $open ) use ( $key ) {
			$attrs = '';
			if ( $group ) { $attrs .= ' data-group="' . esc_attr( $group ) . '"'; }
			if ( ! empty( $open['exclusive'] ) ) { $attrs .= ' data-exclusive="1"'; }
			if ( ! empty( $open['reveals'] ) ) { $attrs .= ' data-reveals="1"'; }
			return '<label class="tf-opt' . ( $open ? ' tf-opt--open' : '' ) . '"' . $attrs . '><input type="checkbox" name="' . esc_attr( $key ) . '[]" value="' . esc_attr( $value ) . '"> <span class="tf-opt-text">' . esc_html( $text ) . '</span></label>';
		};
		$open_pills = function ( $group ) use ( $pill ) {
			$out = '';
			foreach ( tbl_destinations_open_pills() as $p ) {
				$out .= $pill( $group ? $group . ': ' . $p['value'] : $p['value'], $p['value'], $group, $p );
			}
			return $out;
		};
		$html = '';
		foreach ( $names as $name ) {
			if ( empty( $data['countries'][ $name ] ) ) { continue; }
			if ( $multi ) { $html .= '<div class="tf-dest-group">' . esc_html( $name ) . '</div>'; }
			foreach ( $data['countries'][ $name ]['regions'] as $region ) {
				$html .= $pill( $region, $region, $multi ? $name : '', null );
			}
			if ( $multi ) { $html .= $open_pills( $name ); }
		}
		if ( ! $multi ) { $html .= $open_pills( '' ); }
		return $html;
	}

	/**
	 * Render a single field by type. All output escaped.
	 */
	protected static function field( $f ) {
		$type  = $f['type'];
		if ( 'daterange' === $type ) { return ''; } // field type removed; skip any leftover instances
		$key   = isset( $f['key'] ) ? $f['key'] : '';
		$label = isset( $f['label'] ) ? $f['label'] : '';
		$req   = ! empty( $f['required'] );
		$ph    = isset( $f['placeholder'] ) ? $f['placeholder'] : '';
		$id    = 'tff-' . $f['id'];
		$width      = isset( $f['width'] ) && in_array( $f['width'], array( 'full', 'half', 'third' ), true ) ? $f['width'] : 'full';
		$show_label = ! isset( $f['show_label'] ) || ! empty( $f['show_label'] );
		// Sliders show the value in a bubble by default; opt-in to show it inline in the label.
		$slider_inline = ( 'slider' === $type && ! empty( $f['value_inline'] ) );

		// Normalize options to [ {label, value} ].
		$opts = array();
		foreach ( (array) ( isset( $f['options'] ) ? $f['options'] : array() ) as $o ) {
			if ( is_array( $o ) ) {
				$opts[] = array(
					'label'       => isset( $o['label'] ) ? $o['label'] : '',
					'value'       => isset( $o['value'] ) ? $o['value'] : '',
					'description' => isset( $o['description'] ) ? $o['description'] : '',
					'exclusive'   => ! empty( $o['exclusive'] ),
					'reveals'     => ! empty( $o['reveals'] ),
				);
			} else {
				$opts[] = array( 'label' => $o, 'value' => $o );
			}
		}

		$attrs = 'id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" data-key="' . esc_attr( $key ) . '"' . ( $req ? ' data-required="1"' : '' );
		if ( $label && ! $show_label ) { $attrs .= ' aria-label="' . esc_attr( $label ) . '"'; }

		// Constraint attributes (native + data-* for JS validation).
		$extra = '';
		if ( 'number' === $type ) {
			if ( isset( $f['min'] ) && '' !== $f['min'] ) { $extra .= ' min="' . esc_attr( $f['min'] ) . '" data-min="' . esc_attr( $f['min'] ) . '"'; }
			if ( isset( $f['max'] ) && '' !== $f['max'] ) { $extra .= ' max="' . esc_attr( $f['max'] ) . '" data-max="' . esc_attr( $f['max'] ) . '"'; }
		}
		if ( 'date' === $type ) {
			// Bounds are days-from-today; the actual min/max dates are computed client-side (cache-safe).
			if ( isset( $f['min_from_today'] ) && '' !== $f['min_from_today'] ) { $extra .= ' data-min-from-today="' . esc_attr( (int) $f['min_from_today'] ) . '"'; }
			if ( isset( $f['max_from_today'] ) && '' !== $f['max_from_today'] ) { $extra .= ' data-max-from-today="' . esc_attr( (int) $f['max_from_today'] ) . '"'; }
		}
		if ( in_array( $type, array( 'text', 'email', 'tel', 'textarea' ), true ) ) {
			if ( ! empty( $f['minlength'] ) ) { $extra .= ' minlength="' . esc_attr( $f['minlength'] ) . '" data-minlength="' . esc_attr( $f['minlength'] ) . '"'; }
			if ( ! empty( $f['maxlength'] ) ) { $extra .= ' maxlength="' . esc_attr( $f['maxlength'] ) . '" data-maxlength="' . esc_attr( $f['maxlength'] ) . '"'; }
		}
		if ( 'email' === $type && ! empty( $f['block_free'] ) ) { $extra .= ' data-block-free="1"'; } // require a work email

		// Hidden fields render without the .tf-field wrapper so they don't claim a flex/grid
		// slot in the form layout (the wrapper itself was the source of the empty gap).
		// collect() in form.js keys off [data-key] directly, so no wrapper is needed.
		if ( 'hidden' === $type ) {
			$hidden_default = isset( $f['default'] ) ? (string) $f['default'] : '';
			return '<input type="hidden" ' . $attrs . ' value="' . esc_attr( $hidden_default ) . '">';
		}

		ob_start();
		echo '<div class="tf-field tf-field-' . esc_attr( $type ) . ' tf-w-' . esc_attr( $width ) . '">';

		if ( $label && $show_label && ! in_array( $type, array( 'heading', 'paragraph', 'separator', 'custom_html', 'consent', 'budget' ), true ) && ! $slider_inline ) {
			// "{country}" in a label follows the chosen countries (tbl-fields.js rewrites the span).
			$tpl = false !== strpos( $label, '{country}' ) ? ' data-template="' . esc_attr( $label ) . '"' : '';
			echo '<label class="tf-label" for="' . esc_attr( $id ) . '"><span' . $tpl . '>' . esc_html( tbl_template( $label, self::$preset ) ) . '</span>' . ( $req ? ' <span class="tf-req">*</span>' : '' ) . '</label>';
		}

		switch ( $type ) {
			case 'heading':
				echo '<h4 class="tf-heading">' . esc_html( $label ) . '</h4>';
				break;
			case 'paragraph':
				echo '<p class="tf-paragraph">' . nl2br( esc_html( $label ) ) . '</p>';
				break;
			case 'separator':
				echo '<hr class="tf-separator">';
				break;
			case 'custom_html':
				// Stored sanitized at save time (raw only for unfiltered_html authors).
				echo isset( $f['html'] ) ? $f['html'] : ''; // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			case 'slider':
				$smin  = ( isset( $f['min'] ) && '' !== $f['min'] ) ? $f['min'] : 0;
				$smax  = ( isset( $f['max'] ) && '' !== $f['max'] ) ? $f['max'] : 100;
				$sstep = ( isset( $f['step'] ) && '' !== $f['step'] ) ? $f['step'] : 1;
				$sval  = ( isset( $f['default'] ) && '' !== $f['default'] ) ? $f['default'] : $smin;
				$pfx   = isset( $f['prefix'] ) ? $f['prefix'] : '';
				$sfx   = isset( $f['suffix'] ) ? $f['suffix'] : '';
				$fmt   = function ( $n ) use ( $pfx, $sfx ) { return $pfx . number_format_i18n( (float) $n ) . $sfx; };
				// Opt-in: value inline in the label (the generic label above was skipped for this case).
				if ( $slider_inline && $label && $show_label ) {
					echo '<label class="tf-label" for="' . esc_attr( $id ) . '">' . esc_html( $label )
						. ( $req ? ' <span class="tf-req">*</span>' : '' )
						. ': <span class="tf-range-val">' . esc_html( $fmt( $sval ) ) . '</span></label>';
				}
				echo '<div class="tf-slider">';
				echo '<input type="range" class="tf-input tf-range" ' . $attrs // phpcs:ignore
					. ' min="' . esc_attr( $smin ) . '" max="' . esc_attr( $smax ) . '" step="' . esc_attr( $sstep ) . '" value="' . esc_attr( $sval ) . '"'
					. ' data-prefix="' . esc_attr( $pfx ) . '" data-suffix="' . esc_attr( $sfx ) . '"'
					. ( ! empty( $f['max_label'] ) ? ' data-max-label="' . esc_attr( $f['max_label'] ) . '"' : '' ) . '>';
				// Default: value shown in a bubble beside the slider.
				if ( ! $slider_inline ) {
					echo '<output class="tf-range-val">' . esc_html( $fmt( $sval ) ) . '</output>';
				}
				echo '</div>';
				if ( ! empty( $f['show_scale'] ) ) {
					echo '<div class="tf-range-scale"><span>' . esc_html( $fmt( $smin ) ) . '</span><span>' . esc_html( $fmt( $smax ) ) . '</span></div>';
				}
				break;
			case 'textarea':
				if ( ! empty( $f['collapsed'] ) ) {
					// Opens on demand ("Click here to write something for us") — tbl-fields.js.
					echo '<button type="button" class="tf-collapsed-trigger" aria-expanded="false" aria-controls="' . esc_attr( $id ) . '">'
						. '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 20h9" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round"/></svg>'
						. '<span>' . esc_html( ! empty( $f['trigger_text'] ) ? $f['trigger_text'] : 'Add a note' ) . '</span></button>';
					echo '<textarea class="tf-input tf-collapsed" ' . $attrs . $extra . ' placeholder="' . esc_attr( $ph ) . '" rows="2" hidden></textarea>'; // phpcs:ignore
				} else {
					echo '<textarea class="tf-input" ' . $attrs . $extra . ' placeholder="' . esc_attr( $ph ) . '" rows="4"></textarea>'; // phpcs:ignore
				}
				break;
			case 'tel':
				$with_cc   = ! isset( $f['country_code'] ) || ! empty( $f['country_code'] ); // default on
				$show_name = ! empty( $f['cc_show_name'] ); // include the country name (e.g. "🇮🇱 Israel +972")
				if ( $with_cc ) {
					echo '<div class="tf-phone" data-tf-phone>';
					echo '<select class="tf-phone-cc' . ( $show_name ? ' tf-phone-cc-named' : '' ) . '" aria-label="Country dial code">';
					$cc_list = ( isset( $f['countries'] ) && 'curated' === $f['countries'] ) ? tbl_curated_phone_countries() : tf_countries();
					foreach ( $cc_list as $c ) {
						$opt_label = tf_flag( $c[0] ) . ' ' . ( $show_name ? esc_html( $c[2] ) . ' ' : '' ) . esc_html( $c[1] );
						echo '<option value="' . esc_attr( $c[1] ) . '" data-cc="' . esc_attr( $c[0] ) . '" data-name="' . esc_attr( $c[2] ) . '">' . $opt_label . '</option>';
					}
					echo '</select>';
					echo '<input type="tel" class="tf-input tf-phone-num" ' . $attrs . $extra . ' placeholder="' . esc_attr( $ph ) . '">'; // phpcs:ignore
					echo '</div>';
				} else {
					echo '<input type="tel" class="tf-input" ' . $attrs . $extra . ' placeholder="' . esc_attr( $ph ) . '">'; // phpcs:ignore
				}
				break;
			case 'select':
				$multiple = ! empty( $f['multiple'] );
				$satt = 'id="' . esc_attr( $id ) . '" data-key="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . ( $multiple ? '[]' : '' ) . '"' . ( $req ? ' data-required="1"' : '' ) . ( $multiple ? ' multiple' : '' );
				if ( $label && ! $show_label ) { $satt .= ' aria-label="' . esc_attr( $label ) . '"'; }
				echo '<select class="tf-input' . ( $multiple ? ' tf-multiselect' : '' ) . '" ' . $satt . '>'; // phpcs:ignore
				if ( ! $multiple ) { echo '<option value="">' . esc_html( $ph ? $ph : 'Select…' ) . '</option>'; }
				foreach ( $opts as $opt ) {
					echo '<option value="' . esc_attr( $opt['value'] ) . '">' . esc_html( '' !== $opt['label'] ? $opt['label'] : $opt['value'] ) . '</option>';
				}
				echo '</select>';
				if ( $multiple ) { echo '<span class="tf-hint">Hold Ctrl/⌘ to select multiple</span>'; }
				break;
			case 'radio':
			case 'checkbox':
				$input_type = 'radio' === $type ? 'radio' : 'checkbox';
				$style      = ( isset( $f['style'] ) && in_array( $f['style'], array( 'list', 'pills', 'cards' ), true ) ) ? $f['style'] : 'list';
				$has_reveal = false;
				echo '<div class="tf-options tf-style-' . esc_attr( $style ) . '" data-key="' . esc_attr( $key ) . '"' . ( $req ? ' data-required="1"' : '' ) . '>';
				foreach ( $opts as $oi => $opt ) {
					$oid   = $id . '-' . $oi;
					$open  = ! empty( $opt['exclusive'] ) || ! empty( $opt['reveals'] );
					$flags = ( ! empty( $opt['exclusive'] ) ? ' data-exclusive="1"' : '' ) . ( ! empty( $opt['reveals'] ) ? ' data-reveals="1"' : '' );
					if ( ! empty( $opt['reveals'] ) ) { $has_reveal = true; }
					$text  = '' !== $opt['label'] ? $opt['label'] : $opt['value'];
					$inner = ( 'cards' === $style )
						? '<span class="tf-card-dot"></span><span class="tf-opt-text"><strong>' . esc_html( $text ) . '</strong>' . ( ! empty( $opt['description'] ) ? ' <span class="tf-opt-desc">— ' . esc_html( $opt['description'] ) . '</span>' : '' ) . '</span>'
						: ' <span class="tf-opt-text">' . esc_html( $text ) . '</span>';
					echo '<label class="tf-opt' . ( $open ? ' tf-opt--open' : '' ) . '"' . $flags . '><input type="' . esc_attr( $input_type ) . '" name="' . esc_attr( $key ) . ( 'checkbox' === $type ? '[]' : '' ) . '" id="' . esc_attr( $oid ) . '" value="' . esc_attr( $opt['value'] ) . '">' . $inner . '</label>'; // phpcs:ignore
				}
				echo '</div>';
				if ( $has_reveal ) {
					// Shown while a "reveals" option is ticked; submitted as <key>_other.
					echo '<input type="text" class="tf-input tf-reveal" id="' . esc_attr( $id ) . '-other" name="' . esc_attr( $key ) . '_other" data-key="' . esc_attr( $key ) . '_other" placeholder="' . esc_attr( $ph ) . '" autocomplete="off" hidden>';
				}
				break;
			case 'consent':
				echo '<label class="tf-consent"><input type="checkbox" class="tf-consent-input" ' . $attrs . ' value="yes"><span>' // phpcs:ignore
					. wp_kses( $label, tbl_consent_allowed_html() ) . ( $req ? ' <span class="tf-req">*</span>' : '' ) . '</span></label>';
				break;
			case 'countries':
				$data   = tbl_destinations();
				$preset = self::$preset;
				$max    = ( isset( $f['max_countries'] ) && '' !== $f['max_countries'] ) ? (int) $f['max_countries'] : 0; // 0 = no limit
				$codes  = array();
				foreach ( $preset as $name ) { $codes[] = $data['countries'][ $name ]['code']; }
				echo '<div class="tf-countries" data-max="' . (int) $max . '">';
				echo '<div class="tf-countries-chips" hidden></div>';
				echo '<div class="tf-countries-control">';
				echo '<button type="button" class="tf-input tf-countries-trigger is-placeholder" aria-haspopup="listbox" aria-expanded="false">' . esc_html( $ph ? $ph : 'Select countries' ) . '</button>';
				echo '<div class="tf-countries-panel" role="listbox" aria-multiselectable="true" hidden>';
				foreach ( $data['order'] as $name ) {
					$on = in_array( $name, $preset, true );
					echo '<label class="tf-countries-option' . ( $on ? ' is-selected' : '' ) . '" role="option" aria-selected="' . ( $on ? 'true' : 'false' ) . '"><input type="checkbox" value="' . esc_attr( $name ) . '"' . ( $on ? ' checked' : '' ) . '> ' . esc_html( $name ) . '</label>';
				}
				echo '</div></div>';
				echo '<div class="tf-countries-hint"></div>';
				echo '<input type="hidden" class="tf-countries-value" ' . $attrs . ' value="' . esc_attr( implode( ', ', $preset ) ) . '">'; // phpcs:ignore
				echo '<input type="hidden" class="tf-countries-codes" id="' . esc_attr( $id ) . '-code" name="' . esc_attr( $key ) . '_code" data-key="' . esc_attr( $key ) . '_code" value="' . esc_attr( implode( ', ', $codes ) ) . '">';
				echo '</div>';
				break;
			case 'destinations':
				$ckey = ! empty( $f['countries_key'] ) ? $f['countries_key'] : 'trip_country';
				echo '<div class="tf-dest" data-key-base="' . esc_attr( $key ) . '" data-countries-key="' . esc_attr( $ckey ) . '" data-rendered-for="' . esc_attr( implode( ',', self::$preset ) ) . '">';
				echo '<div class="tf-options tf-style-pills tf-dest-pills" data-key="' . esc_attr( $key ) . '"' . ( $req ? ' data-required="1"' : '' ) . '>' . self::destination_pills_html( self::$preset, $key ) . '</div>'; // phpcs:ignore
				echo '<input type="text" class="tf-input tf-reveal tf-dest-other" id="' . esc_attr( $id ) . '-other" name="' . esc_attr( $key ) . '_other" data-key="' . esc_attr( $key ) . '_other" placeholder="' . esc_attr( $ph ? $ph : 'Tell us where…' ) . '" autocomplete="off" hidden>';
				echo '</div>';
				break;
			case 'budget':
				$b     = tbl_budget_settings( $f );
				$rates = array( 'adult' => $b['rate_adult'], 'child' => $b['rate_child'], 'adult_day' => $b['rate_adult_day'], 'child_day' => $b['rate_child_day'] );
				$start = 2 * $b['rate_adult']; // 2 adults, a week: the page's first paint before tbl-fields.js recomputes
				$fmt_b = function ( $n ) use ( $b ) { return $b['currency'] . number_format_i18n( (float) $n ); };
				echo '<div class="tf-budget" data-currency="' . esc_attr( $b['currency'] ) . '" data-rates="' . esc_attr( wp_json_encode( $rates ) ) . '" data-country-rates="' . esc_attr( wp_json_encode( tbl_parse_country_rates( $b['country_rates'] ) ) ) . '"'
					. ' data-included-days="' . esc_attr( $b['included_days'] ) . '" data-range-mult="' . esc_attr( $b['range_mult'] ) . '"'
					. ' data-adults-key="' . esc_attr( $b['adults_key'] ) . '" data-children-key="' . esc_attr( $b['children_key'] ) . '" data-days-key="' . esc_attr( $b['days_key'] ) . '" data-country-key="' . esc_attr( $b['country_key'] ) . '">';
				if ( $label && $show_label ) {
					echo '<label class="tf-label" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . ( $req ? ' <span class="tf-req">*</span>' : '' ) . '</label>';
				}
				echo '<div class="tf-budget-note"></div>';
				echo '<div class="tf-slider"><input type="range" class="tf-input tf-range tf-budget-range" ' . $attrs . ' min="' . esc_attr( $start ) . '" max="' . esc_attr( $start * $b['range_mult'] ) . '" step="' . esc_attr( $b['step'] ) . '" value="' . esc_attr( $start ) . '" data-prefix="' . esc_attr( $b['currency'] ) . '" data-suffix=""><output class="tf-range-val">' . esc_html( $fmt_b( $start ) ) . '</output></div>'; // phpcs:ignore
				echo '<div class="tf-range-scale tf-budget-scale"><span>' . esc_html( $b['currency'] . round( $start / 1000 ) . 'k' ) . '</span><span></span><span>' . esc_html( $b['currency'] . round( $start * $b['range_mult'] / 1000 ) . 'k+' ) . '</span></div>';
				echo '<div class="tf-budget-min">' . esc_html( 'Minimum ' . $fmt_b( $start ) ) . '</div>';
				foreach ( array( 'label', 'min', 'at_min' ) as $extra_key ) {
					echo '<input type="hidden" id="' . esc_attr( $id . '-' . $extra_key ) . '" name="' . esc_attr( $key . '_' . $extra_key ) . '" data-key="' . esc_attr( $key . '_' . $extra_key ) . '" value="">';
				}
				echo '</div>';
				break;
			case 'email':
			case 'number':
			case 'date':
			case 'text':
			default:
				$it = in_array( $type, array( 'email', 'number', 'date' ), true ) ? $type : 'text';
				echo '<input type="' . esc_attr( $it ) . '" class="tf-input" ' . $attrs . $extra . ' placeholder="' . esc_attr( $ph ) . '">'; // phpcs:ignore
				break;
		}
		echo '<span class="tf-error" hidden></span>';
		echo '</div>';
		return ob_get_clean();
	}
}
