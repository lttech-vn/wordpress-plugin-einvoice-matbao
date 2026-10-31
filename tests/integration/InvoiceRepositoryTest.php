<?php
/**
 * Atomic reserve and claim of invoice rows.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Invoice\InvoiceRepository;
use LTTech\EinvoiceMatbao\Invoice\InvoiceStatus;
use WP_UnitTestCase;

/**
 * The statements that stop two hooks or two runners from both winning.
 */
final class InvoiceRepositoryTest extends WP_UnitTestCase {

	public function test_second_reserve_for_the_same_order_loses(): void {
		$repository = new InvoiceRepository();

		$this->assertTrue( $repository->reserve( 9001, 'WC9001', 'Khách', InvoiceStatus::PENDING ) );
		$this->assertFalse( $repository->reserve( 9001, 'WC9001', 'Khách', InvoiceStatus::PENDING ) );
	}

	public function test_second_claim_of_the_same_row_loses(): void {
		$repository = new InvoiceRepository();
		$repository->reserve( 9002, 'WC9002', 'Khách', InvoiceStatus::PENDING );
		$invoice = $repository->find_by_order( 9002 );
		$this->assertNotNull( $invoice );

		$this->assertTrue( $repository->claim( $invoice->id, InvoiceStatus::PENDING, InvoiceStatus::SENDING ) );
		$this->assertFalse( $repository->claim( $invoice->id, InvoiceStatus::PENDING, InvoiceStatus::SENDING ) );
	}
}
