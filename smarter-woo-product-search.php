<?php
/**
 * Plugin Name: Smarter Woo Product Search
 * Description: Enhances the default WooCommerce product search (/?s=...&post_type=product) with weighting, SKU + taxonomy matching, partial matches, and synonyms — without changing the search form or URL structure.
 * Version: 0.1.0
 * Author: Custom
 * Requires at least: 5.8
 * Requires PHP: 7.0
 * WC requires at least: 5.0
 * WC tested up to: 9.0
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) {
	exit;
}

if (!defined('WCSS_SMART_SEARCH_VERSION')) {
	define('WCSS_SMART_SEARCH_VERSION', '0.1.0');
}

/**
 * Optional browser console debug bridge.
 *
 * This helps when you want to see plugin/runtime output in DevTools.
 * For safety, it is admin-only and requires explicit opt-in:
 * - add `?wcss_debug=1` to the URL, OR
 * - enable via the `wcss_console_debug_enabled` filter.
 */
function wcss_console_debug_is_enabled(): bool
{
	if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
		return false;
	}

	if (defined('REST_REQUEST') && REST_REQUEST) {
		return false;
	}

	if (function_exists('wp_is_json_request') && wp_is_json_request()) {
		return false;
	}

	if (!function_exists('is_user_logged_in') || !is_user_logged_in()) {
		return false;
	}

	if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
		return false;
	}

	// Explicit opt-in via URL (admin-only).
	if (isset($_GET['wcss_debug']) && (string) $_GET['wcss_debug'] === '1') {
		return true;
	}

	// Or enable programmatically.
	return (bool) apply_filters('wcss_console_debug_enabled', false);
}

function wcss_console_debug_bootstrap(): void
{
	if (!wcss_console_debug_is_enabled()) {
		return;
	}

	if (!isset($GLOBALS['wcss_console_debug_buffer']) || !is_array($GLOBALS['wcss_console_debug_buffer'])) {
		$GLOBALS['wcss_console_debug_buffer'] = [];
	}

	set_error_handler('wcss_console_debug_error_handler');
	register_shutdown_function('wcss_console_debug_shutdown');

	wcss_console_debug_log('WP_DEBUG console bridge enabled', [
		'plugin' => 'Smarter Woo Product Search',
		'version' => defined('WCSS_SMART_SEARCH_VERSION') ? WCSS_SMART_SEARCH_VERSION : null,
	]);

	add_action('wp_head', 'wcss_console_debug_print', 1);
	add_action('admin_head', 'wcss_console_debug_print', 1);
	add_action('wp_footer', 'wcss_console_debug_print', 999);
	add_action('admin_footer', 'wcss_console_debug_print', 999);
}

function wcss_console_debug_log(string $message, array $context = []): void
{
	if (!wcss_console_debug_is_enabled()) {
		return;
	}

	$GLOBALS['wcss_console_debug_buffer'][] = [
		't' => 'log',
		'message' => $message,
		'context' => $context,
	];
}

function wcss_console_debug_error_handler($errno, $errstr, $errfile = '', $errline = 0): bool
{
	if (!wcss_console_debug_is_enabled()) {
		return false;
	}

	$GLOBALS['wcss_console_debug_buffer'][] = [
		't' => 'php',
		'errno' => (int) $errno,
		'message' => (string) $errstr,
		'file' => (string) $errfile,
		'line' => (int) $errline,
	];

	// Let WordPress/PHP handle it too.
	return false;
}

function wcss_console_debug_shutdown(): void
{
	if (!wcss_console_debug_is_enabled()) {
		return;
	}

	$last = error_get_last();
	if (is_array($last) && !empty($last['message'])) {
		$GLOBALS['wcss_console_debug_buffer'][] = [
			't' => 'shutdown',
			'errno' => isset($last['type']) ? (int) $last['type'] : 0,
			'message' => (string) ($last['message'] ?? ''),
			'file' => (string) ($last['file'] ?? ''),
			'line' => (int) ($last['line'] ?? 0),
		];
	}
}

function wcss_console_debug_print(): void
{
	static $printed = false;
	if ($printed) {
		return;
	}
	$printed = true;

	if (!wcss_console_debug_is_enabled()) {
		return;
	}

	$buffer = isset($GLOBALS['wcss_console_debug_buffer']) && is_array($GLOBALS['wcss_console_debug_buffer'])
		? $GLOBALS['wcss_console_debug_buffer']
		: [];

	// Always print the group header when enabled so we can confirm it's working.

	// Reduce sensitive path exposure: keep only the basename.
	foreach ($buffer as &$row) {
		if (isset($row['file']) && is_string($row['file']) && $row['file'] !== '') {
			$row['file'] = basename($row['file']);
		}
	}
	unset($row);

	$payload = wp_json_encode($buffer);
	if (!is_string($payload) || $payload === '') {
		return;
	}

	echo "\n<script>\n";
	echo "(function(){\n";
	echo "  var items = {$payload};\n";
	echo "  try { console.groupCollapsed('WP_DEBUG (Smarter Search)'); } catch(e) {}\n";
	echo "  for (var i=0;i<items.length;i++){\n";
	echo "    var it = items[i] || {};\n";
	echo "    var loc = (it.file ? it.file : '') + (it.line ? (':' + it.line) : '');\n";
	echo "    var prefix = (it.t === 'php' || it.t === 'shutdown') ? '[PHP]' : '[LOG]';\n";
	echo "    console.log(prefix, it.message || it.m || '', loc, it.context || it);\n";
	echo "  }\n";
	echo "  try { console.groupEnd(); } catch(e) {}\n";
	echo "})();\n";
	echo "</script>\n";
}

add_action('plugins_loaded', 'wcss_console_debug_bootstrap', 1);

final class WCSS_Smarter_Search
{
	private static $instance = null;

	public static function init(): self
	{
		if (null === self::$instance) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct()
	{
		add_action('pre_get_posts', [$this, 'flag_product_search_queries'], 9);
		add_filter('posts_search', [$this, 'filter_posts_search'], 20, 2);
		add_filter('posts_clauses', [$this, 'filter_posts_clauses'], 20, 2);
	}

	public function flag_product_search_queries(WP_Query $query)
	{
		if (is_admin() || !$query->is_main_query()) {
			return;
		}

		$raw = $query->get('s');
		$has_search_term = is_string($raw) && trim($raw) !== '';
		if (!$query->is_search() && !$has_search_term) {
			return;
		}

		$post_type = $query->get('post_type');
		$is_product_search = (is_string($post_type) && $post_type === 'product')
			|| (is_array($post_type) && in_array('product', $post_type, true));

		if (!$is_product_search) {
			return;
		}

		$query->set('_wcss_enabled', 1);

		if (function_exists('wcss_console_debug_log')) {
			wcss_console_debug_log('WCSS flagged product search query', [
				's' => (string) $query->get('s'),
				'post_type' => $post_type,
			]);
		}
	}

	public function filter_posts_search($search_sql, WP_Query $query)
	{
		if (!$this->should_handle_query($query)) {
			return $search_sql;
		}

		$payload = $this->get_like_terms($query);
		$exact_terms = $payload['exact'];
		$fuzzy_terms = $payload['fuzzy'];

		if (empty($exact_terms) && empty($fuzzy_terms)) {
			return $search_sql;
		}

		global $wpdb;
		$or = [];

		foreach ($exact_terms as $term_like) {
			$or[] = $wpdb->prepare("{$wpdb->posts}.post_title LIKE %s", $term_like);
			$or[] = $wpdb->prepare("{$wpdb->posts}.post_excerpt LIKE %s", $term_like);
			$or[] = $wpdb->prepare("{$wpdb->posts}.post_content LIKE %s", $term_like);
			$or[] = $wpdb->prepare('pm_sku.meta_value LIKE %s', $term_like);
			$or[] = $wpdb->prepare('(wcss_t.name LIKE %s OR wcss_t.slug LIKE %s)', $term_like, $term_like);
		}

		foreach ($fuzzy_terms as $term_like) {
			$or[] = $wpdb->prepare("{$wpdb->posts}.post_title LIKE %s", $term_like);
			$or[] = $wpdb->prepare('pm_sku.meta_value LIKE %s', $term_like);
		}

		$or_sql = implode(' OR ', array_unique($or));
		if ($or_sql === '') {
			return $search_sql;
		}

		return " AND ({$or_sql}) ";
	}

	public function filter_posts_clauses(array $clauses, WP_Query $query): array
	{
		if (!$this->should_handle_query($query)) {
			return $clauses;
		}

		$payload = $this->get_like_terms($query);
		$exact_terms = $payload['exact'];
		$fuzzy_terms = $payload['fuzzy'];

		if (empty($exact_terms) && empty($fuzzy_terms)) {
			return $clauses;
		}

		global $wpdb;
		$weights = wcss_get_weights();

		$join = isset($clauses['join']) ? (string) $clauses['join'] : '';
		if (strpos($join, 'pm_sku') === false) {
			$join .= "\nLEFT JOIN {$wpdb->postmeta} AS pm_sku ON (pm_sku.post_id = {$wpdb->posts}.ID AND pm_sku.meta_key = '_sku')";
		}
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
		$score_parts = [];

		foreach ($exact_terms as $term_like) {
			$score_parts[] = $wpdb->prepare("(CASE WHEN {$wpdb->posts}.post_title LIKE %s THEN %d ELSE 0 END)", $term_like, (int) $weights['title']);
			$score_parts[] = $wpdb->prepare("(CASE WHEN pm_sku.meta_value LIKE %s THEN %d ELSE 0 END)", $term_like, (int) $weights['sku']);
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

		foreach ($fuzzy_terms as $term_like) {
			$score_parts[] = $wpdb->prepare("(CASE WHEN {$wpdb->posts}.post_title LIKE %s THEN %d ELSE 0 END)", $term_like, (int) $weights['title_fuzzy']);
			$score_parts[] = $wpdb->prepare("(CASE WHEN pm_sku.meta_value LIKE %s THEN %d ELSE 0 END)", $term_like, (int) $weights['sku_fuzzy']);
		}

		$score_sql = implode(' + ', $score_parts);
		if ($score_sql === '') {
			return $clauses;
		}

		$fields = isset($clauses['fields']) ? (string) $clauses['fields'] : "{$wpdb->posts}.*";
		if (strpos($fields, 'wcss_relevance') === false) {
			$fields .= ", ({$score_sql}) AS wcss_relevance";
		}
		$clauses['fields'] = $fields;

		$groupby = isset($clauses['groupby']) ? trim((string) $clauses['groupby']) : '';
		if ($groupby === '') {
			$clauses['groupby'] = "{$wpdb->posts}.ID";
		} elseif (stripos($groupby, "{$wpdb->posts}.ID") === false) {
			$clauses['groupby'] .= ", {$wpdb->posts}.ID";
		}

		$clauses['orderby'] = "wcss_relevance DESC, {$wpdb->posts}.post_date DESC";

		return $clauses;
	}

	private function should_handle_query(WP_Query $query): bool
	{
		if (is_admin() || !$query->is_main_query()) {
			return false;
		}

		$raw = $query->get('s');
		$has_search_term = is_string($raw) && trim($raw) !== '';
		if (!$query->is_search() && !$has_search_term) {
			return false;
		}

		return (int) $query->get('_wcss_enabled') === 1;
	}

	private function get_like_terms(WP_Query $query): array
	{
		$raw = (string) $query->get('s');
		$raw = trim($raw);
		if ($raw === '') {
			return ['exact' => [], 'fuzzy' => []];
		}

		$payload = wcss_build_search_payload($raw);
		if (function_exists('wcss_console_debug_log')) {
			wcss_console_debug_log('WCSS payload built', [
				'raw' => $raw,
				'exact_like_terms' => isset($payload['exact_like_terms']) ? (array) $payload['exact_like_terms'] : [],
				'fuzzy_like_terms' => isset($payload['fuzzy_like_terms']) ? (array) $payload['fuzzy_like_terms'] : [],
			]);
		}

		return [
			'exact' => $payload['exact_like_terms'] ?? [],
			'fuzzy' => $payload['fuzzy_like_terms'] ?? [],
		];
	}
}

function wcss_maybe_bootstrap(): void
{
	if (!function_exists('add_action')) {
		return;
	}

	if (class_exists('WooCommerce') || function_exists('WC')) {
		WCSS_Smarter_Search::init();
	}
}

add_action('plugins_loaded', 'wcss_maybe_bootstrap', 11);

add_action('woocommerce_init', static function () {
	WCSS_Smarter_Search::init();
});

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
