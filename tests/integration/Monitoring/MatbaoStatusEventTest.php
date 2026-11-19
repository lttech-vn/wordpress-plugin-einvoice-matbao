<?php
/**
 * Monitoring output of the Mat Bao status sync.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration\Monitoring;

use LTTech\EinvoiceMatbao\Alert\InvoiceAlerts;
use LTTech\EinvoiceMatbao\Invoice\InvoiceRepository;
use LTTech\EinvoiceMatbao\Invoice\InvoiceStatus;
use LTTech\EinvoiceMatbao\Invoice\MatbaoStatusSync;
use LTTech\EinvoiceMatbao\Monitoring\Monitor;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\FakeHttp;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\InvoiceWorkflowTestCase;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\MonitoringFixture;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\SentrySink;

/**
 * The Sentry endpoint is faked at the HTTP boundary.
 */
final class MatbaoStatusEventTest extends InvoiceWorkflowTestCase {

	use MonitoringFixture;

	/**
	 * Fake Sentry endpoint.
	 *
	 * @var SentrySink
	 */
	private SentrySink $sink;

	public function set_up(): void {
		parent::set_up();
		InvoiceAlerts::register();
		$this->configure_matbao();
		$this->sink = SentrySink::install( $this->http );
		$this->enable_monitoring();
	}

	public function tear_down(): void {
		$this->reset_monitoring();
		parent::tear_down();
	}

	public function test_sign_error_event(): void {
		$order_id = $this->draft();
		$this->http->respond_with(
			self::LOOKUP_ROUTE,
			static function () use ( $order_id ): array {
				return FakeHttp::json_response(
					200,
					array(
						'errorCode' => 200,
						'data'      => array(
							'invoices' => array(
								array(
									'maSoHDon'     => 'MB' . $order_id,
									'mtChieu'      => 'WC' . $order_id,
									'khmshDon'     => '1',
									'khhDon'       => 'C26TAT',
									'maTThaiHDon'  => 2,
									'tenTThaiHDon' => 'Lỗi ký',
									'maLoaiHDon'   => 2,
									'ghiChuCQT'    => 'GhiChuBiMat',
								),
							),
							'notFound' => array(),
						),
						'success'   => true,
					)
				);
			}
		);

		MatbaoStatusSync::run();

		Monitor::flush();
		$warnings = array_values( array_filter( $this->sink->events(), static fn( array $event ): bool => 'warning' === ( $event['level'] ?? 'error' ) ) );
		$this->assertCount( 1, $warnings );
		$this->assertSame( 'matbao_sign_failed', $warnings[0]['tags']['error_code'] );
		$this->assertSame( (string) $order_id, (string) $warnings[0]['tags']['order_id'] );
		foreach ( $this->sink->raw_bodies() as $body ) {
			$this->assertStringNotContainsString( 'GhiChuBiMat', $body );
		}
	}

	public function test_lookup_failure_logs_warning_only(): void {
		$this->draft();
		$this->http->respond_json( self::LOOKUP_ROUTE, 503, array( 'message' => 'down' ) );

		MatbaoStatusSync::run();

		Monitor::flush();
		$logs = array_values( array_filter( $this->sink->logs(), static fn( array $log ): bool => 'Mat Bao status lookup failed' === $log['body'] ) );
		$this->assertCount( 1, $logs );
		$this->assertSame( 'warn', $logs[0]['level'] );
		$this->assertSame( array(), $this->sink->events() );
	}

	private function draft(): int {
		global $wpdb;

		$order_id = 71001;
		$now      = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture row.
		$wpdb->insert(
			$wpdb->prefix . 'einvoice_matbao_invoice',
			array(
				'order_id'          => $order_id,
				'reference'         => 'WC' . $order_id,
				'status'            => InvoiceStatus::DRAFT_CREATED,
				'matbao_invoice_id' => 'MB' . $order_id,
				'template_form'     => self::TEMPLATE_FORM,
				'template_series'   => self::TEMPLATE_SERIES,
				'issued_on'         => gmdate( 'Y-m-d' ),
				'created_at'        => $now,
				'updated_at'        => $now,
			)
		);
		$this->assertNotNull( ( new InvoiceRepository() )->find( (int) $wpdb->insert_id ) );

		return $order_id;
	}
}
