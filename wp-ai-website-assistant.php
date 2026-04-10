<?php
/**
 * Plugin Name: AI Website Assistant – Smart Chat for WordPress
 * Plugin URI:  https://wazidshah.github.io/AI-Website-Assistant/
 * Description: A lightweight AI chatbot powered by Google Gemini that answers questions about your website content using RAG (Retrieval Augmented Generation).
 * Version:     1.0.0
 * Author:      Wazid Shah
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ai-website-assistant
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'AI_ASSISTANT_VERSION', '1.2.0' );
define( 'AI_ASSISTANT_DB_VERSION', '1.2' );
define( 'AI_ASSISTANT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AI_ASSISTANT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AI_ASSISTANT_TABLE', 'ai_chunks' );

// Load Composer dependencies safely.
if ( file_exists( AI_ASSISTANT_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once AI_ASSISTANT_PLUGIN_DIR . 'vendor/autoload.php';
}

// Autoload includes.
require_once AI_ASSISTANT_PLUGIN_DIR . 'includes/crawler.php';
require_once AI_ASSISTANT_PLUGIN_DIR . 'includes/chunker.php';
require_once AI_ASSISTANT_PLUGIN_DIR . 'includes/embeddings.php';
require_once AI_ASSISTANT_PLUGIN_DIR . 'includes/search.php';
require_once AI_ASSISTANT_PLUGIN_DIR . 'includes/gemini-client.php';
require_once AI_ASSISTANT_PLUGIN_DIR . 'includes/rest-api.php';

if ( is_admin() ) {
	require_once AI_ASSISTANT_PLUGIN_DIR . 'admin/settings-page.php';
	require_once AI_ASSISTANT_PLUGIN_DIR . 'admin/training-page.php';
}

/**
 * Activation hook – create the chunks database table.
 */
function ai_assistant_activate() {
	ai_assistant_upgrade_db();
}
register_activation_hook( __FILE__, 'ai_assistant_activate' );

/**
 * Handle DB creation and structural upgrades.
 */
function ai_assistant_upgrade_db() {
	global $wpdb;
	
	$table_name      = $wpdb->prefix . AI_ASSISTANT_TABLE;
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
		id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		content     LONGTEXT            NOT NULL,
		embedding   BLOB          		NULL,
		url         VARCHAR(2048)       NOT NULL DEFAULT '',
		source_type VARCHAR(100)        NOT NULL DEFAULT 'unknown',
		page_title  VARCHAR(512)        NOT NULL DEFAULT '',
		created_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		FULLTEXT KEY content_ft (content)
	) ENGINE=InnoDB {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	// Store the current DB version.
	update_option( 'ai_assistant_db_version', AI_ASSISTANT_DB_VERSION );
}

/**
 * Run upgrades automatically on plugin load if DB version doesn't match.
 */
function ai_assistant_check_db_version() {
	if ( get_option( 'ai_assistant_db_version' ) !== AI_ASSISTANT_DB_VERSION ) {
		ai_assistant_upgrade_db();
	}
}
add_action( 'plugins_loaded', 'ai_assistant_check_db_version' );

/**
 * Deactivation hook.
 */
function ai_assistant_deactivate() {
	// Flush rewrite rules etc. if needed in future.
}
register_deactivation_hook( __FILE__, 'ai_assistant_deactivate' );

/**
 * Enqueue frontend chatbot assets on non-admin pages.
 */
function ai_assistant_enqueue_frontend_assets() {
	if ( is_admin() ) {
		return;
	}

	// Admin-only mode: hide chatbot from non-admins when the setting is enabled.
	if ( get_option( 'ai_assistant_admin_only', 0 ) && ! current_user_can( 'manage_options' ) ) {
		return;
	}

	wp_enqueue_style(
		'ai-assistant-chatbot',
		AI_ASSISTANT_PLUGIN_URL . 'assets/chatbot.css',
		array(),
		AI_ASSISTANT_VERSION
	);

	wp_enqueue_script(
		'ai-assistant-chatbot',
		AI_ASSISTANT_PLUGIN_URL . 'assets/chatbot.js',
		array(),
		AI_ASSISTANT_VERSION,
		true // Load in footer.
	);

	// Fetch appearance settings
	$font  = esc_html( get_option( 'ai_assistant_font', '' ) );
	$color = esc_attr( get_option( 'ai_assistant_color', '#4f46e5' ) );
	
	$questions_raw = get_option( 'ai_assistant_helper_questions', '' );
	$questions = array_filter( array_map( 'trim', explode( "\n", $questions_raw ) ) );

	// Add inline CSS variables for the color and font to override defaults in chatbot.css.
	$inline_css = ':root { ';
	if ( ! empty( $color ) ) {
		// Provide a generic darker/lighter state based on the primary color for buttons
		$inline_css .= '--ai-primary: ' . $color . '; ';
		$inline_css .= '--ai-primary-border: ' . $color . '; ';
	}
	if ( ! empty( $font ) ) {
		$inline_css .= '--ai-font: ' . $font . '; ';
	}
	$inline_css .= '}';
	wp_add_inline_style( 'ai-assistant-chatbot', $inline_css );

	// Pass REST URL, nonce, and all visual config payload to JS.
	wp_localize_script(
		'ai-assistant-chatbot',
		'aiAssistantConfig',
		array(
			'restUrl'        => esc_url_raw( rest_url( 'ai-assistant/v1/chat' ) ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'botName'        => esc_html( get_option( 'ai_assistant_bot_name', 'AI Assistant' ) ),
			'greeting'       => esc_html( get_option( 'ai_assistant_greeting', 'Hi! How can I help you today?' ) ),
			'botPic'         => esc_url_raw( get_option( 'ai_assistant_bot_pic', '' ) ),
			'removeBranding' => (bool) get_option( 'ai_assistant_remove_branding', 0 ),
			'helperQuestions'=> array_values( $questions ),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'ai_assistant_enqueue_frontend_assets' );
