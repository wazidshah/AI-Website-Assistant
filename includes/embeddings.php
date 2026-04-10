<?php
/**
 * Embeddings – Gemini text-embedding-004 API client + cosine similarity helpers.
 *
 * @package AI_Website_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Embedding model – text-embedding-004 is deprecated, using gemini-embedding-001 which produces 768-dim vectors.
define( 'AI_ASSISTANT_EMBED_MODEL', 'gemini-embedding-001' );

/**
 * Generate a vector embedding for a piece of text using the Gemini Embedding API.
 *
 * Results are cached for 24 hours via WordPress transients so repeated calls for
 * the same text (e.g. during re-training) don't consume extra API quota.
 *
 * @param string $text      The text to embed.
 * @param bool   $use_cache Whether to use/store cached results. Default true.
 * @return float[]|WP_Error 768-element float array or WP_Error on failure.
 */
function ai_assistant_get_embedding( $text, $use_cache = true, $task_type = 'RETRIEVAL_DOCUMENT' ) {
	$api_key = get_option( 'ai_assistant_api_key', '' );

	if ( empty( $api_key ) ) {
		return new WP_Error( 'no_api_key', __( 'Gemini API key is not configured.', 'ai-website-assistant' ) );
	}

	// Normalise text before caching.
	$text = trim( $text );
	if ( empty( $text ) ) {
		return new WP_Error( 'empty_text', __( 'Cannot embed empty text.', 'ai-website-assistant' ) );
	}

	// ── Transient cache ────────────────────────────────────────────────────────
	// Include task_type in cache key – RETRIEVAL_QUERY and RETRIEVAL_DOCUMENT
	// produce different vectors for the same text (asymmetric embeddings).
	$cache_key    = 'ai_embed_' . md5( $text . $task_type );
	$cached_embed = $use_cache ? get_transient( $cache_key ) : false;

	if ( false !== $cached_embed ) {
		return $cached_embed;
	}

	// ── API call ────────────────────────────────────────────────────────────────
	// gemini-embedding-001 often requires v1beta for embedContent functionality.
	$endpoint = sprintf(
		'https://generativelanguage.googleapis.com/v1beta/models/%s:embedContent?key=%s',
		AI_ASSISTANT_EMBED_MODEL,
		rawurlencode( $api_key )
	);

	$body = wp_json_encode(
		array(
			'model'   => 'models/' . AI_ASSISTANT_EMBED_MODEL,
			'content' => array(
				'parts' => array(
					array( 'text' => $text ),
				),
			),
			'taskType' => $task_type,
		)
	);

	$response = wp_remote_post(
		$endpoint,
		array(
			'headers'     => array( 'Content-Type' => 'application/json' ),
			'body'        => $body,
			'timeout'     => 20,
			'data_format' => 'body',
		)
	);

	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'embed_request_failed', $response->get_error_message() );
	}

	$status   = wp_remote_retrieve_response_code( $response );
	$body_raw = wp_remote_retrieve_body( $response );

	if ( 200 !== (int) $status ) {
		$decoded = json_decode( $body_raw, true );
		$message = isset( $decoded['error']['message'] )
			? $decoded['error']['message']
			: "Embedding API error {$status}";
		return new WP_Error( 'embed_api_error', $message );
	}

	$data   = json_decode( $body_raw, true );
	$values = isset( $data['embedding']['values'] ) ? $data['embedding']['values'] : null;

	if ( ! is_array( $values ) || empty( $values ) ) {
		return new WP_Error( 'embed_empty', __( 'Gemini returned an empty embedding.', 'ai-website-assistant' ) );
	}

	// Cast all values to float.
	$vector = array_map( 'floatval', $values );

	// Cache for 24 hours.
	if ( $use_cache ) {
		set_transient( $cache_key, $vector, DAY_IN_SECONDS );
	}

	return $vector;
}

/**
 * Compute cosine similarity between two equal-length float vectors.
 *
 * Returns a value between 0.0 (completely dissimilar) and 1.0 (identical).
 *
 * @param float[] $a First vector.
 * @param float[] $b Second vector.
 * @return float Cosine similarity, or 0.0 on error.
 */
function ai_assistant_cosine_similarity( array $a, array $b ) {
	$count = count( $a );

	if ( $count === 0 || $count !== count( $b ) ) {
		return 0.0;
	}

	$dot_product  = 0.0;
	$magnitude_a  = 0.0;
	$magnitude_b  = 0.0;

	for ( $i = 0; $i < $count; $i++ ) {
		$dot_product += $a[ $i ] * $b[ $i ];
		$magnitude_a += $a[ $i ] * $a[ $i ];
		$magnitude_b += $b[ $i ] * $b[ $i ];
	}

	$magnitude_a = sqrt( $magnitude_a );
	$magnitude_b = sqrt( $magnitude_b );

	if ( $magnitude_a === 0.0 || $magnitude_b === 0.0 ) {
		return 0.0;
	}

	return (float) ( $dot_product / ( $magnitude_a * $magnitude_b ) );
}

/**
 * Get an embedding with caching, returning null on any failure (for training use).
 *
 * @param string $text Text to embed.
 * @return float[]|null Vector or null on failure.
 */
function ai_assistant_embed_or_null( $text, $task_type = 'RETRIEVAL_DOCUMENT' ) {
	$result = ai_assistant_get_embedding( $text, true, $task_type );
	if ( is_wp_error( $result ) ) {
		return null;
	}
	return $result;
}
