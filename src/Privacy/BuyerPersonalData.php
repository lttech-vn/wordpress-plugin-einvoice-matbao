<?php
/**
 * WordPress privacy exporter and eraser for the buyer data kept by the plugin.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Privacy;

use LTTech\EinvoiceMatbao\Buyer\BuyerRequestStore;
use LTTech\EinvoiceMatbao\Buyer\BuyerSnapshot;
use LTTech\EinvoiceMatbao\Buyer\BuyerSnapshotter;
use LTTech\EinvoiceMatbao\Buyer\BuyerType;
use LTTech\EinvoiceMatbao\Invoice\InvoiceRecord;
use LTTech\EinvoiceMatbao\Invoice\InvoiceRepository;
use LTTech\EinvoiceMatbao\Invoice\InvoiceStatus;
use LTTech\EinvoiceMatbao\Invoice\Redactor;
use LTTech\EinvoiceMatbao\Invoice\Schema;
use WC_Order;
use WP_User;

/**
 * Finds the orders of an email address (billing email, guests included, plus the orders of the
 * user with that email) and exports or erases the invoice request, the buyer snapshot and the
 * buyer data in the invoice and attempt rows.
 *
 * The citizen ID is exported as its 4-digit mask, never decrypted: ADR 0002 keeps the full
 * number in encrypted order meta only, and an export file is downloaded and mailed around. The
 * person already knows their own number; the mask lets them recognise it.
 *
 * Erasing deletes both order meta keys and anonymizes `buyer_name` plus any copy of the buyer's
 * values in `status_reason` and attempt messages. The invoice and attempt rows themselves, with
 * `MTChieu`, status, amounts and the Mat Bao invoice ID, are retained for accounting. A draft or
 * invoice already created on Mat Bao is outside the plugin.
 */
final class BuyerPersonalData {

	public const ID = 'einvoice-matbao';

	/** Orders per exporter/eraser page, as WooCommerce uses. */
	private const PAGE_SIZE = 10;

	/**
	 * Shorter values are not replaced in free text, so a 1-2 character value cannot wipe a message.
	 */
	private const MIN_SCRUB_LENGTH = 3;

	/**
	 * Hooks the exporter and eraser. The eraser is registered at priority 5, before WooCommerce's
	 * order eraser (10), so it finds orders while they still carry the billing email; an order
	 * WooCommerce anonymizes by other means is erased through its own hook.
	 */
	public static function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'register_eraser' ), 5 );
		add_action( 'woocommerce_privacy_remove_order_personal_data', array( self::class, 'on_woocommerce_order_anonymized' ) );
	}

	/**
	 * Adds the exporter.
	 *
	 * @param mixed $exporters Registered exporters.
	 * @return array<mixed>
	 */
	public static function register_exporter( $exporters ): array {
		$exporters             = is_array( $exporters ) ? $exporters : array();
		$exporters[ self::ID ] = array(
			'exporter_friendly_name' => __( 'Hóa đơn điện tử Mắt Bão', 'einvoice-matbao' ),
			'callback'               => array( self::class, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Adds the eraser.
	 *
	 * @param mixed $erasers Registered erasers.
	 * @return array<mixed>
	 */
	public static function register_eraser( $erasers ): array {
		$erasers             = is_array( $erasers ) ? $erasers : array();
		$erasers[ self::ID ] = array(
			'eraser_friendly_name' => __( 'Hóa đơn điện tử Mắt Bão', 'einvoice-matbao' ),
			'callback'             => array( self::class, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Exporter callback.
	 *
	 * @param string $email_address Email of the request.
	 * @param int    $page          1-based page.
	 * @return array{data: list<array{group_id: string, group_label: string, group_description: string, item_id: string, data: list<array{name: string, value: string}>}>, done: bool}
	 */
	public static function export( $email_address, $page = 1 ): array {
		$orders = self::orders_of( (string) $email_address, (int) $page );
		$items  = array();

		foreach ( $orders as $order ) {
			$data = self::export_fields( $order );
			if ( array() === $data ) {
				continue;
			}
			$items[] = array(
				'group_id'          => self::ID,
				'group_label'       => __( 'Thông tin xuất hóa đơn', 'einvoice-matbao' ),
				'group_description' => __( 'Thông tin người mua lưu để xuất hóa đơn điện tử Mắt Bão. CCCD chỉ hiện 4 số cuối.', 'einvoice-matbao' ),
				'item_id'           => 'einvoice-matbao-order-' . $order->get_id(),
				'data'              => $data,
			);
		}

		return array(
			'data' => $items,
			'done' => count( $orders ) < self::PAGE_SIZE,
		);
	}

	/**
	 * Eraser callback.
	 *
	 * @param string $email_address Email of the request.
	 * @param int    $page          1-based page. Erasing never changes which orders match, so pages stay stable.
	 * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
	 */
	public static function erase( $email_address, $page = 1 ): array {
		$orders   = self::orders_of( (string) $email_address, (int) $page );
		$response = array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $orders ) < self::PAGE_SIZE,
		);

		foreach ( $orders as $order ) {
			$anonymized = false;
			if ( self::erase_order( $order, $anonymized ) ) {
				$response['items_removed'] = true;
				/* translators: %s: order number. */
				$response['messages'][] = sprintf( __( 'Đã xóa thông tin xuất hóa đơn (CCCD, địa chỉ, mã số thuế, tên người mua) của đơn %s.', 'einvoice-matbao' ), $order->get_order_number() );
			}

			$invoice = ( new InvoiceRepository() )->find_by_order( $order->get_id() );
			if ( null !== $invoice ) {
				$response['items_retained'] = true;
				$response['messages'][]     = self::retained_message( $order, $invoice, $anonymized );
			}
		}

		return $response;
	}

	/**
	 * Erases the plugin data of an order WooCommerce has just anonymized (its order eraser, or
	 * the scheduled removal of old orders). Hooked to `woocommerce_privacy_remove_order_personal_data`.
	 * The billing name is already gone then, so values are taken from the request and snapshot.
	 *
	 * @param mixed $order Order passed by WooCommerce.
	 */
	public static function on_woocommerce_order_anonymized( $order ): void {
		if ( $order instanceof WC_Order ) {
			self::erase_order( $order );
		}
	}

	/**
	 * Erases the plugin's buyer data of one order: deletes both order meta keys and anonymizes
	 * its invoice and attempt rows. The order itself is left to WooCommerce.
	 *
	 * @param WC_Order $order Order.
	 * @param bool     $anonymized Set to whether the invoice or attempt rows changed.
	 * @return bool Whether anything was removed or anonymized.
	 */
	public static function erase_order( WC_Order $order, bool &$anonymized = false ): bool {
		$request  = ( new BuyerRequestStore() )->load( $order );
		$snapshot = ( new BuyerSnapshotter() )->load( $order );
		$values   = self::personal_values( $order, $request, $snapshot );
		$is_buyer = self::is_identified_buyer( $request, $snapshot );

		$removed = false;
		foreach ( array( BuyerRequestStore::META_KEY, BuyerSnapshotter::META_KEY ) as $key ) {
			if ( '' !== (string) $order->get_meta( $key ) ) {
				$order->delete_meta_data( $key );
				$removed = true;
			}
		}
		if ( $removed ) {
			$order->save_meta_data();
		}

		$invoice    = ( new InvoiceRepository() )->find_by_order( $order->get_id() );
		$anonymized = null !== $invoice && self::anonymize_invoice( $invoice, $values, $is_buyer );
		if ( $anonymized ) {
			$removed = true;
		}

		return $removed;
	}

	/**
	 * Export rows of one order; empty when the plugin holds no buyer data for it.
	 *
	 * @param WC_Order $order Order.
	 * @return list<array{name: string, value: string}>
	 */
	private static function export_fields( WC_Order $order ): array {
		$request  = ( new BuyerRequestStore() )->load( $order );
		$snapshot = ( new BuyerSnapshotter() )->load( $order );
		if ( ! self::is_identified_buyer( $request, $snapshot ) ) {
			return array();
		}

		$type   = null !== $snapshot ? $snapshot->type : (string) ( $request['type'] ?? '' );
		$fields = null !== $snapshot ? $snapshot->fields : array();
		$values = array(
			__( 'Đơn hàng', 'einvoice-matbao' )       => $order->get_order_number(),
			__( 'Loại người mua', 'einvoice-matbao' ) => BuyerType::BUSINESS === $type ? __( 'Doanh nghiệp', 'einvoice-matbao' ) : __( 'Cá nhân', 'einvoice-matbao' ),
			__( 'Tên người mua', 'einvoice-matbao' )  => null !== $snapshot ? $snapshot->buyer_name : (string) ( $request['business_name'] ?? '' ),
			__( 'Mã số thuế', 'einvoice-matbao' )     => $fields['NMua_MST'] ?? (string) ( $request['tax_code'] ?? '' ),
			__( 'Địa chỉ', 'einvoice-matbao' )        => $fields['NMua_DChi'] ?? (string) ( $request['address'] ?? '' ),
			'CCCD'                                    => null !== $snapshot && '' !== $snapshot->id_mask ? $snapshot->id_mask : (string) ( $request['id_mask'] ?? '' ),
			__( 'Số điện thoại', 'einvoice-matbao' )  => $fields['NMua_SDThoai'] ?? '',
			__( 'Email', 'einvoice-matbao' )          => $fields['NMua_DCTDTu'] ?? '',
		);

		$invoice = ( new InvoiceRepository() )->find_by_order( $order->get_id() );
		if ( null !== $invoice ) {
			$values[ __( 'Mã tham chiếu (MTChieu)', 'einvoice-matbao' ) ]    = $invoice->reference;
			$values[ __( 'Trạng thái hóa đơn', 'einvoice-matbao' ) ]         = InvoiceStatus::label( $invoice->status );
			$values[ __( 'Tên người mua trên hóa đơn', 'einvoice-matbao' ) ] = Redactor::redact( $invoice->buyer_name );
			$values[ __( 'Lý do trạng thái', 'einvoice-matbao' ) ]           = Redactor::redact( $invoice->status_reason );
		}

		$rows = array();
		foreach ( $values as $name => $value ) {
			if ( '' !== $value ) {
				$rows[] = array(
					'name'  => (string) $name,
					'value' => $value,
				);
			}
		}

		return $rows;
	}

	/**
	 * Whether the order holds a named buyer (individual or business), not a walk-in buyer whose
	 * `NMua_Ten` is the store's generic text.
	 *
	 * @param array<string, string>|null $request  Stored request.
	 * @param BuyerSnapshot|null         $snapshot Stored snapshot.
	 */
	private static function is_identified_buyer( ?array $request, ?BuyerSnapshot $snapshot ): bool {
		$types = array( BuyerType::INDIVIDUAL, BuyerType::BUSINESS );

		return ( null !== $snapshot && in_array( $snapshot->type, $types, true ) )
			|| ( null !== $request && in_array( $request['type'] ?? '', $types, true ) );
	}

	/**
	 * Every personal value the plugin may have copied into its rows, longest first.
	 *
	 * @param WC_Order                   $order    Order (billing may already be anonymized).
	 * @param array<string, string>|null $request  Stored request.
	 * @param BuyerSnapshot|null         $snapshot Stored snapshot.
	 * @return list<string>
	 */
	private static function personal_values( WC_Order $order, ?array $request, ?BuyerSnapshot $snapshot ): array {
		$values = array(
			trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			$order->get_billing_phone(),
			$order->get_billing_email(),
		);
		if ( null !== $request ) {
			array_push( $values, $request['business_name'] ?? '', $request['tax_code'] ?? '', $request['address'] ?? '', $request['id_mask'] ?? '' );
		}
		if ( null !== $snapshot && BuyerType::WALK_IN !== $snapshot->type ) {
			array_push( $values, $snapshot->buyer_name, $snapshot->id_mask, ...array_values( $snapshot->fields ) );
		}

		$values = array_values( array_unique( array_filter( array_map( 'trim', $values ), static fn( string $value ): bool => mb_strlen( $value ) >= self::MIN_SCRUB_LENGTH ) ) );
		usort( $values, static fn( string $a, string $b ): int => mb_strlen( $b ) <=> mb_strlen( $a ) );

		return $values;
	}

	/**
	 * Anonymizes the buyer in the invoice row and its attempts, keeping the rows.
	 *
	 * @param InvoiceRecord $invoice  Invoice row.
	 * @param string[]      $values   Personal values to replace in free text.
	 * @param bool          $is_buyer Whether `buyer_name` names the person (not the walk-in text).
	 * @return bool Whether a row changed.
	 */
	private static function anonymize_invoice( InvoiceRecord $invoice, array $values, bool $is_buyer ): bool {
		global $wpdb;

		$deleted = self::deleted_marker();
		$changes = array();
		if ( $is_buyer && $deleted !== $invoice->buyer_name && '' !== $invoice->buyer_name ) {
			$values[]              = $invoice->buyer_name;
			$changes['buyer_name'] = $deleted;
		}
		$reason = self::scrub( $invoice->status_reason, $values );
		if ( $reason !== $invoice->status_reason ) {
			$changes['status_reason'] = $reason;
		}

		$changed = false;
		if ( array() !== $changes ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin table, no cache layer.
			$changed = false !== $wpdb->update( Schema::invoice_table(), $changes, array( 'id' => $invoice->id ), array_fill( 0, count( $changes ), '%s' ), array( '%d' ) );
		}

		foreach ( ( new InvoiceRepository() )->attempts( $invoice->id ) as $attempt ) {
			$message  = (string) ( $attempt['message'] ?? '' );
			$scrubbed = self::scrub( $message, $values );
			if ( $scrubbed !== $message ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin table, no cache layer.
				$wpdb->update( Schema::attempt_table(), array( 'message' => $scrubbed ), array( 'id' => (int) $attempt['id'] ), array( '%s' ), array( '%d' ) );
				$changed = true;
			}
		}

		return $changed;
	}

	/**
	 * Replaces every personal value in a text with the deleted marker.
	 *
	 * @param string   $text   Free text.
	 * @param string[] $values Values to replace.
	 */
	private static function scrub( string $text, array $values ): string {
		if ( '' === $text || array() === $values ) {
			return $text;
		}

		return str_replace( $values, self::deleted_marker(), $text );
	}

	/**
	 * Core's placeholder for erased text ("[deleted]", translated).
	 */
	private static function deleted_marker(): string {
		return wp_privacy_anonymize_data( 'text' );
	}

	/**
	 * Why the invoice row stays, and that Mat Bao's copy is outside the plugin.
	 *
	 * @param WC_Order      $order   Order.
	 * @param InvoiceRecord $invoice    Invoice row.
	 * @param bool          $anonymized Whether this erase changed the rows.
	 */
	private static function retained_message( WC_Order $order, InvoiceRecord $invoice, bool $anonymized ): string {
		$personal = $anonymized
			? __( 'thông tin người mua trong bản ghi đã được ẩn danh', 'einvoice-matbao' )
			: __( 'bản ghi không có dữ liệu cá nhân cần ẩn danh', 'einvoice-matbao' );

		return sprintf(
			/* translators: 1: order number, 2: reference (MTChieu), 3: what happened to the buyer's data in the record. */
			__( 'Giữ lại bản ghi hóa đơn của đơn %1$s (mã tham chiếu %2$s, trạng thái, lịch sử gửi) vì nghĩa vụ kế toán; %3$s. Hóa đơn hoặc bản nháp đã tạo trên Mắt Bão nằm ngoài phạm vi plugin: cần xử lý trực tiếp trên hệ thống hóa đơn Mắt Bão. Nếu hóa đơn đang chờ gửi hoặc gửi lại, yêu cầu xóa được ưu tiên: hóa đơn có thể chuyển sang trạng thái lỗi để quản trị viên xử lý.', 'einvoice-matbao' ),
			$order->get_order_number(),
			$invoice->reference,
			$personal
		);
	}

	/**
	 * One page of orders whose billing email is the address, or that belong to the user with it.
	 *
	 * @param string $email_address Email.
	 * @param int    $page          1-based page.
	 * @return list<WC_Order>
	 */
	private static function orders_of( string $email_address, int $page ): array {
		$email_address = trim( $email_address );
		if ( '' === $email_address ) {
			return array();
		}

		$customers = array( $email_address );
		$user      = get_user_by( 'email', $email_address );
		if ( $user instanceof WP_User ) {
			$customers[] = $user->ID;
		}

		$orders = wc_get_orders(
			array(
				'limit'    => self::PAGE_SIZE,
				'page'     => max( 1, $page ),
				'customer' => $customers,
				'type'     => 'shop_order',
				'status'   => array_merge( array_keys( wc_get_order_statuses() ), array( 'trash', 'checkout-draft' ) ), // "any" leaves out trash and drafts.
				'orderby'  => 'ID',
				'order'    => 'ASC',
			)
		);

		return is_array( $orders ) ? array_values( $orders ) : array();
	}
}
