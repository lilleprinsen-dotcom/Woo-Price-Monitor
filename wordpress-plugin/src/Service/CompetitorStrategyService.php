<?php
/**
 * Detect competitor pricing behavior from bounded price observations.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CompetitorStrategyService {
	private const MIN_DROP_PERCENT = 2.0;
	private const MIN_DROP_AMOUNT  = 1.0;

	/**
	 * @param array<int,array<string,mixed>> $observations Recent successful observation rows.
	 * @return array<string,mixed>
	 */
	public function analyze( array $observations, int $leader_window_hours = 48, int $campaign_days = 7 ): array {
		$leader_window_hours = max( 1, min( 168, absint( $leader_window_hours ) ) );
		$campaign_days       = max( 1, min( 30, absint( $campaign_days ) ) );
		$events              = $this->detect_drop_events( $observations, $campaign_days );
		$events              = $this->mark_leaders_and_followers( $events, $leader_window_hours );
		$competitors         = $this->summarize_competitors( $events );

		return array(
			'leader_window_hours' => $leader_window_hours,
			'campaign_days'       => $campaign_days,
			'rows_used'           => count( $observations ),
			'events_analyzed'     => count( $events ),
			'competitors_analyzed' => count( $competitors ),
			'leaders'             => $this->count_by_strategy( $competitors, 'price_drop_leader' ),
			'followers'           => $this->count_by_strategy( $competitors, 'market_follower' ),
			'campaign_runners'    => $this->count_by_strategy( $competitors, 'campaign_runner' ),
			'competitors'         => $competitors,
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $observations Observation rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function detect_drop_events( array $observations, int $campaign_days ): array {
		$series = array();

		foreach ( $observations as $row ) {
			$price = $this->normalize_price( $row['observed_price'] ?? null );
			$time  = $this->normalize_time( $row['checked_at'] ?? null );
			if ( null === $price || null === $time || empty( $row['success'] ) ) {
				continue;
			}

			$link_id = absint( $row['competitor_link_id'] ?? 0 );
			if ( $link_id <= 0 ) {
				continue;
			}

			$series[ $link_id ][] = array(
				'competitor_link_id'  => $link_id,
				'competitor_id'       => absint( $row['competitor_id'] ?? 0 ),
				'competitor_name'     => $this->competitor_name( $row ),
				'product_key'         => $this->product_key( $row ),
				'price'               => $price,
				'time'                => $time,
				'checked_at'          => gmdate( 'Y-m-d H:i:s', $time ),
			);
		}

		$events           = array();
		$campaign_seconds = $campaign_days * DAY_IN_SECONDS;

		foreach ( $series as $rows ) {
			usort(
				$rows,
				static fn( array $a, array $b ): int => $a['time'] <=> $b['time']
			);

			$count = count( $rows );
			for ( $index = 1; $index < $count; $index++ ) {
				$previous = $rows[ $index - 1 ];
				$current  = $rows[ $index ];
				$drop     = $previous['price'] - $current['price'];

				if ( $drop < self::MIN_DROP_AMOUNT || $previous['price'] <= 0 ) {
					continue;
				}

				$drop_percent = ( $drop / $previous['price'] ) * 100;
				if ( $drop_percent < self::MIN_DROP_PERCENT ) {
					continue;
				}

				$events[] = array_merge(
					$current,
					array(
						'previous_price' => $previous['price'],
						'new_price'      => $current['price'],
						'drop_percent'   => round( $drop_percent, 2 ),
						'is_campaign'    => $this->recovers_soon( $rows, $index, $previous['price'], $campaign_seconds ),
						'is_leader'      => false,
						'is_follower'    => false,
					)
				);
			}
		}

		return $events;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows Chronological observations for one competitor link.
	 */
	private function recovers_soon( array $rows, int $event_index, float $previous_price, int $campaign_seconds ): bool {
		$event_time = (int) $rows[ $event_index ]['time'];
		$threshold  = $previous_price * 0.98;
		$count      = count( $rows );

		for ( $index = $event_index + 1; $index < $count; $index++ ) {
			$row_time = (int) $rows[ $index ]['time'];
			if ( $row_time - $event_time > $campaign_seconds ) {
				return false;
			}

			if ( (float) $rows[ $index ]['price'] >= $threshold ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<int,array<string,mixed>> $events Drop events.
	 * @return array<int,array<string,mixed>>
	 */
	private function mark_leaders_and_followers( array $events, int $leader_window_hours ): array {
		$window_seconds = $leader_window_hours * HOUR_IN_SECONDS;
		$count          = count( $events );

		for ( $index = 0; $index < $count; $index++ ) {
			$event = $events[ $index ];
			$has_earlier = false;
			$has_later   = false;

			for ( $other_index = 0; $other_index < $count; $other_index++ ) {
				if ( $index === $other_index ) {
					continue;
				}

				$other = $events[ $other_index ];
				if ( $other['product_key'] !== $event['product_key'] || (int) $other['competitor_link_id'] === (int) $event['competitor_link_id'] ) {
					continue;
				}

				$diff = (int) $other['time'] - (int) $event['time'];
				if ( $diff > 0 && $diff <= $window_seconds ) {
					$has_later = true;
				}
				if ( $diff < 0 && abs( $diff ) <= $window_seconds ) {
					$has_earlier = true;
				}
			}

			$events[ $index ]['is_leader']   = $has_later && ! $has_earlier;
			$events[ $index ]['is_follower'] = $has_earlier;
		}

		return $events;
	}

	/**
	 * @param array<int,array<string,mixed>> $events Drop events.
	 * @return array<int,array<string,mixed>>
	 */
	private function summarize_competitors( array $events ): array {
		$summary = array();

		foreach ( $events as $event ) {
			$key = $this->competitor_key( $event );
			if ( ! isset( $summary[ $key ] ) ) {
				$summary[ $key ] = array(
					'competitor_id'     => (int) $event['competitor_id'],
					'competitor_name'   => (string) $event['competitor_name'],
					'price_drop_events' => 0,
					'leader_events'     => 0,
					'follower_events'   => 0,
					'campaign_events'   => 0,
					'total_drop_percent' => 0.0,
					'latest_event_at'    => '',
				);
			}

			$summary[ $key ]['price_drop_events']++;
			$summary[ $key ]['leader_events'] += ! empty( $event['is_leader'] ) ? 1 : 0;
			$summary[ $key ]['follower_events'] += ! empty( $event['is_follower'] ) ? 1 : 0;
			$summary[ $key ]['campaign_events'] += ! empty( $event['is_campaign'] ) ? 1 : 0;
			$summary[ $key ]['total_drop_percent'] += (float) $event['drop_percent'];
			if ( '' === $summary[ $key ]['latest_event_at'] || (string) $event['checked_at'] > $summary[ $key ]['latest_event_at'] ) {
				$summary[ $key ]['latest_event_at'] = (string) $event['checked_at'];
			}
		}

		foreach ( $summary as $key => $row ) {
			$events_count   = max( 1, (int) $row['price_drop_events'] );
			$leader_ratio   = ( (int) $row['leader_events'] / $events_count ) * 100;
			$follower_ratio = ( (int) $row['follower_events'] / $events_count ) * 100;
			$campaign_ratio = ( (int) $row['campaign_events'] / $events_count ) * 100;
			$strategy       = $this->classify_strategy( $row, $leader_ratio, $follower_ratio, $campaign_ratio );

			$summary[ $key ]['leader_ratio']       = round( $leader_ratio, 1 );
			$summary[ $key ]['follower_ratio']     = round( $follower_ratio, 1 );
			$summary[ $key ]['campaign_ratio']     = round( $campaign_ratio, 1 );
			$summary[ $key ]['average_drop_percent'] = round( (float) $row['total_drop_percent'] / $events_count, 1 );
			$summary[ $key ]['strategy']           = $strategy;
			$summary[ $key ]['strategy_label']     = $this->strategy_label( $strategy );
			$summary[ $key ]['explanation']        = $this->strategy_explanation( $summary[ $key ] );
			unset( $summary[ $key ]['total_drop_percent'] );
		}

		$rows = array_values( $summary );
		usort(
			$rows,
			static function ( array $a, array $b ): int {
				$weight = array(
					'price_drop_leader' => 0,
					'campaign_runner'   => 1,
					'market_follower'   => 2,
					'mixed_strategy'    => 3,
					'not_enough_data'   => 4,
				);

				return ( $weight[ $a['strategy'] ] ?? 9 ) <=> ( $weight[ $b['strategy'] ] ?? 9 )
					?: (int) $b['price_drop_events'] <=> (int) $a['price_drop_events'];
			}
		);

		return $rows;
	}

	/**
	 * @param array<string,mixed> $row Competitor summary.
	 */
	private function classify_strategy( array $row, float $leader_ratio, float $follower_ratio, float $campaign_ratio ): string {
		$events = (int) $row['price_drop_events'];
		if ( $events < 2 ) {
			return 'not_enough_data';
		}

		if ( (int) $row['campaign_events'] >= 2 && $campaign_ratio >= 45.0 ) {
			return 'campaign_runner';
		}

		if ( (int) $row['leader_events'] >= 2 && $leader_ratio >= 45.0 ) {
			return 'price_drop_leader';
		}

		if ( (int) $row['follower_events'] >= 2 && $follower_ratio >= 45.0 ) {
			return 'market_follower';
		}

		return 'mixed_strategy';
	}

	private function strategy_label( string $strategy ): string {
		$labels = array(
			'price_drop_leader' => __( 'Leads price drops', 'lilleprinsen-price-monitor' ),
			'market_follower'   => __( 'Follows market moves', 'lilleprinsen-price-monitor' ),
			'campaign_runner'   => __( 'Runs temporary campaigns', 'lilleprinsen-price-monitor' ),
			'mixed_strategy'    => __( 'Mixed behavior', 'lilleprinsen-price-monitor' ),
			'not_enough_data'   => __( 'Needs more data', 'lilleprinsen-price-monitor' ),
		);

		return $labels[ $strategy ] ?? __( 'Unknown strategy', 'lilleprinsen-price-monitor' );
	}

	/**
	 * @param array<string,mixed> $row Competitor summary.
	 */
	private function strategy_explanation( array $row ): string {
		if ( 'not_enough_data' === (string) $row['strategy'] ) {
			return __( 'At least two meaningful price drops are needed before strategy can be classified.', 'lilleprinsen-price-monitor' );
		}

		return sprintf(
			/* translators: 1: leader percentage, 2: follower percentage, 3: campaign percentage, 4: average drop percentage. */
			__( 'Leader %1$s%%, follower %2$s%%, temporary campaign %3$s%%. Average observed drop: %4$s%%.', 'lilleprinsen-price-monitor' ),
			(string) $row['leader_ratio'],
			(string) $row['follower_ratio'],
			(string) $row['campaign_ratio'],
			(string) $row['average_drop_percent']
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $competitors Competitor strategy rows.
	 */
	private function count_by_strategy( array $competitors, string $strategy ): int {
		return count(
			array_filter(
				$competitors,
				static fn( array $row ): bool => (string) ( $row['strategy'] ?? '' ) === $strategy
			)
		);
	}

	/**
	 * @param array<string,mixed> $row Observation row.
	 */
	private function product_key( array $row ): string {
		$monitored_id = absint( $row['monitored_product_id'] ?? 0 );
		if ( $monitored_id > 0 ) {
			return 'monitored:' . $monitored_id;
		}

		return 'product:' . absint( $row['product_id'] ?? 0 );
	}

	/**
	 * @param array<string,mixed> $row Observation row.
	 */
	private function competitor_name( array $row ): string {
		$name = trim( (string) ( $row['competitor_name'] ?? '' ) );

		return '' !== $name ? $name : __( 'Unknown competitor', 'lilleprinsen-price-monitor' );
	}

	/**
	 * @param array<string,mixed> $event Drop event.
	 */
	private function competitor_key( array $event ): string {
		$id = absint( $event['competitor_id'] ?? 0 );
		if ( $id > 0 ) {
			return 'competitor:' . $id;
		}

		return 'name:' . strtolower( (string) $event['competitor_name'] );
	}

	private function normalize_price( $value ): ?float {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return null;
		}

		$price = (float) $value;

		return $price > 0 ? $price : null;
	}

	private function normalize_time( $value ): ?int {
		if ( empty( $value ) ) {
			return null;
		}

		$timestamp = strtotime( (string) $value );

		return false === $timestamp ? null : $timestamp;
	}
}
