<?php
/**
 * WordPress の「個人データのエクスポート」「個人データの消去」（ツール）への対応。
 * 送信者のメールアドレス（_df_email）で受信を探す。メールアドレスのない受信は対象にならない。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DONNMA_FORM_PRIVACY_PER_PAGE', 50 );

function donnma_form_privacy_entries( $email, $page ) {
	return get_posts(
		array(
			'post_type'      => DONNMA_FORM_ENTRY,
			'post_status'    => array( 'df_received', 'df_spam', 'trash' ),
			'meta_key'       => '_df_email',
			'meta_value'     => $email,
			'posts_per_page' => DONNMA_FORM_PRIVACY_PER_PAGE,
			'paged'          => max( 1, (int) $page ),
			'orderby'        => 'ID',
			'order'          => 'ASC',
		)
	);
}

function donnma_form_register_privacy_exporter( $exporters ) {
	$exporters['contact-inbox'] = array(
		'exporter_friendly_name' => 'Contact Inbox（フォームの受信）',
		'callback'               => 'donnma_form_privacy_export',
	);
	return $exporters;
}
add_filter( 'wp_privacy_personal_data_exporters', 'donnma_form_register_privacy_exporter' );

function donnma_form_privacy_export( $email, $page = 1 ) {
	$posts = donnma_form_privacy_entries( $email, $page );
	$items = array();
	foreach ( $posts as $post ) {
		$data = array(
			array(
				'name'  => '受信日時',
				'value' => get_the_date( 'Y-m-d H:i:s', $post ),
			),
			array(
				'name'  => 'フォーム',
				'value' => get_post_meta( $post->ID, '_df_form_title', true ),
			),
		);
		$fields = get_post_meta( $post->ID, '_df_fields', true );
		foreach ( is_array( $fields ) ? $fields : array() as $field ) {
			$data[] = array(
				'name'  => $field['label'],
				'value' => donnma_form_field_text( $field, $field['value'] ),
			);
		}
		foreach ( array( '_df_ip' => 'IP アドレス', '_df_ua' => 'ブラウザ' ) as $key => $label ) {
			$data[] = array(
				'name'  => $label,
				'value' => get_post_meta( $post->ID, $key, true ),
			);
		}
		$replies = get_post_meta( $post->ID, '_df_replies', true );
		foreach ( is_array( $replies ) ? $replies : array() as $i => $reply ) {
			$data[] = array(
				'name'  => sprintf( '返信 %d（%s）', $i + 1, wp_date( 'Y-m-d H:i', $reply['time'] ) ),
				'value' => $reply['subject'] . "\n\n" . $reply['body'],
			);
		}
		$items[] = array(
			'group_id'    => 'contact-inbox',
			'group_label' => 'フォームの受信',
			'item_id'     => 'df-entry-' . $post->ID,
			'data'        => $data,
		);
	}
	return array(
		'data' => $items,
		'done' => count( $posts ) < DONNMA_FORM_PRIVACY_PER_PAGE,
	);
}

function donnma_form_register_privacy_eraser( $erasers ) {
	$erasers['contact-inbox'] = array(
		'eraser_friendly_name' => 'Contact Inbox（フォームの受信）',
		'callback'             => 'donnma_form_privacy_erase',
	);
	return $erasers;
}
add_filter( 'wp_privacy_personal_data_erasers', 'donnma_form_register_privacy_eraser' );

/**
 * 受信をまるごと削除する（ゴミ箱を通さない）。消すと件数が減るので、いつも1ページ目を取る。
 */
function donnma_form_privacy_erase( $email, $page = 1 ) {
	$posts   = donnma_form_privacy_entries( $email, 1 );
	$removed = false;
	foreach ( $posts as $post ) {
		if ( wp_delete_post( $post->ID, true ) ) {
			$removed = true;
		}
	}
	return array(
		'items_removed'  => $removed,
		'items_retained' => false,
		'messages'       => array(),
		'done'           => count( $posts ) < DONNMA_FORM_PRIVACY_PER_PAGE,
	);
}

/**
 * プライバシーポリシーの案内（設定 → プライバシー の「ポリシーガイド」）に文例を出す。
 */
function donnma_form_privacy_policy_content() {
	if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
		return;
	}
	$text = '<p>このサイトのフォームから送信すると、入力した内容（お名前・メールアドレス・本文など）と、送信時の IP アドレス・ブラウザの情報を保存します。保存した内容は、お問い合わせへの対応と、迷惑な送信を防ぐために使います。</p>'
		. '<p>迷惑な送信の判定に Akismet を使っている場合、送信内容は Akismet（Automattic 社）に送られます。</p>'
		. '<p>送信した内容の書き出しや削除を希望する場合は、送信に使ったメールアドレスでお知らせください。</p>';
	wp_add_privacy_policy_content( 'Contact Inbox', wp_kses_post( $text ) );
}
add_action( 'admin_init', 'donnma_form_privacy_policy_content' );
