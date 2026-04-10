<?php
/**
 * Chunker – splits cleaned content into overlapping character chunks and
 * persists them in the wp_ai_chunks database table.
 *
 * @package AI_Website_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Split a string into overlapping chunks.
 *
 * @param string $text       The text to split.
 * @param int    $chunk_size Target chunk character count (default 600).
 * @param int    $overlap    Overlap character count (default 100).
 * @return string[] Array of text chunks.
 */
function ai_assistant_split_chunks( $text, $chunk_size = 700, $overlap = 200 ) {
	$chunks = array();
	$length = mb_strlen( $text );

	if ( $length === 0 ) {
		return $chunks;
	}

	$start = 0;

	while ( $start < $length ) {
		// Grab a chunk of the desired size.
		$chunk = mb_substr( $text, $start, $chunk_size );

		// Try to break at the last full sentence within the chunk instead of
		// cutting words mid-stream. Look for '. ', '! ', '? ' or newline.
		if ( $start + $chunk_size < $length ) {
			$break_pos = ai_assistant_find_sentence_break( $chunk );
			if ( $break_pos !== false && $break_pos > 100 ) {
				$chunk = mb_substr( $chunk, 0, $break_pos + 1 );
			}
		}

		$chunks[] = trim( $chunk );

		// Advance start position, stepping back by overlap.
		$advance = mb_strlen( $chunk ) - $overlap;
		if ( $advance <= 0 ) {
			$advance = $chunk_size; // Safety fallback.
		}
		$start += $advance;
	}

	return array_filter( $chunks ); // Remove empty entries.
}

/**
 * Find the position of the last sentence-ending character in a string.
 *
 * @param string $text Text to search.
 * @return int|false Position of the break character, or false.
 */
function ai_assistant_find_sentence_break( $text ) {
	$last = false;
	foreach ( array( '. ', '! ', '? ', "\n" ) as $delimiter ) {
		$pos = mb_strrpos( $text, $delimiter );
		if ( $pos !== false && ( $last === false || $pos > $last ) ) {
			$last = $pos + mb_strlen( $delimiter ) - 1;
		}
	}
	return $last;
}

/**
 * Clear existing chunks before a new training run.
 */
function ai_assistant_clear_training_data() {
	global $wpdb;
	$table_name = $wpdb->prefix . AI_ASSISTANT_TABLE;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- TRUNCATE does not support placeholders; table name is always $wpdb->prefix . constant.
	$wpdb->query( "TRUNCATE TABLE {$table_name}" );
	update_option( 'ai_assistant_chunk_count', 0 );
}

/**
 * Process a single URL: fetch, extract, chunk, embed, save.
 * Designed to be called per-URL via AJAX.
 *
 * @param string $url The URL to process.
 * @return array { success: bool, inserted: int, error: string }
 */
function ai_assistant_train_single_url( $url ) {
	global $wpdb;
	$table_name = $wpdb->prefix . AI_ASSISTANT_TABLE;

	$page = ai_assistant_fetch_and_parse_url( $url );
	if ( isset( $page['error'] ) ) {
		return array(
			'success'  => false,
			'inserted' => 0,
			'error'    => 'Fetch failed: ' . $page['error'],
		);
	}
	if ( empty( $page['content'] ) ) {
		return array(
			'success'  => false,
			'inserted' => 0,
			'error'    => 'Extracted content was empty.',
		);
	}

	$chunks   = ai_assistant_split_chunks( $page['content'], 700, 200 );
	$inserted = 0;
	$errors   = array();

	// Determine source_type roughly based on URL structure or fallback.
	$source_type = 'page';
	if ( strpos( $url, '/product/' ) !== false ) {
		$source_type = 'product';
	} elseif ( strpos( $url, '/category/' ) !== false ) {
		$source_type = 'category';
	}

	foreach ( $chunks as $chunk ) {
		if ( mb_strlen( $chunk ) < 50 ) {
			continue; // Skip very short chunks.
		}

		// Generate embedding.
		$vector_json = null;
		$vector_res  = ai_assistant_get_embedding( $chunk, true );

		if ( ! is_wp_error( $vector_res ) && is_array( $vector_res ) ) {
			$vector_json = wp_json_encode( $vector_res );
		} else {
			$err_msg = is_wp_error( $vector_res ) ? $vector_res->get_error_message() : 'Unknown error';
			$errors[] = 'Embedding failed: ' . $err_msg;
			// We skip saving the chunk if embedding fails because vector search needs it.
			continue;
		}

		// Insert into DB using new schema.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$db_insert = $wpdb->insert(
			$table_name,
			array(
				'content'     => $chunk,
				'embedding'   => $vector_json,
				'url'         => esc_url_raw( $url ),
				'source_type' => $source_type,
				'page_title'  => sanitize_text_field( $page['title'] ),
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( $db_insert ) {
			$inserted++;
		} else {
			$errors[] = 'DB insert failed.';
		}
	}

	// Update running count of chunks.
	if ( $inserted > 0 ) {
		$current_count = (int) get_option( 'ai_assistant_chunk_count', 0 );
		update_option( 'ai_assistant_chunk_count', $current_count + $inserted );
		update_option( 'ai_assistant_last_trained', current_time( 'timestamp', true ) );
	}

	return array(
		'success'  => empty( $errors ),
		'inserted' => $inserted,
		'error'    => ! empty( $errors ) ? implode( ', ', $errors ) : '',
	);
}
