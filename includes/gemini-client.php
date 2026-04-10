<?php
/**
 * Gemini API Client – sends prompts to Google Gemini and returns text responses.
 *
 * @package AI_Website_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Send a chat question to Gemini using RAG context and return the answer.
 *
 * @param string $question        The user's question.
 * @param string $context         Combined retrieved chunks as text.
 * @param string $model           Gemini model ID (optional, uses saved option).
 * @param array  $history         Optional conversation history array.
 * @return string|WP_Error  Answer string or WP_Error on failure.
 */
function ai_assistant_ask_gemini( $question, $context, $model = '', $history = array() ) {
	$api_key = get_option( 'ai_assistant_api_key', '' );

	if ( empty( $api_key ) ) {
		return new WP_Error( 'no_api_key', __( 'Gemini API key is not configured.', 'ai-website-assistant' ) );
	}

	if ( empty( $model ) ) {
		$model = get_option( 'ai_assistant_model', 'gemini-2.0-flash' );
	}

	// ── Check response cache ─────────────────────────────────────────────────
	$cache_key     = 'ai_assistant_' . md5( $question . $context . wp_json_encode( $history ) );
	$cached_answer = get_transient( $cache_key );
	if ( false !== $cached_answer ) {
		return $cached_answer;
	}

	// ── Build prompt ─────────────────────────────────────────────────────────
	$prompt = ai_assistant_build_prompt( $question, $context );

	// ── Build request ─────────────────────────────────────────────────────────
	$endpoint = sprintf(
		'https://generativelanguage.googleapis.com/v1/models/%s:generateContent?key=%s',
		rawurlencode( $model ),
		rawurlencode( $api_key )
	);

	$contents = array();
	
	if ( ! empty( $history ) && is_array( $history ) ) {
		foreach ( $history as $msg ) {
			if ( ! empty( $msg['role'] ) && ! empty( $msg['content'] ) ) {
				$role = ( $msg['role'] === 'model' ) ? 'model' : 'user';
				$contents[] = array(
					'role'  => $role,
					'parts' => array( array( 'text' => sanitize_text_field( $msg['content'] ) ) ),
				);
			}
		}
	}
	
	$contents[] = array(
		'role'  => 'user',
		'parts' => array( array( 'text' => $prompt ) ),
	);

	$body = wp_json_encode(
		array(
			'contents'         => $contents,
			'generationConfig' => array(
				'temperature'     => 0.4,
				'topP'            => 0.9,
				'maxOutputTokens' => 1024,
			),
			'safetySettings' => array(
				array(
					'category'  => 'HARM_CATEGORY_HATE_SPEECH',
					'threshold' => 'BLOCK_MEDIUM_AND_ABOVE',
				),
				array(
					'category'  => 'HARM_CATEGORY_DANGEROUS_CONTENT',
					'threshold' => 'BLOCK_MEDIUM_AND_ABOVE',
				),
			),
		)
	);

	$response = wp_remote_post(
		$endpoint,
		array(
			'headers'     => array( 'Content-Type' => 'application/json' ),
			'body'        => $body,
			'timeout'     => 30,
			'data_format' => 'body',
		)
	);

	// ── Handle errors ─────────────────────────────────────────────────────────
	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'gemini_request_failed', $response->get_error_message() );
	}

	$status_code = wp_remote_retrieve_response_code( $response );
	$body_raw    = wp_remote_retrieve_body( $response );

	if ( 200 !== (int) $status_code ) {
		$decoded = json_decode( $body_raw, true );
		$message = isset( $decoded['error']['message'] )
			? $decoded['error']['message']
			: sprintf( 'Gemini API returned status %d.', $status_code );
		return new WP_Error( 'gemini_api_error', $message );
	}

	// ── Parse response ────────────────────────────────────────────────────────
	$data = json_decode( $body_raw, true );

	$answer = ai_assistant_extract_gemini_text( $data );

	if ( empty( $answer ) ) {
		return new WP_Error( 'gemini_empty_response', __( 'Gemini returned an empty response.', 'ai-website-assistant' ) );
	}

	// ── Cache for 1 hour ──────────────────────────────────────────────────────
	set_transient( $cache_key, $answer, HOUR_IN_SECONDS );

	return $answer;
}

/**
 * Build the RAG prompt string.
 *
 * @param string $question The user's question.
 * @param string $context  Retrieved chunk context.
 * @return string Formatted prompt.
 */
function ai_assistant_build_prompt( $question, $context ) {
	$site_name = get_bloginfo( 'name' );

	// Use the custom system prompt from settings, or fall back to the hardcoded default.
	$saved_prompt = get_option( 'ai_assistant_system_prompt', '' );

	if ( ! empty( $saved_prompt ) ) {
		// Replace the {site_name} token with the actual site name.
		$system_prompt = str_replace( '{site_name}', $site_name, $saved_prompt );
	} else {
		$system_prompt = "You are a professional AI assistant representing the website '{$site_name}'.\n\n"
			. "Your core directives:\n"
			. "- Answer the user's questions clearly, accurately, and directly using ONLY the provided website context.\n"
			. "- If the user asks for specific details (like contact info, addresses, phone numbers, prices, or names), you MUST read out the exact details directly in your response. NEVER just provide a link and tell them to visit the page to find it themselves.\n"
			. "- You may provide a link to the relevant page ONLY as supplementary reading after you have fully answered their question.\n"
			. "- If the exact information is not available in the context below, politely say so and suggest they use the contact page.";
	}

	if ( empty( $context ) ) {
		return $system_prompt . "\n\nQuestion:\n{$question}";
	}

	return $system_prompt
		. "\n\nContext from website:\n{$context}\n\n"
		. "Question:\n{$question}\n\n"
		. "Answer:";
}

/**
 * Extract the text answer from a Gemini API response array.
 *
 * @param array $data Decoded JSON response.
 * @return string Answer text, or empty string on failure.
 */
function ai_assistant_extract_gemini_text( $data ) {
	if ( empty( $data['candidates'] ) || ! is_array( $data['candidates'] ) ) {
		return '';
	}

	foreach ( $data['candidates'] as $candidate ) {
		if ( isset( $candidate['content']['parts'] ) && is_array( $candidate['content']['parts'] ) ) {
			foreach ( $candidate['content']['parts'] as $part ) {
				if ( ! empty( $part['text'] ) ) {
					return trim( $part['text'] );
				}
			}
		}
	}

	return '';
}

/**
 * Return a list of supported Gemini model IDs.
 *
 * @return array Associative array: model_id => display_label.
 */
function ai_assistant_get_available_models() {
	return array(
		// Stable – available until June 2026
		'gemini-2.0-flash'                   => 'Gemini 2.0 Flash ✅ (Recommended – Fast & Free)',
		// Gemini 2.5
		'gemini-2.5-pro'                      => 'Gemini 2.5 Pro (Advanced reasoning)',
		// Gemini 3 Previews (latest, March 2026)
		'gemini-3-flash'                      => 'Gemini 3 Flash Preview (Pro-level at Flash speed)',
		'gemini-3.1-flash-lite'               => 'Gemini 3.1 Flash-Lite Preview (Fastest & cheapest)',
		'gemini-3.1-pro'                      => 'Gemini 3.1 Pro Preview (Most capable)',
	);
}
