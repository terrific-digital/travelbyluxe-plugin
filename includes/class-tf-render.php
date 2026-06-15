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
	}

	public static function register_assets() {
		wp_register_style( 'tf-form', TF_URL . 'assets/form.css', array(), TF_VERSION );
		wp_register_script( 'tf-form', TF_URL . 'assets/form.js', array(), TF_VERSION, true );
		wp_localize_script( 'tf-form', 'TF_FORM', array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'tf_submit' ),
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
		self::$instances++;

		return self::markup( $def, $post_id );
	}

	protected static function markup( $def, $post_id ) {
		$is_multi     = ( 'multistep' === $def['type'] && count( $def['steps'] ) > 1 );
		$total        = count( $def['steps'] );
		$uid          = 'tf-' . $post_id . '-' . self::$instances;
		$intent       = tf_form_intent_param( $post_id );
		$step_webhook = $is_multi && tf_form_has_step_webhook( $post_id );

		ob_start();
		?>
		<form class="tf-form<?php echo $is_multi ? ' tf-multistep' : ''; ?>"
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
						<?php if ( $is_multi && $n < $total ) : ?>
							<button type="button" class="tf-btn tf-next" data-step="<?php echo (int) $n; ?>">Next &rarr;</button>
						<?php else : ?>
							<button type="submit" class="tf-btn tf-submit"><?php echo esc_html( $def['submit_text'] ); ?></button>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>

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
		$key   = $f['key'];
		$label = $f['label'];
		$req   = ! empty( $f['required'] );
		$ph    = isset( $f['placeholder'] ) ? $f['placeholder'] : '';
		$id    = 'tff-' . $f['id'];
		$width      = isset( $f['width'] ) && in_array( $f['width'], array( 'full', 'half', 'third' ), true ) ? $f['width'] : 'full';
		$show_label = ! isset( $f['show_label'] ) || ! empty( $f['show_label'] );

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

		if ( $label && $show_label && ! in_array( $type, array( 'heading' ), true ) ) {
			echo '<label class="tf-label" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . ( $req ? ' <span class="tf-req">*</span>' : '' ) . '</label>';
		}

		switch ( $type ) {
			case 'heading':
				echo '<h4 class="tf-heading">' . esc_html( $label ) . '</h4>';
				break;
			case 'textarea':
				echo '<textarea class="tf-input" ' . $attrs . $extra . ' placeholder="' . esc_attr( $ph ) . '" rows="4"></textarea>'; // phpcs:ignore
				break;
			case 'tel':
				echo '<div class="tf-phone" data-tf-phone>';
				echo '<select class="tf-phone-cc" aria-label="Country dial code">';
				foreach ( tf_countries() as $c ) {
					echo '<option value="' . esc_attr( $c[1] ) . '" data-cc="' . esc_attr( $c[0] ) . '">' . tf_flag( $c[0] ) . ' ' . esc_html( $c[1] ) . '</option>';
				}
				echo '</select>';
				echo '<input type="tel" class="tf-input tf-phone-num" ' . $attrs . $extra . ' placeholder="' . esc_attr( $ph ) . '">'; // phpcs:ignore
				echo '</div>';
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
