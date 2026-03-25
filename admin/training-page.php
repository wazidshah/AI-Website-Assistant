<?php
/**
 * Training Page – admin UI to trigger website content training.
 *
 * @package AI_Website_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the Training tab content (called from settings-page.php).
 */
function ai_assistant_render_training_tab() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$last_trained  = get_option( 'ai_assistant_last_trained', 0 );
	$chunk_count   = (int) get_option( 'ai_assistant_chunk_count', 0 );
	$train_nonce   = wp_create_nonce( 'ai_assistant_train' );
	$rest_url      = esc_url_raw( rest_url( 'ai-assistant/v1/train' ) );
	$wp_rest_nonce = wp_create_nonce( 'wp_rest' );
	?>

	<div class="ai-assistant-training-wrap" style="max-width:700px;margin-top:24px;">

		<div class="ai-assistant-card" style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px 28px;margin-bottom:24px;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'Knowledge Base Status', 'ai-website-assistant' ); ?></h2>

			<table class="widefat" style="border:none;">
				<tbody>
					<tr>
						<td><strong><?php esc_html_e( 'Total Chunks Stored', 'ai-website-assistant' ); ?></strong></td>
						<td id="ai-chunk-count">
							<?php echo esc_html( number_format_i18n( $chunk_count ) ); ?>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Last Trained', 'ai-website-assistant' ); ?></strong></td>
						<td>
							<?php
							if ( $last_trained ) {
								echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_trained ) );
							} else {
								esc_html_e( 'Never', 'ai-website-assistant' );
							}
							?>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<div class="ai-assistant-card" style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px 28px;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'Train Website Content', 'ai-website-assistant' ); ?></h2>
			<p><?php esc_html_e( 'Clicking "Train Website" will crawl all your published pages and posts, split them into searchable chunks, and store them in the database. This may take a minute on large sites.', 'ai-website-assistant' ); ?></p>

			<div id="ai-training-status" style="display:none;padding:12px 16px;border-radius:6px;margin-bottom:16px;"></div>

			<div id="ai-training-progress" style="display:none;margin-bottom:16px;">
				<div style="background:#e5e7eb;border-radius:4px;height:8px;overflow:hidden;">
					<div id="ai-training-bar" style="background:#4f46e5;height:100%;width:0%;transition:width 0.5s ease;border-radius:4px;"></div>
				</div>
				<p id="ai-training-progress-text" style="margin:8px 0 0;font-size:13px;color:#555;"></p>
			</div>

			<button id="ai-train-btn" class="button button-primary button-large">
				<span class="dashicons dashicons-update" style="margin-top:4px;margin-right:4px;"></span>
				<?php esc_html_e( 'Train Website', 'ai-website-assistant' ); ?>
			</button>
		</div>

	</div>

	<script>
	(function() {
		var btn        = document.getElementById('ai-train-btn');
		var statusEl   = document.getElementById('ai-training-status');
		var progressEl = document.getElementById('ai-training-progress');
		var barEl      = document.getElementById('ai-training-bar');
		var progressTxt= document.getElementById('ai-training-progress-text');
		var chunkCount = document.getElementById('ai-chunk-count');

		var restUrl  = <?php echo wp_json_encode( $rest_url ); ?>;
		var nonce    = <?php echo wp_json_encode( $wp_rest_nonce ); ?>;

		function setStatus(msg, type) {
			var colors = { success: '#d1fae5', error: '#fee2e2', info: '#eff6ff', warning: '#fef3c7' };
			var border = { success: '#10b981', error: '#ef4444', info: '#3b82f6', warning: '#f59e0b' };
			statusEl.style.display  = 'block';
			statusEl.style.background = colors[type] || colors.info;
			statusEl.style.border   = '1px solid ' + (border[type] || border.info);
			statusEl.style.color    = '#111';
			statusEl.textContent    = msg;
		}

		async function processTraining() {
			btn.disabled = true;
			btn.textContent = '⏳ Discovering URLs…';
			statusEl.style.display = 'none';
			progressEl.style.display = 'block';
			barEl.style.width = '5%';
			progressTxt.textContent = 'Finding pages to index...';

			try {
				// 1. Discover URLs
				const discoverRes = await fetch(restUrl, {
					method : 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce'  : nonce
					},
					body: JSON.stringify({ action: 'discover' })
				});
				
				const discoverData = await discoverRes.json();
				
				if (!discoverData.success) {
					throw new Error(discoverData.message || 'Discovery failed.');
				}

				const urls = discoverData.urls || [];
				if (urls.length === 0) {
					btn.disabled = false;
					btn.textContent = '✓ Train Website';
					progressEl.style.display = 'none';
					setStatus('ℹ️ No URLs found to index based on current settings.', 'warning');
					return;
				}

				// 2. Process URLs sequentially
				let totalInserted = 0;
				let errors = 0;
				btn.textContent = '⏳ Crawling ' + urls.length + ' URLs…';

				for (let i = 0; i < urls.length; i++) {
					const url = urls[i];
					const pct = 5 + Math.floor((i / urls.length) * 95);
					barEl.style.width = pct + '%';
					progressTxt.textContent = `Processing (${i+1}/${urls.length}): ${url}`;

					try {
						const processRes = await fetch(restUrl, {
							method : 'POST',
							headers: {
								'Content-Type': 'application/json',
								'X-WP-Nonce'  : nonce
							},
							body: JSON.stringify({ action: 'process', url: url })
						});
						
						const processData = await processRes.json();
						
						if (processData.success) {
							totalInserted += processData.inserted || 0;
						} else {
							console.warn('Chunker error on ' + url + ':', processData.error);
							errors++;
						}
					} catch(e) {
						console.error('Fetch error on ' + url + ':', e);
						errors++;
					}
				}

				// Finalize
				btn.disabled = false;
				btn.textContent = '✓ Train Website';
				barEl.style.width = '100%';
				progressTxt.textContent = 'Indexing Complete!';
				
				// Update chunk count UI
				chunkCount.textContent = totalInserted.toLocaleString() + ' (This Run)';
				
				if (errors > 0) {
					setStatus('⚠️ Training finished with ' + errors + ' errors. See console. ' + totalInserted + ' chunks indexed.', 'warning');
				} else {
					setStatus('✅ Training complete! ' + totalInserted + ' chunks successfully indexed.', 'success');
				}

				setTimeout(function() { progressEl.style.display = 'none'; }, 3000);

			} catch (err) {
				btn.disabled = false;
				btn.textContent = 'Train Website';
				progressEl.style.display = 'none';
				setStatus('❌ Error: ' + err.message, 'error');
			}
		}

		btn.addEventListener('click', processTraining);
	})();
	</script>
	<?php
}
