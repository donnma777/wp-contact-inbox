<?php
/**
 * 受信への返信。1件の画面から、フォームの送信元のアドレスとして相手に送る。
 *
 * - 送った返信はメタ _df_replies に残し、_df_replied（最後に返信した時刻）を付ける
 * - 送ったメールは、送信元アドレスのメールボックスの「送信済み」（Maildir の .Sent）にも保存する。
 *   Xserver の作り（~/<ドメイン>/mail/<ドメイン>/<アドレス>/）を前提に、WordPress の置き場所から探す。
 *   メールボックスがないとき（検証環境など）は保存しない。送信済みフォルダがまだなければ作る
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DONNMA_FORM_REPLY_TEMPLATE_OPTION', 'donnma_form_reply_template' );

function donnma_form_default_reply_template() {
	$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	return "{name} 様\n\nお問い合わせいただき、ありがとうございます。\n\n\n\n――――――――――――――――\n" . $site . "\n" . home_url( '/' ) . "\n";
}

function donnma_form_reply_template() {
	$template = get_option( DONNMA_FORM_REPLY_TEMPLATE_OPTION, '' );
	return $template !== '' ? $template : donnma_form_default_reply_template();
}

/**
 * 返信の送信元。受信したフォームの送信元、なければ管理者のメールアドレス。
 */
function donnma_form_reply_from( $post_id ) {
	$form = donnma_form_get( (string) get_post_meta( $post_id, '_df_form', true ) );
	$from = $form && $form['from'] !== '' && is_email( $form['from'] ) ? $form['from'] : get_option( 'admin_email' );
	return apply_filters( 'donnma_form_reply_from', $from, $post_id );
}

/**
 * 返信の本文の初期値（ひな形＋元の問い合わせの引用）。
 */
function donnma_form_reply_initial_body( $post ) {
	$name = (string) get_post_meta( $post->ID, '_df_name', true );
	$body = donnma_form_reply_template();
	if ( $name === '' ) {
		// 名前がないときは {name} の行ごと消す
		$body = preg_replace( '/^.*\{name\}.*(\n|$)/mu', '', $body, 1 );
	}
	$body = str_replace( '{name}', $name, $body );
	return rtrim( $body ) . "\n\n" . donnma_form_reply_quote( $post );
}

function donnma_form_reply_quote( $post ) {
	$lines   = array( '----- ' . get_the_date( 'Y/m/d H:i', $post ) . ' にいただいたお問い合わせ -----' );
	$fields  = get_post_meta( $post->ID, '_df_fields', true );
	$skip    = array( 'email' );
	foreach ( is_array( $fields ) ? $fields : array() as $field ) {
		$value = donnma_form_field_text( $field, $field['value'] );
		if ( $value === '' || in_array( $field['name'], $skip, true ) ) {
			continue;
		}
		if ( strpos( $value, "\n" ) !== false ) {
			$lines[] = '> ' . $field['label'] . ':';
			foreach ( explode( "\n", $value ) as $line ) {
				$lines[] = '> ' . $line;
			}
		} else {
			$lines[] = '> ' . $field['label'] . ': ' . $value;
		}
	}
	return implode( "\n", $lines ) . "\n";
}

/* ------------------------------------------------------------------
 * 画面
 * ---------------------------------------------------------------- */

function donnma_form_reply_box( $post ) {
	$email   = (string) get_post_meta( $post->ID, '_df_email', true );
	$replies = get_post_meta( $post->ID, '_df_replies', true );
	$replies = is_array( $replies ) ? $replies : array();

	foreach ( $replies as $reply ) {
		$user = isset( $reply['user'] ) ? get_userdata( $reply['user'] ) : null;
		echo '<div class="df-reply-item">';
		echo '<p class="df-reply-meta"><strong>' . esc_html( wp_date( 'Y/m/d H:i', $reply['time'] ) ) . ' に返信</strong>　'
			. esc_html( $reply['from'] . ' → ' . $reply['to'] )
			. ( $user ? '　（' . esc_html( $user->display_name ) . '）' : '' )
			. ( empty( $reply['saved'] ) ? '　<span class="df-muted">送信済みフォルダには保存していません</span>' : '' ) . '</p>';
		echo '<p class="df-reply-subject">件名: ' . esc_html( $reply['subject'] ) . '</p>';
		echo '<details><summary>本文を見る</summary><pre class="df-text">' . esc_html( $reply['body'] ) . '</pre></details>';
		echo '</div>';
	}

	if ( $post->post_status === 'df_spam' ) {
		echo '<p class="df-muted">迷惑メールには返信できません。「迷惑メールではない」にすると返信できます。</p>';
		return;
	}
	if ( $email === '' ) {
		echo '<p class="df-muted">メールアドレスが入力されていないので、返信できません。</p>';
		return;
	}

	$name  = (string) get_post_meta( $post->ID, '_df_name', true );
	$from  = donnma_form_reply_from( $post->ID );
	$draft = get_transient( 'df_reply_draft_' . get_current_user_id() . '_' . $post->ID );
	if ( is_array( $draft ) ) {
		delete_transient( 'df_reply_draft_' . get_current_user_id() . '_' . $post->ID );
	}
	$subject = is_array( $draft ) ? $draft['subject'] : 'Re: ' . $post->post_title;
	$body    = is_array( $draft ) ? $draft['body'] : donnma_form_reply_initial_body( $post );
	$to_text = ( $name !== '' ? $name . ' <' . $email . '>' : $email );

	if ( $replies ) {
		echo '<hr />';
	}
	echo '<div class="df-reply-form">';
	echo '<p class="df-reply-addr">送信元 <code>' . esc_html( $from ) . '</code>　→　宛先 <code>' . esc_html( $to_text ) . '</code></p>';
	wp_nonce_field( 'df_reply_' . $post->ID, 'df_reply_nonce' );
	echo '<p><label for="df_reply_subject">件名</label><br /><input type="text" id="df_reply_subject" name="df_reply_subject" class="large-text" value="' . esc_attr( $subject ) . '" /></p>';
	echo '<p><label for="df_reply_body">本文</label><br /><textarea id="df_reply_body" name="df_reply_body" class="large-text" rows="16">' . esc_textarea( $body ) . '</textarea></p>';
	echo '<p class="df-reply-buttons"><button type="submit" name="df_reply_send" value="1" class="button button-primary" onclick="return confirm(' . esc_attr( wp_json_encode( $to_text . ' に送信します。よろしいですか？' ) ) . ')">返信を送る</button>';
	echo ' <span class="df-muted">ひな形は「フォームの編集」の下で変えられます。</span></p>';
	echo '</div>';
}

/* ------------------------------------------------------------------
 * 送信
 * ---------------------------------------------------------------- */

/**
 * 返信を送る。1件の画面のフォーム（post.php）から送られてくるので、post.php の処理より前に受け取る。
 */
function donnma_form_handle_reply() {
	if ( empty( $_POST['df_reply_send'] ) || empty( $_POST['post_ID'] ) ) {
		return;
	}
	$post_id = (int) $_POST['post_ID'];
	$post    = get_post( $post_id );
	if ( ! $post || $post->post_type !== DONNMA_FORM_ENTRY ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( '権限がありません。' );
	}
	check_admin_referer( 'df_reply_' . $post_id, 'df_reply_nonce' );

	$subject = sanitize_text_field( wp_unslash( $_POST['df_reply_subject'] ?? '' ) );
	$body    = donnma_form_clean_text( wp_unslash( $_POST['df_reply_body'] ?? '' ), true );
	$back    = get_edit_post_link( $post_id, 'url' );

	$result = donnma_form_send_reply( $post, $subject, $body );
	if ( is_wp_error( $result ) ) {
		set_transient(
			'df_reply_draft_' . get_current_user_id() . '_' . $post_id,
			array(
				'subject' => $subject,
				'body'    => $body,
			),
			HOUR_IN_SECONDS
		);
		wp_safe_redirect( add_query_arg( 'df_reply_error', rawurlencode( $result->get_error_message() ), $back ) );
		exit;
	}
	wp_safe_redirect( add_query_arg( 'df_reply_sent', $result ? 1 : 2, $back ) );
	exit;
}
add_action( 'load-post.php', 'donnma_form_handle_reply', 5 );

/**
 * @return true|false|WP_Error 送れて送信済みに保存できたら true、送れたが保存できなかったら false
 */
function donnma_form_send_reply( $post, $subject, $body ) {
	$email = (string) get_post_meta( $post->ID, '_df_email', true );
	if ( $email === '' || ! is_email( $email ) ) {
		return new WP_Error( 'df_no_email', '宛先のメールアドレスがありません。' );
	}
	if ( $post->post_status === 'df_spam' ) {
		return new WP_Error( 'df_spam', '迷惑メールには返信できません。' );
	}
	if ( $subject === '' ) {
		return new WP_Error( 'df_no_subject', '件名を入れてください。' );
	}
	if ( trim( $body ) === '' ) {
		return new WP_Error( 'df_no_body', '本文を入れてください。' );
	}

	$from    = donnma_form_reply_from( $post->ID );
	$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	$name    = donnma_form_header_name( (string) get_post_meta( $post->ID, '_df_name', true ) );
	$to      = $name !== '' ? '"' . $name . '" <' . $email . '>' : $email;
	$headers = array(
		'Content-Type: text/plain; charset=UTF-8',
		'From: ' . donnma_form_header_name( $site ) . ' <' . $from . '>',
	);

	// 送ったメールそのもの（ヘッダーつき）を、送信済みフォルダに入れるために取っておく
	$capture = function ( $phpmailer ) {
		$GLOBALS['donnma_form_capture'] = $phpmailer;
	};
	add_action( 'phpmailer_init', $capture );
	$sent = wp_mail( $to, $subject, $body, $headers );
	remove_action( 'phpmailer_init', $capture );

	if ( ! $sent ) {
		return new WP_Error( 'df_mail', 'メールを送れませんでした。時間をおいて、もう一度お試しください。' );
	}

	$saved = false;
	if ( isset( $GLOBALS['donnma_form_capture'] ) && method_exists( $GLOBALS['donnma_form_capture'], 'getSentMIMEMessage' ) ) {
		$saved = donnma_form_save_to_sent( $from, $GLOBALS['donnma_form_capture']->getSentMIMEMessage() );
	}
	unset( $GLOBALS['donnma_form_capture'] );

	$replies   = get_post_meta( $post->ID, '_df_replies', true );
	$replies   = is_array( $replies ) ? $replies : array();
	$replies[] = array(
		'time'    => time(),
		'user'    => get_current_user_id(),
		'from'    => $from,
		'to'      => $email,
		'subject' => $subject,
		'body'    => $body,
		'saved'   => $saved,
	);
	update_post_meta( $post->ID, '_df_replies', wp_slash( $replies ) );
	update_post_meta( $post->ID, '_df_replied', time() );
	delete_post_meta( $post->ID, '_df_unread' );

	return $saved;
}

/**
 * 送信元アドレスの「送信済み」フォルダ（Maildir）。なければ空文字。
 */
function donnma_form_sent_maildir( $from ) {
	$at = strrpos( $from, '@' );
	if ( $at === false ) {
		return '';
	}
	$domain = strtolower( substr( $from, $at + 1 ) );
	$from   = strtolower( $from );

	// WordPress が ~/<サイトのドメイン>/public_html/ にあるとして、~/<送信元のドメイン>/mail/… を探す
	$home    = dirname( dirname( untrailingslashit( ABSPATH ) ) );
	$mailbox = '';
	foreach ( array( $home . '/' . $domain, dirname( untrailingslashit( ABSPATH ) ) ) as $base ) {
		$candidate = $base . '/mail/' . $domain . '/' . $from;
		if ( is_dir( $candidate . '/cur' ) ) {
			$mailbox = $candidate;
			break;
		}
	}
	$dir = $mailbox !== '' ? $mailbox . '/.Sent' : '';
	$dir = apply_filters( 'donnma_form_sent_maildir', $dir, $from );
	if ( ! $dir ) {
		return '';
	}

	// 送信済みフォルダは、メールソフトで初めて送ったときにできる。まだなければ作る（Courier IMAP の作り）
	$parent = dirname( $dir );
	if ( ! is_dir( $dir ) && is_dir( $parent . '/cur' ) && is_writable( $parent ) ) {
		foreach ( array( '', '/cur', '/new', '/tmp' ) as $sub ) {
			if ( ! is_dir( $dir . $sub ) ) {
				mkdir( $dir . $sub, 0700 );
			}
		}
		if ( ! file_exists( $dir . '/maildirfolder' ) ) {
			touch( $dir . '/maildirfolder' );
		}
		// メールソフトに出るよう購読リストに足す
		$folder     = 'INBOX.' . substr( basename( $dir ), 1 );
		$subscribed = $parent . '/courierimapsubscribed';
		$list       = file_exists( $subscribed ) ? file( $subscribed, FILE_IGNORE_NEW_LINES ) : array();
		if ( ! in_array( $folder, (array) $list, true ) ) {
			file_put_contents( $subscribed, $folder . "\n", FILE_APPEND );
		}
	}
	return ( is_dir( $dir . '/cur' ) && is_dir( $dir . '/tmp' ) ) ? $dir : '';
}

/**
 * メールを Maildir の送信済みフォルダに入れる（既読の印つき）。
 */
function donnma_form_save_to_sent( $from, $message ) {
	$dir = donnma_form_sent_maildir( $from );
	if ( $dir === '' || ! is_writable( $dir . '/tmp' ) || ! is_writable( $dir . '/cur' ) || $message === '' ) {
		return false;
	}
	$message = str_replace( "\r\n", "\n", $message );
	$host    = preg_replace( '/[^A-Za-z0-9.\-]/', '_', (string) php_uname( 'n' ) );
	$name    = sprintf( '%d.M%dP%d.%s,S=%d', time(), (int) ( ( microtime( true ) - floor( microtime( true ) ) ) * 1000000 ), getmypid(), $host, strlen( $message ) );
	$tmp     = $dir . '/tmp/' . $name;
	if ( file_put_contents( $tmp, $message ) === false ) {
		return false;
	}
	if ( ! rename( $tmp, $dir . '/cur/' . $name . ':2,S' ) ) {
		wp_delete_file( $tmp );
		return false;
	}
	return true;
}

function donnma_form_reply_notice() {
	$screen = get_current_screen();
	if ( ! $screen || $screen->id !== DONNMA_FORM_ENTRY ) {
		return;
	}
	if ( isset( $_GET['df_reply_sent'] ) ) {
		$saved = (int) $_GET['df_reply_sent'] === 1;
		echo '<div class="notice notice-success is-dismissible"><p>返信を送りました。'
			. ( $saved ? '送信済みフォルダにも保存しました。' : '（送信済みフォルダには保存できませんでした）' ) . '</p></div>';
	}
	if ( isset( $_GET['df_reply_error'] ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['df_reply_error'] ) ) ) . '</p></div>';
	}
}
add_action( 'admin_notices', 'donnma_form_reply_notice' );

/* ------------------------------------------------------------------
 * ひな形の設定（フォームの編集の一覧の下）
 * ---------------------------------------------------------------- */

function donnma_form_render_reply_template_setting() {
	echo '<h2 id="df-reply-template">返信のひな形</h2>';
	echo '<form method="post" action="' . esc_url( donnma_form_forms_page_url() ) . '#df-reply-template">';
	wp_nonce_field( 'df_save_reply_template' );
	echo '<p class="description">受信の画面で返信を書くときの、本文の最初の文面です。<code>{name}</code> は相手の名前になります（名前がないときは、その行ごと消えます）。この下に元の問い合わせの引用が付きます。</p>';
	echo '<p><textarea name="df_reply_template" class="large-text code" rows="10">' . esc_textarea( donnma_form_reply_template() ) . '</textarea></p>';
	submit_button( 'ひな形を保存', 'secondary', 'df_save_reply_template', false );
	echo '</form>';
}

function donnma_form_save_reply_template() {
	if ( empty( $_POST['df_save_reply_template'] ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	check_admin_referer( 'df_save_reply_template' );
	$template = donnma_form_clean_text( wp_unslash( $_POST['df_reply_template'] ?? '' ), true );
	update_option( DONNMA_FORM_REPLY_TEMPLATE_OPTION, $template === donnma_form_default_reply_template() ? '' : $template, false );
	wp_safe_redirect( donnma_form_forms_page_url( array( 'df_msg' => 'template' ) ) . '#df-reply-template' );
	exit;
}
