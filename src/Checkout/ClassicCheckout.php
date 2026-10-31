<?php
/**
 * Classic checkout: the invoice request fields, validation and storage.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Checkout;

use LTTech\EinvoiceMatbao\Buyer\BuyerInput;
use LTTech\EinvoiceMatbao\Buyer\BuyerRequestStore;
use LTTech\EinvoiceMatbao\Buyer\BuyerRules;
use LTTech\EinvoiceMatbao\Buyer\BuyerType;
use LTTech\EinvoiceMatbao\Encryption;
use WC_Checkout;
use WC_Order;
use WP_Error;

// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before it fires these hooks.

/**
 * Adds a "Yêu cầu xuất hóa đơn" checkbox with individual/business fields below the billing form.
 * The rules live in BuyerRules and the storage in BuyerRequestStore so Checkout Blocks (#9) can
 * reuse them; this class only reads the Classic form post. Without the checkbox nothing is
 * stored and the buyer is a walk-in buyer, whatever billing holds.
 */
final class ClassicCheckout {

	public const SCRIPT_HANDLE = 'einvoice-matbao-checkout';

	/**
	 * Hooks the checkout.
	 */
	public static function register(): void {
		add_action( 'woocommerce_after_checkout_billing_form', array( self::class, 'render_fields' ) );
		add_action( 'woocommerce_after_checkout_validation', array( self::class, 'validate' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( self::class, 'save_to_order' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_script' ) );
	}

	/**
	 * Loads the browser validation on the checkout page.
	 */
	public static function enqueue_script(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		wp_enqueue_script( self::SCRIPT_HANDLE, plugins_url( 'assets/dist/checkout.js', EINVOICE_MATBAO_FILE ), array(), EINVOICE_MATBAO_VERSION, true );
		wp_localize_script(
			self::SCRIPT_HANDLE,
			'einvoiceMatbaoCheckout',
			array(
				'messages'            => BuyerRules::messages(),
				'encryptionAvailable' => ( new Encryption() )->is_available(),
			)
		);
	}

	/**
	 * Prints the checkbox and the buyer fields.
	 *
	 * @param WC_Checkout $checkout Checkout.
	 */
	public static function render_fields( WC_Checkout $checkout ): void {
		unset( $checkout );

		$requested  = '1' === self::posted( 'einv_request_invoice' );
		$type       = self::posted( 'einv_buyer_type' );
		$can_person = ( new Encryption() )->is_available();
		?>
		<div id="einv-invoice-request" class="einv-invoice-request">
			<p class="form-row form-row-wide">
				<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
					<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="einv_request_invoice" id="einv_request_invoice" value="1"<?php checked( $requested ); ?> />
					<span><?php esc_html_e( 'Yêu cầu xuất hóa đơn', 'einvoice-matbao' ); ?></span>
				</label>
			</p>
			<div id="einv-invoice-fields" data-einv-encryption="<?php echo $can_person ? '1' : '0'; ?>"<?php echo $requested ? '' : ' hidden'; ?>>
				<p class="form-row form-row-wide" id="einv_buyer_type_field">
					<label class="woocommerce-form__label woocommerce-form__label-for-radio radio">
						<input type="radio" class="woocommerce-form__input input-radio" name="einv_buyer_type" value="individual"<?php checked( BuyerType::INDIVIDUAL, $type ); ?><?php echo $can_person ? '' : ' disabled'; ?> />
						<span><?php esc_html_e( 'Cá nhân', 'einvoice-matbao' ); ?></span>
					</label>
					<label class="woocommerce-form__label woocommerce-form__label-for-radio radio">
						<input type="radio" class="woocommerce-form__input input-radio" name="einv_buyer_type" value="business"<?php checked( BuyerType::BUSINESS, $type ); ?> />
						<span><?php esc_html_e( 'Doanh nghiệp', 'einvoice-matbao' ); ?></span>
					</label>
					<?php if ( ! $can_person ) : ?>
						<small class="einv-note"><?php esc_html_e( 'Hiện cửa hàng chưa nhận được xuất hóa đơn cho cá nhân.', 'einvoice-matbao' ); ?></small>
					<?php endif; ?>
				</p>
				<?php
				self::text_field( 'einv_business_name', __( 'Tên doanh nghiệp', 'einvoice-matbao' ), BuyerRules::MAX_BUSINESS_NAME, 'business' );
				self::text_field( 'einv_tax_code', __( 'Mã số thuế', 'einvoice-matbao' ), 14, 'business' );
				self::text_field( 'einv_address', __( 'Địa chỉ xuất hóa đơn', 'einvoice-matbao' ), BuyerRules::MAX_ADDRESS, '' );
				self::text_field( 'einv_id_number', __( 'Số CCCD', 'einvoice-matbao' ), 12, 'individual' );
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Adds validation errors for a ticked request. Name, phone and email come from billing.
	 *
	 * @param array<string, mixed> $data   Posted checkout data.
	 * @param WP_Error             $errors Checkout errors.
	 */
	public static function validate( array $data, WP_Error $errors ): void {
		if ( ! self::requested() ) {
			return;
		}

		$input = self::input( $data );
		foreach ( BuyerRules::validate( $input, ( new Encryption() )->is_available() ) as $key => $message ) {
			$errors->add( 'einvoice_buyer_' . $key, $message );
		}
	}

	/**
	 * Stores the request on the order being created. Refuses an invalid request even if
	 * validation was skipped, and never stores the citizen ID without encrypting it.
	 *
	 * @param WC_Order             $order Order being created.
	 * @param array<string, mixed> $data  Posted checkout data.
	 * @throws \Exception When the request is invalid; WooCommerce shows the message at checkout.
	 */
	public static function save_to_order( WC_Order $order, array $data ): void {
		$store = new BuyerRequestStore();
		if ( ! self::requested() ) {
			$store->clear( $order );
			return;
		}

		$encryption = new Encryption();
		$input      = self::input( $data );
		$errors     = BuyerRules::validate( $input, $encryption->is_available() );
		if ( array() !== $errors ) {
			throw new \Exception( esc_html( (string) reset( $errors ) ) );
		}

		$store->save( $order, BuyerRequestStore::from_input( $input, $encryption ) );
	}

	/**
	 * Whether the customer ticked the request checkbox.
	 */
	private static function requested(): bool {
		return '1' === self::posted( 'einv_request_invoice' );
	}

	/**
	 * Buyer input from the post and the billing data.
	 *
	 * @param array<string, mixed> $data Posted checkout data.
	 */
	private static function input( array $data ): BuyerInput {
		$billing = static fn( string $key ): string => is_string( $data[ $key ] ?? null ) ? trim( $data[ $key ] ) : '';

		return new BuyerInput(
			self::posted( 'einv_buyer_type' ),
			trim( $billing( 'billing_first_name' ) . ' ' . $billing( 'billing_last_name' ) ),
			self::posted( 'einv_business_name' ),
			self::posted( 'einv_tax_code' ),
			self::posted( 'einv_address' ),
			self::posted( 'einv_id_number' ),
			$billing( 'billing_phone' ),
			$billing( 'billing_email' ),
		);
	}

	/**
	 * A posted checkout field as plain text.
	 *
	 * @param string $key Field name.
	 */
	private static function posted( string $key ): string {
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/**
	 * One text input row.
	 *
	 * @param string $name       Input name and ID.
	 * @param string $label      Label.
	 * @param int    $max_length Maximum length.
	 * @param string $only_for   Buyer type the row belongs to, or empty for both.
	 */
	private static function text_field( string $name, string $label, int $max_length, string $only_for ): void {
		?>
		<p class="form-row form-row-wide" id="<?php echo esc_attr( $name ); ?>_field"<?php echo '' === $only_for ? '' : ' data-einv-only="' . esc_attr( $only_for ) . '"'; ?>>
			<label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?>&nbsp;<abbr class="required" title="<?php esc_attr_e( 'bắt buộc', 'einvoice-matbao' ); ?>">*</abbr></label>
			<span class="woocommerce-input-wrapper">
				<input type="text" class="input-text" name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $name ); ?>" maxlength="<?php echo esc_attr( (string) $max_length ); ?>" value="<?php echo esc_attr( self::posted( $name ) ); ?>" autocomplete="off" />
			</span>
		</p>
		<?php
	}
}
