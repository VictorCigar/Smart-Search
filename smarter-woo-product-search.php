<?php
/**
 * Plugin Name: Smarter Woo Product Search
 * Description: Enhances the default WooCommerce product search (/?s=...&post_type=product) with weighting, SKU + taxonomy matching, partial matches, and synonyms — without changing the search form or URL structure.
 * Version: 0.1.17
 * Author: VO
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
	define('WCSS_SMART_SEARCH_VERSION', '0.1.17');
}

/**
 * Declare compatibility with optional WooCommerce features.
 *
 * Without this, WooCommerce may show an admin notice when features like HPOS
 * (High-Performance Order Storage) are enabled.
 */
add_action('before_woocommerce_init', static function (): void {
	if (class_exists('\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('order_attribution', __FILE__, true);
	}
});

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

	$GLOBALS['wcss_console_debug_active'] = true;

	set_error_handler('wcss_console_debug_error_handler');
	register_shutdown_function('wcss_console_debug_shutdown');

	// Write directly to buffer so we don't depend on re-checking auth mid-request.
	$GLOBALS['wcss_console_debug_buffer'][] = [
		't' => 'log',
		'message' => 'WP_DEBUG console bridge enabled',
		'context' => [
		'plugin' => 'Smarter Woo Product Search',
		'version' => defined('WCSS_SMART_SEARCH_VERSION') ? WCSS_SMART_SEARCH_VERSION : null,
		],
	];

	// Print late so we capture logs produced during query building and template rendering.
	add_action('wp_footer', 'wcss_console_debug_print', 999);
	add_action('admin_footer', 'wcss_console_debug_print', 999);
}

function wcss_console_debug_log(string $message, array $context = []): void
{
	// Only log if we've bootstrapped the buffer for this request.
	if (!isset($GLOBALS['wcss_console_debug_buffer']) || !is_array($GLOBALS['wcss_console_debug_buffer'])) {
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
	if (!isset($GLOBALS['wcss_console_debug_buffer']) || !is_array($GLOBALS['wcss_console_debug_buffer'])) {
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
	if (!isset($GLOBALS['wcss_console_debug_buffer']) || !is_array($GLOBALS['wcss_console_debug_buffer'])) {
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

	if (empty($buffer)) {
		$buffer[] = [
			't' => 'log',
			'message' => 'WCSS debug enabled, but no logs were captured (buffer empty)',
			'context' => [
				'version' => defined('WCSS_SMART_SEARCH_VERSION') ? WCSS_SMART_SEARCH_VERSION : null,
				'request_uri' => isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : null,
			],
		];
	}

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
	$label = 'WP_DEBUG (Smarter Search)';
	if (defined('WCSS_SMART_SEARCH_VERSION')) {
		$label .= ' v' . WCSS_SMART_SEARCH_VERSION;
	}
	echo "  try { console.groupCollapsed(" . wp_json_encode($label) . "); } catch(e) {}\n";
	echo "  for (var i=0;i<items.length;i++){\n";
	echo "    var it = items[i] || {};\n";
	echo "    var loc = (it.file ? it.file : '') + (it.line ? (':' + it.line) : '');\n";
	echo "    var prefix = (it.t === 'php' || it.t === 'shutdown') ? '[PHP]' : '[LOG]';\n";
	echo "    console.log(prefix, it.message || it.m || '', loc, it.context || it);\n";
	echo "    try { if (it && it.context && typeof it.context.sql === 'string' && it.context.sql) { console.log('[SQL]', it.context.sql); } } catch(e) {}\n";
	echo "  }\n";
	echo "  try { console.groupEnd(); } catch(e) {}\n";
	echo "})();\n";
	echo "</script>\n";
}
function wcss_console_debug_truncate(string $s, int $max = 2000): string
{
	$s = trim($s);
	if ($max < 0) {
		$max = 0;
	}
	if ($max === 0 || $s === '') {
		return $s;
	}
	if (function_exists('mb_strlen') && function_exists('mb_substr')) {
		if (mb_strlen($s) <= $max) {
			return $s;
		}
		return mb_substr($s, 0, $max) . '…';
	}
	if (strlen($s) <= $max) {
		return $s;
	}
	return substr($s, 0, $max) . '…';
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
		// Re-assert constraints after other plugins/hosts modify the query.
		add_action('pre_get_posts', [$this, 'enforce_wcss_query_constraints'], PHP_INT_MAX);
		// Keep the UI (templates/search forms) showing the original term even though we blank `s`.
		add_filter('get_search_query', [$this, 'filter_get_search_query'], 9999);
		// Run very late to override hosts/plugins that rewrite search to REGEXP or other forms.
		add_filter('posts_search', [$this, 'filter_posts_search'], 9999, 2);
		// Last-resort cleanup for hosts that inject REGEXP search via posts_where after posts_search.
		add_filter('posts_where', [$this, 'filter_posts_where'], PHP_INT_MAX, 2);
		add_filter('posts_clauses', [$this, 'filter_posts_clauses'], 20, 2);
		// Log very late so we see the actual final SQL after other filters run.
		add_filter('posts_request', [$this, 'debug_posts_request'], 999, 2);
		// Detect if another plugin short-circuits the query and returns posts without SQL.
		add_filter('posts_pre_query', [$this, 'debug_posts_pre_query'], PHP_INT_MAX, 2);
		// Force SQL execution if another plugin short-circuits the query.
		add_filter('posts_pre_query', [$this, 'force_posts_pre_query'], PHP_INT_MAX - 1, 2);
		// Capture results before/after other filters mutate the posts array.
		add_filter('posts_results', [$this, 'debug_posts_results_initial'], 1, 2);
		add_filter('posts_results', [$this, 'debug_posts_results'], 20, 2);
		add_filter('posts_results', [$this, 'debug_posts_results_final'], PHP_INT_MAX, 2);

		if (function_exists('wcss_console_debug_is_enabled') && wcss_console_debug_is_enabled() && function_exists('wcss_console_debug_log')) {
			wcss_console_debug_log('WCSS mode active', [
				'mode' => 'title_only',
				'version' => defined('WCSS_SMART_SEARCH_VERSION') ? WCSS_SMART_SEARCH_VERSION : null,
				'file' => __FILE__,
			]);
		}
	}

	private function should_blank_public_s(WP_Query $query): bool
	{
		/**
		 * Whether WCSS should blank the public `s` query var.
		 *
		 * Default true for maximum compatibility with hosts/plugins that inject or rewrite
		 * WP's default search SQL based on `s` (often REGEXP). If your theme/plugins rely
		 * on `get_query_var('s')` staying populated, you can return false.
		 */
		return (bool) apply_filters('wcss_blank_public_s', true, $query);
	}

	private function get_request_search_term(): string
	{
		// Prefer the raw request value (GET) so we can recover if another plugin overwrote query vars.
		if (!isset($_GET['s']) || !is_string($_GET['s'])) {
			return '';
		}

		$raw = function_exists('wp_unslash') ? wp_unslash($_GET['s']) : $_GET['s'];
		$raw = is_string($raw) ? trim($raw) : '';
		return $raw;
	}

	private function get_raw_search_term(WP_Query $query): string
	{
		$raw = $query->get('_wcss_raw_s');
		if (is_string($raw)) {
			$raw = trim($raw);
			if ($raw !== '') {
				return $raw;
			}
		}

		$raw = $query->get('s');
		if (is_string($raw)) {
			$raw = trim($raw);
			if ($raw !== '') {
				return $raw;
			}
		}

		return $this->get_request_search_term();
	}

	public function filter_get_search_query($search_query)
	{
		if (is_admin()) {
			return $search_query;
		}

		if (!isset($GLOBALS['wp_query']) || !($GLOBALS['wp_query'] instanceof WP_Query)) {
			return $search_query;
		}

		/** @var WP_Query $q */
		$q = $GLOBALS['wp_query'];
		if ((int) $q->get('_wcss_enabled') !== 1) {
			return $search_query;
		}

		$raw = $q->get('_wcss_raw_s');
		if (!is_string($raw)) {
			return $search_query;
		}
		$raw = trim($raw);
		if ($raw === '') {
			return $search_query;
		}

		return $raw;
	}

	public function filter_posts_where($where, WP_Query $query)
	{
		if (!$this->should_handle_query($query)) {
			return $where;
		}

		$required_where = $query->get('_wcss_required_where');
		$required_where = is_string($required_where) ? trim($required_where) : '';

		$raw = (string) $query->get('_wcss_raw_s');
		$raw = trim($raw);
		if ($raw === '') {
			return $where;
		}

		$where_str = is_string($where) ? $where : '';
		$has_regexp = ($where_str !== '' && stripos($where_str, 'regexp') !== false);

		if ($has_regexp) {
			$raw_re = preg_quote($raw, '/');
			$before = $where_str;

			// Typical injected shape we saw on WP.com staging:
			// AND ( ((wp_posts.post_title REGEXP '...sush...' ) OR (wp_posts.post_content REGEXP '...sush...' AND wp_posts.post_password = '') OR (wp_posts.post_excerpt REGEXP '...sush...')))
			$where_str = preg_replace(
				"/\\s+AND\\s*\\(\\s*\\(\\(\\((?:[a-zA-Z0-9_]+\\.)?post_title\\s+REGEXP\\s+'[^']*{$raw_re}[^']*'\\)\\s+OR\\s+\\((?:[a-zA-Z0-9_]+\\.)?post_content\\s+REGEXP\\s+'[^']*{$raw_re}[^']*'[^)]*\\)\\s+OR\\s+\\((?:[a-zA-Z0-9_]+\\.)?post_excerpt\\s+REGEXP\\s+'[^']*{$raw_re}[^']*'\\)\\)\\)\\s*\\)\\s*\\)/i",
				'',
				$where_str
			);

			if ($where_str !== $before && function_exists('wcss_console_debug_log')) {
				wcss_console_debug_log('WCSS stripped injected REGEXP search clause', [
					'raw' => $raw,
				]);
			}
		}

		if ($required_where !== '' && strpos($where_str, $required_where) === false) {
			$where_str = rtrim($where_str) . " AND ({$required_where}) ";
			if (function_exists('wcss_console_debug_log')) {
				wcss_console_debug_log('WCSS enforced required WHERE tokens (posts_where)', [
					'required_where' => $required_where,
				]);
			}
		}

		return $where_str;
	}

	public function debug_posts_request($request, WP_Query $query)
	{
		if (!wcss_console_debug_is_enabled()) {
			return $request;
		}
		if (!$this->should_handle_query($query)) {
			return $request;
		}

		$request_str = is_string($request) ? $request : '';
		if ($request_str !== '') {
			// Keep it readable in the browser console.
			$request_str = preg_replace('/\s+/u', ' ', trim($request_str));
			// WP may placeholder-escape %/_ wildcards in debug output; remove it for readability.
			global $wpdb;
			if (isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'remove_placeholder_escape')) {
				$request_str = $wpdb->remove_placeholder_escape($request_str);
			}
		}

		if (!is_string($query->get('_wcss_debug_id')) || $query->get('_wcss_debug_id') === '') {
			$query->set('_wcss_debug_id', uniqid('wcss_', true));
		}

		wcss_console_debug_log('WCSS final SQL', [
			'sql' => $request_str,
			'debug_id' => (string) $query->get('_wcss_debug_id'),
			'raw' => (string) $query->get('_wcss_raw_s'),
		]);

		return $request;
	}

	public function debug_posts_results($posts, WP_Query $query)
	{
		if (!wcss_console_debug_is_enabled()) {
			return $posts;
		}
		if (!$this->should_handle_query($query)) {
			return $posts;
		}

		global $wpdb;

		if (!is_string($query->get('_wcss_debug_id')) || $query->get('_wcss_debug_id') === '') {
			$query->set('_wcss_debug_id', uniqid('wcss_', true));
		}

		// WP_Query stores the main SELECT in $query->request; this is often more useful than $wpdb->last_query
		// because WP might run SELECT FOUND_ROWS() after the main query.
		if (isset($query->request) && is_string($query->request) && trim($query->request) !== '') {
			$request_sql = preg_replace('/\s+/u', ' ', trim($query->request));
			if (method_exists($wpdb, 'remove_placeholder_escape')) {
				$request_sql = $wpdb->remove_placeholder_escape($request_sql);
			}
			wcss_console_debug_log('WCSS main query SQL (query->request)', [
				'sql' => wcss_console_debug_truncate($request_sql, 6000),
			]);
		}

		$last_error = isset($wpdb->last_error) ? (string) $wpdb->last_error : '';
		if ($last_error !== '') {
			wcss_console_debug_log('WCSS DB error', [
				'last_error' => $last_error,
			]);
		}

		$last_query = isset($wpdb->last_query) ? (string) $wpdb->last_query : '';
		if ($last_query !== '') {
			$last_query = preg_replace('/\s+/u', ' ', trim($last_query));
			if (method_exists($wpdb, 'remove_placeholder_escape')) {
				$last_query = $wpdb->remove_placeholder_escape($last_query);
			}
			wcss_console_debug_log('WCSS executed SQL (last_query)', [
				'sql' => wcss_console_debug_truncate($last_query, 2500),
				'contains_placeholder_escape' => (strpos($last_query, '{') !== false && strpos($last_query, '}') !== false),
			]);
		}

		wcss_console_debug_log('WCSS results', [
			'count' => is_array($posts) ? count($posts) : null,
			'debug_id' => (string) $query->get('_wcss_debug_id'),
		]);

		if (is_array($posts)) {
			$items = [];
			$ids = [];
			foreach (array_slice($posts, 0, 10) as $post) {
				if (!is_object($post) || !isset($post->ID)) {
					continue;
				}
				$title = isset($post->post_title) ? (string) $post->post_title : '';
				$items[] = [
					'id' => (int) $post->ID,
					'title' => $title,
				];
				$ids[] = (int) $post->ID;
			}
			if (!empty($items)) {
				wcss_console_debug_log('WCSS results sample', [
					'items' => $items,
					'debug_id' => (string) $query->get('_wcss_debug_id'),
				]);
			}

			$raw = (string) $query->get('_wcss_raw_s');
			$compact_query = wcss_compact_query($raw);
			if (!empty($ids) && $compact_query !== '') {
				$in_sql = implode(',', array_map('intval', $ids));
				$title_like = '%' . $wpdb->esc_like($raw) . '%';
				$compact_like = '%' . $wpdb->esc_like($compact_query) . '%';
				$sql = "SELECT ID, post_title, REPLACE(post_title, ' ', '') AS compact_title,"
					. " (post_title LIKE %s) AS title_match,"
					. " (REPLACE(post_title, ' ', '') LIKE %s) AS compact_match"
					. " FROM {$wpdb->posts} WHERE ID IN ({$in_sql})";
				$rows = $wpdb->get_results($wpdb->prepare($sql, $title_like, $compact_like), ARRAY_A);
				if (!empty($rows)) {
					wcss_console_debug_log('WCSS results db check', [
						'compact_query' => $compact_query,
						'debug_id' => (string) $query->get('_wcss_debug_id'),
						'rows' => $rows,
					]);
				}
			}
		}

		return $posts;
	}

	public function debug_posts_results_initial($posts, WP_Query $query)
	{
		if (!wcss_console_debug_is_enabled()) {
			return $posts;
		}
		if (!$this->should_handle_query($query)) {
			return $posts;
		}
		if (!is_array($posts)) {
			return $posts;
		}

		$ids = [];
		foreach ($posts as $post) {
			if (is_object($post) && isset($post->ID)) {
				$ids[] = (int) $post->ID;
			}
		}
		$query->set('_wcss_results_initial_ids', $ids);

		return $posts;
	}

	public function debug_posts_results_final($posts, WP_Query $query)
	{
		if (!wcss_console_debug_is_enabled()) {
			return $posts;
		}
		if (!$this->should_handle_query($query)) {
			return $posts;
		}

		$debug_id = $query->get('_wcss_debug_id');
		if (!is_string($debug_id) || $debug_id === '') {
			$debug_id = uniqid('wcss_', true);
			$query->set('_wcss_debug_id', $debug_id);
		}

		$initial_ids = $query->get('_wcss_results_initial_ids');
		$initial_ids = is_array($initial_ids) ? $initial_ids : [];
		$final_ids = [];
		if (is_array($posts)) {
			foreach ($posts as $post) {
				if (is_object($post) && isset($post->ID)) {
					$final_ids[] = (int) $post->ID;
				}
			}
		}

		$changed = ($initial_ids !== $final_ids);
		wcss_console_debug_log('WCSS results final', [
			'count' => is_array($posts) ? count($posts) : null,
			'debug_id' => (string) $debug_id,
			'changed_after_initial' => $changed,
			'initial_ids' => array_slice($initial_ids, 0, 10),
			'final_ids' => array_slice($final_ids, 0, 10),
		]);

		return $posts;
	}

	public function debug_posts_pre_query($posts, WP_Query $query)
	{
		if (!wcss_console_debug_is_enabled()) {
			return $posts;
		}
		if (!$this->should_handle_query($query)) {
			return $posts;
		}

		if (!is_string($query->get('_wcss_debug_id')) || $query->get('_wcss_debug_id') === '') {
			$query->set('_wcss_debug_id', uniqid('wcss_', true));
		}

		if ($posts !== null) {
			$count = is_array($posts) ? count($posts) : null;
			wcss_console_debug_log('WCSS posts_pre_query short-circuit', [
				'debug_id' => (string) $query->get('_wcss_debug_id'),
				'count' => $count,
			]);
		}

		return $posts;
	}

	public function force_posts_pre_query($posts, WP_Query $query)
	{
		if (!$this->should_handle_query($query)) {
			return $posts;
		}

		$force = (bool) apply_filters('wcss_force_posts_pre_query_null', true, $query);
		if (!$force) {
			return $posts;
		}

		if ($posts !== null && function_exists('wcss_console_debug_is_enabled') && wcss_console_debug_is_enabled() && function_exists('wcss_console_debug_log')) {
			wcss_console_debug_log('WCSS forced posts_pre_query to null', [
				'previous_count' => is_array($posts) ? count($posts) : null,
			]);
		}

		return null;
	}

	private function has_any_exact_title_match(array $exact_like_terms): bool
	{
		global $wpdb;
		if (!isset($wpdb) || !is_object($wpdb)) {
			return false;
		}

		$terms = array_values(array_filter(array_map('strval', $exact_like_terms), static function ($t) {
			return trim($t) !== '';
		}));
		if (empty($terms)) {
			return false;
		}

		// Bound the OR list for performance.
		$terms = array_slice($terms, 0, 3);

		$or = [];
		$params = [];
		foreach ($terms as $t) {
			$or[] = '(p.post_title LIKE %s)';
			$params[] = $t;
		}

		$where_or = implode(' OR ', $or);
		$sql = "SELECT 1 FROM {$wpdb->posts} p WHERE p.post_type = 'product' AND p.post_status IN ('publish','private') AND ({$where_or}) LIMIT 1";
		$prepared = $wpdb->prepare($sql, $params);
		$val = $wpdb->get_var($prepared);
		return !is_null($val);
	}

	private function has_any_compact_title_match(array $compact_like_terms): bool
	{
		global $wpdb;
		if (!isset($wpdb) || !is_object($wpdb)) {
			return false;
		}

		$terms = array_values(array_filter(array_map('strval', $compact_like_terms), static function ($t) {
			return trim($t) !== '';
		}));
		if (empty($terms)) {
			return false;
		}

		// Bound the OR list for performance.
		$terms = array_slice($terms, 0, 3);

		$or = [];
		$params = [];
		foreach ($terms as $t) {
			$or[] = "(REPLACE(p.post_title, ' ', '') LIKE %s)";
			$params[] = $t;
		}

		$where_or = implode(' OR ', $or);
		$sql = "SELECT 1 FROM {$wpdb->posts} p WHERE p.post_type = 'product' AND p.post_status IN ('publish','private') AND ({$where_or}) LIMIT 1";
		$prepared = $wpdb->prepare($sql, $params);
		$val = $wpdb->get_var($prepared);
		return !is_null($val);
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
		// IMPORTANT: Many hosts/plugins rewrite WP's default search clause to REGEXP based on the public `s` var.
		// That REGEXP can exclude typo queries like "sush" before our fuzzy LIKE logic can include matches.
		// So we stash the real search term and (optionally) blank out `s` to prevent any default/rewritten search clause.
		$query->set('_wcss_raw_s', (string) $query->get('s'));
		if ($this->should_blank_public_s($query)) {
			$query->set('s', '');
		}

		if (function_exists('wcss_console_debug_log')) {
			if (!is_string($query->get('_wcss_debug_id')) || $query->get('_wcss_debug_id') === '') {
				$query->set('_wcss_debug_id', uniqid('wcss_', true));
			}
			wcss_console_debug_log('WCSS flagged product search query', [
				's' => (string) $query->get('_wcss_raw_s'),
				'post_type' => $post_type,
				'debug_id' => (string) $query->get('_wcss_debug_id'),
			]);
		}
	}

	public function enforce_wcss_query_constraints(WP_Query $query)
	{
		if (!$this->should_handle_query($query)) {
			return;
		}

		// If another plugin/theme wiped our stored term, try to recover it from the request.
		$raw = $query->get('_wcss_raw_s');
		if (!is_string($raw) || trim($raw) === '') {
			$req = $this->get_request_search_term();
			if ($req !== '') {
				$query->set('_wcss_raw_s', $req);
				if (function_exists('wcss_console_debug_is_enabled') && wcss_console_debug_is_enabled() && function_exists('wcss_console_debug_log')) {
					wcss_console_debug_log('WCSS recovered missing _wcss_raw_s from request', [
						'recovered' => $req,
						'was_blank_public_s' => $this->should_blank_public_s($query),
					]);
				}
			}
		}

		// Keep query limited to products even if another plugin broadens it.
		$post_type = $query->get('post_type');
		$needs_fix = true;
		if (is_string($post_type) && $post_type === 'product') {
			$needs_fix = false;
		} elseif (is_array($post_type) && count($post_type) === 1 && isset($post_type[0]) && $post_type[0] === 'product') {
			$needs_fix = false;
		}
		if ($needs_fix) {
			$query->set('post_type', 'product');
		}

		// Ensure the public `s` remains blank so default search SQL doesn't come back.
		if ($this->should_blank_public_s($query) && (string) $query->get('s') !== '') {
			$query->set('s', '');
		}
	}

	public function filter_posts_search($search_sql, WP_Query $query)
	{
		if (!$this->should_handle_query($query)) {
			return $search_sql;
		}

		$raw = $this->get_raw_search_term($query);
		if ($raw === '') {
			// If `s` was blanked and we can't recover a term, WP's default search SQL is often empty.
			// Returning an empty search clause would cause the product archive to show "all products".
			$search_sql_str = is_string($search_sql) ? trim($search_sql) : '';
			if ($search_sql_str === '' && $this->should_blank_public_s($query)) {
				if (function_exists('wcss_console_debug_is_enabled') && wcss_console_debug_is_enabled() && function_exists('wcss_console_debug_log')) {
					wcss_console_debug_log('WCSS prevented unfiltered results (missing search term)', [
						'action' => 'AND 1=0',
						'reason' => 'raw term missing and default search SQL empty while public s is blanked',
					]);
				}
				return ' AND 1=0 ';
			}
			return $search_sql;
		}

		$payload = $this->get_like_terms($query);
		$exact_terms = $payload['exact'];
		$fuzzy_terms = $payload['fuzzy'];
		$compact_terms = $payload['compact'];
		$tokens = $payload['tokens'];
		$required_tokens = $payload['tokens'];

		// If we already have real (exact) title hits, don't broaden the WHERE with fuzzy patterns.
		// Fuzzy remains available when exact matches are absent (useful for typos).
		$gate_fuzzy = (bool) apply_filters('wcss_gate_fuzzy_when_exact_matches', true, $raw, $query);
		$gate_compact = (bool) apply_filters('wcss_gate_fuzzy_when_compact_matches', true, $raw, $query);
		$has_exact = $query->get('_wcss_has_exact_title_match');
		$has_compact = $query->get('_wcss_has_compact_title_match');
		if (!is_bool($has_exact)) {
			$has_exact = !empty($exact_terms) && $this->has_any_exact_title_match($exact_terms);
			$query->set('_wcss_has_exact_title_match', $has_exact);
		}
		if (!is_bool($has_compact)) {
			$has_compact = $gate_compact && !empty($compact_terms) && $this->has_any_compact_title_match($compact_terms);
			$query->set('_wcss_has_compact_title_match', $has_compact);
		}

		$allow_fuzzy_where = (bool) apply_filters('wcss_allow_fuzzy_where_when_exact_or_compact_exists', false, $raw, $query, $has_exact, $has_compact);
		$include_fuzzy_where = $allow_fuzzy_where || (!$has_exact && !$has_compact);
		if ($gate_fuzzy && !empty($fuzzy_terms) && !$include_fuzzy_where) {
			$fuzzy_terms = [];
			if (function_exists('wcss_console_debug_is_enabled') && wcss_console_debug_is_enabled() && function_exists('wcss_console_debug_log')) {
				wcss_console_debug_log('WCSS gated fuzzy WHERE (exact or compact matches exist)', [
					'raw' => $raw,
					'action' => 'exclude_fuzzy_terms_from_where',
					'has_exact' => $has_exact,
					'has_compact' => $has_compact,
				]);
			}
		}

		if (empty($exact_terms) && empty($fuzzy_terms) && empty($compact_terms)) {
			$search_sql_str = is_string($search_sql) ? trim($search_sql) : '';
			if ($search_sql_str === '' && $this->should_blank_public_s($query)) {
				if (function_exists('wcss_console_debug_is_enabled') && wcss_console_debug_is_enabled() && function_exists('wcss_console_debug_log')) {
					wcss_console_debug_log('WCSS prevented unfiltered results (no LIKE terms built)', [
						'action' => 'AND 1=0',
						'reason' => 'no terms built and default search SQL empty while public s is blanked',
					]);
				}
				return ' AND 1=0 ';
			}
			return $search_sql;
		}

		global $wpdb;
		$or = [];
		$and = [];
		$enforce_brand_structure = (bool) apply_filters('wcss_require_tokens_after_brand', true, $raw, $query);
		if ($enforce_brand_structure && count($tokens) > 1) {
			$unit_tokens = [
				'mg', 'g', 'kg',
				'ml', 'l',
				'oz',
				'lb', 'lbs',
				'mm', 'cm', 'in',
			];
			$required = array_values(array_filter(array_slice($tokens, 1), static function ($t) use ($unit_tokens) {
				$t = (string) $t;
				if ($t === '') {
					return false;
				}
				if (preg_match('/^\d+(?:\.\d+)?$/', $t)) {
					return false;
				}
				if (in_array($t, $unit_tokens, true)) {
					return false;
				}
				return true;
			}));

			foreach ($required as $token) {
				$token = (string) $token;
				if ($token === '') {
					continue;
				}
				$token_like = '%' . $wpdb->esc_like($token) . '%';
				$or_parts = [
					$wpdb->prepare("{$wpdb->posts}.post_title LIKE %s", $token_like),
					$wpdb->prepare("REPLACE({$wpdb->posts}.post_title, ' ', '') LIKE %s", $token_like),
				];
				$fuzzy_for_token = wcss_build_fuzzy_like_terms([$token]);
				foreach ($fuzzy_for_token as $fuzzy_like) {
					$fuzzy_like = (string) $fuzzy_like;
					if ($fuzzy_like === '') {
						continue;
					}
					$or_parts[] = $wpdb->prepare("{$wpdb->posts}.post_title LIKE %s", $fuzzy_like);
				}
				$and[] = '(' . implode(' OR ', array_unique($or_parts)) . ')';
			}
		}

		if (function_exists('wcss_console_debug_is_enabled') && wcss_console_debug_is_enabled()) {
			$sample_fuzzy = array_slice(array_values((array) $fuzzy_terms), 0, 2);
			$sample_exact = array_slice(array_values((array) $exact_terms), 0, 1);
			$diagnostic_terms = array_merge($sample_exact, $sample_fuzzy);
			$diagnostics = [];

			foreach ($diagnostic_terms as $term_like) {
				$term_like = (string) $term_like;
				if ($term_like === '') {
					continue;
				}

				$title_count = (int) $wpdb->get_var($wpdb->prepare(
					"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p WHERE p.post_type = 'product' AND p.post_status = 'publish' AND p.post_title LIKE %s",
					$term_like
				));
				$excerpt_count = (int) $wpdb->get_var($wpdb->prepare(
					"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p WHERE p.post_type = 'product' AND p.post_status = 'publish' AND p.post_excerpt LIKE %s",
					$term_like
				));
				$sku_count = (int) $wpdb->get_var($wpdb->prepare(
					"SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON (p.ID = pm.post_id) WHERE p.post_type = 'product' AND p.post_status = 'publish' AND pm.meta_key = '_sku' AND pm.meta_value LIKE %s",
					$term_like
				));
				$content_count = (int) $wpdb->get_var($wpdb->prepare(
					"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p WHERE p.post_type = 'product' AND p.post_status = 'publish' AND p.post_content LIKE %s",
					$term_like
				));

				$diagnostics[] = [
					'term' => $term_like,
					'title_matches' => $title_count,
					'excerpt_matches' => $excerpt_count,
					'content_matches' => $content_count,
					'sku_matches' => $sku_count,
				];
			}

			if (!empty($diagnostics)) {
				wcss_console_debug_log('WCSS diagnostic match counts (publish products)', [
					'note' => 'These counts ignore product visibility/exclusion tax queries; they are only a quick check that the LIKE patterns match any publish product fields at all.',
					'counts' => $diagnostics,
				]);
			}
		}

		foreach ($exact_terms as $term_like) {
			$or[] = $wpdb->prepare("{$wpdb->posts}.post_title LIKE %s", $term_like);
		}

		if ($include_fuzzy_where) {
			foreach ($fuzzy_terms as $term_like) {
				$or[] = $wpdb->prepare("{$wpdb->posts}.post_title LIKE %s", $term_like);
			}
		}

		// Space-insensitive phrase matching: allow `productname` to match `product name`.
		// This uses REPLACE(..., ' ', '') so it only strips regular spaces (not all whitespace).
		foreach ($compact_terms as $term_like) {
			$or[] = $wpdb->prepare("REPLACE({$wpdb->posts}.post_title, ' ', '') LIKE %s", $term_like);
		}

		$sequence_sql = '';
		$enable_sequence_match = (bool) apply_filters('wcss_enable_compact_sequence_match', true, $raw, $query);
		if ($enable_sequence_match) {
			$compact_query = wcss_compact_query($raw);
			if ($compact_query !== '') {
				$compact_like = '%' . $wpdb->esc_like($compact_query) . '%';
				$sequence_parts = [
					$wpdb->prepare("REPLACE({$wpdb->posts}.post_title, ' ', '') LIKE %s", $compact_like),
				];

				$fuzzy_compact = wcss_build_fuzzy_like_terms([$compact_query]);
				foreach ($fuzzy_compact as $fuzzy_like) {
					$fuzzy_like = (string) $fuzzy_like;
					if ($fuzzy_like === '') {
						continue;
					}
					$sequence_parts[] = $wpdb->prepare("REPLACE({$wpdb->posts}.post_title, ' ', '') LIKE %s", $fuzzy_like);
				}

				$sequence_sql = implode(' OR ', array_unique($sequence_parts));
			}
		}

		$or_sql = implode(' OR ', array_unique($or));
		if ($or_sql === '') {
			return $sequence_sql !== '' ? " AND ({$sequence_sql}) " : $search_sql;
		}

		if (function_exists('wcss_console_debug_is_enabled') && wcss_console_debug_is_enabled()) {
			wcss_console_debug_log('WCSS search OR', [
				'exact_like_terms' => $exact_terms,
				'fuzzy_like_terms' => $fuzzy_terms,
				'compact_like_terms' => $compact_terms,
				'has_exact' => $has_exact,
				'has_compact' => $has_compact,
				'include_fuzzy_where' => $include_fuzzy_where,
				'sequence_sql' => $sequence_sql,
				'required_tokens' => $tokens,
				'required_after_brand' => $and,
				'or_sql' => $or_sql,
			]);
		}

		if (!empty($and)) {
			$and_sql = implode(' AND ', array_unique($and));
			$query->set('_wcss_required_where', $and_sql);
			if ($sequence_sql !== '') {
				return " AND ({$or_sql}) AND ({$sequence_sql}) AND ({$and_sql}) ";
			}
			return " AND ({$or_sql}) AND ({$and_sql}) ";
		}

		$query->set('_wcss_required_where', '');
		if ($sequence_sql !== '') {
			return " AND ({$or_sql}) AND ({$sequence_sql}) ";
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
		$compact_terms = $payload['compact'];

		if (empty($exact_terms) && empty($fuzzy_terms) && empty($compact_terms)) {
			return $clauses;
		}

		global $wpdb;
		$weights = wcss_get_weights();
		if (function_exists('wcss_console_debug_is_enabled') && wcss_console_debug_is_enabled()) {
			wcss_console_debug_log('WCSS resolved weights', [
				'title' => isset($weights['title']) ? (int) $weights['title'] : null,
				'title_phrase' => isset($weights['title_phrase']) ? (int) $weights['title_phrase'] : null,
				'title_all_tokens' => isset($weights['title_all_tokens']) ? (int) $weights['title_all_tokens'] : null,
				'title_fuzzy' => isset($weights['title_fuzzy']) ? (int) $weights['title_fuzzy'] : null,
			]);
		}
		$score_parts = [];

		if (!empty($required_tokens)) {
			$and_parts = [];
			foreach ($required_tokens as $token) {
				$token = (string) $token;
				if ($token === '') {
					continue;
				}
				$and_parts[] = $wpdb->prepare("{$wpdb->posts}.post_title LIKE %s", '%' . $wpdb->esc_like($token) . '%');
			}
			if (!empty($and_parts)) {
				$and_sql = implode(' AND ', $and_parts);
				$score_parts[] = $wpdb->prepare("(CASE WHEN {$and_sql} THEN %d ELSE 0 END)", (int) $weights['title_all_tokens']);
			}
		}

		foreach ($exact_terms as $term_like) {
			// Phrase matches (e.g. "%peach fuzz%") are usually more specific than single-word matches.
			// Boost them so product-name phrases rank above generic token matches.
			$is_phrase = (is_string($term_like) && strpos($term_like, ' ') !== false);
			$w = $is_phrase ? (int) ($weights['title_phrase'] ?? $weights['title']) : (int) $weights['title'];
			$score_parts[] = $wpdb->prepare("(CASE WHEN {$wpdb->posts}.post_title LIKE %s THEN %d ELSE 0 END)", $term_like, $w);
		}

		foreach ($fuzzy_terms as $term_like) {
			$score_parts[] = $wpdb->prepare("(CASE WHEN {$wpdb->posts}.post_title LIKE %s THEN %d ELSE 0 END)", $term_like, (int) $weights['title_fuzzy']);
		}

		foreach ($compact_terms as $term_like) {
			$score_parts[] = $wpdb->prepare("(CASE WHEN REPLACE({$wpdb->posts}.post_title, ' ', '') LIKE %s THEN %d ELSE 0 END)", $term_like, (int) $weights['title_fuzzy']);
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

		$clauses['orderby'] = "wcss_relevance DESC, {$wpdb->posts}.post_date DESC";

		if (function_exists('wcss_console_debug_is_enabled') && wcss_console_debug_is_enabled()) {
			wcss_console_debug_log('WCSS clauses', [
				'where' => isset($clauses['where']) ? (string) $clauses['where'] : '',
				'join' => isset($clauses['join']) ? (string) $clauses['join'] : '',
				'groupby' => isset($clauses['groupby']) ? (string) $clauses['groupby'] : '',
				'orderby' => isset($clauses['orderby']) ? (string) $clauses['orderby'] : '',
			]);
		}

		return $clauses;
	}

	private function should_handle_query(WP_Query $query): bool
	{
		$forced = ((int) $query->get('_wcss_force') === 1);
		if (!$forced) {
			if (is_admin() || !$query->is_main_query()) {
				return false;
			}
		}

		// IMPORTANT: we intentionally blank the public `s` query var to prevent core/host
		// search SQL injection (often REGEXP). So we must NOT use `$query->get('s')` to
		// decide whether to run; rely on our internal flag instead.
		if ((int) $query->get('_wcss_enabled') !== 1) {
			return false;
		}

		return true;
	}

	private function get_like_terms(WP_Query $query): array
	{
		$raw = $this->get_raw_search_term($query);
		if ($raw === '') {
			return ['exact' => [], 'fuzzy' => [], 'compact' => [], 'tokens' => []];
		}

		$payload = wcss_build_search_payload($raw);
		if (function_exists('wcss_console_debug_log')) {
			$exact_preview = isset($payload['exact_like_terms']) ? implode(' | ', array_slice((array) $payload['exact_like_terms'], 0, 8)) : '';
			$fuzzy_preview = isset($payload['fuzzy_like_terms']) ? implode(' | ', array_slice((array) $payload['fuzzy_like_terms'], 0, 8)) : '';
			wcss_console_debug_log('WCSS payload built', [
				'raw' => $raw,
				'exact_like_terms' => isset($payload['exact_like_terms']) ? (array) $payload['exact_like_terms'] : [],
				'fuzzy_like_terms' => isset($payload['fuzzy_like_terms']) ? (array) $payload['fuzzy_like_terms'] : [],
				'exact_preview' => $exact_preview,
				'fuzzy_preview' => $fuzzy_preview,
			]);
		}


		return [
			'exact' => $payload['exact_like_terms'] ?? [],
			'fuzzy' => $payload['fuzzy_like_terms'] ?? [],
			'compact' => $payload['compact_like_terms'] ?? [],
			'tokens' => $payload['tokens_required'] ?? [],
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

/**
 * Run the smart search logic against a custom WP_Query (e.g., AJAX suggestions).
 */
function wcss_query_products_smart(string $term, array $args = []): WP_Query
{
	$term = wp_strip_all_tags($term);
	$term = preg_replace('/\s+/u', ' ', trim($term));

	WCSS_Smarter_Search::init();

	$defaults = [
		'post_type' => 'product',
		'post_status' => 'publish',
		'posts_per_page' => 16,
		's' => '',
		'ignore_sticky_posts' => true,
		'no_found_rows' => true,
		'_wcss_enabled' => 1,
		'_wcss_raw_s' => $term,
		'_wcss_force' => 1,
	];

	$query_args = wp_parse_args($args, $defaults);
	$query_args['_wcss_enabled'] = 1;
	$query_args['_wcss_raw_s'] = $term;
	$query_args['_wcss_force'] = 1;
	if (!isset($query_args['s'])) {
		$query_args['s'] = '';
	}

	return new WP_Query($query_args);
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
	$lower_original = $lower;

	// Tokenize on whitespace. Keep it simple (MVP).
	$tokens_all = preg_split('/\s+/u', $lower) ?: [];
	$tokens_all = array_values(array_filter(array_map('trim', $tokens_all), static function ($t) {
		return (string) $t !== '';
	}));

	$has_long_token = false;
	foreach ($tokens_all as $t) {
		$len = function_exists('mb_strlen') ? mb_strlen($t) : strlen($t);
		if ($len >= 3) {
			$has_long_token = true;
			break;
		}
	}

	$has_numeric_token = false;
	foreach ($tokens_all as $t) {
		if (preg_match('/^\d+(?:\.\d+)?$/', (string) $t)) {
			$has_numeric_token = true;
			break;
		}
	}

	$unit_tokens = [
		'mg', 'g', 'kg',
		'ml', 'l',
		'oz',
		'lb', 'lbs',
		'mm', 'cm', 'in',
	];

	// If the query contains at least one substantial token (len>=3), drop short tokens by default
	// because they match too broadly.
	// EXCEPTION: keep numeric strength tokens (e.g. "6") and allow unit tokens (e.g. "mg") only
	// when a number is present so searches like "peach fuzz 6 mg" can prioritize the correct variant.
	$tokens = $has_long_token
		? array_values(array_filter($tokens_all, static function ($t) use ($has_numeric_token, $unit_tokens) {
			$t = (string) $t;
			$len = function_exists('mb_strlen') ? mb_strlen($t) : strlen($t);
			if ($len >= 3) {
				return true;
			}
			if (preg_match('/^\d+(?:\.\d+)?$/', $t)) {
				return true;
			}
			if ($has_numeric_token && in_array($t, $unit_tokens, true)) {
				return true;
			}
			return false;
		}))
		: $tokens_all;

	$tokens_required = $tokens;

	// Merge adjacent number + unit into a stronger token ("6" + "mg" => "6mg"), and remember
	// the spaced phrase ("6 mg") so titles that include a space still match well.
	$unit_phrases = [];
	$merged_tokens = [];
	for ($i = 0; $i < count($tokens); $i++) {
		$cur = isset($tokens[$i]) ? trim((string) $tokens[$i]) : '';
		$nxt = isset($tokens[$i + 1]) ? trim((string) $tokens[$i + 1]) : '';
		if ($cur === '') {
			continue;
		}

		if ($nxt !== '' && preg_match('/^\d+(?:\.\d+)?$/', $cur) && in_array($nxt, $unit_tokens, true)) {
			$unit_phrases[] = $cur . ' ' . $nxt;
			$merged_tokens[] = $cur;
			$merged_tokens[] = $cur . $nxt;
			$i++; // skip unit token
			continue;
		}

		$merged_tokens[] = $cur;
	}
	$tokens = array_values(array_unique(array_filter($merged_tokens, static function ($t) {
		return trim((string) $t) !== '';
	})));

	// Keep query tokens bounded for performance.
	$tokens = array_slice($tokens, 0, 6);

	$terms = [];
	foreach ($tokens as $t) {
		$terms[] = $t;
	}

	foreach ($unit_phrases as $p) {
		$p = trim((string) $p);
		if ($p !== '') {
			$terms[] = $p;
		}
	}

	// For multi-token queries, also add adjacent phrases (bigrams) like "peach fuzz".
	// This improves ranking for products that match the phrase exactly, without requiring all tokens.
	if (count($tokens) >= 2) {
		$max_bigrams = 5;
		$limit = min(count($tokens) - 1, $max_bigrams);
		for ($i = 0; $i < $limit; $i++) {
			$a = isset($tokens[$i]) ? trim((string) $tokens[$i]) : '';
			$b = isset($tokens[$i + 1]) ? trim((string) $tokens[$i + 1]) : '';
			if ($a === '' || $b === '') {
				continue;
			}
			$terms[] = $a . ' ' . $b;
		}
	}

	// Add the full phrase as an additional (often useful) match term.
	// IMPORTANT: build it from the filtered tokens so dropped short tokens don't re-enter via the phrase.
	if (count($tokens) > 1) {
		$lower_filtered = implode(' ', $tokens);
		if ($lower_filtered !== '') {
			$terms[] = $lower_filtered;
		}
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
		$has_phrase = (strpos(" {$lower_original} ", " {$from_norm} ") !== false);

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

	// Space-insensitive phrase matching (e.g., productname <-> product name).
	$compact_like_terms = [];
	$compact_phrase = preg_replace('/\s+/u', '', implode(' ', $tokens));
	$compact_phrase = is_string($compact_phrase) ? trim($compact_phrase) : '';
	if ($compact_phrase !== '') {
		$compact_len = function_exists('mb_strlen') ? mb_strlen($compact_phrase) : strlen($compact_phrase);
		if ($compact_len >= 3) {
			$compact_like_terms[] = '%' . $wpdb->esc_like($compact_phrase) . '%';
		}
	}

	// Also add compact adjacent phrases for cases like "peachfuzz".
	if (count($tokens) >= 2) {
		$max_compact_bigrams = 5;
		$limit = min(count($tokens) - 1, $max_compact_bigrams);
		for ($i = 0; $i < $limit; $i++) {
			$a = isset($tokens[$i]) ? trim((string) $tokens[$i]) : '';
			$b = isset($tokens[$i + 1]) ? trim((string) $tokens[$i + 1]) : '';
			if ($a === '' || $b === '') {
				continue;
			}
			$compact = preg_replace('/\s+/u', '', $a . ' ' . $b);
			$compact = is_string($compact) ? trim($compact) : '';
			if ($compact === '') {
				continue;
			}
			$len = function_exists('mb_strlen') ? mb_strlen($compact) : strlen($compact);
			if ($len < 3) {
				continue;
			}
			$compact_like_terms[] = '%' . $wpdb->esc_like($compact) . '%';
		}
	}
	$compact_like_terms = array_values(array_unique($compact_like_terms));

	// Fuzzy/typo-tolerant LIKE patterns (lightweight, bounded).
	$fuzzy_like_terms = [];
	// Default: enable fuzzy only for single-token searches (multi-token fuzzy tends to be overly broad).
	$enable_fuzzy_default = (count($tokens) <= 1);
	$enable_fuzzy = apply_filters('wcss_enable_fuzzy', $enable_fuzzy_default, $raw);
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
		'compact_like_terms' => $compact_like_terms,
		'tokens_required' => array_values(array_unique(array_filter(array_map('strval', $tokens_required), static function ($t) {
			return trim((string) $t) !== '';
		}))),
	];

	$payload = apply_filters('wcss_like_term_payload', $payload, $raw);
	if (!is_array($payload)) {
		return [
			'exact_like_terms' => $exact_like_terms,
			'fuzzy_like_terms' => $fuzzy_like_terms,
			'compact_like_terms' => $compact_like_terms,
		];
	}

	// Back-compat: allow older integrations that used wcss_like_terms.
	$payload['exact_like_terms'] = apply_filters('wcss_like_terms', $payload['exact_like_terms'] ?? $exact_like_terms, $raw);

	return [
		'exact_like_terms' => array_values(array_unique(array_filter((array) ($payload['exact_like_terms'] ?? [])))),
		'fuzzy_like_terms' => array_values(array_unique(array_filter((array) ($payload['fuzzy_like_terms'] ?? [])))),
		'compact_like_terms' => array_values(array_unique(array_filter((array) ($payload['compact_like_terms'] ?? [])))),
	];
}

function wcss_compact_query(string $raw): string
{
	$raw = wp_strip_all_tags($raw);
	$raw = preg_replace('/\s+/u', ' ', trim($raw));
	if ($raw === '') {
		return '';
	}

	$lower = function_exists('mb_strtolower') ? mb_strtolower($raw) : strtolower($raw);
	$compact = preg_replace('/\s+/u', '', $lower);
	return is_string($compact) ? trim($compact) : '';
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
		if ($len < 3) {
			continue;
		}

		// For longer single-word tokens, try simple "word break" tolerance.
		// Example: "brickhouse" should match "brick house" via "%brick%house%".
		if ($len >= 8 && $len <= 14) {
			$mid = (int) floor($len / 2);
			$split_points = array_values(array_unique([
				$mid - 1,
				$mid,
				$mid + 1,
			]));

			foreach ($split_points as $i) {
				if ($i < 3 || $i > $len - 3) {
					continue;
				}

				$left = function_exists('mb_substr') ? mb_substr($token, 0, $i) : substr($token, 0, $i);
				$right = function_exists('mb_substr') ? mb_substr($token, $i) : substr($token, $i);
				$left = trim($left);
				$right = trim($right);
				if ($left === '' || $right === '') {
					continue;
				}

				$patterns[] = '%' . $wpdb->esc_like($left) . '%' . $wpdb->esc_like($right) . '%';
				if (count($patterns) >= $max) {
					break 2;
				}
			}

			// Don't generate the more expensive short-token patterns for long strings.
			continue;
		}

		// Keep the original typo-tolerance logic for short tokens.
		if ($len > 7) {
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

		// Extra tolerance for short-ish tokens: allow arbitrary characters between every letter.
		// Example: token=sush => %s%u%s%h% matches "slush".
		if ($len >= 4 && $len <= 5 && count($patterns) < $max) {
			$chars = preg_split('//u', $token, -1, PREG_SPLIT_NO_EMPTY) ?: [];
			if (!empty($chars)) {
				$chain = '%';
				foreach ($chars as $ch) {
					$chain .= $wpdb->esc_like($ch) . '%';
				}
				$patterns[] = $chain;
			}
		}

		// Very short tokens (3 chars): also allow a wildcard between every character.
		// token=abc => %a%b%c%
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
		'title' => 80,
		// More specific than a single-word token match (e.g. "%peach fuzz%" vs "%peach%").
		'title_phrase' => 140,
		// Bonus when the title contains all required tokens (helps full matches win).
		'title_all_tokens' => 500,
		'sku' => 40,
		'attribute' => 25,
		'taxonomy' => 20,
		'excerpt' => 20,
		'content' => 10,
		'title_fuzzy' => 8,
		'sku_fuzzy' => 6,
	];

	/**
	 * Filter weights.
	 *
	 * Keys: title, title_phrase, title_all_tokens, sku, attribute, taxonomy, excerpt, content, title_fuzzy, sku_fuzzy
	 */
	$filtered = apply_filters('wcss_weights', $default);
	$weights = is_array($filtered) ? array_merge($default, $filtered) : $default;

	// Safety: phrase matches should generally score higher than single-token matches.
	// If a site custom filter accidentally makes them equal/lower, enforce a minimum.
	$enforce_phrase_min = (bool) apply_filters('wcss_enforce_phrase_weight_minimum', true, $weights);
	if ($enforce_phrase_min) {
		$title = isset($weights['title']) ? (int) $weights['title'] : 0;
		$title_phrase = isset($weights['title_phrase']) ? (int) $weights['title_phrase'] : $title;
		if ($title_phrase <= $title) {
			$weights['title_phrase'] = max((int) $default['title_phrase'], $title + 10);
		}
	}

	return $weights;
}
