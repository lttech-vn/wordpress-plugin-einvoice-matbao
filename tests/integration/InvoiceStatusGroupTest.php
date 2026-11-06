<?php
/**
 * Badge groups of invoice statuses.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Invoice\InvoiceStatus;
use WP_UnitTestCase;

/**
 * Every status maps to one badge group; unknown values fall into `other`.
 */
final class InvoiceStatusGroupTest extends WP_UnitTestCase {

	/**
	 * A status resolves to its badge group.
	 *
	 * @dataProvider status_groups
	 *
	 * @param string $status   Status value.
	 * @param string $expected Expected group.
	 */
	public function test_every_status_maps_to_its_group( string $status, string $expected ): void {
		$this->assertSame( $expected, InvoiceStatus::group( $status ) );
	}

	/**
	 * Statuses and their groups.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function status_groups(): array {
		return array(
			'failed'          => array( 'failed', 'error' ),
			'needs_attention' => array( 'needs_attention', 'error' ),
			'not_configured'  => array( 'not_configured', 'error' ),
			'pending'         => array( 'pending', 'waiting' ),
			'sending'         => array( 'sending', 'waiting' ),
			'draft_created'   => array( 'draft_created', 'created' ),
			'skipped'         => array( 'skipped', 'other' ),
			'stopped'         => array( 'stopped', 'other' ),
			'unknown'         => array( 'unknown_x', 'other' ),
		);
	}
}
