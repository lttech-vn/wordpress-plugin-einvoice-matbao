<?php
/**
 * Customer-facing status mapping.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Customer\CustomerInvoiceStatus;
use LTTech\EinvoiceMatbao\Invoice\InvoiceRecord;
use LTTech\EinvoiceMatbao\Invoice\InvoiceStatus;
use WP_UnitTestCase;

/**
 * Expectations follow the spec of the customer-invoice-view change, so a change shows up here.
 */
final class CustomerInvoiceStatusTest extends WP_UnitTestCase {

	/**
	 * Record rows: plugin status, Mat Bao status, Mat Bao invoice type, Mat Bao invoice ID, expected status.
	 *
	 * @return array<string, array{0: string, 1: int|null, 2: int|null, 3: string|null, 4: CustomerInvoiceStatus}>
	 */
	public static function records(): array {
		return array(
			'pending'                     => array( InvoiceStatus::PENDING, null, null, null, CustomerInvoiceStatus::PROCESSING ),
			'sending'                     => array( InvoiceStatus::SENDING, null, null, null, CustomerInvoiceStatus::PROCESSING ),
			'failed'                      => array( InvoiceStatus::FAILED, null, null, null, CustomerInvoiceStatus::NEEDS_STORE ),
			'needs attention'             => array( InvoiceStatus::NEEDS_ATTENTION, null, null, null, CustomerInvoiceStatus::NEEDS_STORE ),
			'not configured'              => array( InvoiceStatus::NOT_CONFIGURED, null, null, null, CustomerInvoiceStatus::NEEDS_STORE ),
			'stopped'                     => array( InvoiceStatus::STOPPED, null, null, null, CustomerInvoiceStatus::NEEDS_STORE ),
			'unknown status'              => array( 'weird', null, null, null, CustomerInvoiceStatus::NEEDS_STORE ),
			'skipped'                     => array( InvoiceStatus::SKIPPED, null, null, null, CustomerInvoiceStatus::NOT_REQUIRED ),
			'draft, not yet issued'       => array( InvoiceStatus::DRAFT_CREATED, 1, null, 'abc', CustomerInvoiceStatus::PROCESSING ),
			'draft, no Mat Bao status'    => array( InvoiceStatus::DRAFT_CREATED, null, null, 'abc', CustomerInvoiceStatus::PROCESSING ),
			'draft, not sent to CQT'      => array( InvoiceStatus::DRAFT_CREATED, 3, null, 'abc', CustomerInvoiceStatus::PROCESSING ),
			'draft, CQT no response'      => array( InvoiceStatus::DRAFT_CREATED, 5, null, 'abc', CustomerInvoiceStatus::PROCESSING ),
			'draft, form 04 undeclared'   => array( InvoiceStatus::DRAFT_CREATED, 7, null, 'abc', CustomerInvoiceStatus::PROCESSING ),
			'draft, form 04 pending'      => array( InvoiceStatus::DRAFT_CREATED, 8, null, 'abc', CustomerInvoiceStatus::PROCESSING ),
			'draft, form 04 approved'     => array( InvoiceStatus::DRAFT_CREATED, 9, null, 'abc', CustomerInvoiceStatus::PROCESSING ),
			'draft, unknown Mat Bao code' => array( InvoiceStatus::DRAFT_CREATED, 12, null, 'abc', CustomerInvoiceStatus::PROCESSING ),
			'draft, deleted on Mat Bao'   => array( InvoiceStatus::DRAFT_CREATED, 0, null, 'abc', CustomerInvoiceStatus::NEEDS_STORE ),
			'draft, sign failed'          => array( InvoiceStatus::DRAFT_CREATED, 2, null, 'abc', CustomerInvoiceStatus::NEEDS_STORE ),
			'draft, not eligible'         => array( InvoiceStatus::DRAFT_CREATED, 6, null, 'abc', CustomerInvoiceStatus::NEEDS_STORE ),
			'issued, new invoice'         => array( InvoiceStatus::DRAFT_CREATED, 4, 2, 'abc', CustomerInvoiceStatus::ISSUED ),
			'issued, unknown type'        => array( InvoiceStatus::DRAFT_CREATED, 4, null, 'abc', CustomerInvoiceStatus::ISSUED ),
			'issued, deleted invoice'     => array( InvoiceStatus::DRAFT_CREATED, 4, 3, 'abc', CustomerInvoiceStatus::ADJUSTED ),
			'issued, replaced invoice'    => array( InvoiceStatus::DRAFT_CREATED, 4, 5, 'abc', CustomerInvoiceStatus::ADJUSTED ),
			'issued, no Mat Bao ID'       => array( InvoiceStatus::DRAFT_CREATED, 4, 2, null, CustomerInvoiceStatus::PROCESSING ),
		);
	}

	/**
	 * Each record row maps to its customer status.
	 *
	 * @dataProvider records
	 *
	 * @param string                $status   Plugin invoice status.
	 * @param int|null              $matbao   Mat Bao status code.
	 * @param int|null              $type     Mat Bao invoice type code.
	 * @param string|null           $invoice_id Mat Bao invoice ID.
	 * @param CustomerInvoiceStatus $expected Expected customer status.
	 */
	public function test_maps_record( string $status, ?int $matbao, ?int $type, ?string $invoice_id, CustomerInvoiceStatus $expected ): void {
		$record = new InvoiceRecord(
			id: 1,
			order_id: 99999999,
			reference: 'WC99999999',
			status: $status,
			status_reason: '',
			error_code: '',
			error_kind: '',
			buyer_name: '',
			template_form: '',
			template_series: '',
			matbao_invoice_id: $invoice_id,
			issued_on: null,
			matbao_status: $matbao,
			matbao_invoice_type: $type,
		);

		$this->assertSame( $expected, CustomerInvoiceStatus::from_record( $record ) );
	}

	/**
	 * Only the issued status allows the PDF download.
	 */
	public function test_only_issued_allows_pdf(): void {
		foreach ( CustomerInvoiceStatus::cases() as $case ) {
			$this->assertSame( CustomerInvoiceStatus::ISSUED === $case, $case->allows_pdf(), $case->value );
		}
	}

	/**
	 * Labels are the exact customer-facing strings.
	 */
	public function test_labels(): void {
		$this->assertSame( 'Đang xuất hóa đơn', CustomerInvoiceStatus::PROCESSING->label() );
		$this->assertSame( 'Đang xử lý, cửa hàng sẽ liên hệ nếu cần', CustomerInvoiceStatus::NEEDS_STORE->label() );
		$this->assertSame( 'Đơn hàng không cần xuất hóa đơn', CustomerInvoiceStatus::NOT_REQUIRED->label() );
		$this->assertSame( 'Đã xuất hóa đơn', CustomerInvoiceStatus::ISSUED->label() );
		$this->assertSame( 'Hóa đơn đã được điều chỉnh, vui lòng liên hệ cửa hàng', CustomerInvoiceStatus::ADJUSTED->label() );
	}
}
