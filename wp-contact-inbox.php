<?php
/**
 * Plugin Name: Contact Inbox
 * Description: お問い合わせフォームと受信一覧。管理画面でフォームを作り、ブロック「フォーム」で表示します。送られた内容は受信一覧で見られ、管理画面から返信もできます。メールでも届き、Discord Post Notify が入っていれば Discord にも通知します。
 * Version: 2.2.0
 * Author: donnma
 * Author URI: https://donnma.com/
 * Plugin URI: https://github.com/donnma777/wp-contact-inbox
 * License: GPLv2 or later
 * Requires at least: 6.3
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 関数名・設定名の donnma_form_ は、1.x（Donnma Form）から引き継いでいる
define( 'DONNMA_FORM_VERSION', '2.2.0' );
define( 'DONNMA_FORM_FILE', __FILE__ );
define( 'DONNMA_FORM_URL', plugin_dir_url( __FILE__ ) );

// ページを開いてから送信までの最短秒数。これより早い送信はロボットとみなす
define( 'DONNMA_FORM_MIN_SECONDS', 3 );
// 同じ IP から DONNMA_FORM_RATE_WINDOW 秒の間に送れる回数
define( 'DONNMA_FORM_RATE_LIMIT', 5 );
define( 'DONNMA_FORM_RATE_WINDOW', 600 );
// 1項目の文字数の上限（項目の上限を空にしたときも、これより長いものは受け付けない）
define( 'DONNMA_FORM_HARD_MAX', 50000 );
// 迷惑メールと判定された受信を残しておく日数
define( 'DONNMA_FORM_SPAM_DAYS', 30 );

require_once __DIR__ . '/includes/forms.php';
require_once __DIR__ . '/includes/frontend.php';
require_once __DIR__ . '/includes/entries.php';
require_once __DIR__ . '/includes/admin-forms.php';
require_once __DIR__ . '/includes/block.php';
require_once __DIR__ . '/includes/reply.php';
require_once __DIR__ . '/includes/privacy.php';

register_activation_hook( __FILE__, 'donnma_form_schedule_cleanup' );
register_deactivation_hook( __FILE__, 'donnma_form_unschedule_cleanup' );
