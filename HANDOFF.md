Handoff summary for next agent

Project: Smart Woo Product Search (WordPress/WooCommerce).
Workspace: c:\Users\onyej\OneDrive\Desktop\Cigar Chief Work Space\plugins\Smart Woo Product Search\Smart-Search
Active file: smarter-woo-product-search.php

Goal
- Title-only product search with ranking that prioritizes brand-first product names.
- For "Slush Peach Fuzz 6 mg", results should prioritize/require "Peach Fuzz" over other Slush variants.
- Allow partials for single-token searches (e.g., "slush" returns all Slush products).
- Allow typos and missing spaces in product name ("pech", "peachfuzz").
- Do not require strength tokens (e.g., "6", "mg") for the product-name constraint.

Key changes in local file (not yet reflected in live SQL)
- Added title_all_tokens weight (default 500) to boost titles containing all required tokens.
- Required-after-brand enforcement in WHERE:
  - For multi-token queries, all tokens after the first (brand) are required.
  - Numeric/unit tokens are excluded from required list.
  - Each required token matches via exact, compact (spaces removed), or fuzzy LIKE.
- Added persistence of required WHERE clause via _wcss_required_where and appended in posts_where to avoid late filter overwrites.
- Bumped WCSS_SMART_SEARCH_VERSION to 0.1.16 to match plugin header.

Current problem
- Final SQL captured in console does NOT include:
  - Any title_all_tokens CASE WHEN block, or
  - The required-after-brand AND clause.
- This implies live code is not using the updated file or a later filter is stripping it.

What to verify on live site
- With ?wcss_debug=1, check console logs:
  - "WCSS mode active" (shows plugin file path and version).
  - "WCSS resolved weights" should include title_all_tokens.
  - "WCSS enforced required WHERE tokens (posts_where)" log should appear if the clause is appended.
- If logs are missing or show old version, the active plugin file is not the updated one (deployment mismatch or cache).

Suggested next steps
- Confirm which plugin file is actually loaded on server (log shows __FILE__ path).
- Clear OPcache/object cache if applicable.
- Ensure the updated plugin is the active copy.
- If the live SQL still lacks required clause after confirming file/version, identify a late filter removing it (plugins/themes or host rewrites). Consider raising filter priority further or using posts_request to post-process.
