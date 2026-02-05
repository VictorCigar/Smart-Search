Here’s a concise prompt you can send to his agent:

Please update the AJAX search to use my smart search helper if available. In your ajax_search() (fullscreen-search.php), replace the WP_Query block with:

if (function_exists('wcss_query_products_smart')) {
    $query = wcss_query_products_smart($term, [
        'posts_per_page' => 16,
        'post_status' => 'publish',
    ]);
} else {
    $query = new WP_Query([
        's' => $term,
        'posts_per_page' => 16,
        'post_status' => 'publish',
    ]);
}
No JS changes needed. This keeps your UI but routes search through the smart logic when my plugin is active.