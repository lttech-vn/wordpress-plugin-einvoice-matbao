<?php
/**
 * Shared setup for tests at the invoice workflow seam.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration\Support;

use ActionScheduler;
use LTTech\EinvoiceMatbao\Invoice\DraftInvoiceWorkflow;
use LTTech\EinvoiceMatbao\Invoice\InvoiceRecord;
use LTTech\EinvoiceMatbao\Invoice\InvoiceRepository;
use LTTech\EinvoiceMatbao\Settings;
use WC_Order;
use WC_Product_Simple;
use WC_Tax;
use WP_Error;

/**
 * Real WooCommerce orders moved to `completed`, with Mat Bao faked at the HTTP boundary.
 * Prices are VND without decimals and exclusive of tax; tax rates match every country.
 */
abstract class InvoiceWorkflowTestCase extends SettingsRestTestCase {

	protected const TEMPLATE_FORM   = '1';
	protected const TEMPLATE_SERIES = 'C26TAT';

	protected const CREATE_ROUTE = 'POST /api/invoice/create-invoice';

	/** Tax class slugs created per test; '' is the WooCommerce standard class. */
	protected const CLASS_FIVE  = 'einv-five';
	protected const CLASS_EIGHT = 'einv-eight';
	protected const CLASS_ZERO  = 'einv-zero';
	protected const CLASS_SEVEN = 'einv-seven';

	public function set_up(): void {
		parent::set_up();

		update_option( 'woocommerce_currency', 'VND' );
		update_option( 'woocommerce_price_num_decimals', '0' );
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'no' );
		update_option( 'woocommerce_tax_round_at_subtotal', 'no' );

		$this->create_tax_rate( '', 10 );
		$this->create_tax_rate( self::CLASS_FIVE, 5 );
		$this->create_tax_rate( self::CLASS_EIGHT, 8 );
		$this->create_tax_rate( self::CLASS_ZERO, 0 );
		$this->create_tax_rate( self::CLASS_SEVEN, 7 );
	}

	/**
	 * Saves credentials and the default template, and fakes a successful login.
	 */
	protected function configure_matbao(): void {
		$this->login_as( 'administrator' );
		$this->with_encryption_key();
		$this->save_matbao_credentials();
		( new Settings() )->save_default_template( self::TEMPLATE_FORM, self::TEMPLATE_SERIES );
		$this->fake_matbao_login_ok();
	}

	/**
	 * Fakes a successful create-invoice response echoing the request's template and `MTChieu`.
	 *
	 * @param string $invoice_id `maSoHDon` returned by the fake.
	 */
	protected function fake_create_invoice_ok( string $invoice_id = 'ZmFrZS1pbnZvaWNlLWlk' ): void {
		$this->http->respond_with(
			self::CREATE_ROUTE,
			static function ( array $args ) use ( $invoice_id ): array {
				$sent    = json_decode( (string) $args['body'], true );
				$invoice = is_array( $sent ) && is_array( $sent[0] ?? null ) ? $sent[0] : array();

				return FakeHttp::json_response(
					200,
					array(
						'errorCode' => 200,
						'message'   => 'Thành công',
						'data'      => array(
							array(
								'errorCode' => 200,
								'message'   => 'Thành công',
								'data'      => array(
									'khmshDon' => $invoice['KHMSHDon'] ?? '',
									'khhDon'   => $invoice['KHHDon'] ?? '',
									'mtChieu'  => $invoice['MTChieu'] ?? '',
									'shDon'    => 0,
									'maSoHDon' => $invoice_id,
									'nLap'     => $invoice['NLap'] ?? '',
								),
								'success'   => true,
							),
						),
						'success'   => true,
					)
				);
			}
		);
	}

	/**
	 * Makes create-invoice time out (the request does not complete).
	 */
	protected function fake_create_invoice_timeout(): void {
		$this->http->respond_with( self::CREATE_ROUTE, static fn(): WP_Error => new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );
	}

	/**
	 * Creates a tax class (unless standard) with one rate matching every location.
	 *
	 * @param string $class_slug Tax class slug, '' for standard.
	 * @param float  $percent    Rate percent.
	 */
	protected function create_tax_rate( string $class_slug, float $percent ): void {
		if ( '' !== $class_slug ) {
			WC_Tax::create_tax_class( $class_slug, $class_slug );
		}

		WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => '',
				'tax_rate_state'    => '',
				'tax_rate'          => (string) $percent,
				'tax_rate_name'     => 'VAT ' . $percent,
				'tax_rate_priority' => 1,
				'tax_rate_compound' => 0,
				'tax_rate_shipping' => 1,
				'tax_rate_order'    => 0,
				'tax_rate_class'    => $class_slug,
			)
		);
	}

	/**
	 * Creates a simple product.
	 *
	 * @param string $name       Product name.
	 * @param string $price      Regular price, VND.
	 * @param string $tax_class  Tax class slug.
	 * @param string $tax_status `taxable` or `none`.
	 * @param string $sku        SKU.
	 */
	protected function create_product( string $name, string $price, string $tax_class = '', string $tax_status = 'taxable', string $sku = '' ): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( $price );
		$product->set_tax_class( $tax_class );
		$product->set_tax_status( $tax_status );
		$product->set_virtual( true );
		if ( '' !== $sku ) {
			$product->set_sku( $sku );
		}
		$product->save();

		return $product;
	}

	/**
	 * Creates a pending VND order with the given products and calculated totals.
	 *
	 * @param array<int, array{0: WC_Product_Simple, 1: int}> $lines Product and quantity pairs.
	 */
	protected function create_order( array $lines ): WC_Order {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_currency( 'VND' );
		$order->set_billing_first_name( 'Nguyễn' );
		$order->set_billing_last_name( 'Văn A' );
		$order->set_billing_email( 'buyer@example.invalid' );
		$order->set_billing_country( 'VN' );
		foreach ( $lines as $line ) {
			$order->add_product( $line[0], $line[1] );
		}
		$order->calculate_totals();
		$order->save();

		return $order;
	}

	/**
	 * Moves an order to `completed` and runs the queued draft action like Action Scheduler would.
	 *
	 * @param WC_Order $order Order.
	 */
	protected function complete_and_run( WC_Order $order ): void {
		$order->update_status( 'completed' );
		$this->run_queued_draft_actions();
	}

	/**
	 * Runs every pending draft action through the Action Scheduler queue runner.
	 *
	 * @return int Number of actions run.
	 */
	protected function run_queued_draft_actions(): int {
		$ids = $this->queued_draft_action_ids();
		foreach ( $ids as $id ) {
			ActionScheduler::runner()->process_action( $id, 'einv-test' );
		}

		return count( $ids );
	}

	/**
	 * Pending draft action IDs.
	 *
	 * @return list<int>
	 */
	protected function queued_draft_action_ids(): array {
		$ids = as_get_scheduled_actions(
			array(
				'hook'     => DraftInvoiceWorkflow::ACTION,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			),
			'ids'
		);

		return array_values( array_map( 'intval', $ids ) );
	}

	/**
	 * The invoice row of an order.
	 *
	 * @param WC_Order $order Order.
	 */
	protected function invoice_of( WC_Order $order ): ?InvoiceRecord {
		return ( new InvoiceRepository() )->find_by_order( $order->get_id() );
	}

	/**
	 * Decoded bodies sent to create-invoice: each is the single invoice of the request array.
	 *
	 * @return list<array<string, mixed>>
	 */
	protected function sent_invoices(): array {
		return array_map(
			function ( array $request ): array {
				$this->assertIsArray( $request['body'] );
				$this->assertCount( 1, $request['body'] );

				return $request['body'][0];
			},
			$this->http->requests_to( self::CREATE_ROUTE )
		);
	}

	/**
	 * Attempt rows of an invoice.
	 *
	 * @param InvoiceRecord $invoice Invoice.
	 * @return list<array<string, mixed>>
	 */
	protected function attempts_of( InvoiceRecord $invoice ): array {
		return ( new InvoiceRepository() )->attempts( $invoice->id );
	}
}
