<?php
/**
 * Mat Bao create-invoice payload for a WooCommerce order.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Invoice;

use LTTech\EinvoiceMatbao\Admin\ProductUnitField;
use LTTech\EinvoiceMatbao\Settings;
use WC_Order;
use WC_Order_Item;
use WC_Order_Item_Fee;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;

/**
 * Builds a draft VAT invoice (`LoaiHDon=0`) from the amounts and taxes WooCommerce stored on each
 * order line; nothing is recalculated from the current tax settings.
 *
 * `TSuat` comes only from the rate stored on the line and the Mat Bao tax mapping table the admin
 * maintains (`TaxRateMap`). A line without a stored rate, or whose rate the table does not accept, is refused.
 *
 * Each line is sent at its price after discounts, without discount lines
 * (`ThTienChuaCK = ThTien`, `TLCKhau = 0`, `STCKhau = 0`). Fees and shipping are separate lines.
 * The builder refuses (InvoiceDataError) instead of producing a payload that does not match the
 * order: non-VND currency, a tax rate Mat Bao does not accept, or line sums that differ from the
 * order totals.
 */
final class DraftPayloadBuilder {

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
	 * @param array<string, string>               $buyer      `NMua_*` fields from the buyer snapshot.
	 * @param array{form: string, series: string} $template   `KHMSHDon` and `KHHDon`.
	 * @param \DateTimeImmutable                  $issued_on  `NLap`: the day the draft is sent.
	 * @return array<string, mixed>
	 * @throws InvoiceDataError When the order cannot be invoiced as stored.
	 */
	public function build( WC_Order $order, string $reference, array $buyer, array $template, \DateTimeImmutable $issued_on ): array {
		if ( 'VND' !== $order->get_currency() ) {
			/* translators: %s: order currency code. */
			self::fail( InvoiceErrorCode::CURRENCY_NOT_VND, sprintf( __( 'Đơn hàng dùng tiền tệ %s; chỉ hỗ trợ VND.', 'einvoice-matbao' ), $order->get_currency() ) );
		}

		$rates = $this->stored_rate_percents( $order );
		// Read once per payload so the units are a snapshot of the settings when the draft is built.
		$settings     = new Settings();
		$default_unit = $settings->default_unit();
		$map          = new TaxRateMap( $settings->tax_rate_map() );
		$rate_names   = $this->stored_rate_names( $order );
		$lines        = array();
		$items        = $order->get_items( array( 'line_item', 'fee', 'shipping' ) );
		foreach ( $items as $item ) {
			$line = $this->line( $item, count( $lines ) + 1, $rates, $default_unit, $map, $rate_names );
			if ( null !== $line ) {
				$lines[] = $line;
			}
		}

		if ( array() === $lines ) {
			self::fail( InvoiceErrorCode::NO_LINES, __( 'Đơn hàng không có dòng hàng hóa, dịch vụ nào.', 'einvoice-matbao' ) );
		}

		// Line amounts are whole VND (checked in line()), so the sums are exact integers.
		$net   = (int) array_sum( array_column( $lines, 'ThTien' ) );
		$tax   = (int) array_sum( array_column( $lines, 'TThue' ) );
		$gross = (int) array_sum( array_column( $lines, 'TgTien' ) );

		if ( ! self::same( (float) $tax, (float) $order->get_total_tax() ) || ! self::same( (float) $gross, (float) $order->get_total() ) ) {
			self::fail(
				InvoiceErrorCode::TOTALS_MISMATCH,
				sprintf(
					/* translators: 1: sum of line totals, 2: order total, 3: sum of line taxes, 4: order tax total. */
					__( 'Tổng các dòng (%1$s, thuế %3$s) không khớp tổng đơn hàng (%2$s, thuế %4$s).', 'einvoice-matbao' ),
					self::format( (float) $gross ),
					self::format( (float) $order->get_total() ),
					self::format( (float) $tax ),
					self::format( (float) $order->get_total_tax() )
				)
			);
		}

		return $buyer + array(
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
			'DSHHDVu'          => $lines,
			'TTCKTMai'         => 0,
			'TGTKhac'          => 0,
			'TgThTien'         => $net,
			'TgTThue'          => $tax,
			'TgTTTBSo'         => $gross,
			'TgTTTBChu'        => AmountInWords::vnd( $gross ),
		);
	}

	/**
	 * One `DSHHDVu` line from an order item.
	 *
	 * @param WC_Order_Item      $item         Product, fee or shipping item.
	 * @param int                $index        `STT`.
	 * @param array<int, string> $rates        Stored rate percent by tax rate ID.
	 * @param string             $default_unit Store default `DVTinh`.
	 * @param TaxRateMap         $map          Mat Bao tax mapping table.
	 * @param array<int, string> $rate_names   Tax names from the order's tax items, by tax rate ID.
	 * @return array<string, int|float|string>|null Null for a fee or shipping line of 0 đồng, which is left off.
	 * @throws InvoiceDataError When the line has an unusable tax rate, a negative or fractional amount.
	 */
	private function line( WC_Order_Item $item, int $index, array $rates, string $default_unit, TaxRateMap $map, array $rate_names ): ?array {
		$failure = LineFailure::from_item( $item );
		$name    = $failure->name;
		if ( $item instanceof WC_Order_Item_Product ) {
			$quantity = (float) $item->get_quantity();
			$net      = $item->get_total();
			$taxes    = $item->get_taxes();
			$product  = $item->get_product();
			$code     = $product instanceof \WC_Product ? (string) $product->get_sku() : '';
			$unit     = $product instanceof \WC_Product ? ProductUnitField::unit_for( $product, $default_unit ) : $default_unit;
		} elseif ( $item instanceof WC_Order_Item_Fee || $item instanceof WC_Order_Item_Shipping ) {
			$quantity = 1.0;
			$net      = $item->get_total();
			$taxes    = $item->get_taxes();
			$code     = '';
			$unit     = $default_unit;
		} else {
			self::fail( InvoiceErrorCode::LINE_TYPE_UNSUPPORTED, __( 'Đơn hàng có loại dòng không hỗ trợ.', 'einvoice-matbao' ), $failure );
		}

		$net = self::whole_vnd( $net, $failure );
		if ( $net < 0 || $quantity <= 0 ) {
			/* translators: %s: order line name. */
			self::fail( InvoiceErrorCode::LINE_AMOUNT_INVALID, sprintf( __( 'Dòng "%s" có số tiền âm hoặc số lượng không hợp lệ.', 'einvoice-matbao' ), $name ), $failure );
		}

		$line_taxes = is_array( $taxes['total'] ?? null ) ? array_filter( $taxes['total'], static fn( $amount ): bool => '' !== $amount && null !== $amount ) : array();
		$tax        = self::whole_vnd( array_sum( array_map( 'floatval', $line_taxes ) ), $failure );

		if ( 0 === $net && 0 === $tax && ! $item instanceof WC_Order_Item_Product ) {
			return null;
		}

		$rate = $this->tax_rate( $failure, $line_taxes, $tax, $rates, $map, $rate_names );

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
			'DVTinh'       => $unit,
			'SLuong'       => self::number( $quantity ),
			'DGia'         => self::number( round( $net / $quantity, 6 ) ),
			'ThTienChuaCK' => $net,
			'TLCKhau'      => 0,
			'STCKhau'      => 0,
			'ThTien'       => $net,
			'TSuat'        => $rate,
			'TThue'        => $tax,
			'TgTien'       => $net + $tax,
		);
	}

	/**
	 * `TSuat` of a line from the rate stored on it and the mapping table, checked in the order the spec fixes.
	 *
	 * @param LineFailure               $line       Offending line; its name goes in the error message.
	 * @param array<int|string, string> $line_taxes Tax amounts by rate ID.
	 * @param int                       $tax        Whole-VND tax amount of the line.
	 * @param array<int, string>        $rates      Stored rate percent by tax rate ID.
	 * @param TaxRateMap                $map        Mat Bao tax mapping table.
	 * @param array<int, string>        $rate_names Tax names from the order's tax items, by tax rate ID.
	 * @throws InvoiceDataError When the line has no rate, several rates, or a rate the table does not accept.
	 */
	private function tax_rate( LineFailure $line, array $line_taxes, int $tax, array $rates, TaxRateMap $map, array $rate_names ): int|float {
		$name = $line->name;
		if ( array() === $line_taxes ) {
			/* translators: %s: order line name. */
			self::fail( InvoiceErrorCode::LINE_TAX_NOT_CONFIGURED, sprintf( __( 'Dòng "%s" chưa có thuế GTGT.', 'einvoice-matbao' ), $name ), $line );
		}

		if ( 1 !== count( $line_taxes ) ) {
			/* translators: %s: order line name. */
			self::fail( InvoiceErrorCode::LINE_TAX_RATE_COUNT, sprintf( __( 'Dòng "%s" cần đúng một thuế suất GTGT.', 'einvoice-matbao' ), $name ), $line );
		}

		$rate_id = (int) array_key_first( $line_taxes );
		$group   = $map->group_of( $rate_id );
		if ( null === $group ) {
			self::fail(
				InvoiceErrorCode::TAX_RATE_MISMATCH,
				/* translators: 1: tax name, 2: order line name. */
				sprintf( __( 'Thuế suất "%1$s" của dòng "%2$s" chưa được ánh xạ sang thuế Mắt Bão.', 'einvoice-matbao' ), self::rate_name( $rate_id, $rate_names ), $name ),
				$line
			);
		}

		$percent = $rates[ $rate_id ] ?? '';
		$tsuat   = TaxRateMap::tsuat_for( $group, $percent, $tax );
		if ( null === $tsuat ) {
			self::fail( InvoiceErrorCode::TAX_RATE_MISMATCH, self::mismatch_reason( $group, $percent, $name ), $line );
		}

		return $tsuat;
	}

	/**
	 * Why a stored rate does not fit its group.
	 *
	 * @param string $group   Group of the rate.
	 * @param string $percent Stored percent, '' when the order has none.
	 * @param string $name    Order line name.
	 */
	private static function mismatch_reason( string $group, string $percent, string $name ): string {
		$label = TaxRateMap::label( $group );
		if ( in_array( $group, array( TaxRateMap::GROUP_VAT0, TaxRateMap::GROUP_KCT, TaxRateMap::GROUP_KKKNT ), true ) ) {
			/* translators: 1: order line name, 2: group label. */
			return sprintf( __( 'Dòng "%1$s" được ánh xạ sang %2$s nhưng có tiền thuế.', 'einvoice-matbao' ), $name, $label );
		}

		if ( ! is_numeric( $percent ) ) {
			/* translators: %s: order line name. */
			return sprintf( __( 'Không tìm thấy phần trăm thuế đã lưu của dòng "%s". Bấm "Tính lại" trong đơn.', 'einvoice-matbao' ), $name );
		}

		$shown = wc_format_localized_decimal( $percent );
		if ( TaxRateMap::GROUP_OTHER === $group ) {
			/* translators: 1: order line name, 2: tax percent. */
			return sprintf( __( 'Thuế suất của dòng "%1$s" là %2$s%%, không dùng được cho nhóm Khác.', 'einvoice-matbao' ), $name, $shown );
		}

		/* translators: 1: order line name, 2: tax percent, 3: group label. */
		return sprintf( __( 'Thuế suất của dòng "%1$s" là %2$s%% nhưng được ánh xạ sang %3$s.', 'einvoice-matbao' ), $name, $shown, $label );
	}

	/**
	 * Tax name for a reason: the order's own label, or the rate ID when it has none.
	 *
	 * @param int                $rate_id    Tax rate ID.
	 * @param array<int, string> $rate_names Tax names by tax rate ID.
	 */
	private static function rate_name( int $rate_id, array $rate_names ): string {
		$name = $rate_names[ $rate_id ] ?? '';

		return '' === $name ? '#' . $rate_id : $name;
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
	 * Tax names stored on the order's tax items, keyed by tax rate ID. The `edit` context keeps an
	 * empty label empty (`view` would answer "Tax").
	 *
	 * @param WC_Order $order Order.
	 * @return array<int, string>
	 */
	private function stored_rate_names( WC_Order $order ): array {
		$names = array();
		foreach ( $order->get_taxes() as $tax ) {
			$names[ (int) $tax->get_rate_id() ] = (string) $tax->get_label( 'edit' );
		}

		return $names;
	}

	/**
	 * Refuses the order with an admin-facing reason.
	 *
	 * @param InvoiceErrorCode $code   Stable code (a data error).
	 * @param string           $reason Vietnamese reason, without personal data.
	 * @param LineFailure|null $line   Offending order line, null when not about one line.
	 * @throws InvoiceDataError Always.
	 */
	private static function fail( InvoiceErrorCode $code, string $reason, ?LineFailure $line = null ): never {
		throw new InvoiceDataError( $code, $reason, $line ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain text, escaped where rendered.
	}

	/**
	 * An amount as whole VND, whatever the shop's `price_num_decimals`.
	 *
	 * @param string|int|float $amount Amount as stored by WooCommerce.
	 * @param LineFailure      $line   Offending line; its name goes in the error message.
	 * @throws InvoiceDataError When the amount has a fractional part.
	 */
	private static function whole_vnd( string|int|float $amount, LineFailure $line ): int {
		$value   = (float) $amount;
		$rounded = round( $value );
		if ( abs( $value - $rounded ) >= self::AMOUNT_TOLERANCE ) {
			/* translators: 1: order line name, 2: amount. */
			self::fail( InvoiceErrorCode::AMOUNT_NOT_WHOLE_VND, sprintf( __( 'Dòng "%1$s" có số tiền %2$s không phải số nguyên đồng (VND).', 'einvoice-matbao' ), $line->name, wc_format_localized_decimal( (string) $value ) ), $line );
		}

		return (int) $rounded;
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
