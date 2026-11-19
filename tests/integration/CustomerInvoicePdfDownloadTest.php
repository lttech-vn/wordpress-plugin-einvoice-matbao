<?php
/**
 * PDF download of an issued invoice by the order owner.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the API carries the PDF as base64.

use LTTech\EinvoiceMatbao\Customer\InvoicePdfDownload;
use LTTech\EinvoiceMatbao\Invoice\DraftInvoiceWorkflow;
use LTTech\EinvoiceMatbao\Invoice\InvoiceRecord;
use LTTech\EinvoiceMatbao\Invoice\InvoiceRepository;
use LTTech\EinvoiceMatbao\Invoice\InvoiceStatus;
use LTTech\EinvoiceMatbao\Invoice\MatbaoSnapshot;
use LTTech\EinvoiceMatbao\Invoice\Schema;
use LTTech\EinvoiceMatbao\Invoice\StatusSyncBackoff;
use LTTech\EinvoiceMatbao\Invoice\SyncOutcome;
use LTTech\EinvoiceMatbao\Monitoring\Monitor;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\FakeHttp;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\InvoiceWorkflowTestCase;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\MonitoringFixture;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\RecordingResponder;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\ResponseFinished;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\SentrySink;
use WC_Order;
use WC_Session_Handler;
use WP_User;

/**
 * Runs the handler the way `template_redirect` does, with Mat Bao faked at the HTTP boundary and
 * the response recorded by a PdfResponder whose only overrides are emit() and finish().
 */
final class CustomerInvoicePdfDownloadTest extends InvoiceWorkflowTestCase {

	use MonitoringFixture;

	private const GENERIC_ERROR  = 'Không tải được hóa đơn lúc này. Vui lòng thử lại sau hoặc liên hệ cửa hàng.';
	private const DOWNLOAD_ROUTE = 'POST /api/invoice/download-invoice';
	private const REFERENCE      = 'WC-REF-5512';
	private const INVOICE_ID     = 'abc';
	private const LOOKUP_CODE    = 'FM80520C49';
	private const PDF            = "%PDF-1.4\n%customer invoice\n";

	/**
	 * Saved `$_GET`, restored in tear_down.
	 *
	 * @var array<string, mixed>
	 */
	private array $saved_get = array();

	/**
	 * URL given to wp_redirect(), or null when there was no redirect.
	 *
	 * @var string|null
	 */
	private ?string $redirected_to = null;

	/**
	 * Number of HTTP requests before the last download.
	 *
	 * @var int
	 */
	private int $requests_before = 0;

	public function set_up(): void {
		parent::set_up();

		$this->configure_matbao();
		WC()->session = new WC_Session_Handler();
		WC()->session->init();
		wc_clear_notices();
		$this->saved_get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- saving test state.
		add_filter( 'wp_redirect', array( $this, 'capture_redirect' ) );
		$this->fake_download_ok();
	}

	public function tear_down(): void {
		wc_clear_notices();
		$_GET = $this->saved_get;
		remove_filter( 'wp_redirect', array( $this, 'capture_redirect' ) );
		wp_set_current_user( 0 );
		$this->reset_monitoring();

		parent::tear_down();
	}

	/**
	 * Records the redirect target and cancels the real redirect.
	 *
	 * @param string $location Redirect target.
	 */
	public function capture_redirect( string $location ): bool {
		$this->redirected_to = $location;

		return false;
	}

	public function test_owner_downloads(): void {
		$customer = $this->customer();
		$order    = $this->issued_order( $customer );
		$this->fake_lookup_rows( array( $this->issued_row( array( 'maTraCuu' => self::LOOKUP_CODE ) ) ) );

		$responder = $this->download( $customer, $order->get_id() );

		$this->assertSame( self::PDF, $responder->body );
		$this->assertMatchesRegularExpression( '/^hoa-don-1C26TAT-125\.pdf$/', $this->filename_of( $responder ) );
		$requests = $this->http->requests_to( self::DOWNLOAD_ROUTE );
		$this->assertCount( 1, $requests );
		$this->assertSame( self::LOOKUP_CODE, $requests[0]['body']['MaTraCuu'] );
		$this->assertSame( self::INVOICE_ID, $requests[0]['body']['MaSoHDon'] );
		$this->assertSame( array(), $this->error_notices() );
	}

	public function test_another_customer(): void {
		$owner = $this->customer();
		$other = $this->customer();
		$order = $this->issued_order( $owner );

		$responder = $this->download( $other, $order->get_id() );

		$this->assert_rejected_without_matbao( $responder );
		$this->assert_redirect_to_order( $order->get_id() );
	}

	public function test_logged_out(): void {
		$order = $this->issued_order( $this->customer() );
		$nonce = wp_create_nonce( InvoicePdfDownload::NONCE_ACTION_PREFIX . $order->get_id() );

		$responder = $this->download( null, $order->get_id(), $nonce );

		$this->assert_rejected_without_matbao( $responder );
		$this->assertSame( wc_get_page_permalink( 'myaccount' ), $this->redirected_to );
	}

	public function test_missing_nonce(): void {
		$customer = $this->customer();
		$order    = $this->issued_order( $customer );

		$this->assert_rejected_without_matbao( $this->download( $customer, $order->get_id(), false ) );
		$this->assert_rejected_without_matbao( $this->download( $customer, $order->get_id(), 'not-a-nonce' ) );
	}

	public function test_nonce_of_another_order(): void {
		$customer = $this->customer();
		$order_a  = $this->issued_order( $customer );
		$order_b  = $this->issued_order( $customer, array( 'reference' => 'WC-REF-5513' ) );
		wp_set_current_user( $customer->ID );
		$nonce_a = wp_create_nonce( InvoicePdfDownload::NONCE_ACTION_PREFIX . $order_a->get_id() );

		$responder = $this->download( $customer, $order_b->get_id(), $nonce_a );

		$this->assert_rejected_without_matbao( $responder );
	}

	public function test_missing_order_gives_generic_error(): void {
		$customer = $this->customer();
		$missing  = 987654321;

		$responder = $this->download( $customer, $missing );

		$this->assert_rejected_without_matbao( $responder );
	}

	public function test_draft_link_forged(): void {
		$customer = $this->customer();
		$order    = $this->issued_order(
			$customer,
			array(
				'matbao_status'       => 1,
				'matbao_invoice_type' => 1,
				'invoice_number'      => null,
			)
		);

		$this->assert_rejected_without_matbao( $this->download( $customer, $order->get_id() ) );
	}

	/**
	 * Invoices whose customer status is not "issued".
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function non_issued_invoices(): array {
		$plain = static fn( string $status ): array => array(
			array(
				'status'              => $status,
				'matbao_status'       => null,
				'matbao_invoice_type' => null,
				'invoice_number'      => null,
			),
		);

		return array(
			'pending'         => $plain( InvoiceStatus::PENDING ),
			'sending'         => $plain( InvoiceStatus::SENDING ),
			'needs_attention' => $plain( InvoiceStatus::NEEDS_ATTENTION ),
			'stopped'         => $plain( InvoiceStatus::STOPPED ),
			'skipped'         => $plain( InvoiceStatus::SKIPPED ),
			'issued type 3'   => array( array( 'matbao_invoice_type' => 3 ) ),
		);
	}

	/**
	 * Every non-issued invoice is refused before Mat Bao is called.
	 *
	 * @dataProvider non_issued_invoices
	 *
	 * @param array<string, mixed> $columns Invoice columns.
	 */
	public function test_non_issued_never_reach_matbao( array $columns ): void {
		$customer = $this->customer();
		$order    = $this->issued_order( $customer, $columns );

		$this->assert_rejected_without_matbao( $this->download( $customer, $order->get_id() ) );
	}

	public function test_cancelled_after_issue(): void {
		$customer = $this->customer();
		$order    = $this->issued_order( $customer );
		$this->fake_lookup_rows(
			array(
				$this->issued_row(
					array(
						'maLoaiHDon' => 3,
						'maTraCuu'   => self::LOOKUP_CODE,
					)
				),
			)
		);

		$responder = $this->download( $customer, $order->get_id() );

		$this->assert_rejected_after_lookup( $responder );
		$this->assertSame( 3, $this->invoice_of( $order )?->matbao_invoice_type );
	}

	public function test_lookup_fails_at_download(): void {
		$customer = $this->customer();
		$order    = $this->issued_order( $customer );
		$this->http->respond_json( self::LOOKUP_ROUTE, 503, array( 'message' => 'LOOKUP-FAILURE-BODY' ) );
		$sink = $this->start_monitoring();

		$responder = $this->download( $customer, $order->get_id() );

		$this->stop_monitoring();
		$this->assert_rejected_after_lookup( $responder );
		$this->assert_download_failure_logged( $sink, $order->get_id(), 'recheck_failed' );
	}

	/**
	 * Lookup answers that do not confirm the stored invoice.
	 *
	 * @return array<string, array{0: list<array<string, mixed>>}>
	 */
	public static function unconfirming_rows(): array {
		return array(
			'reference absent'     => array( array() ),
			'different invoice ID' => array(
				array(
					array(
						'maSoHDon'    => 'zzz',
						'maTThaiHDon' => 4,
						'maLoaiHDon'  => 2,
						'shDon'       => 125,
						'maTraCuu'    => self::LOOKUP_CODE,
					),
				),
			),
			'row without a status' => array(
				array(
					array(
						'maSoHDon'    => self::INVOICE_ID,
						'maTThaiHDon' => null,
						'maLoaiHDon'  => 2,
						'shDon'       => 125,
						'maTraCuu'    => self::LOOKUP_CODE,
					),
				),
			),
		);
	}

	/**
	 * A lookup that does not confirm the invoice never leads to a download.
	 *
	 * @dataProvider unconfirming_rows
	 *
	 * @param list<array<string, mixed>> $rows Lookup rows.
	 */
	public function test_lookup_does_not_confirm( array $rows ): void {
		$customer = $this->customer();
		$order    = $this->issued_order( $customer, array( 'lookup_code' => self::LOOKUP_CODE ) );
		$this->fake_lookup_rows( $rows );

		$this->assert_rejected_after_lookup( $this->download( $customer, $order->get_id() ) );
	}

	public function test_deleted_at_download(): void {
		$customer = $this->customer();
		$order    = $this->issued_order( $customer, array( 'lookup_code' => self::LOOKUP_CODE ) );
		$this->fake_lookup_not_found();

		$this->assert_rejected_after_lookup( $this->download( $customer, $order->get_id() ) );
		$invoice = $this->invoice_of( $order );
		$this->assertSame( 0, $invoice?->matbao_status );
		$this->assertNull( $invoice?->lookup_code );
	}

	public function test_still_no_lookup_code_after_check(): void {
		$customer = $this->customer();
		$order    = $this->issued_order( $customer );
		$this->fake_lookup_rows( array( $this->issued_row() ) );

		$this->assert_rejected_after_lookup( $this->download( $customer, $order->get_id() ) );
	}

	public function test_invoice_id_changed_during_check(): void {
		$customer = $this->customer();
		$order    = $this->issued_order( $customer );
		$this->http->respond_with(
			self::LOOKUP_ROUTE,
			function () use ( $order ): array {
				global $wpdb;

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a concurrent writer recreates the draft.
				$wpdb->update( Schema::invoice_table(), array( 'matbao_invoice_id' => 'other' ), array( 'order_id' => $order->get_id() ) );

				return FakeHttp::json_response( 200, $this->lookup_envelope( array( $this->full_row( $this->issued_row( array( 'maTraCuu' => self::LOOKUP_CODE ) ) ) ) ) );
			}
		);

		$this->assert_rejected_after_lookup( $this->download( $customer, $order->get_id() ) );
		$this->assertSame( 'other', $this->invoice_of( $order )?->matbao_invoice_id );
	}

	public function test_concurrent_writer_still_downloads(): void {
		$customer = $this->customer();
		$order    = $this->issued_order( $customer );
		$alerts   = did_action( DraftInvoiceWorkflow::NEEDS_ATTENTION_ACTION );

		$responder = $this->download( $customer, $order->get_id(), null, $this->competing_writer( SyncOutcome::UNCHANGED ) );

		$this->assertSame( self::PDF, $responder->body );
		$requests = $this->http->requests_to( self::DOWNLOAD_ROUTE );
		$this->assertCount( 1, $requests );
		$this->assertSame( self::LOOKUP_CODE, $requests[0]['body']['MaTraCuu'] );
		$this->assertSame( $alerts, did_action( DraftInvoiceWorkflow::NEEDS_ATTENTION_ACTION ) );
	}

	public function test_unconfirmed_recheck_refuses_even_valid_stored_state(): void {
		$customer = $this->customer();
		$order    = $this->issued_order( $customer );

		$sink = $this->start_monitoring();

		$responder = $this->download( $customer, $order->get_id(), null, $this->competing_writer( SyncOutcome::UNCONFIRMED ) );

		$this->stop_monitoring();
		$this->assert_download_failure_logged( $sink, $order->get_id(), 'recheck_unconfirmed' );
		$this->assertNull( $responder->body );
		$this->assertSame( array(), $this->http->requests_to( self::DOWNLOAD_ROUTE ) );
		$this->assertSame( array( self::GENERIC_ERROR ), $this->error_notices() );
		$this->assertSame( self::LOOKUP_CODE, $this->invoice_of( $order )?->lookup_code );
	}

	public function test_served_headers(): void {
		$customer = $this->customer();
		$order    = $this->issued_order( $customer );
		$this->fake_lookup_rows( array( $this->issued_row( array( 'maTraCuu' => self::LOOKUP_CODE ) ) ) );

		$headers = (array) $this->download( $customer, $order->get_id() )->headers;

		$this->assertSame( 'application/pdf', $headers['Content-Type'] ?? null );
		$this->assertSame( 'attachment; filename="hoa-don-1C26TAT-125.pdf"', $headers['Content-Disposition'] ?? null );
		$this->assertSame( 'nosniff', $headers['X-Content-Type-Options'] ?? null );
		$this->assertSame( (string) strlen( self::PDF ), (string) ( $headers['Content-Length'] ?? '' ) );
		$this->assertMatchesRegularExpression( '/no-store|no-cache/', (string) ( $headers['Cache-Control'] ?? '' ) );
	}

	public function test_buffers_cleaned_before_emit(): void {
		$customer = $this->customer();
		$order    = $this->issued_order( $customer );
		$this->fake_lookup_rows( array( $this->issued_row( array( 'maTraCuu' => self::LOOKUP_CODE ) ) ) );

		$responder = $this->download( $customer, $order->get_id() );

		$this->assertSame( 1, $responder->buffer_cleanings );
		$this->assertSame( 1, $responder->compression_disablings );
	}

	public function test_file_name_without_invoice_number(): void {
		$customer = $this->customer();
		$order    = $this->issued_order( $customer, array( 'invoice_number' => null ) );
		$row      = $this->issued_row(
			array(
				'maTraCuu' => self::LOOKUP_CODE,
				'shDon'    => null,
			)
		);
		$this->fake_lookup_rows( array( $row ) );

		$this->assertSame( 'hoa-don-1C26TAT.pdf', $this->filename_of( $this->download( $customer, $order->get_id() ) ) );
	}

	public function test_unsafe_file_name(): void {
		$customer = $this->customer();
		$order    = $this->issued_order( $customer, array( 'template_series' => 'C26"/Ấ' ) );
		$this->fake_lookup_rows( array( $this->issued_row( array( 'maTraCuu' => self::LOOKUP_CODE ) ) ) );

		$filename = $this->filename_of( $this->download( $customer, $order->get_id() ) );

		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9-]+\.pdf$/', $filename );
		$this->assertSame( 'hoa-don-1C26-125.pdf', $filename );
	}

	public function test_not_a_pdf_logs_warning(): void {
		$customer = $this->customer();
		$order    = $this->issued_order( $customer );
		$this->fake_lookup_rows( array( $this->issued_row( array( 'maTraCuu' => self::LOOKUP_CODE ) ) ) );
		$body = (string) wp_json_encode( $this->download_envelope( base64_encode( 'hello' ) ) );
		$this->http->respond_raw( self::DOWNLOAD_ROUTE, 200, $body );
		$sink = SentrySink::install( $this->http );
		$this->enable_monitoring();
		Monitor::start_transaction( 'customer download', 'test' );

		$responder = $this->download( $customer, $order->get_id() );

		Monitor::finish_transaction();
		Monitor::flush();
		$this->assertNull( $responder->body );
		$this->assertSame( array( self::GENERIC_ERROR ), $this->error_notices() );
		$this->assertNotSame( array(), $this->warnings( $sink ) );
		foreach ( $sink->raw_bodies() as $raw ) {
			$this->assertStringNotContainsString( $body, $raw );
			$this->assertStringNotContainsString( base64_encode( 'hello' ), $raw );
		}
	}

	public function test_nothing_sensitive_logged(): void {
		$customer = $this->customer();
		$order    = $this->issued_order( $customer );
		$this->fake_lookup_rows( array( $this->issued_row( array( 'maTraCuu' => self::LOOKUP_CODE ) ) ) );
		$rejection = (string) wp_json_encode(
			array(
				'errorCode' => 301,
				'message'   => 'REJECTION-BODY-MARKER',
				'data'      => null,
				'success'   => false,
			)
		);
		$sink      = SentrySink::install( $this->http );
		$this->enable_monitoring();
		Monitor::start_transaction( 'customer download', 'test' );

		$served = $this->download( $customer, $order->get_id() );
		wc_clear_notices();
		$this->http->respond_raw( self::DOWNLOAD_ROUTE, 200, $rejection );
		$rejected = $this->download( $customer, $order->get_id() );

		Monitor::finish_transaction();
		Monitor::flush();
		$this->assertSame( self::PDF, $served->body );
		$this->assertNull( $rejected->body );
		$this->assertTrue( $this->has_download_span( $sink ) );
		$this->assertNotSame( array(), $this->warnings( $sink ) );
		$this->assertNotSame( array(), $sink->raw_bodies() );
		foreach ( $sink->raw_bodies() as $raw ) {
			foreach ( array( '%PDF-', base64_encode( self::PDF ), self::LOOKUP_CODE, $rejection, 'REJECTION-BODY-MARKER' ) as $forbidden ) {
				$this->assertStringNotContainsString( $forbidden, $raw );
			}
		}
	}

	public function test_nothing_stored(): void {
		global $wpdb;

		$customer = $this->customer();
		$order    = $this->issued_order( $customer );
		$this->fake_lookup_rows( array( $this->issued_row( array( 'maTraCuu' => self::LOOKUP_CODE ) ) ) );
		$uploads   = wp_upload_dir()['basedir'];
		$files_up  = $this->files_in( $uploads );
		$files_tmp = $this->files_in( get_temp_dir() );

		$responder = $this->download( $customer, $order->get_id() );

		$this->assertSame( self::PDF, $responder->body );
		$this->assertSame( $files_up, $this->files_in( $uploads ) );
		$this->assertSame( $files_tmp, $this->files_in( get_temp_dir() ) );
		foreach ( array( '%PDF-', base64_encode( self::PDF ) ) as $needle ) {
			$like = '%' . $wpdb->esc_like( $needle ) . '%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- checking raw storage on purpose.
			$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE option_value LIKE %s', $wpdb->options, $like ) ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- checking raw storage on purpose.
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d', Schema::invoice_table(), $order->get_id() ), ARRAY_A );
			$this->assertIsArray( $row );
			foreach ( $row as $column => $value ) {
				$this->assertStringNotContainsString( $needle, (string) $value, $column );
			}
		}
	}

	public function test_download_check_during_pause(): void {
		$customer = $this->customer();
		$order    = $this->issued_order( $customer );
		$this->pause_with_retry();
		$this->fake_lookup_rows( array( $this->issued_row( array( 'maTraCuu' => self::LOOKUP_CODE ) ) ) );

		$responder = $this->download( $customer, $order->get_id() );

		$this->assertSame( self::PDF, $responder->body );
		$this->assertFalse( get_option( StatusSyncBackoff::OPTION ) );
		$this->assertFalse( ( new StatusSyncBackoff() )->is_paused() );
		$this->assertSame( array(), $this->pending_retries() );
	}

	public function test_download_check_fails_keeps_backoff(): void {
		$customer = $this->customer();
		$order    = $this->issued_order( $customer );
		$this->pause_with_retry();
		$state   = get_option( StatusSyncBackoff::OPTION );
		$retries = $this->pending_retries();
		$this->http->respond_json( self::LOOKUP_ROUTE, 503, array( 'message' => 'failure' ) );

		$this->assert_rejected_after_lookup( $this->download( $customer, $order->get_id() ) );
		$this->assertSame( $state, get_option( StatusSyncBackoff::OPTION ) );
		$this->assertSame( $retries, $this->pending_retries() );
	}

	public function test_issued_invoice_rechecked(): void {
		$customer = $this->customer();
		$order    = $this->issued_order( $customer );
		$alerts   = did_action( DraftInvoiceWorkflow::NEEDS_ATTENTION_ACTION );
		$this->fake_lookup_rows(
			array(
				$this->issued_row(
					array(
						'maLoaiHDon' => 5,
						'maTraCuu'   => self::LOOKUP_CODE,
					)
				),
			)
		);

		$this->download( $customer, $order->get_id() );

		$invoice = $this->invoice_of( $order );
		$this->assertSame( 5, $invoice?->matbao_invoice_type );
		$this->assertSame( $alerts, did_action( DraftInvoiceWorkflow::NEEDS_ATTENTION_ACTION ) );
		$due = ( new InvoiceRepository() )->due_for_status_sync( 200, '2000-01-01' );
		$this->assertNotContains( $invoice?->id, array_map( static fn( InvoiceRecord $r ): int => $r->id, $due ) );
	}

	public function test_error_redirects_to_clean_url(): void {
		$customer = $this->customer();
		$order    = $this->issued_order( $customer );
		$this->http->respond_json( self::LOOKUP_ROUTE, 503, array( 'message' => 'failure' ) );

		$this->download( $customer, $order->get_id() );

		$this->assert_redirect_to_order( $order->get_id() );
		$this->assertStringNotContainsString( InvoicePdfDownload::QUERY_ARG, (string) $this->redirected_to );
		$this->assertStringNotContainsString( '_wpnonce', (string) $this->redirected_to );
	}

	public function test_register_hooks_template_redirect(): void {
		remove_all_actions( 'template_redirect' );

		InvoicePdfDownload::register();

		$this->assertTrue( has_action( 'template_redirect' ) );
	}

	public function test_view_order_without_query_arg_does_nothing(): void {
		$order = $this->issued_order( $this->customer() );

		$this->run_template_redirect( $this->customer(), array( 'view-order' => (string) $order->get_id() ), $order->get_id(), false );

		$this->assert_handler_not_run();
	}

	/**
	 * Query contexts that are not the view-order endpoint.
	 *
	 * @return array<string, array{0: string|null}>
	 */
	public static function other_endpoints(): array {
		return array(
			'order-received' => array( 'order-received' ),
			'no endpoint'    => array( null ),
		);
	}

	/**
	 * The query arg outside view-order never runs the handler.
	 *
	 * @dataProvider other_endpoints
	 *
	 * @param string|null $endpoint Query var set instead of `view-order`, or null for none.
	 */
	public function test_query_arg_outside_view_order_does_nothing( ?string $endpoint ): void {
		$order = $this->issued_order( $this->customer() );
		$vars  = null === $endpoint ? array() : array( $endpoint => (string) $order->get_id() );

		$this->run_template_redirect( $this->customer(), $vars, $order->get_id(), true );

		$this->assert_handler_not_run();
	}

	public function test_view_order_with_query_arg_runs_handler_for_query_var_order(): void {
		$order = $this->issued_order( $this->customer() );

		// A non-owner with a valid nonce: the production responder redirects, observed via wp_redirect.
		$this->run_template_redirect( $this->customer(), array( 'view-order' => (string) $order->get_id() ), $order->get_id(), true );

		$this->assertSame( array( self::GENERIC_ERROR ), $this->error_notices() );
		$this->assert_redirect_to_order( $order->get_id() );
		$this->assertSame( $this->requests_before, count( $this->http->raw_requests() ) );
	}

	/**
	 * Fires `template_redirect` with only this plugin's callback, as `$viewer`, with the given
	 * WooCommerce query vars and a valid nonce for `$order_id`. The production responder is used, so
	 * the redirect filter throws ResponseFinished to stop before `exit`.
	 *
	 * @param WP_User               $viewer    Logged-in viewer.
	 * @param array<string, string> $vars      Query vars of the request.
	 * @param int                   $order_id  Order the nonce is for.
	 * @param bool                  $with_arg  Whether the download query arg is present.
	 */
	private function run_template_redirect( WP_User $viewer, array $vars, int $order_id, bool $with_arg ): void {
		global $wp, $wp_query;

		wp_set_current_user( $viewer->ID );
		$_GET = array( '_wpnonce' => wp_create_nonce( InvoicePdfDownload::NONCE_ACTION_PREFIX . $order_id ) );
		if ( $with_arg ) {
			$_GET[ InvoicePdfDownload::QUERY_ARG ] = '1';
		}
		$saved_wp_vars         = $wp->query_vars;
		$wp->query_vars        = $vars;
		$wp_query->query_vars  = array_merge( $wp_query->query_vars, $vars );
		$this->redirected_to   = null;
		$this->requests_before = count( $this->http->raw_requests() );
		remove_all_actions( 'template_redirect' );
		InvoicePdfDownload::register();
		$stop = static function (): never {
			throw new ResponseFinished();
		};
		add_filter( 'wp_redirect', $stop, 20 );

		try {
			do_action( 'template_redirect' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
		} catch ( ResponseFinished ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- the response ended like `exit`.
		} finally {
			remove_filter( 'wp_redirect', $stop, 20 );
			$wp->query_vars = $saved_wp_vars;
			$wp_query->init();
		}
	}

	/**
	 * Nothing happened: no Mat Bao request, no notice, no redirect.
	 */
	private function assert_handler_not_run(): void {
		$this->assertSame( $this->requests_before, count( $this->http->raw_requests() ) );
		$this->assertSame( array(), $this->error_notices() );
		$this->assertNull( $this->redirected_to );
	}

	/**
	 * Installs the fake Sentry sink, enables monitoring and starts a transaction.
	 */
	private function start_monitoring(): SentrySink {
		$sink = SentrySink::install( $this->http );
		$this->enable_monitoring();
		Monitor::start_transaction( 'customer download', 'test' );

		return $sink;
	}

	/**
	 * Finishes the transaction and sends everything to the sink.
	 */
	private function stop_monitoring(): void {
		Monitor::finish_transaction();
		Monitor::flush();
	}

	/**
	 * One download-failure warning for the order with the reason, and nothing sensitive sent.
	 *
	 * @param SentrySink $sink     Fake Sentry endpoint.
	 * @param int        $order_id Order ID.
	 * @param string     $reason   Expected `reason` attribute.
	 */
	private function assert_download_failure_logged( SentrySink $sink, int $order_id, string $reason ): void {
		$logs = array_values( array_filter( $this->warnings( $sink ), static fn( array $log ): bool => 'customer invoice download failed' === $log['body'] ) );
		$this->assertCount( 1, $logs );
		$this->assertSame( $order_id, $logs[0]['attributes']['order_id']['value'] ?? null );
		$this->assertSame( $reason, $logs[0]['attributes']['reason']['value'] ?? null );
		foreach ( $sink->raw_bodies() as $raw ) {
			foreach ( array( self::LOOKUP_CODE, 'LOOKUP-FAILURE-BODY', '%PDF-', base64_encode( self::PDF ) ) as $forbidden ) {
				$this->assertStringNotContainsString( $forbidden, $raw );
			}
		}
	}

	/**
	 * Runs the handler as `$viewer` for the order, the way the view-order request would.
	 *
	 * @param WP_User|null      $viewer   Logged-in viewer, or null for a visitor.
	 * @param int               $order_id Order ID from the URL.
	 * @param string|false|null $nonce    Nonce; null for a valid one, false for none.
	 * @param \Closure|null     $recheck  Recheck override.
	 */
	private function download( ?WP_User $viewer, int $order_id, string|false|null $nonce = null, ?\Closure $recheck = null ): RecordingResponder {
		wp_set_current_user( $viewer ? $viewer->ID : 0 );
		$_GET = array( InvoicePdfDownload::QUERY_ARG => '1' );
		if ( false !== $nonce ) {
			$_GET['_wpnonce'] = $nonce ?? wp_create_nonce( InvoicePdfDownload::NONCE_ACTION_PREFIX . $order_id );
		}
		$this->redirected_to   = null;
		$this->requests_before = count( $this->http->raw_requests() );
		$responder             = new RecordingResponder();

		try {
			( new InvoicePdfDownload( responder: $responder, recheck: $recheck ) )->handle( $order_id );
		} catch ( ResponseFinished ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- the response ended like `exit`.
		}

		return $responder;
	}

	/**
	 * Recheck that loses nothing but, like a competing writer that won the race, stores the issued
	 * snapshot with the lookup code before returning `$outcome`.
	 *
	 * @param SyncOutcome $outcome Outcome to report.
	 */
	private function competing_writer( SyncOutcome $outcome ): \Closure {
		return static function ( InvoiceRecord $read ) use ( $outcome ): SyncOutcome {
			( new InvoiceRepository() )->record_matbao_status( $read, new MatbaoSnapshot( 4, 'Đã cấp mã', 125, null, 2, null, self::LOOKUP_CODE ) );

			return $outcome;
		};
	}

	/**
	 * Refused with the generic error, no file and no Mat Bao request at all.
	 *
	 * @param RecordingResponder $responder Response of the download.
	 */
	private function assert_rejected_without_matbao( RecordingResponder $responder ): void {
		$this->assertNull( $responder->body );
		$this->assertSame( $this->requests_before, count( $this->http->raw_requests() ) );
		$this->assertSame( array( self::GENERIC_ERROR ), $this->error_notices() );
		$this->assertNotNull( $this->redirected_to );
		wc_clear_notices();
	}

	/**
	 * Refused with the generic error after a lookup, without a download request.
	 *
	 * @param RecordingResponder $responder Response of the download.
	 */
	private function assert_rejected_after_lookup( RecordingResponder $responder ): void {
		$this->assertNull( $responder->body );
		$this->assertCount( 1, $this->http->requests_to( self::LOOKUP_ROUTE ) );
		$this->assertSame( array(), $this->http->requests_to( self::DOWNLOAD_ROUTE ) );
		$this->assertSame( array( self::GENERIC_ERROR ), $this->error_notices() );
	}

	/**
	 * The redirect went to the clean view-order page of the order.
	 *
	 * @param int $order_id Order ID.
	 */
	private function assert_redirect_to_order( int $order_id ): void {
		$this->assertSame( wc_get_endpoint_url( 'view-order', (string) $order_id, wc_get_page_permalink( 'myaccount' ) ), $this->redirected_to );
	}

	/**
	 * Texts of the queued error notices.
	 *
	 * @return list<string>
	 */
	private function error_notices(): array {
		return array_values( array_map( static fn( array $notice ): string => (string) $notice['notice'], wc_get_notices( 'error' ) ) );
	}

	/**
	 * File name from the recorded `Content-Disposition` header.
	 *
	 * @param RecordingResponder $responder Response of the download.
	 */
	private function filename_of( RecordingResponder $responder ): string {
		$disposition = (string) ( $responder->headers['Content-Disposition'] ?? '' );
		$this->assertSame( 1, preg_match( '/filename="([^"]*)"$/', $disposition, $match ), $disposition );

		return $match[1];
	}

	/**
	 * Warning logs received by the sink.
	 *
	 * @param SentrySink $sink Fake Sentry endpoint.
	 * @return list<array<string, mixed>>
	 */
	private function warnings( SentrySink $sink ): array {
		return array_values( array_filter( $sink->logs(), static fn( array $log ): bool => 'warn' === $log['level'] ) );
	}

	/**
	 * Whether a transaction holds the `http.client` span of the download call.
	 *
	 * @param SentrySink $sink Fake Sentry endpoint.
	 */
	private function has_download_span( SentrySink $sink ): bool {
		foreach ( $sink->transactions() as $transaction ) {
			foreach ( (array) ( $transaction['spans'] ?? array() ) as $span ) {
				if ( 'http.client' === ( $span['op'] ?? null ) && self::DOWNLOAD_ROUTE === ( $span['description'] ?? null ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Every file below a directory.
	 *
	 * @param string $dir Directory.
	 * @return list<string>
	 */
	private function files_in( string $dir ): array {
		if ( ! is_dir( $dir ) ) {
			return array();
		}
		$files = array();
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			$files[] = (string) $file;
		}
		sort( $files );

		return $files;
	}

	/**
	 * Pauses the automatic sync and queues its single retry.
	 */
	private function pause_with_retry(): void {
		$until = time() + 600;
		update_option(
			StatusSyncBackoff::OPTION,
			array(
				'failures' => 2,
				'until'    => $until,
			),
			false
		);
		as_schedule_single_action( $until, StatusSyncBackoff::RETRY_ACTION, array(), DraftInvoiceWorkflow::GROUP );
		$this->assertTrue( ( new StatusSyncBackoff() )->is_paused() );
		$this->assertCount( 1, $this->pending_retries() );
	}

	/**
	 * IDs of the pending retry actions.
	 *
	 * @return list<int>
	 */
	private function pending_retries(): array {
		$ids = as_get_scheduled_actions(
			array(
				'hook'   => StatusSyncBackoff::RETRY_ACTION,
				'group'  => DraftInvoiceWorkflow::GROUP,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			),
			'ids'
		);

		return array_map( 'intval', array_values( $ids ) );
	}

	/**
	 * A customer user.
	 */
	private function customer(): WP_User {
		$user = get_user_by( 'id', self::factory()->user->create( array( 'role' => 'customer' ) ) );
		$this->assertInstanceOf( WP_User::class, $user );

		return $user;
	}

	/**
	 * An order of the customer with an issued invoice: Mat Bao status 4, type 2, ID `abc`,
	 * number 125, template `1C26TAT`, no lookup code yet. `$columns` overrides invoice columns.
	 *
	 * @param WP_User              $customer Owner.
	 * @param array<string, mixed> $columns  Invoice column overrides.
	 */
	private function issued_order( WP_User $customer, array $columns = array() ): WC_Order {
		global $wpdb;

		$order = $this->create_order( array( array( $this->create_product( 'Sản phẩm kiểm thử', '100000' ), 1 ) ) );
		$order->set_customer_id( $customer->ID );
		$order->save();
		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test seeds the invoice row directly.
		$wpdb->insert(
			Schema::invoice_table(),
			array_merge(
				array(
					'order_id'            => $order->get_id(),
					'reference'           => self::REFERENCE,
					'status'              => InvoiceStatus::DRAFT_CREATED,
					'matbao_invoice_id'   => self::INVOICE_ID,
					'matbao_status'       => 4,
					'matbao_invoice_type' => 2,
					'invoice_number'      => 125,
					'template_form'       => self::TEMPLATE_FORM,
					'template_series'     => self::TEMPLATE_SERIES,
					'issued_on'           => gmdate( 'Y-m-d' ),
					'created_at'          => $now,
					'updated_at'          => $now,
				),
				$columns
			)
		);

		return $order;
	}

	/**
	 * Lookup row overrides of the issued invoice.
	 *
	 * @param array<string, mixed> $overrides Extra fields.
	 * @return array<string, mixed>
	 */
	private function issued_row( array $overrides = array() ): array {
		return array_merge(
			array(
				'maSoHDon'     => self::INVOICE_ID,
				'shDon'        => 125,
				'maTThaiHDon'  => 4,
				'tenTThaiHDon' => 'Đã cấp mã',
				'maLoaiHDon'   => 2,
				'tenLoaiHDon'  => 'Hóa đơn mới',
			),
			$overrides
		);
	}

	/**
	 * A complete lookup row of the issued invoice's reference and template.
	 *
	 * @param array<string, mixed> $row Row fields.
	 * @return array<string, mixed>
	 */
	private function full_row( array $row ): array {
		return array_merge(
			array(
				'khmshDon' => self::TEMPLATE_FORM,
				'khhDon'   => self::TEMPLATE_SERIES,
				'nLap'     => '2026-10-14T00:00:00',
				'mtChieu'  => self::REFERENCE,
			),
			$row
		);
	}

	/**
	 * Successful lookup envelope.
	 *
	 * @param list<array<string, mixed>> $rows Rows.
	 * @return array<string, mixed>
	 */
	private function lookup_envelope( array $rows ): array {
		return array(
			'errorCode' => 200,
			'message'   => 'Thành công',
			'data'      => array(
				'invoices' => $rows,
				'notFound' => array(),
			),
			'success'   => true,
		);
	}

	/**
	 * Successful download envelope.
	 *
	 * @param string $base64 `data_PDF_Base64`.
	 * @return array<string, mixed>
	 */
	private function download_envelope( string $base64 ): array {
		return array(
			'errorCode' => 200,
			'message'   => 'Thành công',
			'data'      => array(
				'data_PDF_Base64' => $base64,
				'data_XML_Base64' => base64_encode( '<xml/>' ),
			),
			'success'   => true,
		);
	}

	/**
	 * Makes download-invoice return the test PDF.
	 */
	private function fake_download_ok(): void {
		$this->http->respond_json( self::DOWNLOAD_ROUTE, 200, $this->download_envelope( base64_encode( self::PDF ) ) );
	}
}
