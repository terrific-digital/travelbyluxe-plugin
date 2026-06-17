<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Admin builder UI for a Terrific Form (drag-and-drop fields + steps + settings).
 */
class TF_Builder {

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_' . TF_CPT, array( __CLASS__, 'save' ), 20, 1 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || $screen->post_type !== TF_CPT ) { return; }
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) { return; }
		wp_enqueue_style( 'tf-builder', TF_URL . 'assets/builder.css', array(), TF_VERSION );
		wp_enqueue_script( 'tf-sortable', TF_URL . 'assets/sortable.min.js', array(), '1.15.2', true );
		wp_enqueue_script( 'tf-builder', TF_URL . 'assets/builder.js', array( 'tf-sortable' ), TF_VERSION, true );

		wp_enqueue_script( 'tf-actions', TF_URL . 'assets/actions.js', array(), TF_VERSION, true );
		$pages = get_posts( array( 'post_type' => 'page', 'numberposts' => 300, 'post_status' => 'publish', 'orderby' => 'title', 'order' => 'ASC' ) );
		$plist = array();
		foreach ( $pages as $pg ) {
			$plist[] = array( 'id' => (string) $pg->ID, 'title' => get_the_title( $pg ) );
		}
		wp_localize_script( 'tf-actions', 'TF_ACTIONS', array(
			'globalWebhook' => tf_global_webhook(),
			'settingsUrl'   => admin_url( 'admin.php?page=tf-settings' ),
			'pages'         => $plist,
		) );

		// Live preview config (renders the real form via AJAX into an iframe).
		wp_localize_script( 'tf-builder', 'TF_BUILD', array(
			'previewAjax' => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'tf_preview' ),
			'css'         => array(
				TF_URL . 'assets/form.css?ver=' . TF_VERSION,
			),
		) );
	}

	public static function meta_boxes() {
		add_meta_box( 'tf-builder-box', 'Form Builder', array( __CLASS__, 'render_builder' ), TF_CPT, 'normal', 'high' );
		add_meta_box( 'tf-settings-box', 'Form Settings', array( __CLASS__, 'render_settings' ), TF_CPT, 'side', 'default' );
		add_meta_box( 'tf-usage-box', 'Submissions & Usage', array( __CLASS__, 'render_usage' ), TF_CPT, 'side', 'high' );
		add_meta_box( 'tf-actions-box', 'Actions', array( __CLASS__, 'render_actions' ), TF_CPT, 'normal', 'default' );
		// (Live preview is rendered inside the Form Builder box, beside the canvas — see builder.js.)
	}

	public static function render_actions( $post ) {
		$actions = tf_get_actions( $post->ID ); // includes legacy migration for display
		$json    = wp_json_encode( $actions ? $actions : array() );
		?>
		<div id="tf-actions" data-actions="<?php echo esc_attr( $json ); ?>">
			<p class="tf-actions-loading" style="color:#777">Loading actions…</p>
		</div>
		<input type="hidden" name="tf_actions" id="tf_actions" value="<?php echo esc_attr( $json ); ?>">
		<?php
	}

	/** Find every page/post that embeds this form (block, shortcode, or Elementor). */
	public static function find_usages( $form_post_id ) {
		global $wpdb;
		$form_post_id = (int) $form_post_id;
		$sc = '%' . $wpdb->esc_like( '[terrific_form id="' . $form_post_id . '"' ) . '%';
		$bl = '%' . $wpdb->esc_like( '"formId":"' . $form_post_id . '"' ) . '%';

		// Gutenberg block + classic shortcode live in post_content.
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type NOT IN ( 'revision', %s )
			   AND post_status NOT IN ( 'trash', 'auto-draft', 'inherit' )
			   AND ( post_content LIKE %s OR post_content LIKE %s )",
			TF_CPT, $sc, $bl
		) );
		// Shortcode embedded inside Elementor is stored in post meta.
		$meta_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data' AND meta_value LIKE %s",
			$sc
		) );

		$all = array_unique( array_map( 'intval', array_merge( $ids, $meta_ids ) ) );
		$all = array_filter( $all, function ( $id ) {
			return $id && get_post_type( $id ) !== TF_CPT
				&& ! in_array( get_post_status( $id ), array( 'trash', 'auto-draft', 'inherit' ), true );
		} );
		return array_values( $all );
	}

	public static function render_usage( $post ) {
		global $wpdb;
		$count    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}tf_submissions WHERE form_post_id = %d", $post->ID ) );
		$subs_url = add_query_arg( array( 'page' => 'tf-submissions', 'form' => $post->ID ), admin_url( 'admin.php' ) );
		?>
		<p style="margin-top:0"><strong>Submissions</strong></p>
		<a href="<?php echo esc_url( $subs_url ); ?>" class="button button-secondary" style="width:100%;text-align:center;box-sizing:border-box">
			View submissions (<?php echo esc_html( $count ); ?>)
		</a>
		<hr style="margin:14px 0">
		<p><strong>Used on these pages</strong></p>
		<?php
		$usages = self::find_usages( $post->ID );
		if ( empty( $usages ) ) {
			echo '<p style="color:#777">Not embedded anywhere yet. Add the <strong>Terrific Form</strong> block, or the shortcode <code>[terrific_form id="' . (int) $post->ID . '"]</code>.</p>';
			return;
		}
		echo '<ul style="margin:0">';
		foreach ( $usages as $pid ) {
			$title = get_the_title( $pid );
			$title = '' !== $title ? $title : '(no title)';
			$edit  = get_edit_post_link( $pid );
			$view  = get_permalink( $pid );
			$pto   = get_post_type_object( get_post_type( $pid ) );
			echo '<li style="margin-bottom:8px;border-bottom:1px solid #f0f0f1;padding-bottom:8px">';
			if ( $edit ) {
				echo '<a href="' . esc_url( $edit ) . '"><strong>' . esc_html( $title ) . '</strong></a>';
			} else {
				echo '<strong>' . esc_html( $title ) . '</strong>';
			}
			if ( $pto ) {
				echo ' <span style="color:#999;font-size:11px">' . esc_html( $pto->labels->singular_name ) . '</span>';
			}
			$links = array();
			if ( $edit ) { $links[] = '<a href="' . esc_url( $edit ) . '">edit</a>'; }
			if ( $view ) { $links[] = '<a href="' . esc_url( $view ) . '" target="_blank">view ↗</a>'; }
			if ( $links ) {
				echo '<br><span style="font-size:12px">' . implode( ' · ', $links ) . '</span>';
			}
			echo '</li>';
		}
		echo '</ul>';
	}

	public static function render_settings( $post ) {
		$form_id       = get_post_meta( $post->ID, '_tf_form_id', true );
		$def           = tf_get_form( $post->ID );
		$type          = isset( $def['type'] ) ? $def['type'] : 'regular';
		$submit        = $def['submit_text'];
		$success       = $def['success_message'];
		$wrapper_class = get_post_meta( $post->ID, '_tf_wrapper_class', true );
		$custom_css    = get_post_meta( $post->ID, '_tf_custom_css', true );
		?>
		<p><strong>Form ID</strong><br>
			<code><?php echo esc_html( $form_id ?: '(saved on first publish)' ); ?></code></p>
		<p><strong>Shortcode</strong><br>
			<code>[terrific_form id="<?php echo (int) $post->ID; ?>"]</code></p>
		<hr>
		<p>
			<label for="tf_intent"><strong>Intent</strong></label><br>
			<?php $cur_intent = tf_get_intent( $post->ID ); ?>
			<select id="tf_intent" name="tf_intent" class="widefat">
				<?php foreach ( tf_intents() as $key => $info ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $cur_intent, $key ); ?>><?php echo esc_html( $info['label'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<span class="description">Sends <code>form_intent</code> = <code><?php echo esc_html( tf_form_intent_param( $post->ID ) ); ?></code> to the webhook &amp; dataLayer.</span>
		</p>
		<p>
			<label><strong>Form type</strong></label><br>
			<label><input type="radio" name="tf_type" value="regular" <?php checked( $type, 'regular' ); ?>> Regular</label><br>
			<label><input type="radio" name="tf_type" value="multistep" <?php checked( $type, 'multistep' ); ?>> Multi-step</label>
		</p>
		<p>
			<label for="tf_submit_text"><strong>Submit button text</strong></label>
			<input type="text" id="tf_submit_text" name="tf_submit_text" class="widefat" value="<?php echo esc_attr( $submit ); ?>">
		</p>
		<p>
			<label for="tf_success"><strong>Success message</strong></label>
			<textarea id="tf_success" name="tf_success" class="widefat" rows="3"><?php echo esc_textarea( $success ); ?></textarea>
		</p>
		<hr>
		<p>
			<label for="tf_wrapper_class"><strong>Wrapper CSS class</strong></label>
			<input type="text" id="tf_wrapper_class" name="tf_wrapper_class" class="widefat" value="<?php echo esc_attr( $wrapper_class ); ?>" placeholder="e.g. tbl-lux">
			<span class="description">Added to the form's container — use it to scope the Custom CSS below.</span>
		</p>
		<p>
			<label for="tf_custom_css"><strong>Custom CSS</strong></label>
			<textarea id="tf_custom_css" name="tf_custom_css" class="widefat" rows="6" spellcheck="false" style="font-family:Menlo,Consolas,monospace;font-size:12px" placeholder=".tbl-lux .tf-field { … }"><?php echo esc_textarea( $custom_css ); ?></textarea>
			<span class="description">Output in a scoped <code>&lt;style&gt;</code> with this form. Prefix selectors with the wrapper class (or <code>.tf-form</code>) so they only affect this form.</span>
		</p>
		<?php
		wp_nonce_field( 'tf_save', 'tf_nonce' );
	}

	public static function render_builder( $post ) {
		$def  = tf_get_form( $post->ID );
		$json = wp_json_encode( array(
			'type'  => isset( $def['type'] ) ? $def['type'] : 'regular',
			'steps' => isset( $def['steps'] ) ? $def['steps'] : array( array( 'title' => '', 'fields' => array() ) ),
		) );
		?>
		<div id="tf-builder" data-def="<?php echo esc_attr( $json ); ?>">
			<div class="tf-builder-loading">Loading builder…</div>
		</div>
		<input type="hidden" name="tf_definition" id="tf_definition" value="<?php echo esc_attr( $json ); ?>">
		<noscript><p>The form builder requires JavaScript.</p></noscript>
		<?php
	}

	public static function save( $post_id ) {
		if ( ! isset( $_POST['tf_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tf_nonce'] ) ), 'tf_save' ) ) { return; }
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
		if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }

		if ( isset( $_POST['tf_definition'] ) ) {
			// Validate JSON; store raw (already a JSON string from the builder).
			$raw = wp_unslash( $_POST['tf_definition'] );
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				// wp_slash so WP's internal wp_unslash in update_metadata doesn't strip JSON escapes (\uXXXX).
				update_post_meta( $post_id, '_tf_definition', wp_slash( wp_json_encode( self::sanitize_def( $decoded ) ) ) );
			}
		}
		if ( isset( $_POST['tf_type'] ) ) {
			update_post_meta( $post_id, '_tf_type', in_array( $_POST['tf_type'], array( 'regular', 'multistep' ), true ) ? $_POST['tf_type'] : 'regular' );
		}
		if ( isset( $_POST['tf_intent'] ) ) {
			$intent = sanitize_key( wp_unslash( $_POST['tf_intent'] ) );
			update_post_meta( $post_id, '_tf_intent', array_key_exists( $intent, tf_intents() ) ? $intent : 'lead' );
		}
		if ( isset( $_POST['tf_submit_text'] ) ) {
			update_post_meta( $post_id, '_tf_submit_text', sanitize_text_field( wp_unslash( $_POST['tf_submit_text'] ) ) );
		}
		if ( isset( $_POST['tf_success'] ) ) {
			update_post_meta( $post_id, '_tf_success', sanitize_textarea_field( wp_unslash( $_POST['tf_success'] ) ) );
		}
		if ( isset( $_POST['tf_wrapper_class'] ) ) {
			$classes = preg_split( '/\s+/', sanitize_text_field( wp_unslash( $_POST['tf_wrapper_class'] ) ), -1, PREG_SPLIT_NO_EMPTY );
			$classes = array_filter( array_map( 'sanitize_html_class', (array) $classes ) );
			update_post_meta( $post_id, '_tf_wrapper_class', implode( ' ', $classes ) );
		}
		if ( isset( $_POST['tf_custom_css'] ) ) {
			// CSS never needs '<' — stripping it prevents closing the <style> tag / HTML injection.
			$css = str_replace( '<', '', (string) wp_unslash( $_POST['tf_custom_css'] ) );
			update_post_meta( $post_id, '_tf_custom_css', $css );
		}

		// --- Actions list ---
		if ( isset( $_POST['tf_actions'] ) ) {
			$decoded = json_decode( wp_unslash( $_POST['tf_actions'] ), true );
			$clean   = self::sanitize_actions( $decoded );
			update_post_meta( $post_id, '_tf_actions', wp_slash( wp_json_encode( $clean ) ) );
			// Clear legacy single-value metas now that the list is authoritative.
			delete_post_meta( $post_id, '_tf_webhook_url' );
			delete_post_meta( $post_id, '_tf_redirect_type' );
			delete_post_meta( $post_id, '_tf_redirect_url' );
			delete_post_meta( $post_id, '_tf_redirect_page' );
		}

		tf_ensure_form_id( $post_id );
	}

	/** Sanitize the Actions list ( [ {type, enabled, config} ] ). */
	public static function sanitize_actions( $actions ) {
		$out  = array();
		$seen = array();
		if ( ! is_array( $actions ) ) { return $out; }
		foreach ( $actions as $a ) {
			$type = isset( $a['type'] ) ? sanitize_key( $a['type'] ) : '';
			if ( ! in_array( $type, array( 'webhook', 'redirect' ), true ) ) { continue; }
			if ( isset( $seen[ $type ] ) ) { continue; } // only one of each action type
			$seen[ $type ] = true;
			$enabled = ! empty( $a['enabled'] );
			$cfg     = ( isset( $a['config'] ) && is_array( $a['config'] ) ) ? $a['config'] : array();
			if ( 'webhook' === $type ) {
				$clean = array(
					'source'  => ( isset( $cfg['source'] ) && 'global' === $cfg['source'] ) ? 'global' : 'custom',
					'url'     => isset( $cfg['url'] ) ? esc_url_raw( $cfg['url'] ) : '',
					'trigger' => ( isset( $cfg['trigger'] ) && 'every' === $cfg['trigger'] ) ? 'every' : 'final',
				);
			} else {
				$clean = array(
					'mode'    => ( isset( $cfg['mode'] ) && 'page' === $cfg['mode'] ) ? 'page' : 'url',
					'url'     => isset( $cfg['url'] ) ? esc_url_raw( $cfg['url'] ) : '',
					'page_id' => isset( $cfg['page_id'] ) ? (int) $cfg['page_id'] : 0,
				);
			}
			$out[] = array( 'type' => $type, 'enabled' => $enabled, 'config' => $clean );
		}
		return $out;
	}

	/**
	 * Sanitize the builder definition (type + steps + fields).
	 */
	public static function sanitize_def( $def ) {
		$out = array(
			'type'  => ( isset( $def['type'] ) && 'multistep' === $def['type'] ) ? 'multistep' : 'regular',
			'steps' => array(),
		);
		$steps = isset( $def['steps'] ) && is_array( $def['steps'] ) ? $def['steps'] : array();
		foreach ( $steps as $step ) {
			$s = array(
				'title'     => isset( $step['title'] ) ? sanitize_text_field( $step['title'] ) : '',
				'next_text' => isset( $step['next_text'] ) ? sanitize_text_field( $step['next_text'] ) : '',
				'fields'    => array(),
			);
			$fields = isset( $step['fields'] ) && is_array( $step['fields'] ) ? $step['fields'] : array();
			foreach ( $fields as $f ) {
				$type = isset( $f['type'] ) ? sanitize_key( $f['type'] ) : 'text';
				$width = isset( $f['width'] ) && in_array( $f['width'], array( 'full', 'half', 'third' ), true ) ? $f['width'] : 'full';
				$num_or_blank = function ( $v ) { return ( isset( $v ) && '' !== $v && is_numeric( $v ) ) ? $v + 0 : ''; };
				$int_or_blank = function ( $v ) { return ( isset( $v ) && '' !== $v && ctype_digit( (string) $v ) ) ? (int) $v : ''; };
				$is_static = in_array( $type, array( 'heading', 'paragraph', 'separator', 'custom_html' ), true );
				// Paragraph keeps line breaks; other labels are single-line.
				$label = isset( $f['label'] ) ? ( 'paragraph' === $type ? sanitize_textarea_field( $f['label'] ) : sanitize_text_field( $f['label'] ) ) : '';
				// Custom HTML: trusted authors keep it raw; others get the post-content allowlist.
				$html = isset( $f['html'] ) ? (string) $f['html'] : '';
				if ( 'custom_html' === $type && '' !== $html && ! current_user_can( 'unfiltered_html' ) ) {
					$html = wp_kses_post( $html );
				}
				$field = array(
					'id'          => isset( $f['id'] ) ? sanitize_text_field( $f['id'] ) : ( 'f_' . wp_generate_password( 6, false ) ),
					'type'        => $type,
					'label'       => $label,
					'key'         => $is_static ? '' : ( isset( $f['key'] ) ? sanitize_key( $f['key'] ) : '' ),
					'placeholder' => isset( $f['placeholder'] ) ? sanitize_text_field( $f['placeholder'] ) : '',
					'required'    => ! empty( $f['required'] ),
					'show_label'  => ! array_key_exists( 'show_label', $f ) || ! empty( $f['show_label'] ), // default true
					'width'       => $width,
					'min'         => $num_or_blank( isset( $f['min'] ) ? $f['min'] : '' ),
					'max'         => $num_or_blank( isset( $f['max'] ) ? $f['max'] : '' ),
					'minlength'   => $int_or_blank( isset( $f['minlength'] ) ? $f['minlength'] : '' ),
					'maxlength'   => $int_or_blank( isset( $f['maxlength'] ) ? $f['maxlength'] : '' ),
					'multiple'    => ! empty( $f['multiple'] ), // dropdown: multi-choice
					'country_code' => ! array_key_exists( 'country_code', $f ) || ! empty( $f['country_code'] ), // phone: default on
					'cc_show_name' => ! empty( $f['cc_show_name'] ), // phone: show country name in the dial-code selector
					'block_free'   => ! empty( $f['block_free'] ), // email: block free/personal domains (require a work email)

					// Date bounds for the `date` field (days from today; 0 = today).
					'min_from_today' => $int_or_blank( isset( $f['min_from_today'] ) ? $f['min_from_today'] : '' ),
					'max_from_today' => $int_or_blank( isset( $f['max_from_today'] ) ? $f['max_from_today'] : '' ),
					// Slider range + display.
					'step'        => $num_or_blank( isset( $f['step'] ) ? $f['step'] : '' ),
					'default'     => $num_or_blank( isset( $f['default'] ) ? $f['default'] : '' ),
					// Strip tags but keep surrounding spaces (a suffix like " days" needs its leading space).
					'prefix'      => isset( $f['prefix'] ) ? preg_replace( '/[\r\n\t]+/', ' ', wp_kses( (string) $f['prefix'], array() ) ) : '',
					'suffix'      => isset( $f['suffix'] ) ? preg_replace( '/[\r\n\t]+/', ' ', wp_kses( (string) $f['suffix'], array() ) ) : '',
					'show_scale'  => ! empty( $f['show_scale'] ),
					'value_inline' => ! empty( $f['value_inline'] ),
					// Custom HTML content.
					'html'        => $html,
					'options'     => array(),
				);
				if ( in_array( $type, array( 'select', 'radio', 'checkbox' ), true ) && ! empty( $f['options'] ) && is_array( $f['options'] ) ) {
					foreach ( $f['options'] as $opt ) {
						if ( is_array( $opt ) ) {
							$label = isset( $opt['label'] ) ? sanitize_text_field( $opt['label'] ) : '';
							$value = ( isset( $opt['value'] ) && '' !== $opt['value'] ) ? sanitize_text_field( $opt['value'] ) : $label;
						} else {
							$label = sanitize_text_field( $opt );
							$value = $label;
						}
						if ( '' !== $label || '' !== $value ) {
							$field['options'][] = array( 'label' => $label, 'value' => $value );
						}
					}
				}
				if ( ! $is_static && empty( $field['key'] ) ) {
					$field['key'] = sanitize_key( $field['label'] ?: $field['type'] . '_' . substr( $field['id'], 2 ) );
				}
				$s['fields'][] = $field;
			}
			$out['steps'][] = $s;
		}
		if ( empty( $out['steps'] ) ) {
			$out['steps'][] = array( 'title' => '', 'fields' => array() );
		}
		return $out;
	}
}
