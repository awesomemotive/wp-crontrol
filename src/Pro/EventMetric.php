<?php

namespace Crontrol\Pro;

use DateTimeImmutable;

/**
 * Represents an aggregated metric entry for time-series statistics.
 *
 * This allows for efficient storage and querying of historical data
 * after the original event logs have been purged.
 */
final class EventMetric implements Item {
	/**
	 * Metric types.
	 */
	public const TYPE_ERRORS_HOURLY = 'errors_hourly';
	public const TYPE_WARNINGS_HOURLY = 'warnings_hourly';
	public const TYPE_MESSAGE_FREQUENCY = 'message_frequency';
	public const TYPE_HOOK_DURATION_DAILY = 'hook_duration_daily';
	public const TYPE_HOOK_DURATION_HOURLY = 'hook_duration_hourly';

	/**
	 * Metric ID.
	 */
	public int $ID = 0;

	/**
	 * Site ID (for multisite support).
	 */
	public int $site_id;

	/**
	 * Type of metric being tracked.
	 *
	 * @phpstan-var self::TYPE_*
	 */
	public string $metric_type;

	/**
	 * Time bucket for aggregation (e.g., hourly timestamp).
	 */
	public DateTimeImmutable $time_bucket;

	/**
	 * Dimension for grouping (e.g., level name, message hash).
	 */
	public string $dimension;

	/**
	 * Aggregated value (count, sum, etc.).
	 */
	public int $value;

	/**
	 * Create a new metric.
	 *
	 * @param string $metric_type Type of metric.
	 * @param DateTimeImmutable $time_bucket Time bucket for aggregation.
	 * @param string $dimension Dimension for grouping.
	 * @param int $value Aggregated value.
	 * @phpstan-param self::TYPE_* $metric_type
	 * @return self
	 */
	public static function create(
		string $metric_type,
		DateTimeImmutable $time_bucket,
		string $dimension,
		int $value
	): self {
		$item = new self();
		$item->metric_type = $metric_type;
		$item->time_bucket = $time_bucket;
		$item->dimension = $dimension;
		$item->value = $value;

		return $item;
	}
}
