<?php
/**
 * REST routes behind the htmx invoice list, detail, resend and status check.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Admin;

use LTTech\EinvoiceMatbao\CredentialStore;
use LTTech\EinvoiceMatbao\Invoice\DraftInvoiceWorkflow;
use LTTech\EinvoiceMatbao\Invoice\InvoiceErrorCode;
use LTTech\EinvoiceMatbao\Invoice\InvoiceRecord;
use LTTech\EinvoiceMatbao\Invoice\InvoiceRepository;
use LTTech\EinvoiceMatbao\Invoice\InvoiceStatus;
use LTTech\EinvoiceMatbao\Invoice\Redactor;
use LTTech\EinvoiceMatbao\Matbao\MatbaoClient;
use LTTech\EinvoiceMatbao\Matbao\MatbaoError;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Invoice routes under `einvoice-matbao/v1`, with the same access rules as the settings routes
 * (Administrator, `wp_rest` nonce) and HTML fragment responses.
 *
 * Resending always goes through DraftInvoiceWorkflow::restart(), so the run looks the invoice up
 * by `MTChieu` whenever an earlier attempt may have reached Mat Bao and never creates a second
 * one blindly. Two confirmations widen that on purpose, each only for the case it names:
 * `confirm_lost` for a draft the status check found missing, and `confirm_no_invoice` for a
 * `template_changed` stop, where the admin vouches that Mat Bao holds no invoice under the old template.
 */
final class InvoiceController {

	public const PER_PAGE = 20;

	/** Attempt outcome recorded when an admin confirms no invoice exists under the old template. */
	public const OUTCOME_ADMIN_ABSENT = 'admin_absent';

	/** Statuses a resend may start from. A draft is only resent when the check found it missing. */
	private const RESENDABLE = array(
		InvoiceStatus::FAILED,
		InvoiceStatus::NEEDS_ATTENTION,
		InvoiceStatus::NOT_CONFIGURED,
		InvoiceStatus::STOPPED,
		InvoiceStatus::DRAFT_CREATED,
	);

	/**
	 * Registers the routes. Hooked to `rest_api_init`.
	 */
	public static function register_routes(): void {
		$routes = array(
			'/invoices'                           => array( WP_REST_Server::READABLE, 'list_invoices' ),
			'/invoices/(?P<id>\d+)'               => array( WP_REST_Server::READABLE, 'show_invoice' ),
			'/invoices/(?P<id>\d+)/check'         => array( WP_REST_Server::CREATABLE, 'check_status' ),
			'/invoices/(?P<id>\d+)/resend'        => array( WP_REST_Server::CREATABLE, 'resend' ),
		);

		foreach ( $routes as $route => list( $methods, $handler ) ) {
			register_rest_route(
				SettingsController::NAMESPACE,
				$route,
				array(
					'methods'             => $methods,
					'callback'            => array( new self(), $handler ),
					'permission_callback' => array( SettingsController::class, 'check_permission' ),
				)
			);
		}
	}

	/**
	 * Filtered, searched and paginated invoice list.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function list_invoices( WP_REST_Request $request ): WP_REST_Response {
		$filter = sanitize_key( self::text( $request, 'status' ) );
		$search = mb_substr( self::text( $request, 'q' ), 0, 40 );
		$page   = max( 1, (int) self::text( $request, 'page' ) );

		$repository = new InvoiceRepository();
		$statuses   = InvoiceStatus::statuses_for_filter( $filter );
		$result     = $repository->search( $statuses, $search, self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE );
		$pages      = max( 1, (int) ceil( $result['total'] / self::PER_PAGE ) );
		if ( $page > $pages ) {
			$page   = $pages;
			$result = $repository->search( $statuses, $search, self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE );
		}

		return Fragment::response( InvoicePage::render_list( $result['items'], $result['total'], $page, $pages, array_filter( array( 'status' => array() === $statuses ? '' : $filter, 'q' => $search ) ) ) );
	}

	/**
	 * Detail of one invoice with its attempt history.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function show_invoice( WP_REST_Request $request ): WP_REST_Response {
		$repository = new InvoiceRepository();
		$invoice    = $repository->find( (int) $request['id'] );
		if ( null === $invoice ) {
			return self::not_found();
		}

		return Fragment::response( InvoicePage::render_detail( $invoice, $repository->attempts( $invoice->id ) ) );
	}

	/**
	 * Asks Mat Bao for the invoice under its `MTChieu` and the stored template, and reports what is
	 * there. Reads only: it never changes the row and never creates or resends anything.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function check_status( WP_REST_Request $request ): WP_REST_Response {
		$invoice = ( new InvoiceRepository() )->find( (int) $request['id'] );
		if ( null === $invoice ) {
			return self::not_found();
		}
		if ( '' === $invoice->template_form || '' === $invoice->template_series ) {
			return Fragment::error( __( 'Hóa đơn chưa từng được gửi với mẫu nào nên chưa có gì để tra cứu trên Mắt Bão.', 'einvoice-matbao' ), 409 );
		}
		$credentials = ( new CredentialStore() )->matbao();
		if ( null === $credentials ) {
			return Fragment::error( __( 'Chưa có thông tin kết nối Mắt Bão dùng được. Hãy lưu lại thông tin kết nối.', 'einvoice-matbao' ) );
		}

		try {
			$found = ( new MatbaoClient( $credentials ) )->find_invoices_by_reference( $invoice->reference, $invoice->template_form, $invoice->template_series );
		} catch ( MatbaoError $error ) {
			return Fragment::error( __( 'Không tra cứu được trạng thái trên Mắt Bão.', 'einvoice-matbao' ), 422, Redactor::redact( $error->getMessage() ) );
		}

		return Fragment::response( InvoicePage::render_check( $invoice, $found ) );
	}

	/**
	 * Sends an invoice again through the retry workflow.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function resend( WP_REST_Request $request ): WP_REST_Response {
		$repository = new InvoiceRepository();
		$invoice    = $repository->find( (int) $request['id'] );
		if ( null === $invoice ) {
			return self::not_found();
		}
		if ( ! in_array( $invoice->status, self::RESENDABLE, true ) ) {
			return Fragment::error( __( 'Hóa đơn ở trạng thái này không thể gửi lại.', 'einvoice-matbao' ), 409 );
		}
		if ( '1' !== self::text( $request, 'confirm' ) ) {
			return Fragment::error( __( 'Cần xác nhận trước khi gửi lại.', 'einvoice-matbao' ) );
		}

		if ( InvoiceStatus::DRAFT_CREATED === $invoice->status ) {
			$refusal = $this->refuse_unless_draft_is_lost( $request, $invoice );
			if ( null !== $refusal ) {
				return $refusal;
			}
		} elseif ( '1' === self::text( $request, 'confirm_no_invoice' ) ) {
			$refusal = $this->confirm_no_invoice( $request, $repository, $invoice );
			if ( null !== $refusal ) {
				return $refusal;
			}
		}

		if ( ! DraftInvoiceWorkflow::restart( $invoice->order_id, $invoice->status ) ) {
			return Fragment::error( __( 'Hóa đơn vừa được xử lý ở nơi khác; tải lại để xem trạng thái mới.', 'einvoice-matbao' ), 409 );
		}

		return Fragment::success( __( 'Đã xếp hóa đơn vào hàng đợi gửi lại. Hệ thống tra cứu theo mã tham chiếu trước khi tạo, nên không tạo trùng.', 'einvoice-matbao' ) );
	}

	/**
	 * A created draft is resent only when the admin confirms it is gone and Mat Bao agrees: the
	 * lookup must come back empty. Anything found, or a failed lookup, refuses.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param InvoiceRecord   $invoice Invoice in `draft_created`.
	 */
	private function refuse_unless_draft_is_lost( WP_REST_Request $request, InvoiceRecord $invoice ): ?WP_REST_Response {
		if ( '1' !== self::text( $request, 'confirm_lost' ) ) {
			return Fragment::error( __( 'Hóa đơn nháp đã được tạo. Hãy kiểm tra trạng thái; chỉ khi nháp đã bị xóa trên Mắt Bão mới được gửi lại và phải xác nhận rõ.', 'einvoice-matbao' ) );
		}
		$credentials = ( new CredentialStore() )->matbao();
		if ( null === $credentials || '' === $invoice->template_form || '' === $invoice->template_series ) {
			return Fragment::error( __( 'Không kiểm tra lại được nháp trên Mắt Bão nên không gửi lại.', 'einvoice-matbao' ) );
		}
		try {
			$found = ( new MatbaoClient( $credentials ) )->find_invoices_by_reference( $invoice->reference, $invoice->template_form, $invoice->template_series );
		} catch ( MatbaoError $error ) {
			return Fragment::error( __( 'Không tra cứu được Mắt Bão nên không gửi lại.', 'einvoice-matbao' ), 422, Redactor::redact( $error->getMessage() ) );
		}

		return array() === $found
			? null
			: Fragment::error( __( 'Mắt Bão vẫn có hóa đơn với mã tham chiếu này nên không gửi lại. Kiểm tra trạng thái để xem chi tiết.', 'einvoice-matbao' ), 409 );
	}

	/**
	 * Escape hatch for `template_changed`: the admin vouches that Mat Bao holds no invoice under
	 * the old template. Needs the reference typed back. The statement is written to the attempt
	 * history and the stored template is cleared, so the next run looks the invoice up under, and
	 * creates with, the current default template.
	 *
	 * @param WP_REST_Request   $request    Request.
	 * @param InvoiceRepository $repository Storage.
	 * @param InvoiceRecord     $invoice    Invoice stopped with `template_changed`.
	 */
	private function confirm_no_invoice( WP_REST_Request $request, InvoiceRepository $repository, InvoiceRecord $invoice ): ?WP_REST_Response {
		if ( InvoiceErrorCode::TEMPLATE_CHANGED !== $invoice->error() ) {
			return Fragment::error( __( 'Xác nhận "chưa có hóa đơn" chỉ áp dụng khi hóa đơn dừng vì đổi mẫu.', 'einvoice-matbao' ) );
		}
		if ( self::text( $request, 'confirm_reference' ) !== $invoice->reference ) {
			return Fragment::error( __( 'Nhập đúng mã tham chiếu (MTChieu) để xác nhận.', 'einvoice-matbao' ) );
		}

		$attempt = $repository->start_attempt( $invoice->id );
		$repository->finish_attempt(
			$attempt,
			self::OUTCOME_ADMIN_ABSENT,
			null,
			null,
			null,
			sprintf(
				/* translators: 1: admin user ID, 2: form (KHMSHDon), 3: series (KHHDon) of the earlier template. */
				__( 'Quản trị viên #%1$d xác nhận chưa có hóa đơn nào trên Mắt Bão với mẫu cũ %2$s / %3$s; cho gửi lại bằng mẫu mặc định hiện tại.', 'einvoice-matbao' ),
				get_current_user_id(),
				$invoice->template_form,
				$invoice->template_series
			)
		);
		$repository->remember_template( $invoice->id, '', '' );

		return null;
	}

	/**
	 * Error fragment for an unknown invoice.
	 */
	private static function not_found(): WP_REST_Response {
		return Fragment::error( __( 'Không tìm thấy hóa đơn.', 'einvoice-matbao' ), 404 );
	}

	/**
	 * A trimmed, sanitized text parameter.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $name    Parameter name.
	 */
	private static function text( WP_REST_Request $request, string $name ): string {
		$value = $request->get_param( $name );

		return is_string( $value ) ? trim( sanitize_text_field( $value ) ) : '';
	}
}
