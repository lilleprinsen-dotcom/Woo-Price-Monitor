<?php
/**
 * Local tests for product group suggestion behavior.
 *
 * @package LilleprinsenPriceMonitor
 */

declare(strict_types=1);

require_once __DIR__ . '/test-bootstrap.php';

use Lilleprinsen\PriceMonitor\Service\GroupSuggestionService;

final class LpmFakeGroupRepository {
	/**
	 * @var array<string, mixed>|null
	 */
	public ?array $group = null;

	/**
	 * @var array<int, array<string, mixed>>
	 */
	public array $members = array();

	/**
	 * @var array<int, array<string, mixed>>
	 */
	public array $active_sessions = array();

	public function get_active_product_group_for_monitored_product( int $monitored_product_id ): ?array {
		unset( $monitored_product_id );

		return $this->group;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_product_group_members( int $group_id, bool $enabled_only = false ): array {
		unset( $group_id );

		if ( ! $enabled_only ) {
			return $this->members;
		}

		return array_values(
			array_filter(
				$this->members,
				static fn( array $member ): bool => ! empty( $member['enabled'] )
			)
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get_active_price_match_session_for_product( int $product_id ): ?array {
		return $this->active_sessions[ $product_id ] ?? null;
	}
}

final class LpmFakeGroupProduct {
	private float $price;

	private string $status;

	public function __construct( float $price, string $status = 'publish' ) {
		$this->price  = $price;
		$this->status = $status;
	}

	public function get_price(): string {
		return (string) $this->price;
	}

	public function get_status(): string {
		return $this->status;
	}
}

function lpm_group_repo( string $pricing_mode = 'shared_price', int $primary_product_id = 101 ): LpmFakeGroupRepository {
	$repo = new LpmFakeGroupRepository();
	$repo->group = array(
		'id' => 7,
		'name' => 'Color group',
		'enabled' => 1,
		'pricing_mode' => $pricing_mode,
		'primary_product_id' => $primary_product_id,
	);
	$repo->members = array(
		array(
			'id' => 1,
			'group_id' => 7,
			'monitored_product_id' => 11,
			'product_id' => 101,
			'enabled' => 1,
			'min_price' => 900,
		),
		array(
			'id' => 2,
			'group_id' => 7,
			'monitored_product_id' => 12,
			'product_id' => 102,
			'enabled' => 1,
			'min_price' => 900,
		),
		array(
			'id' => 3,
			'group_id' => 7,
			'monitored_product_id' => 13,
			'product_id' => 103,
			'enabled' => 0,
			'min_price' => 900,
		),
	);

	return $repo;
}

lpm_run_tests(
	'GroupSuggestionService',
	array(
		'shared price group suggestion affects all enabled members' => static function (): void {
			$repo    = lpm_group_repo( 'shared_price' );
			$service = new GroupSuggestionService( $repo );
			$context = $service->get_group_context(
				array( 'id' => 11, 'product_id' => 101 ),
				999,
				array(),
				array(
					'products' => array(
						101 => new LpmFakeGroupProduct( 1299 ),
						102 => new LpmFakeGroupProduct( 1299 ),
					),
				)
			);

			lpm_assert_true( is_array( $context ), 'Shared-price group should return group context.' );
			lpm_assert_same( false, $context['skip'], 'Shared-price group should not skip.' );
			lpm_assert_same( array( 101, 102 ), $context['affected_products'], 'Shared-price group should include enabled members only.' );
			lpm_assert_same( true, $context['can_update_group'], 'Shared-price group should pass safety checks.' );
		},
		'primary controlled group blocks non-primary suggestions' => static function (): void {
			$repo    = lpm_group_repo( 'primary_product_controls_group', 101 );
			$service = new GroupSuggestionService( $repo );
			$context = $service->get_group_context( array( 'id' => 12, 'product_id' => 102 ), 999 );

			lpm_assert_true( is_array( $context ), 'Primary-controlled group should return context.' );
			lpm_assert_same( true, $context['skip'], 'Non-primary product should not drive group suggestion.' );
		},
		'manual review only group forces manual review' => static function (): void {
			$repo    = lpm_group_repo( 'manual_review_only' );
			$service = new GroupSuggestionService( $repo );
			$context = $service->get_group_context( array( 'id' => 11, 'product_id' => 101 ), 999 );

			lpm_assert_true( is_array( $context ), 'Manual-review group should return context.' );
			lpm_assert_same( true, $context['force_manual_review'], 'Manual-review group should force manual review.' );
		},
		'group validation blocks if one product violates min price' => static function (): void {
			$repo = lpm_group_repo( 'shared_price' );
			$repo->members[1]['min_price'] = 1100;
			$service = new GroupSuggestionService( $repo );
			$report  = $service->validate_group_members( (array) $repo->group, $repo->get_product_group_members( 7, true ), 999, array() );

			lpm_assert_same( false, $report['success'], 'Group report should fail when one member min price is violated.' );
			lpm_assert_same( false, $report['can_update_group'], 'Group cannot update when one member is blocked.' );
			lpm_assert_same( 102, $report['blocked_products'][0]['product_id'], 'Blocked product should be listed.' );
		},
		'partial updates disabled blocks whole group' => static function (): void {
			$repo = lpm_group_repo( 'shared_price' );
			$repo->members[1]['min_price'] = 1100;
			$service = new GroupSuggestionService( $repo );
			$report  = $service->validate_group_members( (array) $repo->group, $repo->get_product_group_members( 7, true ), 999, array( 'allow_partial_group_price_updates' => 0 ) );

			lpm_assert_same( false, $report['success'], 'Unsafe group report should fail.' );
			lpm_assert_same( array( 101 ), $report['eligible_products'], 'Only safe product should be eligible before update service applies partial setting.' );
		},
		'partial updates enabled exposes eligible products' => static function (): void {
			$repo = lpm_group_repo( 'shared_price' );
			$repo->members[1]['min_price'] = 1100;
			$service = new GroupSuggestionService( $repo );
			$report  = $service->validate_group_members( (array) $repo->group, $repo->get_product_group_members( 7, true ), 999, array( 'allow_partial_group_price_updates' => 1 ) );

			lpm_assert_same( false, $report['success'], 'Report should still note blocked members.' );
			lpm_assert_same( array( 101 ), $report['eligible_products'], 'Eligible products should be available for partial update flow.' );
		},
		'different original prices during recovery cause manual review warning' => static function (): void {
			$service = new GroupSuggestionService( lpm_group_repo() );
			$report  = $service->detect_mixed_original_price_states(
				array(
					101 => array(
						'original_regular_price' => 1499,
						'original_sale_price' => 1299,
						'original_active_price' => 1299,
					),
					102 => array(
						'original_regular_price' => 1599,
						'original_sale_price' => 1399,
						'original_active_price' => 1399,
					),
				)
			);

			lpm_assert_same( true, $report['mixed'], 'Mixed original prices should be detected.' );
			lpm_assert_contains( 'Manual review required', $report['reason'], 'Mixed original prices should require manual review.' );
		},
		'conflicting active session blocks real group validation' => static function (): void {
			$repo = lpm_group_repo( 'shared_price' );
			$repo->active_sessions[102] = array( 'id' => 99, 'product_id' => 102, 'suggestion_id' => 1, 'status' => 'active' );
			$service = new GroupSuggestionService( $repo );
			$report  = $service->validate_group_members(
				(array) $repo->group,
				$repo->get_product_group_members( 7, true ),
				999,
				array(),
				array(
					'block_conflicting_sessions' => true,
					'current_suggestion_id' => 2,
				)
			);

			lpm_assert_same( false, $report['success'], 'Conflicting active session should block group validation.' );
			lpm_assert_same( 102, $report['blocked_products'][0]['product_id'], 'Conflicting product should be listed.' );
		},
	)
);
