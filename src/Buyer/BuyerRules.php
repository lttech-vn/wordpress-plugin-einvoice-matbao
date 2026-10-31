<?php
/**
 * Structural validation of the buyer data a customer enters at checkout.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Buyer;

/**
 * Checks required fields, formats and the `NMua_*` length limits. It never verifies that a
 * person or company exists. Shared by Classic checkout now and Checkout Blocks later; the browser
 * script mirrors these rules for instant feedback, this class is the authority.
 */
final class BuyerRules {

	/** `NMua_HVTNMHang` maximum length. */
	public const MAX_FULL_NAME = 100;

	/** `NMua_Ten` maximum length. */
	public const MAX_BUSINESS_NAME = 400;

	/** `NMua_DChi` maximum length. */
	public const MAX_ADDRESS = 400;

	/** `NMua_SDThoai` maximum length. */
	public const MAX_PHONE = 20;

	/** `NMua_DCTDTu` maximum length. */
	public const MAX_EMAIL = 50;

	/** CCCD: exactly 12 digits (`NMua_CCCDan` allows 12). */
	public const ID_NUMBER_PATTERN = '/^\d{12}$/';

	/** MST: 10 digits, optionally followed by a 3-digit branch part with or without `-`; or 13 digits (`NMua_MST` allows 14). */
	public const TAX_CODE_PATTERN = '/^\d{10}(-?\d{3})?$/';

	/** Vietnamese mobile (10 digits) or landline (11 digits), with `0`, `84` or `+84` prefix, separators removed. */
	public const PHONE_PATTERN = '/^(?:0|\+?84)(?:[35789]\d{8}|2\d{9})$/';

	/**
	 * Vietnamese messages by field key; the browser script receives the same map.
	 *
	 * @return array<string, string>
	 */
	public static function messages(): array {
		return array(
			'type'          => __( 'Vui lòng chọn xuất hóa đơn cho cá nhân hoặc doanh nghiệp.', 'einvoice-matbao' ),
			'full_name'     => __( 'Vui lòng nhập họ tên người mua (tối đa 100 ký tự).', 'einvoice-matbao' ),
			'business_name' => __( 'Vui lòng nhập tên doanh nghiệp (tối đa 400 ký tự).', 'einvoice-matbao' ),
			'tax_code'      => __( 'Mã số thuế phải gồm 10 hoặc 13 chữ số (có thể kèm dấu "-" trước 3 số cuối).', 'einvoice-matbao' ),
			'address'       => __( 'Vui lòng nhập địa chỉ xuất hóa đơn (tối đa 400 ký tự).', 'einvoice-matbao' ),
			'id_number'     => __( 'Số CCCD phải gồm đúng 12 chữ số.', 'einvoice-matbao' ),
			'id_encryption' => __( 'Chưa thể nhận CCCD vì cửa hàng chưa cấu hình khóa mã hóa; vui lòng chọn doanh nghiệp hoặc liên hệ cửa hàng.', 'einvoice-matbao' ),
			'phone'         => __( 'Vui lòng nhập số điện thoại Việt Nam hợp lệ.', 'einvoice-matbao' ),
			'email'         => __( 'Vui lòng nhập email hợp lệ (tối đa 50 ký tự).', 'einvoice-matbao' ),
		);
	}

	/**
	 * Phone number without spaces, dots, dashes and brackets.
	 *
	 * @param string $phone Entered phone number.
	 */
	public static function normalize_phone( string $phone ): string {
		return (string) preg_replace( '/[\s.\-()]/', '', $phone );
	}

	/**
	 * Citizen ID with all but the last four digits hidden.
	 *
	 * @param string $id_number Full ID number.
	 */
	public static function mask_id_number( string $id_number ): string {
		return str_repeat( '*', max( 0, strlen( $id_number ) - 4 ) ) . substr( $id_number, -4 );
	}

	/**
	 * Validates one request.
	 *
	 * @param BuyerInput $input                Entered data.
	 * @param bool       $encryption_available Whether a CCCD can be stored encrypted.
	 * @return array<string, string> Error message by field key; empty when valid. Messages never contain the entered data.
	 */
	public static function validate( BuyerInput $input, bool $encryption_available ): array {
		$messages = self::messages();
		$errors   = array();
		$fail     = static function ( string $key, string $message_key = '' ) use ( &$errors, $messages ): void {
			$errors[ $key ] = $messages[ '' === $message_key ? $key : $message_key ];
		};

		if ( BuyerType::INDIVIDUAL !== $input->type && BuyerType::BUSINESS !== $input->type ) {
			$fail( 'type' );
			return $errors;
		}

		if ( '' === trim( $input->address ) || mb_strlen( trim( $input->address ) ) > self::MAX_ADDRESS ) {
			$fail( 'address' );
		}
		$phone = self::normalize_phone( $input->phone );
		if ( strlen( $phone ) > self::MAX_PHONE || 1 !== preg_match( self::PHONE_PATTERN, $phone ) ) {
			$fail( 'phone' );
		}

		if ( BuyerType::INDIVIDUAL === $input->type ) {
			$name = trim( $input->full_name );
			if ( '' === $name || mb_strlen( $name ) > self::MAX_FULL_NAME ) {
				$fail( 'full_name' );
			}
			if ( 1 !== preg_match( self::ID_NUMBER_PATTERN, trim( $input->id_number ) ) ) {
				$fail( 'id_number' );
			} elseif ( ! $encryption_available ) {
				$fail( 'id_number', 'id_encryption' );
			}
			return $errors;
		}

		$name = trim( $input->business_name );
		if ( '' === $name || mb_strlen( $name ) > self::MAX_BUSINESS_NAME ) {
			$fail( 'business_name' );
		}
		if ( 1 !== preg_match( self::TAX_CODE_PATTERN, trim( $input->tax_code ) ) ) {
			$fail( 'tax_code' );
		}
		$email = trim( $input->email );
		if ( '' === $email || mb_strlen( $email ) > self::MAX_EMAIL || ! is_email( $email ) ) {
			$fail( 'email' );
		}

		return $errors;
	}
}
