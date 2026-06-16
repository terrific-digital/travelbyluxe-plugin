<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Front-end rendering: [terrific_form id="POST_ID"] shortcode + Gutenberg-friendly.
 */
class TF_Render {

	protected static $enqueued = false;
	protected static $instances = 0;

	public static function init() {
		add_shortcode( 'terrific_form', array( __CLASS__, 'shortcode' ) );
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
		) );
	}

	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'terrific_form' );
		$post_id = (int) $atts['id'];
		if ( ! $post_id || get_post_type( $post_id ) !== TF_CPT ) {
			return current_user_can( 'edit_posts' ) ? '<!-- Terrific Forms: invalid form id -->' : '';
		}
		$def = tf_get_form( $post_id );
		tf_ensure_form_id( $post_id );
		$def['form_id'] = get_post_meta( $post_id, '_tf_form_id', true );

		self::$enqueued = true;
		wp_enqueue_style( 'tf-form' );
		wp_enqueue_script( 'tf-form' );
		if ( tf_recaptcha_enabled() ) { wp_enqueue_script( 'tf-recaptcha' ); }
		self::$instances++;

		return self::markup( $def, $post_id );
	}

	protected static function markup( $def, $post_id, $preview = false ) {
		$is_multi     = ( 'multistep' === $def['type'] && count( $def['steps'] ) > 1 );
		$total        = count( $def['steps'] );
		$uid          = 'tf-' . $post_id . '-' . self::$instances;
		$intent       = $preview ? '' : tf_form_intent_param( $post_id );
		$step_webhook = ! $preview && $is_multi && tf_form_has_step_webhook( $post_id );

		ob_start();
		// Front-end quick-edit affordance for logged-in editors only.
		if ( ! $preview && ! is_admin() && $post_id && current_user_can( 'edit_post', $post_id ) ) {
			$edit_url = get_edit_post_link( $post_id );
			if ( $edit_url ) {
				// The Terrific Digital "t" brand mark (colour mosaic) so editors recognise it as a Terrific Form.
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
		<form class="tf-form<?php echo $is_multi ? ' tf-multistep' : ''; ?><?php echo $preview ? ' tf-preview' : ''; ?>"
			<?php if ( $preview ) : ?>onsubmit="return false"<?php endif; ?>
			id="<?php echo esc_attr( $uid ); ?>"
			data-form-id="<?php echo esc_attr( $def['form_id'] ); ?>"
			data-form-name="<?php echo esc_attr( $def['form_name'] ); ?>"
			<?php if ( $intent ) : ?>data-form-intent="<?php echo esc_attr( $intent ); ?>"<?php endif; ?>
			<?php if ( $step_webhook ) : ?>data-step-webhook="1"<?php endif; ?>
			data-post-id="<?php echo (int) $post_id; ?>"
			data-multistep="<?php echo $is_multi ? '1' : '0'; ?>"
			data-total-steps="<?php echo (int) $total; ?>"
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
						<h3 class="tf-step-title"><?php echo esc_html( $step['title'] ); ?></h3>
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

			<?php // Honeypot: hidden from people; bots that fill it get their submission silently dropped. ?>
			<div class="tf-hp" aria-hidden="true">
				<label>Email<input type="text" name="tf_hp" tabindex="-1" autocomplete="off" value=""></label>
			</div>

			<div class="tf-thankyou" hidden>
				<div class="tf-check">&#10003;</div>
				<p><?php echo esc_html( $def['success_message'] ); ?></p>
			</div>
		</form>
		<?php
		return ob_get_clean();
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
				$opts[] = array( 'label' => isset( $o['label'] ) ? $o['label'] : '', 'value' => isset( $o['value'] ) ? $o['value'] : '' );
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

		ob_start();
		echo '<div class="tf-field tf-field-' . esc_attr( $type ) . ' tf-w-' . esc_attr( $width ) . '">';

		if ( 'hidden' === $type ) {
			echo '<input type="hidden" ' . $attrs . '>'; // phpcs:ignore
			echo '</div>';
			return ob_get_clean();
		}

		if ( $label && $show_label && ! in_array( $type, array( 'heading', 'paragraph', 'separator', 'custom_html' ), true ) && ! $slider_inline ) {
			echo '<label class="tf-label" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . ( $req ? ' <span class="tf-req">*</span>' : '' ) . '</label>';
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
					. ' data-prefix="' . esc_attr( $pfx ) . '" data-suffix="' . esc_attr( $sfx ) . '">';
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
				echo '<textarea class="tf-input" ' . $attrs . $extra . ' placeholder="' . esc_attr( $ph ) . '" rows="4"></textarea>'; // phpcs:ignore
				break;
			case 'tel':
				$with_cc = ! isset( $f['country_code'] ) || ! empty( $f['country_code'] ); // default on
				if ( $with_cc ) {
					echo '<div class="tf-phone" data-tf-phone>';
					echo '<select class="tf-phone-cc" aria-label="Country dial code">';
					foreach ( tf_countries() as $c ) {
						echo '<option value="' . esc_attr( $c[1] ) . '" data-cc="' . esc_attr( $c[0] ) . '">' . tf_flag( $c[0] ) . ' ' . esc_html( $c[1] ) . '</option>';
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
				echo '<div class="tf-options" data-key="' . esc_attr( $key ) . '"' . ( $req ? ' data-required="1"' : '' ) . '>';
				foreach ( $opts as $oi => $opt ) {
					$oid = $id . '-' . $oi;
					echo '<label class="tf-opt"><input type="' . esc_attr( $input_type ) . '" name="' . esc_attr( $key ) . ( 'checkbox' === $type ? '[]' : '' ) . '" id="' . esc_attr( $oid ) . '" value="' . esc_attr( $opt['value'] ) . '"> <span>' . esc_html( '' !== $opt['label'] ? $opt['label'] : $opt['value'] ) . '</span></label>';
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
