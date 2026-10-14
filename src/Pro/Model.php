<?php

namespace Crontrol\Pro;

use DateTimeImmutable;
use DateTimeZone;
use stdClass;

/**
 * Base class for models.
 *
 * This provides a lightweight wpdb-based ORM with functionality that is specific to
 * time-series log tables and time-series aggregated metrics tables.
 *
 * - Automatic two-way mapping between typed class properties and their database fields.
 * - Cursor-based pagination for efficient browsing of large datasets.
 * - Simple methods for inserting, updating, deleting, fetching, and filtering items.
 * - Automatic database table creation and migration.
 * - Soft limit on counting to mitigate performance issues.
 *
 * @template TItem of Item
 */
abstract class Model {
	/** @var literal-string|null */
	protected const TABLE = null;

	/** @var positive-int */
	protected const VERSION = 1;

	/** @var literal-string */
	public const PRIMARY_KEY = 'ID';

	/** @var positive-int */
	public const SOFT_LIMIT = 10000;

	/** @var positive-int */
	public const COUNT_LIMIT = 100000;

	/**
	 * Inserts a new item into the database.
	 *
	 * @param TItem $item
	 * @throws \RuntimeException
	 */
	public static function create( Item $item ): void {
		$wpdb = self::wpdb();

		$result = $wpdb->insert(
			self::get_table(),
			self::get_values_for_db( $item ),
			array_values( self::get_field_types() )
		);

		if ( $result === false ) {
			throw new \RuntimeException( 'Failed to insert item: ' . $wpdb->last_error );
		}

		$item->{static::PRIMARY_KEY} = $wpdb->insert_id;
	}

	/**
	 * Updates an existing item in the database.
	 *
	 * @param TItem $item
	 * @throws \RuntimeException
	 */
	final public static function update( Item $item ): void {
		$wpdb = self::wpdb();
		$table = self::get_table();
		$data = self::get_values_for_db( $item );
		$where = [ static::PRIMARY_KEY => $item->{static::PRIMARY_KEY} ];

		$result = self::wpdb()->update(
			$table,
			$data,
			$where,
			array_values( self::get_field_types() ),
			[
				'%d',
			]
		);

		if ( $result === false ) {
			throw new \RuntimeException( 'Failed to update item: ' . $wpdb->last_error );
		}
	}

	/**
	 * Deletes an item from the database.
	 *
	 * @throws \RuntimeException
	 *
	 * @param TItem $item
	 */
	final public static function delete( Item $item ): void {
		$result = self::wpdb()->delete(
			self::get_table(),
			[
				static::PRIMARY_KEY => $item->{static::PRIMARY_KEY},
			],
			[
				'%d',
			]
		);

		if ( $result === false ) {
			throw new \RuntimeException( 'Failed to delete item' );
		}
	}

	/**
	 * Finds an item by its primary key.
	 *
	 * @param int $id ID of the item to find.
	 * @return TItem|null
	 */
	final public static function find( int $id ): ?Item {
		$wpdb = self::wpdb();
		$table = self::get_table();
		$result = $wpdb->get_row(
			$wpdb->prepare(
				'
					SELECT *
					FROM %i
					WHERE %i = %d
				',
				$table,
				static::PRIMARY_KEY,
				$id
			)
		);

		if ( $result === null ) {
			return null;
		}

		return self::db_result_to_item( $result );
	}

	/**
	 * Gets a new instance of the item class.
	 *
	 * @return TItem
	 */
	final public static function get_item(): Item {
		$class = static::get_item_class();
		return new $class();
	}

	/**
	 * Fetches items before a given ID, ordered by ID descending.
	 *
	 * @param int $id       ID to start from, or 0 for the latest items.
	 * @param int $per_page Maximum number of items to return.
	 *
	 * @return list<TItem>
	 */
	final public static function before( int $id, int $per_page = 20 ): array {
		return static::query()
			->before( $id )
			->per_page( $per_page )
			->get();
	}

	/**
	 * Fetches items after a given ID, ordered by ID ascending.
	 *
	 * @param int $id       ID to start from.
	 * @param int $per_page Maximum number of items to return.
	 *
	 * @return list<TItem>
	 */
	final public static function after( int $id, int $per_page = 20 ): array {
		return static::query()
			->after( $id )
			->per_page( $per_page )
			->get();
	}

	/**
	 * Creates a new query builder instance.
	 *
	 * @return QueryBuilder<TItem,static>
	 */
	final public static function query(): QueryBuilder {
		return new QueryBuilder( static::class );
	}

	/**
	 * Counts the total number of items in the table, up to COUNT_LIMIT.
	 *
	 * @param int $start ID to start from, or 0 for all items.
	 * @phpstan-return int<0, self::COUNT_LIMIT>
	 */
	final public static function count( int $start = 0 ): int {
		$query = static::query();

		if ( $start > 0 ) {
			$query->where( static::PRIMARY_KEY, QueryBuilder::OPERATOR_LESS_THAN_OR_EQUAL, $start );
		}

		return $query->count();
	}

	/**
	 * Prunes the oldest rows from the table, keeping only the specified number of most recent rows.
	 *
	 * @param int $keep Number of rows to keep (most recent by ID).
	 * @return int Number of rows deleted.
	 */
	final public static function prune( int $keep = self::SOFT_LIMIT ): int {
		$wpdb = self::wpdb();
		$table = self::get_table();
		$primary = static::PRIMARY_KEY;

		// Nothing to keep means nothing to prune against; use truncate() to clear the table.
		if ( $keep < 1 ) {
			return 0;
		}

		// Fast pre-check: MAX - MIN is an upper bound on the row count.
		$span = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT MAX(%i) - MIN(%i) FROM %i',
				$primary,
				$primary,
				$table
			)
		);

		if ( $span === null || ( (int) $span ) <= $keep ) {
			return 0;
		}

		// Get the lowest ID to keep: the Nth most recent row, found via a reverse index scan.
		$cutoff_id = $wpdb->get_var(
			$wpdb->prepare(
				'
					SELECT %i
					FROM %i
					ORDER BY %i DESC
					LIMIT %d, 1
				',
				$primary,
				$table,
				$primary,
				$keep - 1
			)
		);

		// If no cutoff ID, nothing to delete
		if ( $cutoff_id === null ) {
			return 0;
		}

		// Delete all rows with ID less than cutoff
		$deleted = $wpdb->query(
			(string) $wpdb->prepare(
				'
					DELETE FROM %i
					WHERE %i < %d
				',
				$table,
				$primary,
				$cutoff_id
			)
		);

		return is_int( $deleted ) ? $deleted : 0;
	}

	/**
	 * Truncates the table, removing all rows.
	 *
	 * @return bool True on success, false on failure.
	 */
	final public static function truncate(): bool {
		$wpdb = self::wpdb();
		$table = self::get_table();

		$result = $wpdb->query(
			(string) $wpdb->prepare(
				'TRUNCATE TABLE %i',
				$table
			)
		);

		return $result !== false;
	}

	/**
	 * Clear all records, optionally network-wide in multisite.
	 *
	 * @param bool $network_wide Whether to clear records from all sites in multisite.
	 * @return bool True on success, false on failure.
	 */
	public static function clear_records( bool $network_wide = false ): bool {
		$success = false;

		if ( $network_wide || ! is_multisite() ) {
			// DELETE rather than TRUNCATE, so IDs are never reused while a cron run still holds one.
			$wpdb = self::wpdb();
			$result = $wpdb->query( (string) $wpdb->prepare( 'DELETE FROM %i', self::get_table() ) );
			$success = $result !== false;
		} else {
			// For multisite, delete only records from the current site
			$wpdb = self::wpdb();
			$table = self::get_table();
			$site_id = get_current_blog_id();

			$result = $wpdb->delete( $table, [ 'site_id' => $site_id ], [ '%d' ] );
			$success = $result !== false;
		}

		return $success;
	}

	/**
	 * Converts a database result row into an item instance.
	 *
	 * @param stdClass $result Database result row.
	 * @return TItem
	 */
	public static function db_result_to_item( stdClass $result ): Item {
		$item = self::get_item();

		foreach ( get_object_vars( $result ) as $key => $value ) {
			$item->$key = self::map_db_field_to_value( $key, $value );
		}

		return $item;
	}

	/**
	 * Converts a database result row into an array with basic type casting.
	 *
	 * @param stdClass $result Database result row.
	 * @return array<string, mixed>
	 */
	public static function db_result_to_array( stdClass $result ): array {
		$array = [];
		$reflection = new \ReflectionClass( static::get_item_class() );

		foreach ( get_object_vars( $result ) as $key => $value ) {
			// Cast numeric fields to integers for proper formatting
			if ( $reflection->hasProperty( $key ) ) {
				$property = $reflection->getProperty( $key );
				$type_name = self::get_property_type_name( $property );

				if ( $type_name === 'int' ) {
					$array[ $key ] = (int) $value;
				} elseif ( $type_name === 'bool' ) {
					$array[ $key ] = (int) $value; // Keep as int for CLI display
				} else {
					$array[ $key ] = $value;
				}
			} else {
				$array[ $key ] = $value;
			}
		}

		return $array;
	}

	/**
	 * Initializes the database table for this model.
	 *
	 * If the table does not exist or is out of date, it will be created or updated. The table is
	 * shared by every site in a network, so its version is stored as a network option. The version
	 * is only recorded once the table is confirmed to exist, so a failed creation is retried later,
	 * at most once an hour.
	 *
	 * @return bool Whether the table is ready to use.
	 */
	final public static function init_db(): bool {
		if ( static::is_ready() ) {
			return true;
		}

		$retry_key = static::TABLE . '_retry';

		if ( get_site_transient( $retry_key ) ) {
			return false;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( static::get_db_delta() );

		// dbDelta() doesn't report a failed ALTER, so check every column is really there.
		if ( ! static::table_exists() || ! static::columns_exist() ) {
			set_site_transient( $retry_key, 1, HOUR_IN_SECONDS );
			return false;
		}

		update_site_option( static::TABLE . '_version', static::VERSION );

		return true;
	}

	/**
	 * Whether the table exists at its current version, according to the stored version.
	 */
	final public static function is_ready(): bool {
		return (int) get_site_option( static::TABLE . '_version', 0 ) === static::VERSION;
	}

	/**
	 * Whether the table exists in the database.
	 */
	final public static function table_exists(): bool {
		$wpdb = self::wpdb();
		$table = self::get_table();

		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/**
	 * Whether the table has a column for every field.
	 */
	final public static function columns_exist(): bool {
		$wpdb = self::wpdb();
		$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', self::get_table() ) );

		return ! array_diff( array_keys( self::get_field_definitions() ), (array) $columns );
	}

	/**
	 * Drops the table and forgets its version. Used on uninstall.
	 */
	final public static function drop_table(): void {
		$wpdb = self::wpdb();

		$wpdb->query( (string) $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::get_table() ) );

		delete_site_option( static::TABLE . '_version' );
		delete_site_transient( static::TABLE . '_retry' );
	}

	final public static function wpdb(): \wpdb {
		return $GLOBALS['wpdb'];
	}

	final public static function get_table(): string {
		if ( ! static::TABLE ) {
			throw new \RuntimeException( 'Table name not defined' );
		}

		return self::wpdb()->base_prefix . static::TABLE;
	}

	final public static function get_db_delta(): string {
		$collation = self::wpdb()->get_charset_collate();
		$table = self::get_table();
		$primary = static::PRIMARY_KEY;
		$sql = [
			"CREATE TABLE {$table} (",
		];

		foreach ( self::get_field_definitions() as $name => $type ) {
			$sql[] = "  {$name} {$type},";
		}

		foreach ( static::get_field_indexes() as $name => $index ) {
			$sql[] = "  KEY {$name} ({$index}),";
		}

		$sql[] = "  PRIMARY KEY ({$primary})";
		$sql[] = ") {$collation}";

		return implode( "\n", $sql );
	}

	/**
	 * @return array<string,string>
	 */
	abstract protected static function get_field_indexes(): array;

	/**
	 * Returns the field definitions for the database table, keyed by property name.
	 *
	 * @return array<string,string>
	 */
	private static function get_field_definitions(): array {
		$properties = ( new \ReflectionClass( static::get_item_class() ) )->getProperties();
		$fields = [];

		foreach ( $properties as $property ) {
			$fields[ $property->getName() ] = self::map_property_to_field_definition( $property );
		}

		return $fields;
	}

	/**
	 * Maps a property to its database field definition.
	 *
	 * @throws \InvalidArgumentException
	 *
	 * @param \ReflectionProperty $property Property to map.
	 * @return string Database field definition.
	 */
	private static function map_property_to_field_definition( \ReflectionProperty $property ): string {
		$property_name = $property->getName();

		if ( $property_name === static::PRIMARY_KEY ) {
			return 'bigint(20) unsigned NOT NULL AUTO_INCREMENT';
		}

		$type_name = self::get_property_type_name( $property );

		switch ( $type_name ) {
			case 'int':
				return 'bigint(20) unsigned NOT NULL';
			case 'string':
				return 'varchar(255) NOT NULL';
			case 'DateTimeImmutable':
				return 'datetime(3) NOT NULL';
			case 'bool':
				return 'tinyint(1) NOT NULL';
			case 'array':
				return 'text NOT NULL';
		}

		throw new \InvalidArgumentException( "Unknown field type: {$type_name}" );
	}

	/**
	 * Returns the field types for use in `wpdb` methods, keyed by property name.
	 *
	 * @return array<string,string>
	 * @phpstan-return array<string,'%s'|'%d'>
	 */
	private static function get_field_types(): array {
		$properties = ( new \ReflectionClass( static::get_item_class() ) )->getProperties();
		$fields = [];

		// Remove primary key
		unset( $properties[0] );

		foreach ( $properties as $property ) {
			$fields[ $property->getName() ] = self::map_property_to_field_type( $property );
		}

		return $fields;
	}

	/**
	 * Maps a property to its database field type for use in `wpdb` methods.
	 *
	 * @param \ReflectionProperty $property Property to map.
	 *
	 * @phpstan-return '%s'|'%d'
	 */
	public static function map_property_to_field_type( \ReflectionProperty $property ): string {
		switch ( self::get_property_type_name( $property ) ) {
			case 'int':
			case 'bool':
				return '%d';
		}

		return '%s';
	}

	/**
	 * @param \ReflectionProperty $property The property to inspect.
	 *
	 * @throws \InvalidArgumentException If the property is untyped or has a union/intersection type.
	 */
	private static function get_property_type_name( \ReflectionProperty $property ): string {
		$type = $property->getType();

		if ( ! $type instanceof \ReflectionNamedType ) {
			throw new \InvalidArgumentException( "Property {$property->getName()} must have a named type" );
		}

		return $type->getName();
	}

	/**
	 * Returns the values of an item for use in `wpdb` methods, keyed by property name.
	 *
	 * @param TItem $item
	 * @return array<string,string|int>
	 */
	private static function get_values_for_db( Item $item ): array {
		$fields = [];
		$vars = get_object_vars( $item );

		unset( $vars[ static::PRIMARY_KEY ] );

		foreach ( $vars as $key => $value ) {
			$fields[ $key ] = self::map_value_to_db_value( $value );
		}

		return $fields;
	}

	/**
	 * Maps a property value to its database representation.
	 *
	 * @throws \InvalidArgumentException
	 *
	 * @param mixed $value Value to map.
	 * @return mixed Mapped value.
	 */
	public static function map_value_to_db_value( $value ) {
		if ( $value instanceof DateTimeImmutable ) {
			return $value->format( 'Y-m-d H:i:s.v' );
		}

		if ( is_array( $value ) ) {
			return wp_json_encode( $value );
		}

		if ( is_bool( $value ) ) {
			return $value ? 1 : 0;
		}

		if ( is_string( $value ) ) {
			return $value;
		}

		if ( is_int( $value ) ) {
			return $value;
		}

		throw new \InvalidArgumentException( 'Unknown field type' );
	}

	/**
	 * Maps a database field value to its property representation.
	 *
	 * @throws \InvalidArgumentException
	 *
	 * @param string $key   Property name.
	 * @param mixed  $value Value to map.
	 * @return mixed Mapped value.
	 */
	private static function map_db_field_to_value( string $key, $value ) {
		$reflection = new \ReflectionClass( static::get_item_class() );

		if ( ! $reflection->hasProperty( $key ) ) {
			throw new \InvalidArgumentException( esc_html( "Unknown field: {$key}" ) );
		}

		$property = $reflection->getProperty( $key );
		$type_name = self::get_property_type_name( $property );

		switch ( $type_name ) {
			case 'int':
				return (int) $value;
			case 'DateTimeImmutable':
				return new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) );
			case 'bool':
				return (bool) $value;
			case 'array':
				$result = json_decode( $value, true );
				if ( ! is_array( $result ) ) {
					$result = [];
				}
				return $result;
		}

		return $value;
	}

	/**
	 * @return class-string<TItem>
	 */
	abstract public static function get_item_class(): string;

	private function __construct() {}
}
