<?php

namespace TenupFramework\Filters;

/**
 */
class QueryFilters {

	public array $filters = [];
	public array $applied = [];

	public function __construct( public array $config, public array $post_types ) {
		foreach ( $this->config as $filter ) {
			if ( ! isset( $filter['type'] ) || ! isset( $filter['name'] ) ) {
				continue;
			}

			switch ( $filter['type'] ) {
				case 'search':
					$this->filters['search'] = $filter;
					break;
				case 'taxonomy':
					$filter[ 'taxonomy' ]                    = $filter['taxonomy'] ?? $filter['name'];
					$this->filters['taxonomy'][ $filter['name'] ] = $filter;
					break;
				case 'meta':
					$filter['meta_key']                       = $filter['meta_key'] ?? $filter['name'];
					$filter['meta_compare']                   = $filter['meta_compare'] ?? '=';
					$this->filters['meta'][ $filter['name'] ] = $filter;
					break;
				// TODO: handle custom filters.
			}
		}
	}

	public function init() : void {

		// Add values from query var 'filter' to filters.
		$this->add_filter_values();

		// Fetch possible values
		foreach ( $this->post_types as $post_type ) {
			$this->fetch_possible_values( $post_type );
		}

		$this->filters = apply_filters( 'wp_framework_query_filters', $this->filters, $this );

		// Create applied data.
		// TODO:
	}

	public function get() : array {
		$sort_map = array_map(
			fn ( $filter ) => $filter['name'],
			$this->config
		);

		$filters = [
			...$this->filters['search'] ?? [],
			...$this->filters['taxonomy'] ?? [],
			...$this->filters['meta'] ?? [],
		];

		$filters = array_merge( array_flip( $sort_map ), $filters );

		return $filters;
	}

	private function add_filter_values() : void {
		$filter_values = get_query_var( 'filter', $_GET['filter'] ?? [] );

		foreach ( $this->filters as $type => $filters ) {
			foreach ( $filters as $filter_name => $filter_data ) {
				$values = [];
				switch ( $type ) {
					case 'search':
						$values = $filter_values[ 's' ] ?? '';
						break;
					case 'taxonomy':
						$values = $filter_values[ 'tax' ][ $filter_name ] ?? [];
						break;
					case 'meta':
						$values = $filter_values[ 'meta' ][ $filter_name ] ?? [];
						break;
				}

				// If hardcoded, then ignore any passed values.
				if ( isset( $filter_data['hardcoded'] ) && $filter_data['hardcoded'] === true ) {
					$values = [];
				}

				if ( isset( $filter_data['query_values'] ) ) {
					$values = $filter_data['query_values'];
				}

				if ( empty( $values ) && isset( $filter_data['default'] ) ) {
					$values = $filter_data['default'];
				}

				if ( is_array( $values ) && count( $values ) === 1 ) {
					$values = explode( ',', array_shift( $values ) );
				}

				if ( ! is_array( $values ) ) {
					$values = [ $values ];
				}

				if ( $type === 'meta' && ( ! empty( $filter_data['min'] ) || ! empty( $filter_data['max'] ) ) ) {
					$values = array_map(
						function ( $value ) use ( $filter_data ) {
							if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
								return $value;
							}
							if ( ! empty( $filter_data['min'] ) && $value < $filter_data['min'] ) {
								return $filter_data['min'];
							}
							if ( ! empty( $filter_data['max'] ) && $value > $filter_data['max'] ) {
								return $filter_data['max'];
							}
							return $value;
						},
						$values
					);
				}

				$this->filters[ $type ][ $filter_name ]['query_values'] = $values;
			}
		}
	}

	private function fetch_possible_values( string $post_type ) {
		/**
		 * Filters possible values.
		 *
		 * Useful for custom implementations of filters.
		 *
		 * @param array  $values
		 * @param string $post_type
		 * @param QueryFilters $this
		 */
		$filters = apply_filters( 'wp_framework_query_filters_possible_values', null, $post_type, $this );
		if ( ! empty( $filters ) ) {
			$this->filters = $filters;
			return;
		}

		// Get possible values for the taxonomies.
		foreach ( ( $this->filters['taxonomy'] ?? [] ) as $name => $tax_filter ) {

			/**
			 * Merge the selected values with the possible values to ensure selected values are always present.
			 * TODO: or should possible values be only the possible values via a query and then we should have another one that joins the two?
			 */
			$this->filters['taxonomy'][ $name ]['possible_values'] = array_unique(
				array_merge(
					array_filter( $tax_filter['query_values'] ),
					$this->get_tax_possible_values( $post_type, $tax_filter )
				)
			);
		}

		// Get possible values for the metas.
		foreach ( $this->filters['meta'] ?? [] as $name => $meta_filter ) {
			if ( isset( $meta_filter['possible_values'] ) && $meta_filter['possible_values'] === false ) {
				continue;
			}
			$this->filters['meta'][ $name ]['possible_values'] = $this->get_meta_possible_values( $post_type, $meta_filter );
		}

		return;
	}

	/**
	 * Retrieving possible values for a taxonomy filter given the filters and post_type.
	 *
	 * @since 0.0.0
	 * @param string $post_type
	 * @param array $tax_filter
	 * @return array
	 */
	private function get_tax_possible_values( string $post_type, array $tax_filter ) : array {
		global $wpdb;

		$taxonomy = $tax_filter['taxonomy'] ?? $tax_filter['name'];

		// Get possible post ids sub query.
		$sub_query = $this->get_filters_sub_query( $post_type, 'taxonomy', $taxonomy, $tax_filter );

		/**
		 * TODO: docs
		 */
		$extra_filter = apply_filters( 'wp_framework_query_filters_taxonomy_extra_filter', '', $post_type, $taxonomy, $tax_filter, $this );

		$term_ids = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT DISTINCT T.term_id
				FROM {$wpdb->prefix}term_taxonomy as T
				INNER JOIN {$wpdb->prefix}terms as TE ON T.term_id = TE.term_id
				INNER JOIN {$wpdb->prefix}term_relationships as R ON T.term_taxonomy_id = R.term_taxonomy_id
				WHERE object_id IN ( {$sub_query} )
				AND T.taxonomy = %s
				{$extra_filter}
				ORDER BY TE.name",
				$taxonomy
				// phpcs:enable
			),
			OBJECT_K
		);

		if ( $term_ids === null ) {
			return [];
		}

		return array_keys( $term_ids );
	}

	/**
	 * Retrieving possible values for a meta filter given the filters and post_type.
	 *
	 * @since 0.0.0
	 * @param string $post_type
	 * @param array $tax_filter
	 * @return array
	 */
	private function get_meta_possible_values( string $post_type, array $meta_filter ) : array {
		global $wpdb;

		$meta_key = $meta_filter['meta_key'] ?? $meta_filter['name'];

		// Get possible post ids sub query.
		$sub_query = $this->get_filters_sub_query( $post_type, 'meta', $meta_key, $meta_filter );

		/**
		 * TODO: docs
		 */
		$extra_filter = apply_filters( 'wp_framework_query_filters_meta_extra_filter', '', $post_type, $meta_key, $meta_filter, $this );

		$meta_values = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.QuotedSimplePlaceholder
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT DISTINCT meta_value
				FROM {$wpdb->prefix}posts as A
				INNER JOIN (
					SELECT * FROM {$wpdb->prefix}postmeta WHERE meta_key = %s
				) as M on A.ID = M.post_id
				WHERE A.ID IN ( {$sub_query} )
				{$extra_filter}",
				// phpcs:enable
				$meta_key
			),
			OBJECT_K
		);

		if ( $meta_values === null ) {
			return [];
		}

		return array_map( 'strval', array_keys( $meta_values ) );
	}

	/**
	 * Get the sub query for possible post ids under the current filters (excluding the passed filter).
	 *
	 * The reason the passed filter is excluded is that we only want to filter its values from the
	 * other selected filters, in order to get all possible values.
	 *
	 * @param $post_type
	 * @param $type          Accepts 'taxonomy' or 'meta'.
	 * @param $wp_identifier Taxonomy name (if $type = 'taxonomy') or Meta key (if $type = 'meta').
	 * @param $filter_data   Filter data.
	 * @return string
	 */
	private function get_filters_sub_query( $post_type, $type, $wp_identifier, array $filter_data ) : string {
		global $wpdb;

		$filtered_by = $filter_data['filtered_by'] ?? null;

		// Get query filters for search.
		$search_filters = $this->build_search_query_filters( $filtered_by );

		// Get query filters for taxonomies.
		$term_filters = $this->build_term_query_filters( $wp_identifier, $filtered_by );

		// Get query filters for meta.
		list( $meta_join_filters, $meta_where_filters ) = $this->build_meta_query_filters( $wp_identifier, $filtered_by );

		// If it's translatable, then filter by language.
		$join_filters  = apply_filters( 'wp_framework_query_filters_sub_query_join_filter', '', $post_type, $type, $wp_identifier, $filtered_by, $this );
		$where_filters = apply_filters( 'wp_framework_query_filters_sub_query_where_filter', '', $post_type, $type, $wp_identifier, $filtered_by, $this );

		return $wpdb->prepare(
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT ID FROM (
				SELECT DISTINCT P.ID FROM {$wpdb->prefix}posts as P
				{$meta_join_filters}
				{$join_filters}
				WHERE post_type = %s
				{$search_filters}
				{$where_filters}
				{$meta_where_filters}
			) AS P
			{$term_filters}",
			// phpcs:enable
			$post_type
		);
	}

	/**
	 * Build query filters for search.
	 *
	 * @since 0.0.0
	 * @param array|null $filtered_by
	 * @return string
	 */
	private function build_search_query_filters( ?array $filtered_by ) : string {
		global $wpdb;
		if ( is_array( $filtered_by ) && in_array( 'search', $filtered_by, true ) ) {
			return '';
		}

		if ( empty( $this->filters['search']['query_values'] ) || ! is_string( $this->filters['search']['query_values'] ) ) {
			return '';
		}

		$like = sprintf( '%%%s%%', $wpdb->esc_like( $this->filters['search']['query_values'] ) );
		return $wpdb->prepare(
			' AND ( P.post_content LIKE %s
			OR P.post_title LIKE %s
			OR P.post_excerpt LIKE %s )',
			$like,
			$like,
			$like
		);
	}

	/**
	 * Build query filters for taxonomy through INNER JOINs.
	 *
	 * @since 0.0.0
	 * @param string $wp_identifier Name of the taxonomy or the meta_key.
	 * @param ?array $filtered_by   Optional list of filters to consider. If null, then all filters are considered.
	 * @return string
	 */
	private function build_term_query_filters( string $wp_identifier, ?array $filtered_by ) : string {
		global $wpdb;
		$term_filters = '';
		$index        = 0;
		foreach ( $this->filters['taxonomy'] ?? [] as $name => $tax_filter ) {
			$tax = $tax_filter['taxonomy'] ?? $tax_filter['name'];

			// If they're selected, we filter its values from the other selected.
			if ( $tax === $wp_identifier ) {
				continue;
			}

			if ( is_array( $filtered_by ) && ! in_array( $name, $filtered_by, true ) ) {
				continue;
			}

			$term_ids = $tax_filter['query_values'] ?? [];
			if ( ! is_array( $term_ids ) ) {
				$term_ids = [ $term_ids ];
			}

			// Handle multiple select.
			if ( count( $term_ids ) === 1 && ! is_numeric( $term_ids[0] ) ) {
				$term_ids = explode( ',', $term_ids[0] );
			}

			// Skip empty values.
			if ( empty( $term_ids ) || ! is_numeric( $term_ids[0] ) ) {
				continue;
			}

			$term_filters .= sprintf(
				'INNER JOIN %1$sterm_relationships as R%2$d ON ( R%2$d.object_id = P.ID )
				INNER JOIN %1$sterm_taxonomy as TT%2$d ON ( R%2$d.term_taxonomy_id = TT%2$d.term_taxonomy_id AND TT%2$d.term_id IN (%3$s) )',
				$wpdb->prefix,
				$index++,
				implode( ',', array_filter( $term_ids, 'is_numeric' ) )
			);
		}

		return $term_filters;
	}

	/**
	 * Build query filters for metas through INNER JOINs.
	 *
	 * @since 0.0.0
	 * @param string $wp_identifier Name of the taxonomy or the meta_key.
	 * @param ?array $filtered_by   Optional list of filters to consider. If null, then all filters are considered.
	 * @return array
	 */
	private function build_meta_query_filters( string $wp_identifier, ?array $filtered_by ) : array {
		global $wpdb;
		$index              = 0;
		$meta_join_filters  = '';
		$meta_where_filters = '';

		foreach ( $this->filters['meta'] ?? [] as $name => $meta_filter ) {
			$meta_key = $meta_filter['meta_key'] ?? $meta_filter['name'];

			// If they're selected, we filter its values from the other selected.
			if ( $meta_key === $wp_identifier ) {
				continue;
			}

			if ( is_array( $filtered_by ) && ! in_array( $name, $filtered_by, true ) ) {
				continue;
			}

			if ( ( $meta_filter['meta_compare'] ?? '=' ) === 'NOT EXISTS' ) {
				$meta_join_filters .= sprintf(
					"LEFT JOIN %1\$spostmeta as M%2\$d on ( P.id = M%2\$d.post_id and M%2\$d.meta_key = \"%3\$s\" ) ",
					$wpdb->prefix,
					$index,
					$meta_key
				);

				$meta_where_filters .= sprintf( ' AND M%1$d.meta_value IS NULL ', $index );

				$index++;
				continue;
			}

			$meta_values = $meta_filter['query_values'] ?? [];
			if ( empty( $meta_values ) && ( $meta_filter['meta_compare'] ?? '=' ) !== 'EXISTS' ) {
				continue;
			}

			$compare_str = '';
			switch ( $meta_filter['meta_compare'] ?? '=' ) {
				case '=':
				case 'IN':
					$compare_str = sprintf( 'IN ("%s")', is_string( $meta_values ) ? $meta_values : implode( '","', $meta_values ) );
					break;
				case '!=':
				case 'NOT IN':
					$compare_str = sprintf( 'NOT IN ("%s")', is_string( $meta_values ) ? $meta_values : implode( '","', $meta_values ) );
					break;
				case '>':
				case '>=':
					// Only one value is allowed.
					// If multiple values, then we should take the max.
					$meta_value = is_array( $meta_values ) ? max( $meta_values ) : $meta_values;
					$compare_str = sprintf( '%s "%s"', $meta_filter['meta_compare'], $meta_value );
					break;

				case '<':
				case '<=':
					// Only one value is allowed.
					// If multiple values, then we should take the min.
					$meta_value = is_array( $meta_values ) ? array_shift( $meta_values ) : $meta_values;
					$compare_str = sprintf( '%s "%s"', $meta_filter['meta_compare'], $meta_value );
					break;

				case 'EXISTS':
					$compare_str = '';
					break;
				default:
					// Unsupported compare.
					continue 2;
			}

			$meta_join_filters .= sprintf(
				"INNER JOIN %1\$spostmeta as M%2\$d on ( P.id = M%2\$d.post_id and M%2\$d.meta_key = \"%3\$s\" %4\$s ) ",
				$wpdb->prefix,
				$index,
				$meta_key,
				$compare_str ? "and M{$index}.meta_value {$compare_str}" : ''
			);

			$index++;
		}

		return [ $meta_join_filters, $meta_where_filters ];
	}
}
