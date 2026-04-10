<?php
/**
 * REST API – registers the /wp-json/ai-assistant/v1/chat endpoint.
 *
 * @package AI_Website_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the REST routes.
 */
function ai_assistant_register_rest_routes() {
	register_rest_route(
		'ai-assistant/v1',
		'/chat',
		array(
			'methods'             => WP_REST_Server::CREATABLE, // POST
			'callback'            => 'ai_assistant_handle_chat',
			'permission_callback' => '__return_true', // Public endpoint.
			'args'                => array(
				'question' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => function( $value ) {
						return is_string( $value ) && mb_strlen( trim( $value ) ) >= 2;
					},
					'description'       => 'The user question to answer.',
				),
				'history' => array(
					'required'          => false,
					'type'              => 'array',
					'description'       => 'Previously sent messages to maintain conversation context.',
					'default'           => array(),
				),
			),
		)
	);

	// Training endpoint (admin-only).
	register_rest_route(
		'ai-assistant/v1',
		'/train',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'ai_assistant_handle_training',
			'permission_callback' => function() {
				return current_user_can( 'manage_options' );
			},
		)
	);

	// List available Gemini models (admin-only).
	register_rest_route(
		'ai-assistant/v1',
		'/models',
		array(
			'methods'             => WP_REST_Server::READABLE, // GET
			'callback'            => 'ai_assistant_handle_list_models',
			'permission_callback' => function() {
				return current_user_can( 'manage_options' );
			},
		)
	);
}
add_action( 'rest_api_init', 'ai_assistant_register_rest_routes' );

/**
 * Handle POST /wp-json/ai-assistant/v1/chat
 *
 * @param WP_REST_Request $request Incoming request object.
 * @return WP_REST_Response|WP_Error
 */
function ai_assistant_handle_chat( WP_REST_Request $request ) {
	$question = $request->get_param( 'question' );
	$question = sanitize_text_field( $question );
	$history  = $request->get_param( 'history' );
	if ( ! is_array( $history ) ) {
		$history = array();
	}

	if ( empty( $question ) ) {
		return new WP_Error(
			'empty_question',
			__( 'Please provide a question.', 'ai-website-assistant' ),
			array( 'status' => 400 )
		);
	}

	// ── Rate limiting (simple transient-based) ────────────────────────────────
	$ip          = ai_assistant_get_client_ip();
	$rate_key    = 'ai_assist_rate_' . md5( $ip );
	$rate_count  = (int) get_transient( $rate_key );

	$rate_limit = (int) get_option( 'ai_assistant_rate_limit', 20 );

	if ( $rate_count >= $rate_limit ) {
		return new WP_Error(
			'rate_limited',
			__( 'Too many requests. Please wait a moment before trying again.', 'ai-website-assistant' ),
			array( 'status' => 429 )
		);
	}

	// Increment rate counter (window: 1 minute).
	if ( 0 === $rate_count ) {
		set_transient( $rate_key, 1, MINUTE_IN_SECONDS );
	} else {
		set_transient( $rate_key, $rate_count + 1, MINUTE_IN_SECONDS );
	}

	// ── Search knowledge base ─────────────────────────────────────────────────
	$chunks  = ai_assistant_search_chunks( $question, 10, 20000 );
	$context = ai_assistant_build_context( $chunks );

	// ── Call Gemini ───────────────────────────────────────────────────────────
	$answer = ai_assistant_ask_gemini( $question, $context, '', $history );

	if ( is_wp_error( $answer ) ) {
		$error_code = $answer->get_error_code();

		// Translate internal errors into HTTP status codes.
		$status_map = array(
			'no_api_key'           => 503,
			'gemini_request_failed'=> 502,
			'gemini_api_error'     => 502,
			'gemini_empty_response'=> 500,
		);

		$http_status = isset( $status_map[ $error_code ] ) ? $status_map[ $error_code ] : 500;

		return new WP_Error(
			$error_code,
			$answer->get_error_message(),
			array( 'status' => $http_status )
		);
	}

	// ── Build source attribution ──────────────────────────────────────────────
	$sources = array();
	foreach ( $chunks as $chunk ) {
		if ( ! empty( $chunk->url ) ) {
			$source_data = array(
				'title' => ! empty( $chunk->page_title ) ? $chunk->page_title : $chunk->url,
				'url'   => esc_url( $chunk->url ),
			);
			// Include semantic similarity score if it was calculated.
			if ( isset( $chunk->similarity_score ) ) {
				$source_data['similarity'] = round( $chunk->similarity_score, 3 );
			}
			$sources[] = $source_data;
		}
	}
	// De-duplicate sources.
	$sources = array_values(
		array_unique( $sources, SORT_REGULAR )
	);

	return rest_ensure_response(
		array(
			'answer'  => wp_kses( $answer, array() ), // Strip any HTML from Gemini output.
			'sources' => $sources,
		)
	);
}

/**
 * Handle POST /wp-json/ai-assistant/v1/train  (admin only).
 * Handles the new AJAX batched approach: expects a single URL or 'discover'.
 *
 * @param WP_REST_Request $request Incoming request.
 * @return WP_REST_Response
 */
function ai_assistant_handle_training( WP_REST_Request $request ) {
	// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Training requests may process many pages; extended execution time is required.
	set_time_limit( 300 );

	$action = $request->get_param( 'action' );
	
	if ( 'discover' === $action ) {
		// Clear existing DB chunks to start fresh.
		ai_assistant_clear_training_data();
		
		$urls = ai_assistant_discover_urls();
		return rest_ensure_response( array(
			'success' => true,
			'urls'    => $urls,
			'message' => count( $urls ) . ' URLs discovered.',
		) );
	}
	
	$url = $request->get_param( 'url' );
	if ( empty( $url ) ) {
		return new WP_Error( 'missing_url', 'No URL provided.', array( 'status' => 400 ) );
	}

	$result = ai_assistant_train_single_url( $url );
	
	return rest_ensure_response( array(
		'success'  => $result['success'],
		'inserted' => $result['inserted'],
		'error'    => $result['error'],
	) );
}

/**
 * Handle GET /wp-json/ai-assistant/v1/models (admin only).
 * Proxies the Gemini ListModels API and returns models that support generateContent.
 *
 * @return WP_REST_Response|WP_Error
 */
function ai_assistant_handle_list_models() {
	$api_key = get_option( 'ai_assistant_api_key', '' );

	if ( empty( $api_key ) ) {
		return new WP_Error(
			'no_api_key',
			__( 'Gemini API key is not configured.', 'ai-website-assistant' ),
			array( 'status' => 503 )
		);
	}

	// The ListModels endpoint uses v1beta (listing is not in v1).
	$url = 'https://generativelanguage.googleapis.com/v1beta/models?key=' . rawurlencode( $api_key ) . '&pageSize=100';

	$response = wp_remote_get( $url, array( 'timeout' => 15 ) );

	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'list_models_failed', $response->get_error_message(), array( 'status' => 502 ) );
	}

	$status = wp_remote_retrieve_response_code( $response );
	$body   = wp_remote_retrieve_body( $response );

	if ( 200 !== (int) $status ) {
		$decoded = json_decode( $body, true );
		$message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : "API error {$status}";
		return new WP_Error( 'list_models_api_error', $message, array( 'status' => 502 ) );
	}

	$data   = json_decode( $body, true );
	$models = isset( $data['models'] ) ? $data['models'] : array();

	// Keep only models that support generateContent and are not embedding/vision-only models.
	$filtered = array();
	foreach ( $models as $m ) {
		$name    = isset( $m['name'] ) ? $m['name'] : '';
		$methods = isset( $m['supportedGenerationMethods'] ) ? $m['supportedGenerationMethods'] : array();

		if ( ! in_array( 'generateContent', $methods, true ) ) {
			continue;
		}

		// Strip the "models/" prefix to get the bare model ID.
		$model_id = str_replace( 'models/', '', $name );

		// Skip embedding, vision-only, tts, or image generation models.
		$skip_keywords = array( 'embedding', 'aqa', 'tts', 'imagen', 'veo', 'lyria' );
		$skip = false;
		foreach ( $skip_keywords as $kw ) {
			if ( false !== strpos( strtolower( $model_id ), $kw ) ) {
				$skip = true;
				break;
			}
		}
		if ( $skip ) {
			continue;
		}

		$display = isset( $m['displayName'] ) ? $m['displayName'] : $model_id;

		$filtered[] = array(
			'id'    => sanitize_text_field( $model_id ),
			'label' => sanitize_text_field( $display ),
		);
	}

	return rest_ensure_response( array( 'models' => $filtered ) );
}

function ai_assistant_get_client_ip() {
	$headers = array(
		'HTTP_CF_CONNECTING_IP', // Cloudflare.
		'HTTP_X_REAL_IP',
		'HTTP_CLIENT_IP',
		'REMOTE_ADDR',
	);

	foreach ( $headers as $header ) {
		if ( ! empty( $_SERVER[ $header ] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
		}
	}

	return '0.0.0.0';
}
