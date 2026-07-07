<?php
/**
 * Builds safe admin review links for notifications.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Service;

use Lilleprinsen\PriceMonitor\Admin\AdminPage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ReviewLinkService {
	public function get_approvals_url( string $view = 'pending', ?int $suggestion_id = null ): string {
		$args = array(
			'page'              => AdminPage::SLUG,
			'tab'               => 'approvals',
			'lpm_approval_view' => $this->sanitize_view( $view ),
		);

		if ( null !== $suggestion_id && $suggestion_id > 0 ) {
			$args['lpm_suggestion_id'] = absint( $suggestion_id );
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	public function get_suggestion_review_url( array $context ): string {
		$status        = (string) ( $context['status'] ?? '' );
		$suggestion_id = absint( $context['suggestion_id'] ?? 0 );
		$type          = (string) ( $context['suggestion_type'] ?? '' );
		$view          = 'pending';

		if ( 'blocked' === $status ) {
			$view = 'blocked';
		} elseif ( str_starts_with( $type, 'restore_previous_' ) || 'price_match_up' === $type ) {
			$view = 'recovery';
		}

		return $this->get_approvals_url( $view, $suggestion_id > 0 ? $suggestion_id : null );
	}

	private function sanitize_view( string $view ): string {
		$view    = sanitize_key( $view );
		$allowed = array(
			'pending',
			'blocked',
			'approved_dry_run',
			'approved_real_update',
			'rejected',
			'failed',
			'price_match_down',
			'price_match_up',
			'restore_previous_price',
			'recovery',
			'all',
		);

		return in_array( $view, $allowed, true ) ? $view : 'pending';
	}
}
