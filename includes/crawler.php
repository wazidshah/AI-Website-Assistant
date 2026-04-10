<?php
/**
 * Advanced Crawler – uses Guzzle and Symfony DomCrawler
 * to discover URLs and extract content.
 *
 * @package AI_Website_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DomCrawler\Crawler as DomCrawler;

/**
 * Get the Guzzle Client configured for scraping.
 *
 * @return Client
 */
function ai_assistant_get_guzzle_client() {
	// Only disable SSL verification on local/debug environments.
	$site_url = home_url();
	$is_local = ( defined( 'WP_DEBUG' ) && WP_DEBUG )
		&& (
			false !== strpos( $site_url, 'localhost' )
			|| false !== strpos( $site_url, '127.0.0.1' )
			|| false !== strpos( $site_url, '.local' )
		);

	return new Client(array(
		'timeout'         => 10.0,
		'allow_redirects' => array( 'max' => 5 ),
		'headers'         => array(
			'User-Agent' => 'AI Website Assistant Bot/1.0',
		),
		'verify'          => ! $is_local,
	));
}

/**
 * Discover URLs to train on. Returns an array of URLs.
 * Checks sitemaps first, falls back to WP DB for allowed post types.
 *
 * @return string[]
 */
function ai_assistant_discover_urls() {
	$urls = array();
	$site_url = home_url();
	
	$sitemaps_to_process = array(
		$site_url . '/sitemap.xml',
		$site_url . '/sitemap_index.xml',
		$site_url . '/wp-sitemap.xml',
	);

	$client = ai_assistant_get_guzzle_client();
	$sitemap_found = false;
	$processed_sitemaps = array();

	while ( ! empty( $sitemaps_to_process ) ) {
		$sitemap_url = array_shift( $sitemaps_to_process );
		if ( in_array( $sitemap_url, $processed_sitemaps, true ) ) {
			continue;
		}
		$processed_sitemaps[] = $sitemap_url;

		try {
			$response = $client->request( 'GET', $sitemap_url );
			if ( $response->getStatusCode() === 200 ) {
				$xml = $response->getBody()->getContents();
				// Basic extraction of <loc> tags from the XML.
				preg_match_all( '/<loc>(.*?)<\/loc>/is', $xml, $matches );
				if ( ! empty( $matches[1] ) ) {
					$found_any = false;
					foreach ( $matches[1] as $loc ) {
						$loc = esc_url_raw( trim( $loc ) );
						// Basic check if it's an internal link
						if ( strpos( $loc, $site_url ) === 0 ) {
							if ( substr( $loc, -4 ) === '.xml' ) {
								// It's a nested sitemap, queue it to be processed
								$sitemaps_to_process[] = $loc;
								$found_any = true;
							} else {
								// It's a standard URL
								$urls[] = $loc;
								$found_any = true;
							}
						}
					}
					// If we discovered valid URLs or sub-sitemaps, mark sitemap_found true.
					if ( $found_any ) {
						$sitemap_found = true;
					}
				}
			}
		} catch ( RequestException $e ) {
			// Ignore gracefully and try the next queued sitemap.
			continue;
		}
	}

	// Step 2: Fallback if no sitemap was found - combine DB permalinks for configured content types
	if ( ! $sitemap_found || empty( $urls ) ) {
		$post_types = array();
		if ( get_option( 'ai_assistant_crawl_pages', 1 ) ) {
			$post_types[] = 'page';
		}
		if ( get_option( 'ai_assistant_crawl_posts', 1 ) ) {
			$post_types[] = 'post';
		}
		if ( get_option( 'ai_assistant_crawl_products', 0 ) ) {
			$post_types[] = 'product';
		}
		if ( get_option( 'ai_assistant_crawl_cpts', 1 ) ) {
			$all_public = get_post_types( array( 'public' => true ) );
			foreach ( $all_public as $pt ) {
				if ( ! in_array( $pt, array( 'post', 'page', 'attachment', 'product' ), true ) ) {
					$post_types[] = $pt;
				}
			}
		}

		if ( ! empty( $post_types ) ) {
			$query_args = array(
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			);

			$post_ids = get_posts( $query_args );
			foreach ( $post_ids as $pid ) {
				$urls[] = get_permalink( $pid );
			}
		}
	}

	$urls = array_unique( array_filter( $urls ) );

	// Step 3: Apply Filters (Include / Exclude)
	$include_raw = get_option( 'ai_assistant_include_patterns', '' );
	$exclude_raw = get_option( 'ai_assistant_exclude_patterns', "/cart/\n/checkout/\n/account/\n/wp-admin/" );

	$includes = array_filter( array_map( 'trim', explode( "\n", $include_raw ) ) );
	$excludes = array_filter( array_map( 'trim', explode( "\n", $exclude_raw ) ) );

	$filtered_urls = array();
	foreach ( $urls as $url ) {
		$url_lower = strtolower( $url );
		
		// 1. Check Excludes first
		$is_excluded = false;
		foreach ( $excludes as $exclude ) {
			if ( strpos( $url_lower, strtolower( $exclude ) ) !== false ) {
				$is_excluded = true;
				break;
			}
		}
		if ( $is_excluded ) {
			continue;
		}

		// 2. Check Includes
		if ( ! empty( $includes ) ) {
			$is_included = false;
			foreach ( $includes as $include ) {
				if ( strpos( $url_lower, strtolower( $include ) ) !== false ) {
					$is_included = true;
					break;
				}
			}
			if ( ! $is_included ) {
				continue;
			}
		}

		$filtered_urls[] = $url;
	}

	return array_values( $filtered_urls );
}

/**
 * Fetch a single page URL using Guzzle and parse it with Symfony DomCrawler.
 *
 * @param string $url The URL to fetch.
 * @return array Array of { url, title, content } or { error }.
 */
function ai_assistant_fetch_and_parse_url( $url ) {
	$client = ai_assistant_get_guzzle_client();

	try {
		$response = $client->request( 'GET', $url );
		if ( $response->getStatusCode() !== 200 ) {
			return array( 'error' => 'HTTP ' . $response->getStatusCode() );
		}
		$html = $response->getBody()->getContents();
	} catch ( RequestException $e ) {
		// Fallback to WP Remote GET if Guzzle fails (e.g. SSL cert issues)
		$fallback = wp_remote_get( $url, array( 'timeout' => 15 ) );
		if ( is_wp_error( $fallback ) ) {
			return array( 'error' => 'Fallback fetch failed: ' . $fallback->get_error_message() );
		}
		
		$status = wp_remote_retrieve_response_code( $fallback );
		if ( $status !== 200 ) {
			return array( 'error' => 'Fallback HTTP ' . $status );
		}
		$html = wp_remote_retrieve_body( $fallback );
	}

	if ( empty( trim( $html ) ) ) {
		return array( 'error' => 'Received empty HTML.' );
	}

	$page_crawler = new DomCrawler( $html );

	// ── Extract page title ────────────────────────────────────────────────────
	$title = '';
	if ( $page_crawler->filter( 'title' )->count() > 0 ) {
		$title = trim( $page_crawler->filter( 'title' )->text() );
	}

	// ── Step 1: Strip noise blocks from raw HTML ──────────────────────────────
	// Remove scripts, styles, and non-content structural elements so they don't
	// pollute the chunks. We strip nav/header/footer/aside here since they
	// contain menus, ads, and boilerplate that hurt retrieval accuracy.
	$body_html = preg_replace(
		'@<(script|style|noscript|svg|iframe|nav|header|footer|aside)[^>]*?>.*?</\1>@si',
		' ',
		$html
	);

	// ── Step 2: Find the best semantic content container ─────────────────────
	// Try progressively broader selectors. Stop at the first one with real text.
	$content_crawler   = new DomCrawler( $body_html );
	$main_text         = '';
	$content_selectors = array(
		'main',
		'article',
		'.entry-content',
		'.post-content',
		'.page-content',
		'.wp-block-post-content',
		'#content',
		'#main',
		'.content-area',
		'body',
	);

	foreach ( $content_selectors as $selector ) {
		try {
			$node = $content_crawler->filter( $selector );
			if ( $node->count() > 0 ) {
				$inner = $node->first()->html();
				// Inject spaces around block-level elements before stripping tags.
				$inner     = preg_replace( '/(<(?:p|div|li|br|tr|td|th|h[1-6]|section|article)[^>]*>)/i', ' $1', $inner );
				$inner     = preg_replace( '/(<\/(?:p|div|li|br|tr|td|th|h[1-6]|section|article)>)/i', '$1 ', $inner );
				$candidate = trim( wp_strip_all_tags( $inner ) );
				if ( mb_strlen( $candidate ) > 100 ) {
					$main_text = $candidate;
					break;
				}
			}
		} catch ( \Exception $e ) {
			// Selector may not exist on this page – keep trying.
			continue;
		}
	}

	// ── Step 3: Full-body fallback ────────────────────────────────────────────
	if ( empty( $main_text ) ) {
		$fallback  = preg_replace( '/(<(?:p|div|li|br|tr|td|th|h[1-6])[^>]*>)/i', ' $1', $body_html );
		$fallback  = preg_replace( '/(<\/(?:p|div|li|br|tr|td|th|h[1-6])>)/i', '$1 ', $fallback );
		$main_text = trim( wp_strip_all_tags( $fallback ) );
	}

	// ── Step 4: Clean up whitespace ──────────────────────────────────────────
	$final_content = preg_replace( '/[ \t]+/', ' ', $main_text );
	$final_content = preg_replace( '/\n{3,}/', "\n\n", $final_content );
	$final_content = trim( $final_content );
	// Note: title and URL are stored as separate DB columns — do NOT prepend
	// them to the content string. Embedding the same prefix on every chunk
	// from a page biases cosine similarity scores.

	return array(
		'url'     => $url,
		'title'   => $title,
		'content' => trim( $final_content ),
	);
}

/**
 * Fallback to read DB if URL fetch gets nothing. 
 * E.g. for post_id lookup. Unused natively in ajax, but kept for robust DB reading.
 */
function ai_assistant_extract_acf_text( $field ) {
	if ( empty( $field ) ) {
		return '';
	}

	$text = array();
	if ( is_array( $field ) || is_object( $field ) ) {
		foreach ( (array) $field as $key => $val ) {
			if ( $key === 'acf_fc_layout' ) {
				continue;
			}
			$extracted = ai_assistant_extract_acf_text( $val );
			if ( ! empty( $extracted ) ) {
				$text[] = $extracted;
			}
		}
	} elseif ( is_string( $field ) || is_numeric( $field ) ) {
		$val = wp_strip_all_tags( (string) $field );
		if ( ! empty( trim( $val ) ) ) {
			$text[] = trim( $val );
		}
	}
	return implode( ' ', $text );
}
