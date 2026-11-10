<?php
/**
 * ブロック「フォーム」（donnma/form）。表示は PHP で作る（ショートコードと同じ処理）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function donnma_form_register_block() {
	wp_register_script(
		'donnma-form-block',
		DONNMA_FORM_URL . 'assets/block.js',
		array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render' ),
		DONNMA_FORM_VERSION,
		true
	);
	register_block_type(
		dirname( __DIR__ ) . '/block.json',
		array( 'render_callback' => 'donnma_form_render_block' )
	);
}
add_action( 'init', 'donnma_form_register_block' );

function donnma_form_render_block( $attributes ) {
	$form_id = isset( $attributes['formId'] ) ? sanitize_key( $attributes['formId'] ) : '';
	if ( $form_id === '' ) {
		return '';
	}
	$html = donnma_form_shortcode( array( 'id' => $form_id ) );
	if ( $html === '' ) {
		return '';
	}
	return '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>';
}

/**
 * エディタに、選べるフォームと編集画面へのリンクを渡す。
 */
function donnma_form_block_editor_data() {
	$forms = array();
	foreach ( donnma_form_definitions() as $id => $form ) {
		$forms[] = array(
			'id'    => (string) $id,
			'title' => $form['title'],
		);
	}
	$data = array(
		'forms'   => $forms,
		'canEdit' => current_user_can( 'manage_options' ),
		'editUrl' => donnma_form_forms_page_url( array( 'edit' => '' ) ),
		'newUrl'  => donnma_form_forms_page_url( array( 'new' => 1 ) ),
	);
	wp_add_inline_script( 'donnma-form-block', 'window.donnmaFormBlock = ' . wp_json_encode( $data ) . ';', 'before' );
}
add_action( 'enqueue_block_editor_assets', 'donnma_form_block_editor_data' );
