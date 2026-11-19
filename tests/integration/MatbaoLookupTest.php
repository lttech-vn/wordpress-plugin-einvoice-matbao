<?php
/**
 * Batch lookup of invoices by `MTChieu` and the fields parsed from each row.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Invoice\MatbaoStatus;
use LTTech\EinvoiceMatbao\Matbao\MatbaoClient;
use LTTech\EinvoiceMatbao\Matbao\MatbaoCredentials;
use LTTech\EinvoiceMatbao\Matbao\MatbaoError;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\SettingsRestTestCase;

/**
 * Mat Bao is faked at the HTTP boundary (`POST /api/invoice/invoice-detail-batch`).
 */
final class MatbaoLookupTest extends SettingsRestTestCase {

	private const ROUTE = 'POST /api/invoice/invoice-detail-batch';

	public function set_up(): void {
		parent::set_up();

		$this->with_encryption_key();
		$this->fake_matbao_login_ok();
	}

	public function test_issued_invoice_fields(): void {
		$this->answer_rows(
			array(
				array_merge(
					$this->row( 'WC1' ),
					array(
						'shDon'       => 125,
						'mccqt'       => '00ABC',
						'maLoaiHDon'  => 2,
						'maTThaiHDon' => 4,
						'ghiChuCQT'   => 'Ghi chú',
					)
				),
			)
		);

		$lookup  = $this->client()->find_invoices_by_references( array( 'WC1' ), '1', 'C26TAT' );
		$invoice = $lookup->invoices[0];

		$this->assertSame( 125, $invoice->invoice_number );
		$this->assertSame( '00ABC', $invoice->tax_authority_code );
		$this->assertSame( 2, $invoice->invoice_type );
		$this->assertSame( 'Ghi chú', $invoice->tax_note );
		$this->assertSame( 'Hóa đơn mới', MatbaoStatus::invoice_type_label( 2 ) );
	}

	public function test_lookup_code_read(): void {
		$this->answer_rows( array( array_merge( $this->row( 'WC1' ), array( 'maTraCuu' => 'FM80520C49' ) ) ) );

		$invoice = $this->client()->find_invoices_by_references( array( 'WC1' ), '1', 'C26TAT' )->invoices[0];

		$this->assertSame( 'FM80520C49', $invoice->lookup_code );
	}

	public function test_lookup_code_missing(): void {
		$this->answer_rows( array( $this->row( 'WC1' ), array_merge( $this->row( 'WC2' ), array( 'maTraCuu' => '' ) ) ) );

		$lookup = $this->client()->find_invoices_by_references( array( 'WC1', 'WC2' ), '1', 'C26TAT' );

		$this->assertNull( $lookup->invoices[0]->lookup_code );
		$this->assertNull( $lookup->invoices[1]->lookup_code );
	}

	public function test_draft_without_number(): void {
		$this->answer_rows(
			array(
				array_merge(
					$this->row( 'WC1' ),
					array(
						'shDon' => 0,
						'mccqt' => '',
					)
				),
			)
		);

		$invoice = $this->client()->find_invoices_by_references( array( 'WC1' ), '1', 'C26TAT' )->invoices[0];

		$this->assertNull( $invoice->invoice_number );
		$this->assertNull( $invoice->tax_authority_code );
		$this->assertNull( $invoice->tax_note );
	}

	public function test_draft_by_name_without_code(): void {
		$row = $this->row( 'WC1' );
		unset( $row['maTThaiHDon'] );
		$this->answer_rows( array( $row ) );

		$invoice = $this->client()->find_invoices_by_references( array( 'WC1' ), '1', 'C26TAT' )->invoices[0];

		$this->assertNull( $invoice->status );
		$this->assertTrue( $invoice->is_draft() );
	}

	public function test_several_references_one_request(): void {
		$references = array( '1/C26TAT-A', '1/C26TAT-B', '1/C26TAT-C' );
		$this->answer_rows( array() );

		$this->client()->find_invoices_by_references( $references, '1', 'C26TAT' );

		$requests = $this->http->requests_to( self::ROUTE );
		$this->assertCount( 1, $requests );
		$this->assertIsArray( $requests[0]['body'] );
		$this->assertSame( $references, $requests[0]['body']['danhSach'] );
		$this->assertSame( 1, $requests[0]['body']['loai'] );
	}

	public function test_partly_not_found(): void {
		$this->answer_rows( array( $this->row( 'WC1' ) ), array( 'WC2' ) );

		$lookup = $this->client()->find_invoices_by_references( array( 'WC1', 'WC2' ), '1', 'C26TAT' );

		$this->assertSame( array( 'WC2' ), $lookup->not_found );
		$this->assertCount( 1, $lookup->invoices );
	}

	public function test_not_found_301_lists_every_reference(): void {
		$this->http->respond_json(
			self::ROUTE,
			200,
			array(
				'errorCode' => 301,
				'message'   => 'Không tìm thấy',
				'data'      => null,
				'success'   => false,
			)
		);

		$lookup = $this->client()->find_invoices_by_references( array( 'WC1', 'WC2' ), '1', 'C26TAT' );

		$this->assertSame( array(), $lookup->invoices );
		$this->assertSame( array( 'WC1', 'WC2' ), $lookup->not_found );
	}

	public function test_unreadable_200_throws(): void {
		$this->http->respond_json(
			self::ROUTE,
			200,
			array(
				'errorCode' => 200,
				'data'      => array( 'notFound' => array() ),
				'success'   => true,
			)
		);

		$this->expectException( MatbaoError::class );
		$this->client()->find_invoices_by_references( array( 'WC1' ), '1', 'C26TAT' );
	}

	public function test_single_reference_wrapper_unchanged(): void {
		$this->answer_rows( array( $this->row( 'WC1' ) ) );

		$found = $this->client()->find_invoices_by_reference( 'WC1', '1', 'C26TAT' );

		$this->assertCount( 1, $found );
		$this->assertSame( 'WC1', $found[0]->reference );
	}

	public function test_single_reference_wrapper_returns_empty_on_301(): void {
		$this->http->respond_json(
			self::ROUTE,
			200,
			array(
				'errorCode' => 301,
				'message'   => 'x',
				'data'      => null,
				'success'   => false,
			)
		);

		$this->assertSame( array(), $this->client()->find_invoices_by_reference( 'WC1', '1', 'C26TAT' ) );
	}

	public function test_non_scalar_not_found_entries_are_dropped(): void {
		$this->http->respond_json(
			self::ROUTE,
			200,
			array(
				'errorCode' => 200,
				'data'      => array(
					'invoices' => array(),
					'notFound' => array( 'WC2', array( 'x' ), null ),
				),
				'success'   => true,
			)
		);

		$lookup = $this->client()->find_invoices_by_references( array( 'WC2' ), '1', 'C26TAT' );

		$this->assertSame( array( 'WC2' ), $lookup->not_found );
	}

	public function test_not_found_that_is_not_a_list_counts_as_empty(): void {
		$this->http->respond_json(
			self::ROUTE,
			200,
			array(
				'errorCode' => 200,
				'data'      => array(
					'invoices' => array(),
					'notFound' => 'WC2',
				),
				'success'   => true,
			)
		);

		$this->assertSame( array(), $this->client()->find_invoices_by_references( array( 'WC2' ), '1', 'C26TAT' )->not_found );
	}

	/**
	 * Fakes a successful lookup.
	 *
	 * @param list<array<string, mixed>> $rows      Invoice rows.
	 * @param string[]                   $not_found `notFound` list.
	 */
	private function answer_rows( array $rows, array $not_found = array() ): void {
		$this->http->respond_json(
			self::ROUTE,
			200,
			array(
				'errorCode' => 200,
				'message'   => 'Thành công',
				'data'      => array(
					'invoices' => $rows,
					'notFound' => $not_found,
				),
				'success'   => true,
			)
		);
	}

	/**
	 * A draft row as in docs/research/demo-results.json.
	 *
	 * @param string $reference `mtChieu`.
	 * @return array<string, mixed>
	 */
	private function row( string $reference ): array {
		return array(
			'maSoHDon'     => 'abc',
			'mtChieu'      => $reference,
			'shDon'        => 0,
			'nLap'         => '2026-10-14T00:00:00',
			'tenTThaiHDon' => 'Nháp',
			'maTThaiHDon'  => 1,
			'maLoaiHDon'   => 1,
		);
	}

	private function client(): MatbaoClient {
		return new MatbaoClient( new MatbaoCredentials( 'demo', self::MST, self::USERNAME, self::PASSWORD ) );
	}
}
