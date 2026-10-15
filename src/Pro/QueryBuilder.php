<?php

namespace Crontrol\Pro;

/**
 * Lightweight query builder for Model classes with cursor-based pagination.
 *
 * @template TItem of Item
 * @template TModel of Model<TItem>
 */
final class QueryBuilder {
	/**
	 * Operator constants for use in where() clauses.
	 */
	public const OPERATOR_EQUALS = '=';
	public const OPERATOR_NOT_EQUALS = '!=';
	public const OPERATOR_GREATER_THAN = '>';
	public const OPERATOR_GREATER_THAN_OR_EQUAL = '>=';
	public const OPERATOR_LESS_THAN = '<';
	public const OPERATOR_LESS_THAN_OR_EQUAL = '<=';
	public const OPERATOR_LIKE = 'LIKE';
	public const OPERATOR_NOT_LIKE = 'NOT LIKE';
	public const OPERATOR_IN = 'IN';
	public const OPERATOR_NOT_IN = 'NOT IN';

	/**
	 * @var class-string<TModel>
	 */
	private string $model_class;

	/**
	 * @var array<int,array{
	 *   field: string,
	 *   operator: self::OPERATOR_*,
	 *   value: mixed,
	 * }>
	 */
	private array $wheres = [];

	/**
	 * Number of rows per page.
	 */
	private int $per_page = 20;

	/**
	 * The ID that the page starts from.
	 */
	private int $cursor_id = 0;

	/**
	 * True for descending order (before cursor), false for ascending (after cursor).
	 */
	private bool $descending = true;

	/**
	 * Whether to limit rows to the current site.
	 */
	private bool $filter_by_site = true;

	/**
	 * @param class-string<TModel> $model_class
	 */
	public function __construct( string $model_class ) {
		$this->model_class = $model_class;
	}

	/**
	 * Add a where clause to the query.
	 *
	 * @param string $field Field name to filter by.
	 * @param string $operator Operator (use QueryBuilder::OPERATOR_* constants).
	 * @param mixed $value Value to compare.
	 * @phpstan-param literal-string $field
	 * @phpstan-param self::OPERATOR_* $operator
	 * @throws \InvalidArgumentException If operator is not valid.
	 * @return $this
	 */
	public function where( string $field, string $operator, $value ): self {
		// Validate operator
		$valid_operators = [
			self::OPERATOR_EQUALS,
			self::OPERATOR_NOT_EQUALS,
			self::OPERATOR_GREATER_THAN,
			self::OPERATOR_GREATER_THAN_OR_EQUAL,
			self::OPERATOR_LESS_THAN,
			self::OPERATOR_LESS_THAN_OR_EQUAL,
			self::OPERATOR_LIKE,
			self::OPERATOR_NOT_LIKE,
			self::OPERATOR_IN,
			self::OPERATOR_NOT_IN,
		];

		if ( ! in_array( $operator, $valid_operators, true ) ) {
			throw new \InvalidArgumentException( "Invalid operator: {$operator}" );
		}

		$this->wheres[] = [
			'field' => $field,
			'operator' => $operator,
			'value' => $value,
		];

		return $this;
	}

	/**
	 * Set the number of items per page for pagination.
	 *
	 * @param int $per_page Items per page.
	 * @return $this
	 */
	public function per_page( int $per_page ): self {
		$this->per_page = $per_page;
		return $this;
	}

	/**
	 * Get items before the given cursor ID (most recent first).
	 *
	 * @param int $id Cursor ID to start from, or 0 for latest items.
	 * @return $this
	 */
	public function before( int $id ): self {
		$this->cursor_id = $id;
		$this->descending = true;
		return $this;
	}

	/**
	 * Get items after the given cursor ID (oldest first, then reversed).
	 *
	 * @param int $id Cursor ID to start from.
	 * @return $this
	 */
	public function after( int $id ): self {
		$this->cursor_id = $id;
		$this->descending = false;
		return $this;
	}

	/**
	 * Query across all sites in the network (multisite only).
	 * By default, queries are filtered to the current site.
	 *
	 * @return $this
	 */
	public function query_network(): self {
		$this->filter_by_site = false;
		return $this;
	}

	/**
	 * Build WHERE clause conditions and values for the query.
	 *
	 * @param bool $include_cursor Whether to include cursor conditions.
	 * @throws \InvalidArgumentException If an unknown field is referenced.
	 * @return array{conditions: list<literal-string>, values: array<mixed>}
	 */
	private function build_where_clause( bool $include_cursor ): array {
		$model_class = $this->model_class;
		$where_conditions = [];
		$where_values = [];

		// Apply site filter automatically for multisite
		if ( $this->filter_by_site && is_multisite() ) {
			$where_conditions[] = '%i = %d';
			$where_values[] = 'site_id';
			$where_values[] = get_current_blog_id();
		}

		foreach ( $this->wheres as $where ) {
			$field = $where['field'];
			$operator = $where['operator'];
			$value = $where['value'];

			// Validate field exists
			$item_class = $model_class::get_item_class();
			if ( ! property_exists( $item_class, $field ) ) {
				throw new \InvalidArgumentException( esc_html( "Unknown field: {$field}" ) );
			}

			// Determine placeholder type
			$property = ( new \ReflectionClass( $item_class ) )->getProperty( $field );
			$placeholder = $model_class::map_property_to_field_type( $property );

			// Handle IN and NOT IN operators which require array values
			if ( $operator === self::OPERATOR_IN || $operator === self::OPERATOR_NOT_IN ) {
				if ( empty( $value ) ) {
					$where_conditions[] = '1 = 0';
				} else {
					$placeholders = implode( ',', array_fill( 0, count( $value ), $placeholder ) );
					$where_conditions[] = "%i {$operator} ({$placeholders})";
					$where_values[] = $field;
					foreach ( $value as $v ) {
						$where_values[] = $model_class::map_value_to_db_value( $v );
					}
				}
			} else {
				$where_conditions[] = "%i {$operator} ({$placeholder})";
				$where_values[] = $field;
				$where_values[] = $model_class::map_value_to_db_value( $value );
			}
		}

		// Add cursor condition if requested
		if ( $include_cursor && $this->cursor_id > 0 ) {
			$primary_key = $model_class::PRIMARY_KEY;
			if ( $this->descending ) {
				$where_conditions[] = '%i < %d';
			} else {
				$where_conditions[] = '%i > %d';
			}
			$where_values[] = $primary_key;
			$where_values[] = $this->cursor_id;
		}

		return [
			'conditions' => $where_conditions,
			'values' => $where_values,
		];
	}

	/**
	 * Execute the query and return results using cursor-based pagination.
	 *
	 * @throws \InvalidArgumentException If an unknown field is referenced.
	 * @return list<TItem>
	 */
	public function get(): array {
		$results = $this->execute_query();
		$model_class = $this->model_class;

		$items = array_map( [ $model_class, 'db_result_to_item' ], $results );

		// Reverse results for ascending order to maintain DESC display order
		if ( ! $this->descending ) {
			$items = array_reverse( $items );
		}

		return array_values( $items );
	}

	/**
	 * Execute the query and return raw database rows as arrays with basic formatting.
	 *
	 * @throws \InvalidArgumentException If an unknown field is referenced.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_raw(): array {
		$results = $this->execute_query();
		$model_class = $this->model_class;

		// Convert to arrays with basic type casting
		$arrays = array_map( [ $model_class, 'db_result_to_array' ], $results );

		// Reverse results for ascending order to maintain DESC display order
		if ( ! $this->descending ) {
			$arrays = array_reverse( $arrays );
		}

		return $arrays;
	}

	/**
	 * Execute the database query and return raw results.
	 *
	 * @throws \InvalidArgumentException If an unknown field is referenced.
	 * @return array<int, \stdClass>
	 */
	private function execute_query(): array {
		$model_class = $this->model_class;
		$wpdb = $model_class::wpdb();
		$table = $model_class::get_table();
		$primary_key = $model_class::PRIMARY_KEY;

		// Build WHERE clause
		$where_data = $this->build_where_clause( true );
		$where_sql = '';
		if ( ! empty( $where_data['conditions'] ) ) {
			$where_sql = 'WHERE ' . implode( ' AND ', $where_data['conditions'] );
		}

		// Build ORDER BY
		$order = ( $this->descending ? 'DESC' : 'ASC' );
		$order_sql = "ORDER BY %i {$order}";

		// Build complete query
		$sql = "SELECT * FROM %i {$where_sql} {$order_sql} LIMIT %d";

		$query_values = array_merge(
			[ $table ],
			$where_data['values'],
			[ $primary_key, $this->per_page ]
		);

		$results = $wpdb->get_results(
			$wpdb->prepare( $sql, ...$query_values ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		return $results === null ? [] : $results;
	}

	/**
	 * Count the results (up to Model::COUNT_LIMIT).
	 *
	 * @throws \InvalidArgumentException If an unknown field is referenced.
	 * @param int $start Optional offset to start counting from (default 0).
	 * @phpstan-return int<0, Model::COUNT_LIMIT>
	 */
	public function count( int $start = 0 ): int {
		$model_class = $this->model_class;
		$wpdb = $model_class::wpdb();
		$table = $model_class::get_table();

		// Build WHERE clause for filters only (ignore cursor)
		$where_data = $this->build_where_clause( false );

		if ( $start > 0 ) {
			$where_data['conditions'][] = '%i <= %d';
			$where_data['values'][] = $model_class::PRIMARY_KEY;
			$where_data['values'][] = $start;
		}

		// Build WHERE clause
		$where_sql = '';
		if ( ! empty( $where_data['conditions'] ) ) {
			$where_sql = 'WHERE ' . implode( ' AND ', $where_data['conditions'] );
		}

		// Build count query
		$sql = "SELECT COUNT(*) FROM ( SELECT ID FROM %i {$where_sql} LIMIT %d ) AS r";

		$query_values = array_merge(
			[ $table ],
			$where_data['values'],
			[ $model_class::COUNT_LIMIT ]
		);

		$count = (int) $wpdb->get_var(
			$wpdb->prepare( $sql, ...$query_values ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		// The query's LIMIT guarantees this bound; clamp so the return type holds.
		return max( 0, min( Model::COUNT_LIMIT, $count ) );
	}
}
