<?php
/**
 * Meaning of the Mat Bao invoice status codes.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Invoice\InvoiceErrorCode;
use LTTech\EinvoiceMatbao\Invoice\MatbaoSnapshot;
use LTTech\EinvoiceMatbao\Invoice\MatbaoStatus;
use LTTech\EinvoiceMatbao\Matbao\MatbaoInvoice;
use WP_UnitTestCase;

/**
 * Expectations are the literal labels and classes of the spec, so a change shows up here.
 */
final class MatbaoStatusTest extends WP_UnitTestCase {

	/**
	 * Code, label, final, alert code, group.
	 *
	 * @return array<string, array{0: int, 1: string, 2: bool, 3: string|null, 4: string}>
	 */
	public static function statuses(): array {
		return array(
			'deleted'              => array( 0, 'Đã xóa trên Mắt Bão', true, 'matbao_draft_deleted', 'error' ),
			'draft'                => array( 1, 'Nháp', false, null, 'progress' ),
			'sign failed'          => array( 2, 'Lỗi ký', false, 'matbao_sign_failed', 'error' ),
			'not sent'             => array( 3, 'Chưa gửi CQT', false, null, 'progress' ),
			'issued'               => array( 4, 'Đã cấp mã/tiếp nhận', true, null, 'issued' ),
			'no response'          => array( 5, 'CQT chưa phản hồi', false, null, 'progress' ),
			'not eligible'         => array( 6, 'Không đủ điều kiện cấp mã/tiếp nhận', false, 'matbao_not_eligible', 'error' ),
			'form 04 undeclared'   => array( 7, '[Mẫu 04/SS] Chưa tạo tờ khai', false, null, 'progress' ),
			'form 04 not received' => array( 8, '[Mẫu 04/SS] CQT chưa tiếp nhận', false, null, 'progress' ),
			'form 04 approved'     => array( 9, '[Mẫu 04/SS] CQT đã phê duyệt', false, null, 'progress' ),
		);
	}

	/**
	 * Label, final flag, alert code and group of each status.
	 *
	 * @dataProvider statuses
	 *
	 * @param int         $code  Stored code.
	 * @param string      $label Vietnamese label.
	 * @param bool        $is_final Whether it is final.
	 * @param string|null $alert Alert code value.
	 * @param string      $group Badge group.
	 */
	public function test_status_semantics( int $code, string $label, bool $is_final, ?string $alert, string $group ): void {
		$status = MatbaoStatus::from( $code );

		$this->assertSame( $label, $status->label() );
		$this->assertSame( $is_final, $status->is_final() );
		$this->assertSame( $alert, $status->alert_code()?->value );
		$this->assertSame( $group, $status->group() );
	}

	public function test_unknown_code_has_no_case(): void {
		$this->assertNull( MatbaoStatus::tryFrom( 12 ) );
	}

	public function test_final_values_match_is_final(): void {
		$final = array_values( array_map( static fn( MatbaoStatus $s ): int => $s->value, array_filter( MatbaoStatus::cases(), static fn( MatbaoStatus $s ): bool => $s->is_final() ) ) );

		$this->assertEqualsCanonicalizing( MatbaoStatus::FINAL_VALUES, $final );
		$this->assertSame( array( 0, 4 ), MatbaoStatus::FINAL_VALUES );
	}

	public function test_invoice_type_labels(): void {
		$this->assertSame( 'Nháp', MatbaoStatus::invoice_type_label( 1 ) );
		$this->assertSame( 'Hóa đơn mới', MatbaoStatus::invoice_type_label( 2 ) );
		$this->assertSame( 'Hóa đơn bị điều chỉnh', MatbaoStatus::invoice_type_label( 10 ) );
		$this->assertSame( '', MatbaoStatus::invoice_type_label( 11 ) );
		$this->assertSame( '', MatbaoStatus::invoice_type_label( null ) );
	}

	public function test_snapshot_from_invoice_and_deleted(): void {
		$invoice  = new MatbaoInvoice( 'id', 'WC1', 4, 'Đã cấp mã', '2026-10-14', 100, null, null, 125, '00ABC', 2, 'Ghi chú' );
		$snapshot = MatbaoSnapshot::from_invoice( $invoice );

		$this->assertSame( 4, $snapshot->status );
		$this->assertSame( 'Đã cấp mã', $snapshot->status_name );
		$this->assertSame( 125, $snapshot->invoice_number );
		$this->assertSame( '00ABC', $snapshot->tax_authority_code );
		$this->assertSame( 2, $snapshot->invoice_type );
		$this->assertSame( 'Ghi chú', $snapshot->tax_note );

		$deleted = MatbaoSnapshot::deleted();
		$this->assertSame( 0, $deleted->status );
		$this->assertSame( '', $deleted->status_name );
		$this->assertSame( InvoiceErrorCode::MATBAO_DRAFT_DELETED, MatbaoStatus::tryFrom( (int) $deleted->status )?->alert_code() );
	}
}
