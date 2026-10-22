<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Registers the "TBL Form" Gutenberg block (dynamic / server-rendered).
 * Lets editors drop a form into any page and pick which form to show.
 */
class TF_Block {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_filter( 'block_categories_all', array( __CLASS__, 'category' ), 10, 1 );
	}

	public static function category( $categories ) {
		foreach ( $categories as $c ) {
			if ( isset( $c['slug'] ) && 'terrific-digital' === $c['slug'] ) { return $categories; }
		}
		array_unshift( $categories, array(
			'slug'  => 'terrific-digital',
			'title' => 'Travel by Luxe',
			'icon'  => null,
		) );
		return $categories;
	}

	public static function register() {
		wp_register_script(
			'tf-block-editor',
			TF_URL . 'assets/block.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n', 'wp-server-side-render' ),
			TF_VERSION,
			true
		);
		// Register the form's style/script handles so the editor preview is styled.
		TF_Render::register_handles();

		// Provide the list of forms to the editor (id, title, form_id).
		$forms = get_posts( array(
			'post_type'   => TF_CPT,
			'numberposts' => -1,
			'post_status' => 'publish',
			'orderby'     => 'title',
			'order'       => 'ASC',
		) );
		$list = array();
		foreach ( $forms as $f ) {
			$list[] = array(
				'id'       => (string) $f->ID,
				'title'    => get_the_title( $f ),
				'form_id'  => TF_Forms::get_form_id( $f->ID ),
				'edit_url' => admin_url( 'post.php?post=' . $f->ID . '&action=edit' ),
			);
		}
		wp_localize_script( 'tf-block-editor', 'TF_BLOCK', array(
			'forms'  => $list,
			'newUrl' => admin_url( 'post-new.php?post_type=' . TF_CPT ),
		) );

		register_block_type( 'terrific-digital/form', array(
			'api_version'     => 2,
			'editor_script'   => 'tf-block-editor',
			'editor_style'    => 'tf-form',
			'attributes'      => array(
				'formId' => array( 'type' => 'string', 'default' => '' ),
			),
			'render_callback' => array( __CLASS__, 'render' ),
		) );
	}

	public static function render( $attributes ) {
		$id = isset( $attributes['formId'] ) ? (int) $attributes['formId'] : 0;
		if ( ! $id || get_post_type( $id ) !== TF_CPT ) {
			return current_user_can( 'edit_posts' ) ? '<p><em>TBL Form: please select a form.</em></p>' : '';
		}
		return TF_Render::shortcode( array( 'id' => $id ) );
	}
}
