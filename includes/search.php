<?php
/**
 * Search – keyword-based search over stored content chunks.
 *
 * @package AI_Website_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Search the chunks table for the most relevant results for a given question.
 *
 * Strategy:
 *  1. Semantic Search (Gemini text-embedding-004 + Cosine Similarity).
 *  2. Fallback: FULLTEXT/LIKE keyword search if API fails or embeddings don't exist yet.
 *
 * @param string $question    The user's question.
 * @param int    $top_n       Number of top chunks to return (default 10).
 * @param int    $max_context Maximum total characters across all chunks (default 20000).
 * @return array Array of matching chunk objects: { id, content, url, page_title }.
 */
function ai_assistant_search_chunks( $question, $top_n = 10, $max_context = 20000 ) {
	// First try semantic search (if API working and embeddings exist).
	$semantic_results = ai_assistant_semantic_search( $question, $top_n, $max_context );
	
	if ( ! empty( $semantic_results ) ) {
		return $semantic_results;
	}

	// ── Fallback: Keyword Search (Pass 1: FULLTEXT, Pass 2: LIKE) ────────────
	global $wpdb;
	$table_name = $wpdb->prefix . AI_ASSISTANT_TABLE;
	$keywords   = ai_assistant_extract_keywords( $question );

	if ( empty( $keywords ) ) {
		return array();
	}

	$results = array();

	$ft_search_term = implode( ' ', array_map( fn( $kw ) => '+' . $kw, $keywords ) );
	$ft_search_term = sanitize_text_field( $ft_search_term );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$results = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, content, url, page_title,
			        MATCH(content) AGAINST (%s IN BOOLEAN MODE) AS score
			 FROM {$table_name}
			 WHERE MATCH(content) AGAINST (%s IN BOOLEAN MODE)
			 ORDER BY score DESC
			 LIMIT %d",
			$ft_search_term,
			$ft_search_term,
			$top_n * 2 // Fetch a bit extra for scoring.
		)
	);

	if ( empty( $results ) ) {
		$like_clauses = array();
		$like_values  = array();

		foreach ( $keywords as $kw ) {
			$like_clauses[] = 'content LIKE %s';
			$like_values[]  = '%' . $wpdb->esc_like( $kw ) . '%';
		}

		$where_sql = implode( ' OR ', $like_clauses );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT id, content, url, page_title
				 FROM {$table_name}
				 WHERE {$where_sql}
				 LIMIT %d",
				array_merge( $like_values, array( $top_n * 2 ) )
			)
		);
	}

	if ( empty( $results ) ) {
		return array();
	}

	$scored = ai_assistant_score_results( $results, $keywords );
	$scored = array_slice( $scored, 0, $top_n );

	return ai_assistant_trim_to_context( $scored, $max_context );
}

/**
 * Perform vector-based semantic search using Gemini Embeddings.
 *
 * @param string $question    User question.
 * @param int    $top_n       Limit.
 * @param int    $max_context Max chars.
 * @return array Array of chunk objects or empty if failed/no data.
 */
function ai_assistant_semantic_search( $question, $top_n, $max_context ) {
	global $wpdb;
	
	// 1. Embed the question
	// Use RETRIEVAL_QUERY for the question — Gemini embeddings are asymmetric:
	// documents use RETRIEVAL_DOCUMENT, queries use RETRIEVAL_QUERY.
	// Using the wrong type measurably reduces retrieval accuracy.
	$q_vector = ai_assistant_embed_or_null( $question, 'RETRIEVAL_QUERY' );
	if ( null === $q_vector ) {
		return array(); // API failure, fall back to keywords.
	}

	$table_name = $wpdb->prefix . AI_ASSISTANT_TABLE;
	
	// 2. Fetch all chunks that have embeddings.
	// For production on v.large sites, this fetch needs pagination or a vector DB ext.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$chunks = $wpdb->get_results( "SELECT id, content, embedding, url, page_title FROM {$table_name} WHERE embedding IS NOT NULL" );
	
	if ( empty( $chunks ) ) {
		return array(); // Site hasn't been trained since the upgrade.
	}

	// 3. Score via Cosine Similarity in PHP.
	$scored_chunks = array();
	foreach ( $chunks as $chunk ) {
		if ( empty( $chunk->embedding ) ) continue;
		
		$chunk_vector = json_decode( $chunk->embedding, true );
		if ( ! is_array( $chunk_vector ) ) continue;

		$similarity = ai_assistant_cosine_similarity( $q_vector, $chunk_vector );
		$chunk->similarity_score = $similarity;
		
		$scored_chunks[] = $chunk;
	}

	// 4. Sort descending by similarity score.
	usort( $scored_chunks, function( $a, $b ) {
		return $b->similarity_score <=> $a->similarity_score;
	} );

	// Filter out really poor matches if needed (e.g. < 0.3)
	$filtered = array_filter( $scored_chunks, function( $c ) {
		return $c->similarity_score > 0.4;
	});

	// If everything was terrible, return empty (so it tries keyword fallback or fails gracefully).
	if ( empty( $filtered ) ) {
		$filtered = array_slice( $scored_chunks, 0, 1 ); // At least return the best one if all poor
	}

	$candidates = array_slice( $filtered, 0, $top_n );

	return ai_assistant_trim_to_context( $candidates, $max_context );
}

/**
 * Extract and clean keywords from a question string.
 *
 * @param string $question Raw user question.
 * @return string[] Array of lowercase keyword strings.
 */
function ai_assistant_extract_keywords( $question ) {
	// Common English stop words to filter out.
	$stop_words = array(
		'a','an','the','is','it','in','of','to','and','or','are','was','were',
		'be','been','have','has','had','do','does','did','will','would','could',
		'should','may','might','shall','can','that','this','these','those','at',
		'by','for','with','about','from','as','on','up','if','then','than','so',
		'but','not','no','what','how','when','where','who','which','why','me',
		'my','your','our','their','its','we','he','she','they','i','you',
	);

	// Lowercase and strip punctuation.
	$question = strtolower( $question );
	$question = preg_replace( '/[^a-z0-9\s]/', ' ', $question );

	$words = preg_split( '/\s+/', trim( $question ) );

	return array_values(
		array_filter(
			$words,
			fn( $word ) => mb_strlen( $word ) >= 3 && ! in_array( $word, $stop_words, true )
		)
	);
}

/**
 * Score results based on keyword frequency in content.
 *
 * @param object[] $results  DB result objects.
 * @param string[] $keywords Keywords to score against.
 * @return object[] Sorted descending by score.
 */
function ai_assistant_score_results( $results, $keywords ) {
	foreach ( $results as $result ) {
		$lower_content  = strtolower( $result->content );
		$result->_score = 0;
		foreach ( $keywords as $kw ) {
			$result->_score += substr_count( $lower_content, $kw );
		}
	}

	usort(
		$results,
		fn( $a, $b ) => $b->_score <=> $a->_score
	);

	return $results;
}

/**
 * Trim chunks to stay within a maximum character budget.
 *
 * @param object[] $chunks     Scored chunk objects.
 * @param int      $max_chars  Max total characters.
 * @return object[] Chunks that fit within budget.
 */
function ai_assistant_trim_to_context( $chunks, $max_chars = 20000 ) {
	$kept  = array();
	$total = 0;

	foreach ( $chunks as $chunk ) {
		$len = mb_strlen( $chunk->content );
		if ( $total + $len > $max_chars ) {
			// Include a partial chunk if space allows.
			$remaining = $max_chars - $total;
			if ( $remaining > 100 ) {
				$chunk->content = mb_substr( $chunk->content, 0, $remaining ) . '…';
				$kept[]         = $chunk;
			}
			break;
		}
		$kept[]  = $chunk;
		$total  += $len;
	}

	return $kept;
}

/**
 * Build a context string from an array of chunk objects.
 *
 * @param object[] $chunks Retrieved chunks.
 * @return string Formatted context string.
 */
function ai_assistant_build_context( $chunks ) {
	$context_parts = array();

	foreach ( $chunks as $chunk ) {
		$source          = ! empty( $chunk->page_title ) ? $chunk->page_title : $chunk->url;
		$context_parts[] = "[Source: {$source}]\n{$chunk->content}";
	}

	return implode( "\n\n---\n\n", $context_parts );
}
