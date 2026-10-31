<?php
/**
 * The templates list checked before each create call is cached for a few minutes.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Invoice\InvoiceStatus;
use LTTech\EinvoiceMatbao\Settings;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\InvoiceWorkflowTestCase;

/**
 * Counts `GET /api/invoice/templates` across several drafts.
 */
final class DraftInvoiceTemplateCacheTest extends InvoiceWorkflowTestCase {

	private const TEMPLATES_ROUTE = 'GET /api/invoice/templates';

	public function test_two_drafts_in_a_row_fetch_the_templates_once(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$first  = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$second = $this->create_order( array( array( $this->create_product( 'B', '200000' ), 1 ) ) );

		$this->complete_and_run( $first );
		$this->complete_and_run( $second );

		$this->assertSame( InvoiceStatus::DRAFT_CREATED, $this->invoice_of( $first )?->status );
		$this->assertSame( InvoiceStatus::DRAFT_CREATED, $this->invoice_of( $second )?->status );
		$this->assertCount( 1, $this->http->requests_to( self::TEMPLATES_ROUTE ) );
		$this->assertCount( 2, $this->http->requests_to( self::CREATE_ROUTE ) );
	}

	public function test_template_rejected_on_create_drops_the_cached_list(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_rejected( 308 );
		$first = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$this->complete_and_run( $first );
		$this->assertSame( 'template_rejected', $this->invoice_of( $first )?->error_code );

		$this->fake_create_invoice_ok();
		$second = $this->create_order( array( array( $this->create_product( 'B', '200000' ), 1 ) ) );
		$this->complete_and_run( $second );

		$this->assertCount( 2, $this->http->requests_to( self::TEMPLATES_ROUTE ) );
		$this->assertSame( InvoiceStatus::DRAFT_CREATED, $this->invoice_of( $second )?->status );
	}

	public function test_template_unavailable_drops_the_cached_list(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$first = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$this->complete_and_run( $first );
		// Bypasses the settings save (which drops the cache too), as an edited option could.
		update_option( Settings::OPTION, array_merge( (array) get_option( Settings::OPTION, array() ), array( 'template_series' => 'C25TAT' ) ) );
		$second = $this->create_order( array( array( $this->create_product( 'B', '200000' ), 1 ) ) );
		$this->complete_and_run( $second );
		$this->assertSame( 'template_unavailable', $this->invoice_of( $second )?->error_code );

		$third = $this->create_order( array( array( $this->create_product( 'C', '300000' ), 1 ) ) );
		$this->complete_and_run( $third );

		$this->assertCount( 2, $this->http->requests_to( self::TEMPLATES_ROUTE ) );
	}

	public function test_saving_the_default_template_drops_the_cached_list(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$first = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$this->complete_and_run( $first );

		( new Settings() )->save_default_template( self::TEMPLATE_FORM, self::TEMPLATE_SERIES );
		$second = $this->create_order( array( array( $this->create_product( 'B', '200000' ), 1 ) ) );
		$this->complete_and_run( $second );

		$this->assertCount( 2, $this->http->requests_to( self::TEMPLATES_ROUTE ) );
	}

	public function test_saving_matbao_credentials_drops_the_cached_list(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$first = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$this->complete_and_run( $first );

		$this->save_matbao_credentials();
		$second = $this->create_order( array( array( $this->create_product( 'B', '200000' ), 1 ) ) );
		$this->complete_and_run( $second );

		$this->assertCount( 2, $this->http->requests_to( self::TEMPLATES_ROUTE ) );
	}

	/**
	 * Makes create-invoice reject the template inside a successful envelope.
	 *
	 * @param int $api_code Mat Bao item `errorCode`.
	 */
	private function fake_create_invoice_rejected( int $api_code ): void {
		$this->http->respond_json(
			self::CREATE_ROUTE,
			200,
			array(
				'errorCode' => 200,
				'message'   => 'Thành công',
				'data'      => array(
					array(
						'errorCode' => $api_code,
						'message'   => 'Không tìm thấy mẫu số kí hiệu',
						'data'      => null,
						'success'   => false,
					),
				),
				'success'   => true,
			)
		);
	}
}
