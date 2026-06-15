<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Registers the Terrific Form custom post type and ensures a static form ID on save.
 */
class TF_CPT_Manager {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'save_post_' . TF_CPT, array( __CLASS__, 'on_save' ), 10, 2 );
		// Show the static ID + shortcode in the forms list table.
		add_filter( 'manage_' . TF_CPT . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . TF_CPT . '_posts_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
	}

	public static function register() {
		register_post_type( TF_CPT, array(
			'labels' => array(
				'name'               => 'Terrific Forms',
				'singular_name'      => 'Form',
				'add_new'            => 'Add New Form',
				'add_new_item'       => 'Add New Form',
				'edit_item'          => 'Edit Form',
				'new_item'           => 'New Form',
				'view_item'          => 'View Form',
				'search_items'       => 'Search Forms',
				'not_found'          => 'No forms found',
				'menu_name'          => 'Terrific Forms',
			),
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => false, // nested under the "Terrific Digital" menu by TD_Menu.
			'supports'            => array( 'title' ),
			'capability_type'     => 'page',
			'map_meta_cap'        => true,
		) );
	}

	public static function on_save( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
		if ( wp_is_post_revision( $post_id ) ) { return; }
		tf_ensure_form_id( $post_id );
	}

	public static function columns( $cols ) {
		$new = array();
		foreach ( $cols as $k => $v ) {
			$new[ $k ] = $v;
			if ( 'title' === $k ) {
				$new['tf_id']        = 'Form ID';
				$new['tf_shortcode'] = 'Shortcode';
			}
		}
		return $new;
	}

	public static function column_content( $col, $post_id ) {
		if ( 'tf_id' === $col ) {
			echo '<code>' . esc_html( get_post_meta( $post_id, '_tf_form_id', true ) ) . '</code>';
		}
		if ( 'tf_shortcode' === $col ) {
			echo '<code>[terrific_form id="' . esc_attr( $post_id ) . '"]</code>';
		}
	}
}
