<?php
/**
 * Admin Settings Page – API key, model selection, and misc options.
 *
 * @package AI_Website_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register settings page under Settings menu.
 */
function ai_assistant_settings_menu() {
	add_options_page(
		__( 'AI Website Assistant', 'ai-website-assistant' ),
		__( 'AI Assistant', 'ai-website-assistant' ),
		'manage_options',
		'ai-assistant-settings',
		'ai_assistant_render_settings_page'
	);
}
add_action( 'admin_menu', 'ai_assistant_settings_menu' );

/**
 * Register settings fields via the Settings API.
 */
function ai_assistant_register_settings() {
	// API Key.
	register_setting( 'ai_assistant_settings', 'ai_assistant_api_key', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'default'           => '',
	) );

	// Model.
	register_setting( 'ai_assistant_settings', 'ai_assistant_model', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'default'           => 'gemini-2.0-flash',
	) );

	// Bot display name.
	register_setting( 'ai_assistant_settings', 'ai_assistant_bot_name', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'default'           => 'AI Assistant',
	) );

	// Greeting message.
	register_setting( 'ai_assistant_settings', 'ai_assistant_greeting', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'default'           => 'Hi! How can I help you today?',
	) );

	// Rate limit.
	register_setting( 'ai_assistant_settings', 'ai_assistant_rate_limit', array(
		'type'              => 'integer',
		'sanitize_callback' => 'absint',
		'default'           => 20,
	) );

	// ACF Indexing.
	register_setting( 'ai_assistant_settings', 'ai_assistant_index_acf', array(
		'type'              => 'integer',
		'sanitize_callback' => 'absint',
		'default'           => 0, // Disabled by default.
	) );

	// Crawl Pages.
	register_setting( 'ai_assistant_settings', 'ai_assistant_crawl_pages', array(
		'type'              => 'integer',
		'sanitize_callback' => 'absint',
		'default'           => 1,
	) );

	// Crawl Posts.
	register_setting( 'ai_assistant_settings', 'ai_assistant_crawl_posts', array(
		'type'              => 'integer',
		'sanitize_callback' => 'absint',
		'default'           => 1,
	) );

	// Crawl Custom Post Types.
	register_setting( 'ai_assistant_settings', 'ai_assistant_crawl_cpts', array(
		'type'              => 'integer',
		'sanitize_callback' => 'absint',
		'default'           => 1,
	) );

	// Crawl WooCommerce Products.
	register_setting( 'ai_assistant_settings', 'ai_assistant_crawl_products', array(
		'type'              => 'integer',
		'sanitize_callback' => 'absint',
		'default'           => 0,
	) );

	// URL Include Patterns.
	register_setting( 'ai_assistant_settings', 'ai_assistant_include_patterns', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_textarea_field',
		'default'           => '',
	) );

	// URL Exclude Patterns.
	register_setting( 'ai_assistant_settings', 'ai_assistant_exclude_patterns', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_textarea_field',
		'default'           => "/cart/\n/checkout/\n/account/\n/wp-admin/",
	) );

	// --- Sections ---
	add_settings_section(
		'ai_assistant_api_section',
		__( 'API Configuration', 'ai-website-assistant' ),
		'__return_false',
		'ai-assistant-settings'
	);

	add_settings_section(
		'ai_assistant_widget_section',
		__( 'Chat Widget Configuration', 'ai-website-assistant' ),
		'__return_false',
		'ai-assistant-settings'
	);

	add_settings_section(
		'ai_assistant_indexing_section',
		__( 'Crawler & Indexing Configuration', 'ai-website-assistant' ),
		'__return_false',
		'ai-assistant-settings'
	);

	// --- Fields ---
	add_settings_field(
		'ai_assistant_api_key',
		__( 'Google Gemini API Key', 'ai-website-assistant' ),
		'ai_assistant_field_api_key',
		'ai-assistant-settings',
		'ai_assistant_api_section'
	);

	add_settings_field(
		'ai_assistant_model',
		__( 'Gemini Model', 'ai-website-assistant' ),
		'ai_assistant_field_model',
		'ai-assistant-settings',
		'ai_assistant_api_section'
	);

	add_settings_field(
		'ai_assistant_rate_limit',
		__( 'Rate Limit (requests per minute per IP)', 'ai-website-assistant' ),
		'ai_assistant_field_rate_limit',
		'ai-assistant-settings',
		'ai_assistant_api_section'
	);

	add_settings_field(
		'ai_assistant_bot_name',
		__( 'Bot Name', 'ai-website-assistant' ),
		'ai_assistant_field_bot_name',
		'ai-assistant-settings',
		'ai_assistant_widget_section'
	);

	add_settings_field(
		'ai_assistant_greeting',
		__( 'Greeting Message', 'ai-website-assistant' ),
		'ai_assistant_field_greeting',
		'ai-assistant-settings',
		'ai_assistant_widget_section'
	);

	add_settings_field(
		'ai_assistant_index_acf',
		__( 'Advance Custom Fields (ACF)', 'ai-website-assistant' ),
		'ai_assistant_field_index_acf',
		'ai-assistant-settings',
		'ai_assistant_indexing_section'
	);

	add_settings_field(
		'ai_assistant_crawl_content_types',
		__( 'Content Types to Crawl', 'ai-website-assistant' ),
		'ai_assistant_field_crawl_content_types',
		'ai-assistant-settings',
		'ai_assistant_indexing_section'
	);

	add_settings_field(
		'ai_assistant_include_patterns',
		__( 'URL Include Patterns', 'ai-website-assistant' ),
		'ai_assistant_field_include_patterns',
		'ai-assistant-settings',
		'ai_assistant_indexing_section'
	);

	add_settings_field(
		'ai_assistant_exclude_patterns',
		__( 'URL Exclude Patterns', 'ai-website-assistant' ),
		'ai_assistant_field_exclude_patterns',
		'ai-assistant-settings',
		'ai_assistant_indexing_section'
	);
}
add_action( 'admin_init', 'ai_assistant_register_settings' );

// ── Field Renderers ──────────────────────────────────────────────────────────

function ai_assistant_field_api_key() {
	$val = esc_attr( get_option( 'ai_assistant_api_key', '' ) );
	echo '<input type="password" id="ai_assistant_api_key" name="ai_assistant_api_key" value="' . $val . '" class="regular-text" autocomplete="off" />';
	echo '<p class="description">' . wp_kses_post( sprintf(
		/* translators: link URL */
		__( 'Get your API key from <a href="%s" target="_blank" rel="noopener">Google AI Studio</a>.', 'ai-website-assistant' ),
		'https://aistudio.google.com/app/apikey'
	) ) . '</p>';
}

function ai_assistant_field_model() {
	$current  = esc_js( get_option( 'ai_assistant_model', 'gemini-2.0-flash' ) );
	$rest_url = esc_url_raw( rest_url( 'ai-assistant/v1/models' ) );
	$nonce    = wp_create_nonce( 'wp_rest' );
	?>
	<select id="ai_assistant_model" name="ai_assistant_model">
		<option value="<?php echo esc_attr( get_option( 'ai_assistant_model', 'gemini-2.0-flash' ) ); ?>" selected>
			⏳ Loading available models…
		</option>
	</select>
	<button type="button" id="ai-refresh-models" class="button" style="margin-left:8px;">↻ Refresh</button>
	<span id="ai-models-status" style="margin-left:8px;font-size:12px;color:#666;"></span>

	<script>
	(function() {
		var select   = document.getElementById('ai_assistant_model');
		var refresh  = document.getElementById('ai-refresh-models');
		var status   = document.getElementById('ai-models-status');
		var current  = <?php echo wp_json_encode( get_option( 'ai_assistant_model', 'gemini-2.0-flash' ) ); ?>;
		var restUrl  = <?php echo wp_json_encode( $rest_url ); ?>;
		var nonce    = <?php echo wp_json_encode( $nonce ); ?>;

		function loadModels() {
			select.disabled = true;
			status.textContent = 'Fetching models from Gemini API…';

			fetch(restUrl, {
				headers: { 'X-WP-Nonce': nonce }
			})
			.then(function(r) { return r.json(); })
			.then(function(data) {
				if (!data.models || !data.models.length) {
					status.textContent = '⚠️ No models returned. Check your API key.';
					select.disabled = false;
					return;
				}

				// Clear and rebuild options.
				select.innerHTML = '';
				data.models.forEach(function(m) {
					var opt = document.createElement('option');
					opt.value = m.id;
					opt.textContent = m.label + ' (' + m.id + ')';
					if (m.id === current) { opt.selected = true; }
					select.appendChild(opt);
				});

				// If saved model not in list, add it with a warning.
				var found = data.models.some(function(m) { return m.id === current; });
				if (!found && current) {
					var opt = document.createElement('option');
					opt.value = current;
					opt.textContent = '⚠️ ' + current + ' (may be unavailable)';
					opt.selected = true;
					select.insertBefore(opt, select.firstChild);
				}

				status.textContent = '✅ ' + data.models.length + ' models loaded.';
				select.disabled = false;
			})
			.catch(function(err) {
				status.textContent = '❌ Failed to load models: ' + err.message;
				select.disabled = false;
			});
		}

		loadModels();
		refresh.addEventListener('click', loadModels);
	})();
	</script>
	<?php
}

function ai_assistant_field_rate_limit() {
	$val = absint( get_option( 'ai_assistant_rate_limit', 20 ) );
	echo '<input type="number" id="ai_assistant_rate_limit" name="ai_assistant_rate_limit" value="' . $val . '" min="1" max="200" class="small-text" />';
	echo '<p class="description">' . esc_html__( 'Max chatbot requests per IP per minute.', 'ai-website-assistant' ) . '</p>';
}

function ai_assistant_field_bot_name() {
	$val = esc_attr( get_option( 'ai_assistant_bot_name', 'AI Assistant' ) );
	echo '<input type="text" id="ai_assistant_bot_name" name="ai_assistant_bot_name" value="' . $val . '" class="regular-text" />';
}

function ai_assistant_field_greeting() {
	$val = esc_attr( get_option( 'ai_assistant_greeting', 'Hi! How can I help you today?' ) );
	echo '<input type="text" id="ai_assistant_greeting" name="ai_assistant_greeting" value="' . $val . '" class="large-text" />';
	echo '<p class="description">' . esc_html__( 'First message shown when the chat opens.', 'ai-website-assistant' ) . '</p>';
}

function ai_assistant_field_index_acf() {
	$val = get_option( 'ai_assistant_index_acf', 0 );
	echo '<label><input type="checkbox" name="ai_assistant_index_acf" value="1" ' . checked( 1, $val, false ) . ' /> ' . esc_html__( 'Enable checking Advanced Custom Fields (ACF/ACF Pro) content when scanning the website.', 'ai-website-assistant' ) . '</label>';
	echo '<p class="description">' . esc_html__( 'If enabled, text from repeaters, flexible layouts, and standard ACF fields will be combined with exactly matched page text.', 'ai-website-assistant' ) . '</p>';
}

function ai_assistant_field_crawl_content_types() {
	$pages    = get_option( 'ai_assistant_crawl_pages', 1 );
	$posts    = get_option( 'ai_assistant_crawl_posts', 1 );
	$cpts     = get_option( 'ai_assistant_crawl_cpts', 1 );
	$products = get_option( 'ai_assistant_crawl_products', 0 );

	echo '<fieldset>';
	echo '<label><input type="checkbox" name="ai_assistant_crawl_pages" value="1" ' . checked( 1, $pages, false ) . ' /> ' . esc_html__( 'Pages', 'ai-website-assistant' ) . '</label><br>';
	echo '<label><input type="checkbox" name="ai_assistant_crawl_posts" value="1" ' . checked( 1, $posts, false ) . ' /> ' . esc_html__( 'Posts', 'ai-website-assistant' ) . '</label><br>';
	echo '<label><input type="checkbox" name="ai_assistant_crawl_cpts" value="1" ' . checked( 1, $cpts, false ) . ' /> ' . esc_html__( 'Custom Post Types (excluding Products)', 'ai-website-assistant' ) . '</label><br>';
	echo '<label><input type="checkbox" name="ai_assistant_crawl_products" value="1" ' . checked( 1, $products, false ) . ' /> ' . esc_html__( 'WooCommerce Products', 'ai-website-assistant' ) . '</label>';
	echo '</fieldset>';
	echo '<p class="description">' . esc_html__( 'Select which post types the crawler should fetch if it falls back to database lookup.', 'ai-website-assistant' ) . '</p>';
}

function ai_assistant_field_include_patterns() {
	$val = esc_textarea( get_option( 'ai_assistant_include_patterns', '' ) );
	echo '<textarea id="ai_assistant_include_patterns" name="ai_assistant_include_patterns" rows="4" cols="50" class="large-text code">' . $val . '</textarea>';
	echo '<p class="description">' . esc_html__( 'Only crawl URLs containing these exact strings (one per line). Leave empty to scan everything discoverable.', 'ai-website-assistant' ) . '</p>';
}

function ai_assistant_field_exclude_patterns() {
	$val = esc_textarea( get_option( 'ai_assistant_exclude_patterns', "/cart/\n/checkout/\n/account/\n/wp-admin/" ) );
	echo '<textarea id="ai_assistant_exclude_patterns" name="ai_assistant_exclude_patterns" rows="4" cols="50" class="large-text code">' . $val . '</textarea>';
	echo '<p class="description">' . esc_html__( 'Do not crawl URLs containing these exact strings (one per line). Overrides include rules.', 'ai-website-assistant' ) . '</p>';
}

// ── Main Settings Page Renderer ──────────────────────────────────────────────

function ai_assistant_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification
	?>
	<div class="wrap ai-assistant-admin">
		<h1>
			<span class="dashicons dashicons-format-chat" style="font-size:32px;line-height:1;margin-right:8px;color:#4f46e5;"></span>
			<?php esc_html_e( 'AI Website Assistant', 'ai-website-assistant' ); ?>
		</h1>

		<nav class="nav-tab-wrapper">
			<a href="?page=ai-assistant-settings&tab=settings" class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>">
				<?php esc_html_e( 'Settings', 'ai-website-assistant' ); ?>
			</a>
			<a href="?page=ai-assistant-settings&tab=training" class="nav-tab <?php echo $tab === 'training' ? 'nav-tab-active' : ''; ?>">
				<?php esc_html_e( 'Training', 'ai-website-assistant' ); ?>
			</a>
		</nav>

		<?php if ( 'settings' === $tab ) : ?>
		<form method="post" action="options.php">
			<?php
			settings_fields( 'ai_assistant_settings' );
			do_settings_sections( 'ai-assistant-settings' );
			submit_button( __( 'Save Settings', 'ai-website-assistant' ) );
			?>
		</form>
		<?php elseif ( 'training' === $tab ) :
			// Delegate to training page renderer.
			ai_assistant_render_training_tab();
		endif; ?>
	</div>
	<?php
}
