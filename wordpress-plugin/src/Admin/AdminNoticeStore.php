<?php
/**
 * Stores one-time admin notices between redirects.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AdminNoticeStore {
	public function set( string $message, string $type = 'success' ): void {
		set_transient(
			$this->get_key(),
			array(
				'message' => sanitize_text_field( $message ),
				'type'    => in_array( $type, array( 'success', 'error', 'warning' ), true ) ? $type : 'success',
			),
			60
		);
	}

	/**
	 * @return array<string, string>|null
	 */
	public function pull(): ?array {
		$key    = $this->get_key();
		$notice = get_transient( $key );

		if ( false === $notice ) {
			return null;
		}

		delete_transient( $key );

		return is_array( $notice ) ? $notice : null;
	}

	private function get_key(): string {
		return 'lpm_admin_notice_' . (int) get_current_user_id();
	}
}
