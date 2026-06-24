<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Form Import / Export — round-trip a single form between sites via a JSON file,
 * without touching wp_postmeta wholesale.
 *
 * Export: a row action on each form in the Forms list streams a download with the
 * full form definition (fields/steps), settings (intent, submit text, success
 * message, wrapper class, custom CSS), and actions (webhooks/redirects).
 *
 * Import: a submenu page under Terrific Digital accepts the JSON via file upload
 * or paste. Forms are matched on the stable `_tf_form_id` (e.g. "tf_77_c93888"):
 *   - Match found  → updates the existing form in place.
 *   - No match     → creates a fresh form, preserving the imported form_id so a
 *                     re-import is idempotent.
 *
 * Everything is sanitized through the existing sanitize_def() / sanitize_actions()
 * helpers so an imported file can't sneak unfiltered HTML or unknown field types in.
 */
class TF_Import_Export {

	const VERSION = 1;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 13 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_action' ), 11, 2 );
		add_action( 'admin_post_tf_export_form', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_tf_import_form', array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_notice' ) );
	}

	/* ----------------------------------------------------------------- Menu */

	public static function menu() {
		add_submenu_page( TD_MENU, 'Import Form', 'Import Form', 'edit_pages', 'tf-import-form', array( __CLASS__, 'screen' ) );
	}

	public static function row_action( $actions, $post ) {
		if ( $post->post_type !== TF_CPT || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}
		$url = wp_nonce_url(
			add_query_arg( array( 'action' => 'tf_export_form', 'id' => $post->ID ), admin_url( 'admin-post.php' ) ),
			'tf_export_form_' . $post->ID
		);
		$actions['tf_export'] = sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'Export JSON', 'terrific-digital' ) );
		return $actions;
	}

	/* --------------------------------------------------------------- Export */

	public static function handle_export() {
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		if ( ! $id || get_post_type( $id ) !== TF_CPT ) { wp_die( esc_html__( 'Invalid form.', 'terrific-digital' ), 400 ); }
		check_admin_referer( 'tf_export_form_' . $id );
		if ( ! current_user_can( 'edit_post', $id ) ) { wp_die( esc_html__( 'Insufficient permissions.', 'terrific-digital' ), 403 ); }

		$payload = self::build_export( $id );
		$slug    = sanitize_file_name( $payload['form_id'] ?: ( 'form-' . $id ) );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="terrific-form-' . $slug . '.json"' );
		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/** Snapshot a form's full state into a JSON-serialisable array. */
	protected static function build_export( $post_id ) {
		$post = get_post( $post_id );
		$def  = tf_get_form( $post_id );
		// Strip the runtime keys tf_get_form() merges in; we only export the persisted definition.
		$definition = array(
			'type'  => isset( $def['type'] ) ? $def['type'] : 'regular',
			'steps' => isset( $def['steps'] ) ? $def['steps'] : array(),
		);
		if ( isset( $def['submission_step'] ) ) {
			$definition['submission_step'] = (int) $def['submission_step'];
		}
		return array(
			'tf_export_version' => self::VERSION,
			'exported_at'       => gmdate( 'c' ),
			'form_id'           => TF_Forms::get_form_id( $post_id ),
			'name'              => (string) $post->post_title,
			'status'            => (string) $post->post_status,
			'intent'            => tf_get_intent( $post_id ),
			'submit_text'       => TF_Forms::get_submit_text( $post_id ),
			'success_message'   => TF_Forms::get_success_message( $post_id ),
			'wrapper_class'     => TF_Forms::get_wrapper_class( $post_id ),
			'custom_css'        => TF_Forms::get_custom_css( $post_id ),
			'definition'        => $definition,
			'actions'           => tf_get_actions( $post_id ),
		);
	}

	/* --------------------------------------------------------------- Import */

	public static function screen() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import Form', 'terrific-digital' ); ?></h1>
			<p style="max-width:720px;color:#555">
				<?php esc_html_e( 'Upload a JSON file (or paste the JSON contents) exported from another Terrific Digital site. Forms are matched on their stable form ID — a match updates the existing form in place, otherwise a new form is created.', 'terrific-digital' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="tf_import_form">
				<?php wp_nonce_field( 'tf_import_form' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="tf_import_file"><?php esc_html_e( 'JSON file', 'terrific-digital' ); ?></label></th>
						<td>
							<input type="file" name="tf_import_file" id="tf_import_file" accept="application/json,.json">
							<p class="description"><?php esc_html_e( 'Pick a terrific-form-*.json file from your computer.', 'terrific-digital' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tf_import_json"><?php esc_html_e( '…or paste JSON', 'terrific-digital' ); ?></label></th>
						<td>
							<textarea name="tf_import_json" id="tf_import_json" rows="10" class="large-text code" placeholder='{"tf_export_version":1, …}'></textarea>
							<p class="description"><?php esc_html_e( 'Either field works — file takes precedence if both are provided.', 'terrific-digital' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Import Form', 'terrific-digital' ) ); ?>
			</form>
		</div>
		<?php
	}

	public static function handle_import() {
		check_admin_referer( 'tf_import_form' );
		if ( ! current_user_can( 'edit_pages' ) ) { wp_die( esc_html__( 'Insufficient permissions.', 'terrific-digital' ), 403 ); }

		$json = '';
		if ( ! empty( $_FILES['tf_import_file']['tmp_name'] ) && empty( $_FILES['tf_import_file']['error'] ) ) {
			$json = (string) file_get_contents( $_FILES['tf_import_file']['tmp_name'] );
		} elseif ( ! empty( $_POST['tf_import_json'] ) ) {
			$json = (string) wp_unslash( $_POST['tf_import_json'] );
		}
		$json = trim( $json );
		if ( '' === $json ) {
			self::redirect_with( array( 'tf_import' => 'no_input' ) );
		}

		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || empty( $data['tf_export_version'] ) || empty( $data['form_id'] ) ) {
			self::redirect_with( array( 'tf_import' => 'invalid' ) );
		}
		if ( (int) $data['tf_export_version'] > self::VERSION ) {
			self::redirect_with( array( 'tf_import' => 'newer_version' ) );
		}

		$result = self::apply_import( $data );
		if ( is_wp_error( $result ) ) {
			self::redirect_with( array( 'tf_import' => 'failed', 'msg' => $result->get_error_message() ) );
		}
		self::redirect_with( array( 'tf_import' => $result['action'], 'id' => $result['post_id'] ) );
	}

	/**
	 * Create-or-update a form from a parsed export payload.
	 * Returns array{ action: 'created'|'updated', post_id: int } or WP_Error.
	 */
	protected static function apply_import( $data ) {
		$form_id = sanitize_text_field( (string) $data['form_id'] );
		if ( '' === $form_id ) { return new WP_Error( 'tf_import_bad_id', __( 'Missing form_id.', 'terrific-digital' ) ); }

		$existing = self::find_by_form_id( $form_id );
		$is_new   = ! $existing;
		$post_id  = $existing;
		$title    = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : 'Imported Form';
		$status   = ( isset( $data['status'] ) && in_array( $data['status'], array( 'publish', 'draft', 'private' ), true ) ) ? $data['status'] : 'draft';

		if ( $is_new ) {
			$post_id = wp_insert_post( array(
				'post_type'   => TF_CPT,
				'post_title'  => $title,
				'post_status' => $status,
			), true );
			if ( is_wp_error( $post_id ) ) { return $post_id; }
		} else {
			wp_update_post( array( 'ID' => $post_id, 'post_title' => $title ) );
		}

		// Re-sanitize definition + actions through the builder's own helpers so unknown
		// field types / disallowed HTML can't sneak in via a hand-edited export.
		$def = isset( $data['definition'] ) && is_array( $data['definition'] ) ? $data['definition'] : array();
		if ( class_exists( 'TF_Builder' ) ) { $def = TF_Builder::sanitize_def( $def ); }
		$actions = isset( $data['actions'] ) && is_array( $data['actions'] ) ? $data['actions'] : array();
		if ( class_exists( 'TF_Builder' ) && method_exists( 'TF_Builder', 'sanitize_actions' ) ) {
			$actions = TF_Builder::sanitize_actions( $actions );
		}

		$intents = tf_intents();
		$intent  = isset( $data['intent'] ) && isset( $intents[ $data['intent'] ] ) ? $data['intent'] : 'lead';

		TF_Forms::save( $post_id, array(
			'form_id'         => $form_id,
			'definition'      => wp_json_encode( $def ),
			'intent'          => $intent,
			'submit_text'     => isset( $data['submit_text'] ) ? sanitize_text_field( $data['submit_text'] ) : '',
			'success_message' => isset( $data['success_message'] ) ? wp_kses_post( $data['success_message'] ) : '',
			'wrapper_class'   => isset( $data['wrapper_class'] ) ? sanitize_text_field( $data['wrapper_class'] ) : '',
			'custom_css'      => isset( $data['custom_css'] ) ? str_replace( '<', '', (string) $data['custom_css'] ) : '',
			'actions'         => wp_json_encode( $actions ),
		) );

		return array(
			'action'  => $is_new ? 'created' : 'updated',
			'post_id' => (int) $post_id,
		);
	}

	/** Look up a Terrific Form CPT post by its stable form_id. */
	protected static function find_by_form_id( $form_id ) {
		return (int) TF_Forms::find_post_id_by_form_id( $form_id );
	}

	protected static function redirect_with( $args ) {
		$url = add_query_arg(
			array_merge( array( 'page' => 'tf-import-form' ), $args ),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/** Render the success / failure notice after a redirect from handle_import. */
	public static function maybe_notice() {
		if ( ! isset( $_GET['page'] ) || 'tf-import-form' !== $_GET['page'] || empty( $_GET['tf_import'] ) ) { return; }
		$state = sanitize_key( $_GET['tf_import'] );
		$id    = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		$edit  = $id ? get_edit_post_link( $id ) : '';

		if ( in_array( $state, array( 'created', 'updated' ), true ) && $edit ) {
			$verb = 'created' === $state ? __( 'Created new form', 'terrific-digital' ) : __( 'Updated existing form', 'terrific-digital' );
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s — <a href="%s">%s</a></p></div>',
				esc_html( $verb ),
				esc_url( $edit ),
				esc_html__( 'open it', 'terrific-digital' )
			);
			return;
		}

		$messages = array(
			'no_input'      => __( 'Please upload a JSON file or paste the JSON.', 'terrific-digital' ),
			'invalid'       => __( "That doesn't look like a Terrific Digital form export.", 'terrific-digital' ),
			'newer_version' => __( 'This export was made with a newer plugin version. Upgrade the plugin and try again.', 'terrific-digital' ),
			'failed'        => isset( $_GET['msg'] ) ? sanitize_text_field( wp_unslash( $_GET['msg'] ) ) : __( 'Import failed.', 'terrific-digital' ),
		);
		if ( isset( $messages[ $state ] ) ) {
			printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( $messages[ $state ] ) );
		}
	}
}
