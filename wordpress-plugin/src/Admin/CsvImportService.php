<?php
/**
 * Bounded CSV import preview and commit service.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Admin;

use Lilleprinsen\PriceMonitor\Database\Repository;
use Lilleprinsen\PriceMonitor\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CsvImportService {
	public const MAX_BYTES = 524288;
	public const MAX_ROWS  = 500;

	private Repository $repository;

	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * @param array<string, mixed> $file Uploaded file info.
	 * @return array<string, mixed>
	 */
	public function preview_upload( array $file ): array {
		$validation = $this->validate_upload( $file );

		if ( ! empty( $validation['error'] ) ) {
			return array(
				'success' => false,
				'message' => (string) $validation['error'],
			);
		}

		$handle = fopen( (string) $file['tmp_name'], 'r' );

		if ( false === $handle ) {
			return array(
				'success' => false,
				'message' => __( 'Could not read uploaded CSV file.', 'lilleprinsen-price-monitor' ),
			);
		}

		$headers = $this->read_headers( $handle );

		if ( empty( $headers ) ) {
			fclose( $handle );

			return array(
				'success' => false,
				'message' => __( 'CSV file is missing a header row.', 'lilleprinsen-price-monitor' ),
			);
		}

		$preview = array(
			'success'      => true,
			'created_at'   => current_time( 'mysql' ),
			'headers'      => array_values( $headers ),
			'valid_rows'   => array(),
			'invalid_rows' => array(),
			'summary'      => array(
				'total_rows'          => 0,
				'valid_rows'          => 0,
				'rows_with_warnings'  => 0,
				'invalid_rows'        => 0,
				'products_found'      => 0,
				'products_not_found'  => 0,
				'duplicate_products'  => 0,
				'duplicate_links'     => 0,
				'truncated'           => 0,
			),
		);
		$seen_products = array();
		$seen_links    = array();
		$row_number    = 1;

		while ( false !== ( $row = fgetcsv( $handle ) ) ) {
			++$row_number;

			if ( (int) $preview['summary']['total_rows'] >= self::MAX_ROWS ) {
				$preview['summary']['truncated'] = 1;
				break;
			}

			if ( $this->is_empty_row( $row ) ) {
				continue;
			}

			$mapped = $this->map_row( $headers, $row );
			$result = $this->validate_row( $mapped, $row_number, $seen_products, $seen_links );
			$preview['summary']['total_rows']++;

			if ( empty( $result['valid'] ) ) {
				$preview['invalid_rows'][] = $result;
				$preview['summary']['invalid_rows']++;

				if ( empty( $result['product_found'] ) ) {
					$preview['summary']['products_not_found']++;
				}

				continue;
			}

			$preview['valid_rows'][] = $result;
			$preview['summary']['valid_rows']++;
			$preview['summary']['products_found']++;

			if ( ! empty( $result['warnings'] ) ) {
				$preview['summary']['rows_with_warnings']++;
			}

			if ( ! empty( $result['duplicate_product'] ) ) {
				$preview['summary']['duplicate_products']++;
			}

			if ( ! empty( $result['duplicate_link'] ) || ! empty( $result['duplicate_link_in_file'] ) ) {
				$preview['summary']['duplicate_links']++;
			}
		}

		fclose( $handle );

		return $preview;
	}

	/**
	 * @param array<string, mixed> $preview Preview payload.
	 * @return array<string, int>
	 */
	public function commit_preview( array $preview ): array {
		$summary = array(
			'imported_rows'        => 0,
			'updated_products'     => 0,
			'created_products'     => 0,
			'created_links'        => 0,
			'skipped_links'        => 0,
			'skipped_invalid_rows' => 0,
		);

		$invalid_rows = isset( $preview['invalid_rows'] ) && is_array( $preview['invalid_rows'] ) ? $preview['invalid_rows'] : array();

		foreach ( $invalid_rows as $invalid_row ) {
			$summary['skipped_invalid_rows']++;
			$this->repository->write_log(
				'warning',
				'csv_import_row_skipped',
				__( 'CSV import row skipped during confirm.', 'lilleprinsen-price-monitor' ),
				array(
					'row_number' => (int) ( $invalid_row['row_number'] ?? 0 ),
					'errors'     => $invalid_row['errors'] ?? array(),
				),
				null
			);
		}

		$valid_rows = isset( $preview['valid_rows'] ) && is_array( $preview['valid_rows'] ) ? $preview['valid_rows'] : array();

		foreach ( $valid_rows as $row ) {
			if ( empty( $row['product_id'] ) ) {
				continue;
			}

			$product_id = absint( $row['product_id'] );
			$sku        = isset( $row['sku'] ) ? (string) $row['sku'] : '';
			$existing   = $this->repository->get_monitored_product_by_product_id( $product_id );
			$result     = $this->repository->add_monitored_product( $product_id, $sku );
			$monitored  = $this->repository->get_monitored_product_by_product_id( $product_id );

			if ( ! $monitored ) {
				continue;
			}

			if ( $existing ) {
				$summary['updated_products']++;
			} elseif ( ! empty( $result['success'] ) ) {
				$summary['created_products']++;
			}

			$this->repository->update_monitored_product_rules( (int) $monitored['id'], $this->build_rule_update_data( $monitored, $row ) );

			$link_id      = 0;
			$link_skipped = false;

			if ( ! empty( $row['competitor_url'] ) ) {
				$existing_link = $this->repository->get_competitor_link_by_url( (int) $monitored['id'], (string) $row['competitor_url'] );

				if ( $existing_link ) {
					$link_skipped = true;
					$summary['skipped_links']++;
				} else {
					$link_id = $this->repository->add_competitor_link(
						array(
							'monitored_product_id' => (int) $monitored['id'],
							'competitor_name'      => (string) ( $row['competitor_name'] ?? '' ),
							'competitor_url'       => (string) $row['competitor_url'],
							'match_type'           => (string) ( $row['match_type'] ?? 'unknown' ),
							'enabled'              => array_key_exists( 'enabled', $row ) && null !== $row['enabled'] ? (int) $row['enabled'] : 1,
						)
					);

					if ( $link_id > 0 ) {
						$summary['created_links']++;
					}
				}
			}

			$summary['imported_rows']++;
			$this->repository->write_log(
				'info',
				'csv_import_row_imported',
				__( 'CSV import row confirmed.', 'lilleprinsen-price-monitor' ),
				array(
					'row_number'              => (int) ( $row['row_number'] ?? 0 ),
					'monitored_product_id'    => (int) $monitored['id'],
					'competitor_link_id'      => $link_id,
					'competitor_link_skipped' => $link_skipped,
					'warnings'                => $row['warnings'] ?? array(),
				),
				$product_id
			);
		}

		return $summary;
	}

	/**
	 * @param array<string, mixed> $row Preview row.
	 * @return array<string, mixed>
	 */
	private function build_rule_update_data( array $current, array $row ): array {
		return array(
			'enabled'               => array_key_exists( 'enabled', $row ) && null !== $row['enabled'] ? (int) $row['enabled'] : (int) ( $current['enabled'] ?? 1 ),
			'priority'              => ! empty( $row['priority'] ) ? (string) $row['priority'] : (string) ( $current['priority'] ?? 'normal' ),
			'strategy'              => ! empty( $row['strategy'] ) ? (string) $row['strategy'] : (string) ( $current['strategy'] ?? 'match_competitor' ),
			'min_margin_percent'    => array_key_exists( 'min_margin_percent', $row ) && null !== $row['min_margin_percent'] ? $row['min_margin_percent'] : ( $current['min_margin_percent'] ?? '' ),
			'min_price'             => array_key_exists( 'min_price', $row ) && null !== $row['min_price'] ? $row['min_price'] : ( $current['min_price'] ?? '' ),
			'check_frequency_hours' => ! empty( $row['check_frequency_hours'] ) ? (int) $row['check_frequency_hours'] : (int) ( $current['check_frequency_hours'] ?? 24 ),
		);
	}

	/**
	 * @param array<string, mixed> $file Uploaded file info.
	 * @return array{error: string}
	 */
	private function validate_upload( array $file ): array {
		if ( empty( $file['tmp_name'] ) || ! is_string( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return array( 'error' => __( 'No CSV file was uploaded.', 'lilleprinsen-price-monitor' ) );
		}

		if ( ! empty( $file['error'] ) ) {
			return array( 'error' => __( 'The CSV upload failed.', 'lilleprinsen-price-monitor' ) );
		}

		$size = isset( $file['size'] ) ? absint( $file['size'] ) : 0;

		if ( $size <= 0 || $size > self::MAX_BYTES ) {
			return array(
				'error' => sprintf(
					/* translators: %d: max upload size in KB. */
					__( 'CSV file must be smaller than %d KB.', 'lilleprinsen-price-monitor' ),
					(int) floor( self::MAX_BYTES / 1024 )
				),
			);
		}

		$name = isset( $file['name'] ) ? sanitize_file_name( (string) $file['name'] ) : '';

		if ( ! preg_match( '/\.csv$/i', $name ) ) {
			return array( 'error' => __( 'Only .csv files are accepted.', 'lilleprinsen-price-monitor' ) );
		}

		return array( 'error' => '' );
	}

	/**
	 * @param resource $handle CSV handle.
	 * @return array<int, string>
	 */
	private function read_headers( $handle ): array {
		$row = fgetcsv( $handle );

		if ( ! is_array( $row ) ) {
			return array();
		}

		$headers = array();

		foreach ( $row as $header ) {
			$header = strtolower( trim( (string) $header ) );
			$header = preg_replace( '/^\xEF\xBB\xBF/', '', $header );
			$header = sanitize_key( str_replace( '-', '_', (string) $header ) );
			$headers[] = $header;
		}

		return $headers;
	}

	/**
	 * @param array<int, string> $headers CSV headers.
	 * @param array<int, mixed>  $row CSV row.
	 * @return array<string, string>
	 */
	private function map_row( array $headers, array $row ): array {
		$mapped = array();

		foreach ( $headers as $index => $header ) {
			if ( '' === $header ) {
				continue;
			}

			$mapped[ $header ] = isset( $row[ $index ] ) ? trim( (string) $row[ $index ] ) : '';
		}

		return $mapped;
	}

	/**
	 * @param array<string, string> $row Raw mapped row.
	 * @param array<int, int>       $seen_products Seen product IDs.
	 * @param array<string, bool>   $seen_links Seen product URL keys.
	 * @return array<string, mixed>
	 */
	private function validate_row( array $row, int $row_number, array &$seen_products, array &$seen_links ): array {
		$errors       = array();
		$warnings     = array();
		$product_id   = 0;
		$sku          = sanitize_text_field( (string) ( $row['sku'] ?? '' ) );
		$product      = null;
		$product_match = '';

		if ( ! empty( $row['product_id'] ) ) {
			$product_id    = absint( $row['product_id'] );
			$product       = $this->get_product( $product_id );
			$product_match = 'product_id';
		} elseif ( '' !== $sku && function_exists( 'wc_get_product_id_by_sku' ) ) {
			$product_id    = absint( wc_get_product_id_by_sku( $sku ) );
			$product       = $this->get_product( $product_id );
			$product_match = 'sku';
		} else {
			$errors[] = __( 'Row must include product_id or sku.', 'lilleprinsen-price-monitor' );
		}

		if ( $product_id <= 0 || ! $product ) {
			$errors[] = __( 'WooCommerce product was not found by product_id or sku.', 'lilleprinsen-price-monitor' );
		}

		$normalized = array(
			'row_number'            => $row_number,
			'product_id'            => $product_id,
			'sku'                   => '' !== $sku ? $sku : $this->get_product_sku( $product ),
			'product_match'         => $product_match,
			'product_found'         => $product_id > 0 && is_object( $product ),
			'competitor_name'       => sanitize_text_field( (string) ( $row['competitor_name'] ?? '' ) ),
			'competitor_url'        => esc_url_raw( (string) ( $row['competitor_url'] ?? '' ) ),
			'match_type'            => $this->sanitize_match_type( (string) ( $row['match_type'] ?? 'unknown' ) ),
			'enabled'               => $this->parse_optional_bool( $row['enabled'] ?? '' ),
			'priority'              => $this->sanitize_priority( (string) ( $row['priority'] ?? '' ) ),
			'strategy'              => $this->sanitize_strategy( (string) ( $row['strategy'] ?? '' ) ),
			'min_margin_percent'    => $this->parse_optional_decimal( $row['min_margin_percent'] ?? '', $errors, __( 'Minimum margin percent must be numeric.', 'lilleprinsen-price-monitor' ) ),
			'min_price'             => $this->parse_optional_decimal( $row['min_price'] ?? '', $errors, __( 'Minimum price must be numeric.', 'lilleprinsen-price-monitor' ) ),
			'check_frequency_hours' => $this->parse_optional_frequency( $row['check_frequency_hours'] ?? '', $errors ),
			'notes'                 => sanitize_textarea_field( (string) ( $row['notes'] ?? '' ) ),
			'warnings'              => array(),
			'errors'                => array(),
			'valid'                 => false,
		);

		if ( $product_id > 0 ) {
			if ( in_array( $product_id, $seen_products, true ) ) {
				$warnings[] = __( 'Product appears more than once in this CSV. Rules will be applied in row order.', 'lilleprinsen-price-monitor' );
				$normalized['duplicate_product'] = true;
			}

			$seen_products[] = $product_id;
		}

		if ( '' !== $normalized['competitor_url'] ) {
			if ( '' === $normalized['competitor_name'] ) {
				$errors[] = __( 'Competitor name is required when competitor_url is present.', 'lilleprinsen-price-monitor' );
			}

			if ( ! $this->is_valid_http_url( (string) $normalized['competitor_url'] ) ) {
				$errors[] = __( 'Competitor URL must be a valid http or https URL.', 'lilleprinsen-price-monitor' );
			}

			if ( $product_id > 0 ) {
				$link_key = $product_id . '|' . strtolower( (string) $normalized['competitor_url'] );

				if ( isset( $seen_links[ $link_key ] ) ) {
					$warnings[] = __( 'Duplicate competitor URL appears earlier in this CSV and will be skipped on confirm.', 'lilleprinsen-price-monitor' );
					$normalized['duplicate_link_in_file'] = true;
				}

				$seen_links[ $link_key ] = true;
				$monitored = $this->repository->get_monitored_product_by_product_id( $product_id );

				if ( $monitored && $this->repository->get_competitor_link_by_url( (int) $monitored['id'], (string) $normalized['competitor_url'] ) ) {
					$warnings[] = __( 'Competitor URL already exists for this monitored product and will be skipped on confirm.', 'lilleprinsen-price-monitor' );
					$normalized['duplicate_link'] = true;
				}
			}
		}

		if ( '' !== (string) ( $row['priority'] ?? '' ) && '' === $normalized['priority'] ) {
			$errors[] = __( 'Priority is invalid.', 'lilleprinsen-price-monitor' );
		}

		if ( '' !== (string) ( $row['strategy'] ?? '' ) && '' === $normalized['strategy'] ) {
			$errors[] = __( 'Strategy is invalid.', 'lilleprinsen-price-monitor' );
		}

		if ( '' !== (string) ( $row['enabled'] ?? '' ) && null === $normalized['enabled'] ) {
			$errors[] = __( 'Enabled must be yes/no, true/false, 1/0, enabled, or disabled.', 'lilleprinsen-price-monitor' );
		}

		$normalized['warnings'] = $warnings;
		$normalized['errors']   = $errors;
		$normalized['valid']    = empty( $errors );

		return $normalized;
	}

	/**
	 * @param array<int, mixed> $row CSV row.
	 */
	private function is_empty_row( array $row ): bool {
		foreach ( $row as $value ) {
			if ( '' !== trim( (string) $value ) ) {
				return false;
			}
		}

		return true;
	}

	private function get_product( int $product_id ): ?object {
		if ( $product_id <= 0 || ! Plugin::is_woocommerce_active() || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$product = wc_get_product( $product_id );

		return is_object( $product ) ? $product : null;
	}

	private function get_product_sku( ?object $product ): string {
		if ( ! $product || ! method_exists( $product, 'get_sku' ) ) {
			return '';
		}

		return (string) $product->get_sku();
	}

	/**
	 * @return int|null
	 */
	private function parse_optional_bool( string $value ): ?int {
		$value = strtolower( trim( $value ) );

		if ( '' === $value ) {
			return null;
		}

		if ( in_array( $value, array( '1', 'yes', 'true', 'enabled', 'on' ), true ) ) {
			return 1;
		}

		if ( in_array( $value, array( '0', 'no', 'false', 'disabled', 'off' ), true ) ) {
			return 0;
		}

		return null;
	}

	/**
	 * @param array<int, string> $errors Errors.
	 * @return float|null
	 */
	private function parse_optional_decimal( string $value, array &$errors, string $message ): ?float {
		$value = str_replace( ',', '.', trim( $value ) );

		if ( '' === $value ) {
			return null;
		}

		if ( ! is_numeric( $value ) ) {
			$errors[] = $message;
			return null;
		}

		return max( 0, round( (float) $value, 4 ) );
	}

	/**
	 * @param array<int, string> $errors Errors.
	 * @return int|null
	 */
	private function parse_optional_frequency( string $value, array &$errors ): ?int {
		$value = trim( $value );

		if ( '' === $value ) {
			return null;
		}

		$frequency = absint( $value );

		if ( $frequency < 1 || $frequency > 720 ) {
			$errors[] = __( 'Check frequency must be between 1 and 720 hours.', 'lilleprinsen-price-monitor' );
			return null;
		}

		return $frequency;
	}

	private function sanitize_priority( string $priority ): string {
		$priority = sanitize_key( $priority );

		return in_array( $priority, array( 'low', 'normal', 'high', 'urgent' ), true ) ? $priority : '';
	}

	private function sanitize_strategy( string $strategy ): string {
		$strategy = sanitize_key( $strategy );

		return in_array( $strategy, array( 'notify_only', 'match_competitor', 'beat_competitor_by_amount', 'stay_above_competitor_by_amount' ), true ) ? $strategy : '';
	}

	private function sanitize_match_type( string $match_type ): string {
		$match_type = sanitize_key( $match_type );

		return in_array( $match_type, array( 'unknown', 'exact', 'similar', 'different_variant', 'bundle', 'not_comparable' ), true ) ? $match_type : 'unknown';
	}

	private function is_valid_http_url( string $url ): bool {
		$parts = wp_parse_url( $url );

		return is_array( $parts )
			&& ! empty( $parts['host'] )
			&& ! empty( $parts['scheme'] )
			&& in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true );
	}
}
