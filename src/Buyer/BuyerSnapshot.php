<?php
/**
 * Buyer data captured when an order completed.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Buyer;

use LTTech\EinvoiceMatbao\Encryption;
use LTTech\EinvoiceMatbao\Invoice\InvoiceDataError;
use LTTech\EinvoiceMatbao\Invoice\InvoiceErrorCode;

/**
 * Everything the payload needs about the buyer. The citizen ID stays encrypted until a payload
 * is built; only its 4-digit mask is readable here.
 */
final class BuyerSnapshot {

	/**
	 * Creates the snapshot.
	 *
	 * @param string                $type         BuyerType constant.
	 * @param string                $buyer_name   `NMua_Ten`.
	 * @param array<string, string> $fields       `NMua_*` fields without the citizen ID.
	 * @param string                $id_encrypted Encrypted citizen ID, or empty.
	 * @param string                $id_mask      Citizen ID with all but 4 digits hidden, or empty.
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $buyer_name,
		public readonly array $fields,
		public readonly string $id_encrypted = '',
		public readonly string $id_mask = '',
	) {
	}

	/**
	 * The `NMua_*` payload fields, with the citizen ID decrypted.
	 *
	 * @param Encryption $encryption Encryption service.
	 * @return array<string, string>
	 * @throws InvoiceDataError When the buyer name is missing or the citizen ID cannot be decrypted.
	 */
	public function payload_fields( Encryption $encryption = new Encryption() ): array {
		if ( '' === $this->buyer_name ) {
			throw new InvoiceDataError( InvoiceErrorCode::BUYER_SNAPSHOT_INVALID, __( 'Thiếu tên người mua trong thông tin xuất hóa đơn của đơn hàng.', 'einvoice-matbao' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain text, escaped where rendered.
		}

		$fields = $this->fields;
		if ( '' !== $this->id_encrypted ) {
			if ( ! $encryption->is_available() ) {
				throw new InvoiceDataError( InvoiceErrorCode::BUYER_ID_KEY_MISSING, __( 'Thiếu khóa mã hóa EINVOICE_MATBAO_ENCRYPTION_KEY để đọc CCCD của người mua; cấu hình khóa rồi gửi lại.', 'einvoice-matbao' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain text, escaped where rendered.
			}
			$id_number = $encryption->decrypt( $this->id_encrypted );
			if ( null === $id_number ) {
				throw new InvoiceDataError( InvoiceErrorCode::BUYER_ID_UNREADABLE, __( 'Không giải mã được CCCD của người mua; kiểm tra khóa mã hóa EINVOICE_MATBAO_ENCRYPTION_KEY.', 'einvoice-matbao' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain text, escaped where rendered.
			}
			$fields['NMua_CCCDan'] = $id_number;
		}

		return $fields;
	}

	/**
	 * Array form for order meta.
	 *
	 * @return array{type: string, buyer_name: string, fields: array<string, string>, id_encrypted: string, id_mask: string}
	 */
	public function to_array(): array {
		return array(
			'type'         => $this->type,
			'buyer_name'   => $this->buyer_name,
			'fields'       => $this->fields,
			'id_encrypted' => $this->id_encrypted,
			'id_mask'      => $this->id_mask,
		);
	}

	/**
	 * Rebuilds a snapshot from decoded order meta.
	 *
	 * @param array<mixed> $data Decoded meta.
	 */
	public static function from_array( array $data ): self {
		$fields = array();
		if ( is_array( $data['fields'] ?? null ) ) {
			foreach ( $data['fields'] as $key => $value ) {
				if ( is_string( $key ) && is_string( $value ) ) {
					$fields[ $key ] = $value;
				}
			}
		}
		$text = static fn( string $key ): string => is_string( $data[ $key ] ?? null ) ? $data[ $key ] : '';

		return new self( $text( 'type' ), $text( 'buyer_name' ), $fields, $text( 'id_encrypted' ), $text( 'id_mask' ) );
	}
}
