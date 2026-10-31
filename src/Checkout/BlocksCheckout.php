<?php
/**
 * Checkout Blocks: the invoice request received through the Store API.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Checkout;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use LTTech\EinvoiceMatbao\Buyer\BuyerInput;
use LTTech\EinvoiceMatbao\Buyer\BuyerRequestStore;
use LTTech\EinvoiceMatbao\Buyer\BuyerRules;
use LTTech\EinvoiceMatbao\Encryption;
use WC_Order;
use WP_REST_Request;

/**
 * Declares an `einvoice-matbao` extension on the Store API checkout route and applies the same
 * rules and storage as Classic checkout (BuyerRules, BuyerRequestStore). The server is the
 * authority: a direct Store API call without the block is validated exactly like the block's.
 * The extension is write-only: no data callback is registered, so nothing, the citizen ID
 * included, is echoed in any Store API response or kept in the session.
 */
final class BlocksCheckout {

	public const EXTENSION_NAMESPACE = 'einvoice-matbao';

	/**
	 * Hooks the Store API once WooCommerce Blocks is loaded.
	 */
	public static function register(): void {
		if ( did_action( 'woocommerce_blocks_loaded' ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hook.
			self::register_extension();
		} else {
			add_action( 'woocommerce_blocks_loaded', array( self::class, 'register_extension' ) );
		}

		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( self::class, 'save_to_order' ), 10, 2 );
		add_action( 'woocommerce_blocks_checkout_block_registration', array( self::class, 'register_integration' ) );
		add_action( 'init', array( self::class, 'register_block' ) );
	}

	/**
	 * Registers the write-only checkout extension schema.
	 */
	public static function register_extension(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}

		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => 'checkout',
				'namespace'       => self::EXTENSION_NAMESPACE,
				'schema_callback' => array( self::class, 'schema' ),
				'schema_type'     => ARRAY_A,
			)
		);
	}

	/**
	 * Schema of the posted extension data.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function schema(): array {
		$text = static fn( string $description, int $max_length ): array => array(
			'description' => $description,
			'type'        => array( 'string', 'null' ),
			'maxLength'   => $max_length,
			'context'     => array(),
		);

		return array(
			'request_invoice' => array(
				'description' => 'Whether the customer asks for a VAT invoice.',
				'type'        => array( 'boolean', 'null' ),
				'context'     => array(),
			),
			'buyer_type'      => array(
				'description' => 'individual or business.',
				'type'        => array( 'string', 'null' ),
				'enum'        => array( 'individual', 'business', null ),
				'context'     => array(),
			),
			'business_name'   => $text( 'Business name.', BuyerRules::MAX_BUSINESS_NAME ),
			'tax_code'        => $text( 'Business tax code.', 14 ),
			'address'         => $text( 'Invoice address.', BuyerRules::MAX_ADDRESS ),
			'id_number'       => $text( 'Citizen ID, write-only.', 12 ),
		);
	}

	/**
	 * Registers the block's script data with the Checkout block.
	 *
	 * @param object $registry WooCommerce Blocks integration registry.
	 */
	public static function register_integration( object $registry ): void {
		if ( method_exists( $registry, 'register' ) ) {
			$registry->register( new BlocksIntegration() );
		}
	}

	/**
	 * Registers the inner block of the Checkout block.
	 */
	public static function register_block(): void {
		register_block_type( dirname( EINVOICE_MATBAO_FILE ) . '/assets/blocks/invoice-request' );
	}

	/**
	 * Validates and stores the request while the Store API places the order (POST only). Without the
	 * checkbox nothing is stored and the buyer is a walk-in buyer, whatever billing holds.
	 *
	 * @param WC_Order        $order   Order being placed.
	 * @param WP_REST_Request $request Checkout request.
	 * @throws RouteException When a ticked request is invalid; the Store API answers 400 with the message.
	 */
	public static function save_to_order( WC_Order $order, WP_REST_Request $request ): void {
		// The hook also fires on PUT/PATCH when a pending or failed order is in the session. Those
		// calls are mid-typing updates: validating or storing there would refuse half-typed input
		// and write the encrypted citizen ID on every change. Only the place-order POST counts.
		if ( 'POST' !== $request->get_method() ) {
			return;
		}

		$store = new BuyerRequestStore();
		$data  = self::posted( $request );
		if ( true !== ( $data['request_invoice'] ?? null ) ) {
			$store->clear( $order );
			return;
		}

		$text       = static fn( string $key ): string => is_string( $data[ $key ] ?? null ) ? sanitize_text_field( $data[ $key ] ) : '';
		$encryption = new Encryption();
		$input      = new BuyerInput(
			$text( 'buyer_type' ),
			trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			$text( 'business_name' ),
			$text( 'tax_code' ),
			$text( 'address' ),
			$text( 'id_number' ),
			trim( $order->get_billing_phone() ),
			trim( $order->get_billing_email() ),
		);

		$errors = BuyerRules::validate( $input, $encryption->is_available() );
		if ( array() !== $errors ) {
			throw new RouteException( 'einvoice_buyer_invalid', esc_html( implode( ' ', $errors ) ), 400 );
		}

		$store->save( $order, BuyerRequestStore::from_input( $input, $encryption ) );
	}

	/**
	 * This plugin's extension data of a checkout request.
	 *
	 * @param WP_REST_Request $request Checkout request.
	 * @return array<string, mixed>
	 */
	private static function posted( WP_REST_Request $request ): array {
		$extensions = $request['extensions'];
		$data       = is_array( $extensions ) ? ( $extensions[ self::EXTENSION_NAMESPACE ] ?? null ) : null;

		return is_array( $data ) ? $data : array();
	}
}
