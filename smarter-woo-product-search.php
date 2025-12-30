<?php
/**
 * Plugin Name: Smarter Woo Product Search
 * Description: Enhances the default WooCommerce product search (/?s=...&post_type=product) with weighting, SKU + taxonomy matching, partial matches, and synonyms — without changing the search form or URL structure.
 * Version: 0.1.0
 * Author: Custom
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 9.0
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * This plugin deliberately stays server-side and only modifies the main WP search query
 * when searching WooCommerce products using the standard URL structure.
 */

add_action('pre_get_posts', function (WP_Query $query) {
	if (is_admin()) {
		return;
	}

	if (!$query->is_main_query() || !$query->is_search()) {
		return;
	}

	$post_type = $query->get('post_type');
	$is_product_search = (is_string($post_type) && $post_type === 'product')
		|| (is_array($post_type) && in_array('product', $post_type, true));

	if (!$is_product_search) {
		return;
	}

	// Flag the query so our SQL filters can reliably detect it.
	$query->set('_wcss_enabled', 1);
}, 9);

/**
 * Replace the default WordPress search WHERE fragment with our own product-focused matching.
 *
 * We keep the URL and query vars identical (/?s=...&post_type=product). We only alter SQL.
 */
add_filter('posts_search', function ($search_sql, WP_Query $query) {
	if (is_admin() || !$query->is_main_query() || !$query->is_search()) {
		return $search_sql;
	}

	if ((int) $query->get('_wcss_enabled') !== 1) {
		return $search_sql;
	}

	global $wpdb;

	$raw = (string) $query->get('s');
	$raw = trim($raw);
	if ($raw === '') {
		return $search_sql;
	}

	$payload = wcss_build_search_payload($raw);
	$exact_terms = $payload['exact_like_terms'] ?? [];
	$fuzzy_terms = $payload['fuzzy_like_terms'] ?? [];

	if (empty($exact_terms) && empty($fuzzy_terms)) {
		return $search_sql;
	}

	// Build a conservative OR-group that matches any term across the supported fields.
	$or = [];
	foreach ($exact_terms as $term_like) {
		// Title, excerpt, content.
		$or[] = $wpdb->prepare("{$wpdb->posts}.post_title LIKE %s", $term_like);
		$or[] = $wpdb->prepare("{$wpdb->posts}.post_excerpt LIKE %s", $term_like);
		$or[] = $wpdb->prepare("{$wpdb->posts}.post_content LIKE %s", $term_like);

		// SKU (requires JOIN added via posts_clauses).
		$or[] = $wpdb->prepare("pm_sku.meta_value LIKE %s", $term_like);

		// Taxonomy terms (requires JOIN added via posts_clauses).
		$or[] = $wpdb->prepare("(wcss_t.name LIKE %s OR wcss_t.slug LIKE %s)", $term_like, $term_like);
	}

	// Fuzzy terms (typo-tolerant): keep these narrower for performance/precision.
	// We apply fuzzy matching primarily to title and SKU.
	foreach ($fuzzy_terms as $term_like) {
		$or[] = $wpdb->prepare("{$wpdb->posts}.post_title LIKE %s", $term_like);
		$or[] = $wpdb->prepare("pm_sku.meta_value LIKE %s", $term_like);
	}

	$or_sql = implode(' OR ', array_unique($or));
	if ($or_sql === '') {
		return $search_sql;
	}

	return " AND ({$or_sql}) ";
}, 20, 2);

/**
 * Add JOINs + relevance scoring + stable ordering.
 *
 * - Ensures SKU and taxonomy matching works.
 * - Adds a computed relevance score (weighted matches) and orders by it.
 * - GROUP BY post ID to dedupe products when taxonomy joins expand rows.
 */
add_filter('posts_clauses', function (array $clauses, WP_Query $query) {
	if (is_admin() || !$query->is_main_query() || !$query->is_search()) {
		return $clauses;
	}

	if ((int) $query->get('_wcss_enabled') !== 1) {
		return $clauses;
	}

	global $wpdb;

	$raw = (string) $query->get('s');
	$raw = trim($raw);
	if ($raw === '') {
		return $clauses;
	}

	$payload = wcss_build_search_payload($raw);
	$exact_terms = $payload['exact_like_terms'] ?? [];
	$fuzzy_terms = $payload['fuzzy_like_terms'] ?? [];

	if (empty($exact_terms) && empty($fuzzy_terms)) {
		return $clauses;
	}

	$weights = wcss_get_weights();

	// JOIN SKU postmeta.
	$join = isset($clauses['join']) ? (string) $clauses['join'] : '';
	if (strpos($join, 'pm_sku') === false) {
		$join .= "\nLEFT JOIN {$wpdb->postmeta} AS pm_sku ON (pm_sku.post_id = {$wpdb->posts}.ID AND pm_sku.meta_key = '_sku')";
	}

	// JOIN product taxonomies (categories, tags, attributes pa_*).
	// We restrict term_taxonomy join to only the relevant taxonomies to reduce row expansion.
	if (strpos($join, 'wcss_tr') === false) {
		$join .= "\nLEFT JOIN {$wpdb->term_relationships} AS wcss_tr ON (wcss_tr.object_id = {$wpdb->posts}.ID)";
	}
	if (strpos($join, 'wcss_tt') === false) {
		$join .= "\nLEFT JOIN {$wpdb->term_taxonomy} AS wcss_tt ON (wcss_tt.term_taxonomy_id = wcss_tr.term_taxonomy_id AND (wcss_tt.taxonomy IN ('product_cat','product_tag') OR wcss_tt.taxonomy LIKE 'pa_%'))";
	}
	if (strpos($join, 'wcss_t') === false) {
		$join .= "\nLEFT JOIN {$wpdb->terms} AS wcss_t ON (wcss_t.term_id = wcss_tt.term_id)";
	}

	$clauses['join'] = $join;

	// Relevance scoring: higher weights for title and SKU, then attributes, then excerpt/content.
	$score_parts = [];
	foreach ($exact_terms as $term_like) {
		$score_parts[] = $wpdb->prepare("(CASE WHEN {$wpdb->posts}.post_title LIKE %s THEN %d ELSE 0 END)", $term_like, (int) $weights['title']);
		$score_parts[] = $wpdb->prepare("(CASE WHEN pm_sku.meta_value LIKE %s THEN %d ELSE 0 END)", $term_like, (int) $weights['sku']);

		// Attributes (pa_*) weighted higher than categories/tags.
		$score_parts[] = $wpdb->prepare(
			"(CASE WHEN (wcss_tt.taxonomy LIKE 'pa_%' AND (wcss_t.name LIKE %s OR wcss_t.slug LIKE %s)) THEN %d ELSE 0 END)",
			$term_like,
			$term_like,
			(int) $weights['attribute']
		);

		$score_parts[] = $wpdb->prepare(
			"(CASE WHEN (wcss_tt.taxonomy IN ('product_cat','product_tag') AND (wcss_t.name LIKE %s OR wcss_t.slug LIKE %s)) THEN %d ELSE 0 END)",
			$term_like,
			$term_like,
			(int) $weights['taxonomy']
		);

		$score_parts[] = $wpdb->prepare("(CASE WHEN {$wpdb->posts}.post_excerpt LIKE %s THEN %d ELSE 0 END)", $term_like, (int) $weights['excerpt']);
		$score_parts[] = $wpdb->prepare("(CASE WHEN {$wpdb->posts}.post_content LIKE %s THEN %d ELSE 0 END)", $term_like, (int) $weights['content']);
	}

	// Fuzzy scoring: lower weight so typo-tolerance doesn't outrank exact matches.
	foreach ($fuzzy_terms as $term_like) {
		$score_parts[] = $wpdb->prepare("(CASE WHEN {$wpdb->posts}.post_title LIKE %s THEN %d ELSE 0 END)", $term_like, (int) $weights['title_fuzzy']);
		$score_parts[] = $wpdb->prepare("(CASE WHEN pm_sku.meta_value LIKE %s THEN %d ELSE 0 END)", $term_like, (int) $weights['sku_fuzzy']);
	}

	$score_sql = implode(' + ', $score_parts);
	if ($score_sql === '') {
		return $clauses;
	}

	// Add score to SELECT.
	$fields = isset($clauses['fields']) ? (string) $clauses['fields'] : "{$wpdb->posts}.*";
	if (strpos($fields, 'wcss_relevance') === false) {
		$fields .= ", ({$score_sql}) AS wcss_relevance";
	}
	$clauses['fields'] = $fields;

	// Dedupe products (taxonomy joins can produce multiple rows per product).
	$groupby = isset($clauses['groupby']) ? trim((string) $clauses['groupby']) : '';
	if ($groupby === '') {
		$clauses['groupby'] = "{$wpdb->posts}.ID";
	} elseif (stripos($groupby, "{$wpdb->posts}.ID") === false) {
		$clauses['groupby'] .= ", {$wpdb->posts}.ID";
	}

	// Order by relevance first, then recency.
	$clauses['orderby'] = "wcss_relevance DESC, {$wpdb->posts}.post_date DESC";

	return $clauses;
}, 20, 2);

/**
 * Build LIKE-ready search terms for partial matching + conservative synonym expansion.
 *
 * - Partial matching: each term becomes %term%.
 * - Conservative synonyms: only expand exact tokens / exact phrases found.
 * - Caps term count to avoid overly expensive SQL.
 */
function wcss_build_search_payload(string $raw): array
{
	global $wpdb;

	$raw = wp_strip_all_tags($raw);
	$raw = preg_replace('/\s+/u', ' ', trim($raw));
	if ($raw === '') {
		return [];
	}

	$lower = function_exists('mb_strtolower') ? mb_strtolower($raw) : strtolower($raw);

	// Tokenize on whitespace. Keep it simple (MVP).
	$tokens = preg_split('/\s+/u', $lower) ?: [];
	$tokens = array_values(array_filter(array_map('trim', $tokens), function ($t) {
		return $t !== '';
	}));

	// Keep query tokens bounded for performance.
	$tokens = array_slice($tokens, 0, 6);

	$terms = [];
	foreach ($tokens as $t) {
		$terms[] = $t;
	}

	// Add the full phrase as an additional (often useful) match term.
	if (count($tokens) > 1) {
		$terms[] = $lower;
	}

	// Synonyms (configurable via filter).
	$synonyms = wcss_get_synonyms();
	$expanded = $terms;

	foreach ($synonyms as $from => $to_list) {
		$from_norm = trim((string) $from);
		if ($from_norm === '') {
			continue;
		}

		$from_norm = function_exists('mb_strtolower') ? mb_strtolower($from_norm) : strtolower($from_norm);
		$has_phrase = (strpos(" {$lower} ", " {$from_norm} ") !== false);

		if (!$has_phrase) {
			continue;
		}

		foreach ((array) $to_list as $to) {
			$to_norm = trim((string) $to);
			if ($to_norm === '') {
				continue;
			}
			$to_norm = function_exists('mb_strtolower') ? mb_strtolower($to_norm) : strtolower($to_norm);
			$expanded[] = $to_norm;
		}
	}

	// Deduplicate, cap.
	$expanded = array_values(array_unique($expanded));
	$expanded = array_slice($expanded, 0, 12);

	// Convert EXACT terms to LIKE patterns safely.
	$exact_like_terms = [];
	foreach ($expanded as $t) {
		$t = trim($t);
		if ($t === '') {
			continue;
		}
		$exact_like_terms[] = '%' . $wpdb->esc_like($t) . '%';
	}
	$exact_like_terms = array_values(array_unique($exact_like_terms));

	// Fuzzy/typo-tolerant LIKE patterns (lightweight, bounded).
	$fuzzy_like_terms = [];
	$enable_fuzzy = apply_filters('wcss_enable_fuzzy', true, $raw);
	if ($enable_fuzzy) {
		$fuzzy_like_terms = wcss_build_fuzzy_like_terms($tokens);
	}

	/**
	 * Filter final LIKE-ready term payload.
	 *
	 * @param array{exact_like_terms:string[], fuzzy_like_terms:string[]} $payload
	 * @param string $raw Original raw query string
	 */
	$payload = [
		'exact_like_terms' => $exact_like_terms,
		'fuzzy_like_terms' => $fuzzy_like_terms,
	];

	$payload = apply_filters('wcss_like_term_payload', $payload, $raw);
	if (!is_array($payload)) {
		return [
			'exact_like_terms' => $exact_like_terms,
			'fuzzy_like_terms' => $fuzzy_like_terms,
		];
	}

	// Back-compat: allow older integrations that used wcss_like_terms.
	$payload['exact_like_terms'] = apply_filters('wcss_like_terms', $payload['exact_like_terms'] ?? $exact_like_terms, $raw);

	return [
		'exact_like_terms' => array_values(array_unique(array_filter((array) ($payload['exact_like_terms'] ?? [])))),
		'fuzzy_like_terms' => array_values(array_unique(array_filter((array) ($payload['fuzzy_like_terms'] ?? [])))),
	];
}

/**
 * Build fuzzy LIKE patterns from raw tokens.
 *
 * Goal: catch common short typos without expensive edit-distance logic.
 * Examples:
 * - "sush"  -> "%s%ush%"  (matches "slush")
 * - "suh"   -> "%s%u%h%"  (matches "slush")
 */
function wcss_build_fuzzy_like_terms(array $tokens): array
{
	global $wpdb;

	$max = (int) apply_filters('wcss_fuzzy_max_terms', 10);
	if ($max < 0) {
		$max = 0;
	}

	$patterns = [];

	foreach ($tokens as $token) {
		$token = trim((string) $token);
		if ($token === '') {
			continue;
		}

		$len = function_exists('mb_strlen') ? mb_strlen($token) : strlen($token);
		if ($len < 3 || $len > 7) {
			continue;
		}

		// One-missing-character tolerance: insert a wildcard between each adjacent pair once.
		// token=abcd => a%bcd, ab%cd, abc%d
		for ($i = 1; $i <= $len - 1; $i++) {
			$left = function_exists('mb_substr') ? mb_substr($token, 0, $i) : substr($token, 0, $i);
			$right = function_exists('mb_substr') ? mb_substr($token, $i) : substr($token, $i);
			$patterns[] = '%' . $wpdb->esc_like($left) . '%' . $wpdb->esc_like($right) . '%';
			if (count($patterns) >= $max) {
				break 2;
			}
		}

		// Very short tokens (3 chars): also allow a wildcard between every character.
		// token=abc => a%b%c
		if ($len === 3 && count($patterns) < $max) {
			$c1 = function_exists('mb_substr') ? mb_substr($token, 0, 1) : substr($token, 0, 1);
			$c2 = function_exists('mb_substr') ? mb_substr($token, 1, 1) : substr($token, 1, 1);
			$c3 = function_exists('mb_substr') ? mb_substr($token, 2, 1) : substr($token, 2, 1);
			$patterns[] = '%' . $wpdb->esc_like($c1) . '%' . $wpdb->esc_like($c2) . '%' . $wpdb->esc_like($c3) . '%';
		}
 	}

	$patterns = array_values(array_unique($patterns));
	if (count($patterns) > $max) {
		$patterns = array_slice($patterns, 0, $max);
	}

	return $patterns;
}

/**
 * Default synonyms (conservative expansion).
 *
 * You can change these via the 'wcss_synonyms' filter in a mu-plugin or a small custom plugin.
 */
function wcss_get_synonyms(): array
{
	$default = [
		// Examples from your spec.
		'sampler' => ['sample pack'],
		'sample pack' => ['sampler'],
		'maduro' => ['dark'],
		'dark' => ['maduro'],
	];

	/**
	 * Filter synonym map.
	 *
	 * Format: [ 'from phrase' => ['to phrase', 'to phrase 2'] ]
	 */
	$filtered = apply_filters('wcss_synonyms', $default);
	return is_array($filtered) ? $filtered : $default;
}

/**
 * Default relevance weights.
 *
 * These are intentionally simple and can be tuned without changing core logic.
 */
function wcss_get_weights(): array
{
	$default = [
		'title' => 50,
		'sku' => 40,
		'attribute' => 30,
		'taxonomy' => 25,
		'excerpt' => 20,
		'content' => 10,
		'title_fuzzy' => 8,
		'sku_fuzzy' => 6,
	];

	/**
	 * Filter weights.
	 *
	 * Keys: title, sku, attribute, taxonomy, excerpt, content, title_fuzzy, sku_fuzzy
	 */
	$filtered = apply_filters('wcss_weights', $default);
	return is_array($filtered) ? array_merge($default, $filtered) : $default;
}
