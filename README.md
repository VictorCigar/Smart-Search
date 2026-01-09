# Smarter Woo Product Search

Server-side enhancements for the stock WooCommerce search endpoint (`/?s=term&post_type=product`). The plugin keeps your theme forms and URLs untouched while delivering more relevant storefront results via weighted scoring, synonym expansion, and lightweight typo tolerance.

## Requirements
- WordPress 5.8+
- PHP 7.4+
- WooCommerce 5.0+ (tested up to 9.0)
- Classic product tables (HPOS compatibility not yet verified)

## Key Capabilities
- Boosts title, SKU, attribute, taxonomy, excerpt, and content matches using weighted relevance scoring
- Expands queries with configurable synonyms and whole-phrase variants
- Adds partial/fuzzy matching for short tokens to absorb common typos
- Works entirely through core query hooks (`pre_get_posts`, `posts_search`, `posts_clauses`) without front-end changes
- Deduplicates results when taxonomy joins would otherwise create duplicates

## How It Works
1. **Flag product searches**: `pre_get_posts` detects main front-end searches scoped to `product` and injects `_wcss_enabled`.
2. **Rewrite WHERE clause**: `posts_search` swaps the default search SQL for targeted LIKE comparisons across posts, `_sku`, and taxonomy names/slugs.
3. **Augment JOIN + scoring**: `posts_clauses` adds postmeta/term joins, computes `wcss_relevance`, groups by product ID, and orders by relevance then recency.
4. **Payload builder**: Helper functions tokenize the query, expand synonyms, and generate exact/fuzzy LIKE patterns, all filterable.

## Installation
1. Copy the `Smart-Search` folder into `wp-content/plugins/`.
2. In wp-admin → Plugins, activate **Smarter Woo Product Search**.
3. Use the default WooCommerce search form (`/?s=...&post_type=product`) and confirm results improve.

## Configuration Hooks
All customization happens via standard filters—use a small mu-plugin or your theme’s functions file.

### Synonyms
```php
add_filter('wcss_synonyms', function ($map) {
    $map['hoodie'] = ['pullover', 'sweatshirt'];
    return $map;
});
```

### Relevance Weights
```php
add_filter('wcss_weights', function ($weights) {
    $weights['sku'] = 60;      // emphasize SKU matches
    $weights['content'] = 5;   // de-emphasize long descriptions
    return $weights;
});
```

### Limit or Disable Fuzzy Matching
```php
add_filter('wcss_enable_fuzzy', function ($enabled, $raw_query) {
    return strlen($raw_query) <= 30; // disable for very long phrases
}, 10, 2);

add_filter('wcss_fuzzy_max_terms', function () {
    return 5; // default is 10
});
```

### Inspect/Override LIKE Payload
```php
add_filter('wcss_like_term_payload', function ($payload, $raw) {
    // Example: ensure SKU prefixes are included
    if (preg_match('/^SKU-(\d+)/', $raw, $m)) {
        $payload['exact_like_terms'][] = '%' . $m[0] . '%';
    }
    return $payload;
}, 10, 2);
```

## Go-Live Checklist
- ✅ Activate on a staging copy of your store.
- ✅ Run representative searches (titles, SKUs, attributes, synonyms, typos) and confirm ordering looks right.
- ✅ Profile query performance on large catalogs (enable query monitor or check slow-query logs).
- ✅ Confirm compatibility if High-Performance Order Storage or custom product tables are enabled.
- ✅ Document any custom filters you add for future maintenance.
- ✅ Back up the site and deploy to production during a low-traffic window.

## Troubleshooting
- **No change in results**: ensure searches include `post_type=product` or that the theme form is the default WooCommerce search widget.
- **Slow queries**: reduce term caps via `wcss_like_term_payload` or disable fuzzy matching for long queries.
- **Duplicate products**: verify no other plugin alters `posts_clauses` ordering/grouping; this plugin already groups by `ID`.

## Support
Open an issue in your internal tracker or extend the hooks above. The code is intentionally compact so you can adapt it to more advanced scoring logic if needed.