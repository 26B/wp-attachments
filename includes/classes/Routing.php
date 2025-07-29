<?php

namespace TSB\WP\Plugin\Attachments;

use TenupFramework\Module;
use TenupFramework\ModuleInterface;

class Routing implements ModuleInterface {

	use Module;

	public function can_register(): bool {
		return ! \is_admin();
	}

	public function register(): void {

		// Stop WordPress from matching attachments during page regex matches.
		\add_filter( 'do_parse_request', [ $this, 'do_parse_request' ], PHP_INT_MAX );
	}

	/**
	 * When WordPress is parsing the request, we want to ovverride the default behavior of
	 * matching rewrite rules.
	 *
	 * The issue is related to the function call `get_page_by_path` in `class-wp.php`. If a
	 * shorthand for a category is used for the route (e.g. `/page/` instead of `/category/page/`),
	 * it might get redirected to an attachment that has the same name (slug) as the category.
	 * This is because the `get_page_by_path` function will return the first post that is a page
	 * or an attachment with the same name.
	 *
	 * To fix this, on 'do_parse_request' we will try to match the requested path against
	 * the rewrite rules using copied code from WordPress, and return a single rule that matches
	 * the requested path, keeping the `get_page_by_path` call only to pages and not
	 * attachments. If we match any rule, we will override the rewrite rules to only return that
	 * rule, so that WordPress will not try to match any other rule. The rest of the behaviour
	 * will be the same and done by the WP core.
	 *
	 * Some extra code is added to ensure that the rewrite rules are not updated when we return
	 * the matched rule, and that the original rewrite rules are restored after the request is
	 * parsed.
	 *
	 * @param bool $bool Whether to parse the request.
	 * @return bool
	 */
	public function do_parse_request( $bool ) : bool {
		global $wp_rewrite;

		$matched_rule = $this->wp_rewrite_parse_request();

		// Don't do anything if no rule matched, let WP handle it.
		if ( empty( $matched_rule ) ) {
			return $bool;
		}

		$old_rewrite_rules = $wp_rewrite->wp_rewrite_rules();

		// Return a single rewrite rule that matches the requested path.
		$force_rule_fn = function () use ( $matched_rule ) {
			return $matched_rule;
		};

		// Stop WP from updating the rewrite rules when we return the matched rule.
		$stop_update_fn = function ( $value, $old_value ) {
			return $old_value;
		};

		// Return the matched rule as the only rewrite rule.
		\add_filter( 'pre_option_' . 'rewrite_rules', $force_rule_fn );
		\add_filter( 'pre_update_option_' . 'rewrite_rules', $stop_update_fn, 10, 2 );

		// When request is done being parsed, restore the original rewrite rules.
		\add_action(
			'parse_request',
			function () use (
				$force_rule_fn,
				$stop_update_fn,
				$old_rewrite_rules
			) {
				global $wp_rewrite;

				// WP might've added an action to save the rewrite rules, so we remove it.
				if ( ! \did_action( 'wp_loaded' ) ) {
					\remove_action( 'wp_loaded', array( $wp_rewrite, 'flush_rules' ) );
				}

				// Remove the filter that forces the rewrite rules.
				\remove_filter( 'pre_option_' . 'rewrite_rules', $force_rule_fn );
				\remove_filter( 'pre_update_option_' . 'rewrite_rules', $stop_update_fn, 10, 2 );

				// Restore the original rewrite rules.
				$wp_rewrite->rules = $old_rewrite_rules;
			},
			PHP_INT_MIN
		);

		return $bool;
	}

	/**
	 * Parse the request and return a single rewrite rule that matches the requested path.
	 *
	 * Code mostly copied from `WP::parse_request()`, with some modifications and unnecessary
	 * code removed.
	 *
	 * Some changes are highlighted in the comments since they are the important parts.
	 *
	 * @return array
	 */
	public function wp_rewrite_parse_request() : array {
		global $wp_rewrite;

		// Fetch the rewrite rules.
		$rewrite = $wp_rewrite->wp_rewrite_rules();

		if ( ! empty( $rewrite ) ) {
			$pathinfo         = isset( $_SERVER['PATH_INFO'] ) ? $_SERVER['PATH_INFO'] : '';
			list( $pathinfo ) = explode( '?', $pathinfo );
			$pathinfo         = str_replace( '%', '%25', $pathinfo );

			list( $req_uri ) = explode( '?', $_SERVER['REQUEST_URI'] );

			$home_path       = parse_url( \home_url(), PHP_URL_PATH );
			$home_path_regex = '';
			if ( is_string( $home_path ) && '' !== $home_path ) {
				$home_path       = trim( $home_path, '/' );
				$home_path_regex = sprintf( '|^%s|i', preg_quote( $home_path, '|' ) );
			}

			/*
			 * Trim path info from the end and the leading home path from the front.
			 * For path info requests, this leaves us with the requesting filename, if any.
			 * For 404 requests, this leaves us with the requested permalink.
			 */
			$req_uri  = str_replace( $pathinfo, '', $req_uri );
			$req_uri  = trim( $req_uri, '/' );
			$pathinfo = trim( $pathinfo, '/' );

			if ( ! empty( $home_path_regex ) ) {
				$req_uri  = preg_replace( $home_path_regex, '', $req_uri );
				$req_uri  = trim( $req_uri, '/' );
				$pathinfo = preg_replace( $home_path_regex, '', $pathinfo );
				$pathinfo = trim( $pathinfo, '/' );
			}

			// The requested permalink is in $pathinfo for path info requests and $req_uri for other requests.
			if ( ! empty( $pathinfo ) && ! preg_match( '|^.*' . $wp_rewrite->index . '$|', $pathinfo ) ) {
				$requested_path = $pathinfo;
			} else {
				// If the request uri is the index, blank it out so that we don't try to match it against a rule.
				if ( $req_uri === $wp_rewrite->index ) {
					$req_uri = '';
				}

				$requested_path = $req_uri;
			}

			$requested_file = $req_uri;

			// Look for matches.
			$request_match = $requested_path;
			if ( empty( $request_match ) ) {
				// CHANGE 1 FROM ORIGINAL CODE. If the requested path is empty, we return an empty array.
				return [];

			} else {
				foreach ( (array) $rewrite as $match => $query ) {
					// If the requested file is the anchor of the match, prepend it to the path info.
					if ( ! empty( $requested_file )
						&& str_starts_with( $match, $requested_file )
						&& $requested_file !== $requested_path
					) {
						$request_match = $requested_file . '/' . $requested_path;
					}

					if ( preg_match( "#^$match#", $request_match, $matches )
						|| preg_match( "#^$match#", urldecode( $request_match ), $matches )
					) {

						if ( $wp_rewrite->use_verbose_page_rules
							&& preg_match( '/pagename=\$matches\[([0-9]+)\]/', $query, $varmatch )
						) {
							// This is a verbose page match, let's check to be sure about it.
							// CHANGE 2 FROM ORIGINAL CODE. Force only pages, no attachments.
							$page = get_page_by_path( $matches[ $varmatch[1] ], post_type: [ 'page' ] );

							if ( ! $page ) {
								continue;
							}

							$post_status_obj = get_post_status_object( $page->post_status );

							if ( ! $post_status_obj->public && ! $post_status_obj->protected
								&& ! $post_status_obj->private && $post_status_obj->exclude_from_search
							) {
								continue;
							}
						}

						// CHANGE 3. FROM ORIGINAL CODE.
						// If we find a match, return it as the only rewrite rule.
						return [ $match => $query ];
					}
				}
			}

			// CHANGE 4. FROM ORIGINAL CODE. Removed code after this comment, not needed for matching.
		}

		// No matched route, let WP handle it.
		return [];
	}
}
