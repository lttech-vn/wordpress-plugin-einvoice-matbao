<?php
/**
 * Classic checkout: invoice request fields, server-side validation and the stored request.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Buyer\BuyerRequestStore;
use LTTech\EinvoiceMatbao\Checkout\ClassicCheckout;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\InvoiceWorkflowTestCase;
use WC_Order;
use WP_Error;

/**
 * The checkout seam: WooCommerce's own validation and order-creation hooks, as Classic checkout fires them.
 * All identity numbers are obviously fake test values.
 */
final class ClassicCheckoutBuyerTest extends InvoiceWorkflowTestCase {

	private const ID_NUMBER = '000000001234';
	private const TAX_CODE  = '0000000000-001';

	public function tear_down(): void {
		$_POST = array();

		parent::tear_down();
	}

	public function test_without_the_checkbox_nothing_is_validated_or_stored_even_with_billing_data(): void {
		$this->with_encryption_key();

		$errors = $this->validate_checkout( array( 'einv_buyer_type' => 'individual' ) );
		$order  = $this->order_with_request( array( 'einv_buyer_type' => 'individual' ) );

		$this->assertSame( array(), $errors->get_error_codes() );
		$this->assertNull( ( new BuyerRequestStore() )->load( $order ) );
	}

	public function test_valid_individual_request_passes_and_stores_the_id_number_encrypted(): void {
		$this->with_encryption_key();
		$post = $this->individual_post();

		$this->assertSame( array(), $this->validate_checkout( $post )->get_error_codes() );
		$order   = $this->order_with_request( $post );
		$request = ( new BuyerRequestStore() )->load( $order );

		$this->assertNotNull( $request );
		$this->assertSame( 'individual', $request['type'] );
		$this->assertSame( 'Số 1 Đường Thử, Quận Thử', $request['address'] );
		$this->assertSame( '********1234', $request['id_mask'] );
		$this->assertStringStartsWith( 'v1.', $request['id_encrypted'] );
		$this->assertStringNotContainsString( self::ID_NUMBER, (string) wp_json_encode( $this->all_meta( $order ) ) );
	}

	public function test_individual_requires_name_address_id_number_and_phone(): void {
		$this->with_encryption_key();

		$errors = $this->validate_checkout(
			array(
				'einv_request_invoice' => '1',
				'einv_buyer_type'      => 'individual',
			),
			array(
				'billing_first_name' => '',
				'billing_last_name'  => '',
				'billing_phone'      => '',
			)
		);

		$this->assertEqualsCanonicalizing(
			array( 'einvoice_buyer_full_name', 'einvoice_buyer_address', 'einvoice_buyer_id_number', 'einvoice_buyer_phone' ),
			$errors->get_error_codes()
		);
		$this->assertStringContainsString( 'CCCD', $errors->get_error_message( 'einvoice_buyer_id_number' ) );
	}

	/**
	 * Rejects malformed values.
	 *
	 * @dataProvider invalid_id_numbers
	 *
	 * @param string $id_number Submitted value.
	 */
	public function test_individual_rejects_an_id_number_that_is_not_twelve_digits( string $id_number ): void {
		$this->with_encryption_key();

		$errors = $this->validate_checkout( array( 'einv_id_number' => $id_number ) + $this->individual_post() );

		$this->assertSame( array( 'einvoice_buyer_id_number' ), $errors->get_error_codes() );
	}

	/**
	 * Provider.
	 *
	 * @return array<string, array{string}>
	 */
	public static function invalid_id_numbers(): array {
		return array(
			'eleven digits'  => array( '00000000123' ),
			'thirteen'       => array( '0000000012345' ),
			'letters'        => array( '00000000123A' ),
			'with separator' => array( '0000-0000-123' ),
		);
	}

	public function test_individual_rejects_an_invalid_phone_and_accepts_international_form(): void {
		$this->with_encryption_key();

		$bad = $this->validate_checkout( $this->individual_post(), array( 'billing_phone' => '12345' ) );
		$ok  = $this->validate_checkout( $this->individual_post(), array( 'billing_phone' => '+84 900 000 001' ) );

		$this->assertSame( array( 'einvoice_buyer_phone' ), $bad->get_error_codes() );
		$this->assertSame( array(), $ok->get_error_codes() );
	}

	public function test_individual_rejects_an_address_longer_than_the_mat_bao_limit(): void {
		$this->with_encryption_key();

		$errors = $this->validate_checkout( array( 'einv_address' => str_repeat( 'a', 401 ) ) + $this->individual_post() );

		$this->assertSame( array( 'einvoice_buyer_address' ), $errors->get_error_codes() );
	}

	public function test_individual_is_refused_when_no_encryption_key_is_configured(): void {
		$errors = $this->validate_checkout( $this->individual_post() );

		$this->assertSame( array( 'einvoice_buyer_id_number' ), $errors->get_error_codes() );
		$this->assertStringContainsString( 'mã hóa', $errors->get_error_message( 'einvoice_buyer_id_number' ) );
	}

	public function test_valid_business_request_passes_without_a_contact_name_and_needs_no_key(): void {
		$post = $this->business_post();

		$errors  = $this->validate_checkout(
			$post,
			array(
				'billing_first_name' => '',
				'billing_last_name'  => '',
			)
		);
		$order   = $this->order_with_request( $post );
		$request = ( new BuyerRequestStore() )->load( $order );

		$this->assertSame( array(), $errors->get_error_codes() );
		$this->assertNotNull( $request );
		$this->assertSame( 'business', $request['type'] );
		$this->assertSame( 'Doanh nghiệp Thử', $request['business_name'] );
		$this->assertSame( self::TAX_CODE, $request['tax_code'] );
		$this->assertSame( '', $request['id_encrypted'] );
	}

	public function test_business_requires_name_tax_code_address_phone_and_email(): void {
		$errors = $this->validate_checkout(
			array(
				'einv_request_invoice' => '1',
				'einv_buyer_type'      => 'business',
			),
			array(
				'billing_phone' => '',
				'billing_email' => '',
			)
		);

		$this->assertEqualsCanonicalizing(
			array( 'einvoice_buyer_business_name', 'einvoice_buyer_tax_code', 'einvoice_buyer_address', 'einvoice_buyer_phone', 'einvoice_buyer_email' ),
			$errors->get_error_codes()
		);
	}

	/**
	 * Rejects malformed values.
	 *
	 * @dataProvider invalid_tax_codes
	 *
	 * @param string $tax_code Submitted value.
	 */
	public function test_business_rejects_a_malformed_tax_code( string $tax_code ): void {
		$errors = $this->validate_checkout( array( 'einv_tax_code' => $tax_code ) + $this->business_post() );

		$this->assertSame( array( 'einvoice_buyer_tax_code' ), $errors->get_error_codes() );
	}

	/**
	 * Provider.
	 *
	 * @return array<string, array{string}>
	 */
	public static function invalid_tax_codes(): array {
		return array(
			'nine digits'     => array( '000000000' ),
			'eleven digits'   => array( '00000000001' ),
			'letters'         => array( '00000000AB' ),
			'bad branch part' => array( '0000000000-01' ),
		);
	}

	public function test_business_accepts_ten_digit_and_branch_tax_codes(): void {
		foreach ( array( '0000000000', '0000000000-001', '0000000000001' ) as $tax_code ) {
			$errors = $this->validate_checkout( array( 'einv_tax_code' => $tax_code ) + $this->business_post() );
			$this->assertSame( array(), $errors->get_error_codes(), $tax_code );
		}
	}

	public function test_business_rejects_an_email_longer_than_the_mat_bao_limit(): void {
		$errors = $this->validate_checkout( $this->business_post(), array( 'billing_email' => str_repeat( 'a', 45 ) . '@e.invalid' ) );

		$this->assertSame( array( 'einvoice_buyer_email' ), $errors->get_error_codes() );
	}

	public function test_a_ticked_request_must_choose_individual_or_business(): void {
		$errors = $this->validate_checkout( array( 'einv_request_invoice' => '1' ) );

		$this->assertSame( array( 'einvoice_buyer_type' ), $errors->get_error_codes() );
	}

	public function test_order_creation_refuses_an_invalid_request_that_skipped_validation(): void {
		$this->with_encryption_key();
		$_POST = array( 'einv_id_number' => '123' ) + $this->individual_post();
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );

		$this->expectException( \Exception::class );
		ClassicCheckout::save_to_order( $order, $this->billing() );
	}

	public function test_fields_render_with_the_checkbox_and_individual_disabled_without_a_key(): void {
		$html = $this->render_fields();

		$this->assertStringContainsString( 'name="einv_request_invoice"', $html );
		$this->assertStringContainsString( 'Yêu cầu xuất hóa đơn', $html );
		$this->assertMatchesRegularExpression( '/value="individual"[^>]*disabled/', $html );
		$this->assertDoesNotMatchRegularExpression( '/value="business"[^>]*disabled/', $html );
	}

	public function test_fields_render_with_individual_enabled_when_a_key_is_configured(): void {
		$this->with_encryption_key();

		$this->assertDoesNotMatchRegularExpression( '/value="individual"[^>]*disabled/', $this->render_fields() );
	}

	public function test_fields_escape_submitted_values(): void {
		$_POST = array( 'einv_address' => '"><script>alert(1)</script>' );

		$this->assertStringNotContainsString( '<script>alert(1)', $this->render_fields() );
	}

	/**
	 * Posted fields.
	 *
	 * @return array<string, string>
	 */
	private function individual_post(): array {
		return array(
			'einv_request_invoice' => '1',
			'einv_buyer_type'      => 'individual',
			'einv_address'         => 'Số 1 Đường Thử, Quận Thử',
			'einv_id_number'       => self::ID_NUMBER,
		);
	}

	/**
	 * Posted fields.
	 *
	 * @return array<string, string>
	 */
	private function business_post(): array {
		return array(
			'einv_request_invoice' => '1',
			'einv_buyer_type'      => 'business',
			'einv_business_name'   => 'Doanh nghiệp Thử',
			'einv_tax_code'        => self::TAX_CODE,
			'einv_address'         => 'Số 2 Đường Thử, Quận Thử',
		);
	}

	/**
	 * Billing fields as WooCommerce posts them.
	 *
	 * @param array<string, string> $override Replacements.
	 * @return array<string, string>
	 */
	private function billing( array $override = array() ): array {
		return $override + array(
			'billing_first_name' => 'Nguyễn',
			'billing_last_name'  => 'Văn A',
			'billing_phone'      => '0900000001',
			'billing_email'      => 'buyer@example.invalid',
		);
	}

	/**
	 * Fires WooCommerce's checkout validation hook for a submitted form.
	 *
	 * @param array<string, string> $post    Submitted `einv_*` fields.
	 * @param array<string, string> $billing Billing field overrides.
	 */
	private function validate_checkout( array $post, array $billing = array() ): WP_Error {
		$_POST  = $post;
		$errors = new WP_Error();
		do_action( 'woocommerce_after_checkout_validation', $this->billing( $billing ), $errors ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- fires a WooCommerce hook.

		return $errors;
	}

	/**
	 * Creates an order through WooCommerce's order-creation hook for a submitted form.
	 *
	 * @param array<string, string> $post Submitted `einv_*` fields.
	 */
	private function order_with_request( array $post ): WC_Order {
		$_POST = $post;
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		do_action( 'woocommerce_checkout_create_order', $order, $this->billing() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- fires a WooCommerce hook.
		$order->save();

		return $order;
	}

	/**
	 * Raw meta of an order.
	 *
	 * @param WC_Order $order Order.
	 * @return list<array<string, mixed>>
	 */
	private function all_meta( WC_Order $order ): array {
		return array_map( static fn( $meta ): array => $meta->get_data(), $order->get_meta_data() );
	}

	/**
	 * Checkout fields HTML.
	 */
	private function render_fields(): string {
		ob_start();
		ClassicCheckout::render_fields( WC()->checkout() );

		return (string) ob_get_clean();
	}
}
