<?php
/**
 * Per-product unit of measure (`DVTinh`) override on the WooCommerce product edit screen.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Admin;

use LTTech\EinvoiceMatbao\Settings;
use WC_Product;
use WC_Product_Variation;

/**
 * Stores the override in product meta through the WooCommerce CRUD, behind a nonce and the
 * `edit_post` capability. A variation without its own override inherits its parent's.
 */
final class ProductUnitField {

	public const META_KEY = '_einvoice_matbao_unit';

	public const FIELD = 'einvoice_matbao_unit';

	public const NONCE_FIELD = 'einvoice_matbao_unit_nonce';

	public const NONCE_ACTION = 'einvoice_matbao_save_unit';

	/**
	 * Hooks the field into the simple/parent product and variation panels, and their saves.
	 */
	public static function register(): void {
		add_action( 'woocommerce_product_options_general_product_data', array( self::class, 'render' ) );
		add_action( 'woocommerce_admin_process_product_object', array( self::class, 'save' ) );
		add_action( 'woocommerce_variation_options_pricing', array( self::class, 'render_variation' ), 10, 3 );
		add_action( 'woocommerce_admin_process_variation_object', array( self::class, 'save_variation' ), 10, 2 );
	}

	/**
	 * The unit for an order line: the product's override, else its parent's, else the store default.
	 *
	 * @param WC_Product $product      Product or variation on the order line.
	 * @param string     $default_unit Store default unit.
	 */
	public static function unit_for( WC_Product $product, string $default_unit ): string {
		$unit = self::override_of( $product );
		if ( '' === $unit && $product->get_parent_id() > 0 ) {
			$parent = wc_get_product( $product->get_parent_id() );
			$unit   = $parent instanceof WC_Product ? self::override_of( $parent ) : '';
		}

		return '' === $unit ? $default_unit : $unit;
	}

	/**
	 * Renders the field on the General tab.
	 */
	public static function render(): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		woocommerce_wp_text_input(
			array(
				'id'                => self::FIELD,
				'label'             => __( 'Đơn vị tính hóa đơn', 'einvoice-matbao' ),
				'description'       => __( 'Ghi trên hóa đơn điện tử Mắt Bão. Để trống để dùng đơn vị tính mặc định của cửa hàng.', 'einvoice-matbao' ),
				'desc_tip'          => true,
				'placeholder'       => ( new Settings() )->default_unit(),
				'value'             => self::current_product_override(),
				'custom_attributes' => array( 'maxlength' => (string) Settings::MAX_UNIT_LENGTH ),
			)
		);
	}

	/**
	 * Renders the field inside a variation panel.
	 *
	 * @param int                  $loop            Variation position in the list.
	 * @param array<string, mixed> $_variation_data Variation data (unused).
	 * @param \WP_Post             $variation       Variation post.
	 */
	public static function render_variation( $loop, $_variation_data, $variation ): void {
		$loop = (int) $loop;

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD . '[' . $loop . ']', false );
		$product = wc_get_product( $variation->ID );
		woocommerce_wp_text_input(
			array(
				'id'                => self::FIELD . $loop,
				'name'              => self::FIELD . '[' . $loop . ']',
				'label'             => __( 'Đơn vị tính hóa đơn', 'einvoice-matbao' ),
				'placeholder'       => __( 'Theo sản phẩm cha', 'einvoice-matbao' ),
				'value'             => $product instanceof WC_Product ? self::override_of( $product ) : '',
				'wrapper_class'     => 'form-row form-row-full',
				'custom_attributes' => array( 'maxlength' => (string) Settings::MAX_UNIT_LENGTH ),
			)
		);
	}

	/**
	 * Saves the override of a simple or parent product from the edit form.
	 *
	 * @param WC_Product $product Product being saved (WooCommerce saves it afterwards).
	 */
	public static function save( WC_Product $product ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in apply().
		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) && is_string( $_POST[ self::NONCE_FIELD ] ) ? wp_unslash( $_POST[ self::NONCE_FIELD ] ) : '';
		$value = isset( $_POST[ self::FIELD ] ) && is_string( $_POST[ self::FIELD ] ) ? wp_unslash( $_POST[ self::FIELD ] ) : null;
		// phpcs:enable

		self::apply( $product, $nonce, $value );
	}

	/**
	 * Saves the override of one variation from the variations panel.
	 *
	 * @param WC_Product_Variation $variation Variation being saved (WooCommerce saves it afterwards).
	 * @param int                  $loop      Variation position in the submitted list.
	 */
	public static function save_variation( $variation, $loop ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in apply().
		$nonces = isset( $_POST[ self::NONCE_FIELD ] ) && is_array( $_POST[ self::NONCE_FIELD ] ) ? wp_unslash( $_POST[ self::NONCE_FIELD ] ) : array();
		$values = isset( $_POST[ self::FIELD ] ) && is_array( $_POST[ self::FIELD ] ) ? wp_unslash( $_POST[ self::FIELD ] ) : array();
		// phpcs:enable

		$nonce = $nonces[ (int) $loop ] ?? '';
		$value = $values[ (int) $loop ] ?? null;
		self::apply( $variation, is_string( $nonce ) ? $nonce : '', is_string( $value ) ? $value : null );
	}

	/**
	 * Writes or clears the override when the nonce and capability check out and the value fits.
	 *
	 * @param WC_Product  $product Product to update (not saved here).
	 * @param string      $nonce   Submitted nonce.
	 * @param string|null $value   Submitted unit, or null when the field was not submitted.
	 */
	private static function apply( WC_Product $product, string $nonce, ?string $value ): void {
		if ( null === $value || false === wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		$post_id = $product->get_parent_id() > 0 ? $product->get_parent_id() : $product->get_id();
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$unit = trim( sanitize_text_field( $value ) );
		if ( mb_strlen( $unit ) > Settings::MAX_UNIT_LENGTH ) {
			if ( class_exists( \WC_Admin_Meta_Boxes::class ) ) {
				\WC_Admin_Meta_Boxes::add_error(
					sprintf(
						/* translators: %d: maximum unit length in characters. */
						__( 'Đơn vị tính hóa đơn tối đa %d ký tự; giá trị cũ được giữ nguyên.', 'einvoice-matbao' ),
						Settings::MAX_UNIT_LENGTH
					)
				);
			}
			return;
		}

		if ( '' === $unit ) {
			$product->delete_meta_data( self::META_KEY );
		} else {
			$product->update_meta_data( self::META_KEY, $unit );
		}
	}

	/**
	 * The product's own override, empty when none.
	 *
	 * @param WC_Product $product Product or variation.
	 */
	private static function override_of( WC_Product $product ): string {
		$unit = $product->get_meta( self::META_KEY );

		return is_string( $unit ) ? $unit : '';
	}

	/**
	 * Override of the product open in the edit screen.
	 */
	private static function current_product_override(): string {
		global $post;

		$product = $post instanceof \WP_Post ? wc_get_product( $post->ID ) : false;

		return $product instanceof WC_Product ? self::override_of( $product ) : '';
	}
}
