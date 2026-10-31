<?php
/**
 * Mat Bao create-invoice payload for a WooCommerce order.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Invoice;

use WC_Order;
use WC_Order_Item;
use WC_Order_Item_Fee;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;

/**
 * Builds a draft VAT invoice (`LoaiHDon=0`) from the amounts and taxes WooCommerce stored on each
 * order line; nothing is recalculated from the current tax settings.
 *
 * Each line is sent at its price after discounts, without discount lines
 * (`ThTienChuaCK = ThTien`, `TLCKhau = 0`, `STCKhau = 0`). Fees and shipping are separate lines.
 * The builder refuses (InvoiceDataError) instead of producing a payload that does not match the
 * order: non-VND currency, a tax rate Mat Bao does not accept, or line sums that differ from the
 * order totals.
 */
final class DraftPayloadBuilder {

	/** `TSuat` for lines with tax status `none` (KCT, không chịu thuế). */
	public const TAX_RATE_NOT_TAXABLE = -1;

	/** `TSuat` values accepted from WooCommerce rates. */
	public const ALLOWED_TAX_RATES = array( 0, 5, 8, 10 );

	/** `DVTinh` until per-store and per-product units exist (ticket #12). */
	public const DEFAULT_UNIT = 'Gói';

	/** `DVTTe` for VND. */
	private const CURRENCY_VND = 704;

	/** `TChat` of a goods/service line. */
	private const LINE_KIND_GOODS = 1;

	/** `HTTToan`, as in the payloads proven on the Mat Bao demo API. */
	private const PAYMENT_METHOD = 'CK';

	/** Amounts within this difference are equal (float storage noise). */
	private const AMOUNT_TOLERANCE = 0.000001;

	/** `MHHDVu` and `THHDVu` maximum lengths. */
	private const MAX_ITEM_CODE_LENGTH = 50;
	private const MAX_ITEM_NAME_LENGTH = 500;

	/**
	 * Builds the payload.
	 *
	 * @param WC_Order                            $order      Completed order.
	 * @param string                              $reference  `MTChieu`.
	 * @param string                              $buyer_name `NMua_Ten` (walk-in buyer name).
	 * @param array{form: string, series: string} $template   `KHMSHDon` and `KHHDon`.
	 * @param \DateTimeImmutable                  $issued_on  `NLap`: the day the draft is sent.
	 * @return array<string, mixed>
	 * @throws InvoiceDataError When the order cannot be invoiced as stored.
	 */
	public function build( WC_Order $order, string $reference, string $buyer_name, array $template, \DateTimeImmutable $issued_on ): array {
		if ( 'VND' !== $order->get_currency() ) {
			/* translators: %s: order currency code. */
			self::fail( sprintf( __( 'Đơn hàng dùng tiền tệ %s; chỉ hỗ trợ VND.', 'einvoice-matbao' ), $order->get_currency() ) );
		}

		$rates = $this->stored_rate_percents( $order );
		$lines = array();
		$items = $order->get_items( array( 'line_item', 'fee', 'shipping' ) );
		foreach ( $items as $item ) {
			$lines[] = $this->line( $item, count( $lines ) + 1, $rates );
		}

		if ( array() === $lines ) {
			self::fail( __( 'Đơn hàng không có dòng hàng hóa, dịch vụ nào.', 'einvoice-matbao' ) );
		}

		$net   = array_sum( array_column( $lines, 'ThTien' ) );
		$tax   = array_sum( array_column( $lines, 'TThue' ) );
		$gross = array_sum( array_column( $lines, 'TgTien' ) );

		if ( ! self::same( $tax, (float) $order->get_total_tax() ) || ! self::same( $gross, (float) $order->get_total() ) ) {
			self::fail(
				sprintf(
					/* translators: 1: sum of line totals, 2: order total, 3: sum of line taxes, 4: order tax total. */
					__( 'Tổng các dòng (%1$s, thuế %3$s) không khớp tổng đơn hàng (%2$s, thuế %4$s).', 'einvoice-matbao' ),
					self::format( $gross ),
					self::format( (float) $order->get_total() ),
					self::format( $tax ),
					self::format( (float) $order->get_total_tax() )
				)
			);
		}

		return array(
			'KHMSHDon'         => $template['form'],
			'KHHDon'           => $template['series'],
			'NLap'             => $issued_on->format( 'Y-m-d' ) . 'T00:00:00',
			'LoaiHDon'         => 0,
			'KTraMTChieuTrung' => 1,
			'TCHDon'           => 0,
			'LoaiTraHang'      => 0,
			'DVTTe'            => self::CURRENCY_VND,
			'TGia'             => 1,
			'HTTToan'          => self::PAYMENT_METHOD,
			'MTChieu'          => $reference,
			'NMua_Ten'         => $buyer_name,
			'DSHHDVu'          => $lines,
			'TTCKTMai'         => 0,
			'TGTKhac'          => 0,
			'TgThTien'         => self::number( $net ),
			'TgTThue'          => self::number( $tax ),
			'TgTTTBSo'         => self::number( $gross ),
			'TgTTTBChu'        => AmountInWords::vnd( (int) round( $gross ) ),
		);
	}

	/**
	 * One `DSHHDVu` line from an order item.
	 *
	 * @param WC_Order_Item      $item  Product, fee or shipping item.
	 * @param int                $index `STT`.
	 * @param array<int, string> $rates Stored rate percent by tax rate ID.
	 * @return array<string, int|float|string>
	 * @throws InvoiceDataError When the line has an unusable tax rate or a negative amount.
	 */
	private function line( WC_Order_Item $item, int $index, array $rates ): array {
		$name = $item->get_name();
		if ( $item instanceof WC_Order_Item_Product ) {
			$quantity = (float) $item->get_quantity();
			$net      = (float) $item->get_total();
			$taxes    = $item->get_taxes();
			$taxable  = 'none' !== $item->get_tax_status();
			$product  = $item->get_product();
			$code     = $product instanceof \WC_Product ? (string) $product->get_sku() : '';
		} elseif ( $item instanceof WC_Order_Item_Fee || $item instanceof WC_Order_Item_Shipping ) {
			$quantity = 1.0;
			$net      = (float) $item->get_total();
			$taxes    = $item->get_taxes();
			$taxable  = 'none' !== $item->get_tax_status();
			$code     = '';
		} else {
			self::fail( __( 'Đơn hàng có loại dòng không hỗ trợ.', 'einvoice-matbao' ) );
		}

		if ( $net < 0 || $quantity <= 0 ) {
			/* translators: %s: order line name. */
			self::fail( sprintf( __( 'Dòng "%s" có số tiền âm hoặc số lượng không hợp lệ.', 'einvoice-matbao' ), $name ) );
		}

		$line_taxes = is_array( $taxes['total'] ?? null ) ? array_filter( $taxes['total'], static fn( $amount ): bool => '' !== $amount && null !== $amount ) : array();
		$tax        = round( (float) array_sum( array_map( 'floatval', $line_taxes ) ), wc_get_price_decimals() );
		$rate       = $taxable ? $this->rate( $name, $line_taxes, $rates ) : self::TAX_RATE_NOT_TAXABLE;

		if ( ! $taxable && ! self::same( $tax, 0.0 ) ) {
			/* translators: %s: order line name. */
			self::fail( sprintf( __( 'Dòng "%s" không chịu thuế nhưng có tiền thuế.', 'einvoice-matbao' ), $name ) );
		}

		$line = array(
			'TChat'  => self::LINE_KIND_GOODS,
			'STT'    => $index,
			'MHHDVu' => mb_substr( $code, 0, self::MAX_ITEM_CODE_LENGTH ),
		);
		if ( '' === $code ) {
			unset( $line['MHHDVu'] );
		}

		return $line + array(
			'THHDVu'       => mb_substr( $name, 0, self::MAX_ITEM_NAME_LENGTH ),
			'DVTinh'       => self::DEFAULT_UNIT,
			'SLuong'       => self::number( $quantity ),
			'DGia'         => self::number( round( $net / $quantity, 6 ) ),
			'ThTienChuaCK' => self::number( $net ),
			'TLCKhau'      => 0,
			'STCKhau'      => 0,
			'ThTien'       => self::number( $net ),
			'TSuat'        => $rate,
			'TThue'        => self::number( $tax ),
			'TgTien'       => self::number( $net + $tax ),
		);
	}

	/**
	 * `TSuat` of a taxable line from the single WooCommerce rate stored on it.
	 *
	 * @param string                    $name       Line name, for the error message.
	 * @param array<int|string, string> $line_taxes Tax amounts by rate ID.
	 * @param array<int, string>        $rates      Stored rate percent by tax rate ID.
	 * @throws InvoiceDataError When there is not exactly one rate or it is not 0, 5, 8 or 10.
	 */
	private function rate( string $name, array $line_taxes, array $rates ): int {
		if ( 1 !== count( $line_taxes ) ) {
			/* translators: %s: order line name. */
			self::fail( sprintf( __( 'Dòng "%s" cần đúng một thuế suất GTGT.', 'einvoice-matbao' ), $name ) );
		}

		$rate_id = (int) array_key_first( $line_taxes );
		$percent = $rates[ $rate_id ] ?? '';
		if ( '' === $percent ) {
			/* translators: %s: order line name. */
			self::fail( sprintf( __( 'Không tìm thấy thuế suất đã lưu của dòng "%s".', 'einvoice-matbao' ), $name ) );
		}

		foreach ( self::ALLOWED_TAX_RATES as $allowed ) {
			if ( self::same( (float) $percent, (float) $allowed ) ) {
				return $allowed;
			}
		}

		self::fail(
			sprintf(
				/* translators: 1: tax rate percent, 2: order line name. */
				__( 'Thuế suất %1$s%% của dòng "%2$s" không thuộc 0%%, 5%%, 8%%, 10%%.', 'einvoice-matbao' ),
				wc_format_localized_decimal( $percent ),
				$name
			)
		);
	}

	/**
	 * Rate percent stored on the order's tax items, keyed by tax rate ID.
	 *
	 * @param WC_Order $order Order.
	 * @return array<int, string>
	 */
	private function stored_rate_percents( WC_Order $order ): array {
		$rates = array();
		foreach ( $order->get_taxes() as $tax ) {
			// Orders older than WooCommerce 3.7 have no stored percent; the empty string marks it.
			$rates[ (int) $tax->get_rate_id() ] = (string) $tax->get_rate_percent();
		}

		return $rates;
	}

	/**
	 * Refuses the order with an admin-facing reason.
	 *
	 * @param string $reason Vietnamese reason, without personal data.
	 * @throws InvoiceDataError Always.
	 */
	private static function fail( string $reason ): never {
		throw new InvoiceDataError( $reason ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain text, escaped where rendered.
	}

	/**
	 * Whole amounts as int so JSON carries `100000`, not `100000.0`.
	 *
	 * @param float $amount Amount.
	 */
	private static function number( float $amount ): int|float {
		$rounded = round( $amount, 6 );

		return floor( $rounded ) === $rounded ? (int) $rounded : $rounded;
	}

	/**
	 * Whether two amounts are equal within float noise.
	 *
	 * @param float $a First amount.
	 * @param float $b Second amount.
	 */
	private static function same( float $a, float $b ): bool {
		return abs( $a - $b ) < self::AMOUNT_TOLERANCE;
	}

	/**
	 * Amount for messages.
	 *
	 * @param float $amount Amount.
	 */
	private static function format( float $amount ): string {
		return wc_format_localized_decimal( (string) self::number( $amount ) );
	}
}
