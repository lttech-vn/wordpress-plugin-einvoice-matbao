<?php
/**
 * Link that downloads the PDF of the buyer's own invoice.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Customer;

use LTTech\EinvoiceMatbao\CredentialStore;
use LTTech\EinvoiceMatbao\Invoice\InvoiceRecord;
use LTTech\EinvoiceMatbao\Invoice\InvoiceRepository;
use LTTech\EinvoiceMatbao\Invoice\MatbaoStatusSync;
use LTTech\EinvoiceMatbao\Invoice\SyncOutcome;
use LTTech\EinvoiceMatbao\Matbao\MatbaoClient;
use LTTech\EinvoiceMatbao\Matbao\MatbaoError;
use LTTech\EinvoiceMatbao\Monitoring\Monitor;
use WC_Order;

/**
 * Builds the download URL on the My Account order page and serves it.
 *
 * Fail closed: only the logged-in owner of the order with a valid nonce gets past authorize();
 * only an invoice the customer sees as issued is looked up again; only a confirmed lookup whose
 * re-read record is still issued, with the same Mat Bao invoice ID and a lookup code, is
 * downloaded. Every refusal looks the same to the visitor.
 */
final class InvoicePdfDownload {

	public const QUERY_ARG           = 'einvoice-matbao-pdf';
	public const NONCE_ACTION_PREFIX = 'einvoice_matbao_invoice_pdf_';

	/**
	 * Creates the handler.
	 *
	 * @param InvoiceRepository $repository Invoice storage.
	 * @param MatbaoClient|null $client     Mat Bao client; built from the saved credentials when null.
	 * @param PdfResponder      $responder  Output seam.
	 * @param \Closure|null     $recheck    `Closure( InvoiceRecord ): SyncOutcome`; defaults to MatbaoStatusSync::sync_one().
	 */
	public function __construct(
		private readonly InvoiceRepository $repository = new InvoiceRepository(),
		private readonly ?MatbaoClient $client = null,
		private readonly PdfResponder $responder = new PdfResponder(),
		private readonly ?\Closure $recheck = null,
	) {
	}

	/**
	 * Hooks the request handler.
	 */
	public static function register(): void {
		add_action(
			'template_redirect',
			static function (): void {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presence check only; handle() verifies the nonce.
				if ( ! is_wc_endpoint_url( 'view-order' ) || ! isset( $_GET[ self::QUERY_ARG ] ) ) {
					return;
				}
				( new self() )->handle( absint( get_query_var( 'view-order' ) ) );
			}
		);
	}

	/**
	 * Serves the invoice PDF of an order to its owner, or redirects with the generic error.
	 *
	 * @param int $order_id Order ID from the view-order URL.
	 */
	public function handle( int $order_id ): void {
		$order  = $this->authorize( $order_id );
		$issued = null === $order ? null : $this->issued_record( $order );
		if ( null === $issued ) {
			$this->fail( $order_id, null );
			return;
		}
		$client = $this->resolve_client();
		$record = null === $client ? null : $this->recheck( $issued, $client );
		if ( null === $client || null === $record ) {
			$this->fail( $order_id, null );
			return;
		}

		try {
			$bytes = $client->download_invoice_pdf( (string) $record->matbao_invoice_id, (string) $record->lookup_code );
		} catch ( MatbaoError $error ) {
			$this->fail( $order_id, $error );
			return;
		}

		$this->responder->send( self::filename( $record ), $bytes );
	}

	/**
	 * The order when the current user is logged in, sent a valid nonce for it and owns it.
	 *
	 * @param int $order_id Order ID from the URL.
	 */
	private function authorize( int $order_id ): ?WC_Order {
		if ( ! is_user_logged_in() ) {
			return null;
		}
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION_PREFIX . $order_id ) ) {
			return null;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return null;
		}
		$customer_id = $order->get_customer_id();

		return 0 !== $customer_id && get_current_user_id() === $customer_id ? $order : null;
	}

	/**
	 * The order's invoice when the customer sees it as issued; anything else never reaches Mat Bao.
	 *
	 * @param WC_Order $order Authorized order.
	 */
	private function issued_record( WC_Order $order ): ?InvoiceRecord {
		$record = $this->repository->find_by_order( $order->get_id() );

		return null !== $record && CustomerInvoiceStatus::ISSUED === CustomerInvoiceStatus::from_record( $record ) ? $record : null;
	}

	/**
	 * Looks the invoice up again and returns the re-read record, or null unless the lookup confirmed
	 * it and it is still the same issued invoice with a lookup code. The stored state alone is never
	 * proof: FAILED and UNCONFIRMED refuse even when the row looks valid.
	 *
	 * @param InvoiceRecord $record Issued invoice as read before the lookup.
	 * @param MatbaoClient  $client Client for the default recheck.
	 */
	private function recheck( InvoiceRecord $record, MatbaoClient $client ): ?InvoiceRecord {
		$recheck = $this->recheck ?? fn( InvoiceRecord $read ): SyncOutcome => ( new MatbaoStatusSync( $this->repository, $client ) )->sync_one( $read );
		$outcome = $recheck( $record );
		if ( SyncOutcome::UPDATED !== $outcome && SyncOutcome::UNCHANGED !== $outcome ) {
			return null;
		}

		$fresh = $this->repository->find( $record->id );
		if (
			null === $fresh
			|| $fresh->matbao_invoice_id !== $record->matbao_invoice_id
			|| CustomerInvoiceStatus::ISSUED !== CustomerInvoiceStatus::from_record( $fresh )
			|| null === $fresh->lookup_code || '' === $fresh->lookup_code
		) {
			return null;
		}

		return $fresh;
	}

	/**
	 * Shows the generic error and sends the visitor back: to the clean order page, or to the
	 * My Account login when logged out. A Mat Bao error is logged without any response content.
	 *
	 * @param int              $order_id Order ID from the URL.
	 * @param MatbaoError|null $error    The Mat Bao error, when the download call failed.
	 */
	private function fail( int $order_id, ?MatbaoError $error ): void {
		if ( null !== $error ) {
			Monitor::log(
				'warn',
				'customer invoice download failed',
				array(
					'order_id'   => $order_id,
					'error_kind' => $error->kind,
					'api_code'   => $error->api_code,
				)
			);
		}

		wc_add_notice( __( 'Không tải được hóa đơn lúc này. Vui lòng thử lại sau hoặc liên hệ cửa hàng.', 'einvoice-matbao' ), 'error' );
		$account = wc_get_page_permalink( 'myaccount' );
		$this->responder->redirect( is_user_logged_in() ? wc_get_endpoint_url( 'view-order', (string) $order_id, $account ) : $account );
	}

	/**
	 * The injected client, or one built from the saved credentials.
	 */
	private function resolve_client(): ?MatbaoClient {
		if ( null !== $this->client ) {
			return $this->client;
		}
		$credentials = ( new CredentialStore() )->matbao();

		return null === $credentials ? null : new MatbaoClient( $credentials );
	}

	/**
	 * Attachment name `hoa-don-<form><series>-<number>.pdf`, keeping only ASCII letters, digits and `-`.
	 *
	 * @param InvoiceRecord $record Issued invoice.
	 */
	private static function filename( InvoiceRecord $record ): string {
		$template = (string) preg_replace( '/[^A-Za-z0-9-]/', '', $record->template_form . $record->template_series );

		return 'hoa-don-' . $template . '-' . $record->invoice_number . '.pdf';
	}

	/**
	 * Download URL of an order's invoice PDF, on its My Account order page.
	 *
	 * @param WC_Order $order Order of the buyer.
	 */
	public static function url( WC_Order $order ): string {
		$order_id = $order->get_id();
		$base_url = wc_get_endpoint_url( 'view-order', (string) $order_id, wc_get_page_permalink( 'myaccount' ) );

		return add_query_arg(
			array(
				self::QUERY_ARG => '1',
				'_wpnonce'      => wp_create_nonce( self::NONCE_ACTION_PREFIX . $order_id ),
			),
			$base_url
		);
	}
}
