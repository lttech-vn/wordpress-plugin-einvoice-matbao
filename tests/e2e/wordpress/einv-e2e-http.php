<?php
/**
 * Plugin Name: E-Invoice Mat Bao E2E HTTP guard
 * Description: E2E only (copied into mu-plugins by tests/e2e/setup-site.sh). Never ship.
 *
 * Every mode: a Mat Bao request to any host other than the demo API is refused before it leaves.
 * Mode `fake` (option `einv_e2e_mode`, the CI default): the demo API is answered locally, so no
 * request reaches Mat Bao. Mode `demo`: requests go to the demo API unchanged.
 *
 * @package EinvoiceMatbao
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- E2E fixture, functions are prefixed einv_e2e_.

const EINV_E2E_DEMO_HOST = 'demo-api-hddt.matbao.in';

add_filter(
	'pre_http_request',
	static function ( $preempt, array $args, string $url ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( ! str_contains( $host, 'matbao' ) ) {
			return $preempt;
		}
		if ( EINV_E2E_DEMO_HOST !== $host || 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
			return new WP_Error( 'einv_e2e_blocked', 'E2E: only the Mat Bao demo API is allowed.' );
		}
		if ( 'fake' !== get_option( 'einv_e2e_mode', 'fake' ) ) {
			return $preempt;
		}

		return einv_e2e_fake_matbao( strtoupper( (string) ( $args['method'] ?? 'GET' ) ), (string) wp_parse_url( $url, PHP_URL_PATH ), $args );
	},
	1,
	3
);

/**
 * Local answers in the shapes recorded on the demo API (docs/research/e2e-demo-results.md).
 *
 * @param string               $method HTTP method.
 * @param string               $path   URL path.
 * @param array<string, mixed> $args   Request arguments.
 * @return array<string, mixed>
 */
function einv_e2e_fake_matbao( string $method, string $path, array $args ): array {
	$body = json_decode( (string) ( $args['body'] ?? '' ), true );
	$ok   = static fn( $data ): array => array(
		'errorCode' => 200,
		'message'   => 'Thành công',
		'data'      => $data,
		'success'   => true,
	);

	$route = $method . ' ' . $path;
	if ( 'POST /api/auth/login' === $route ) {
		$json = $ok(
			array(
				'accessToken' => 'e2e-fake-token',
				'expiredDate' => ( new DateTimeImmutable( '+1 day', new DateTimeZone( 'Asia/Ho_Chi_Minh' ) ) )->format( 'Y-m-d\TH:i:s' ),
			)
		);
	} elseif ( 'GET /api/invoice/templates' === $route ) {
		$json = $ok(
			array(
				array(
					'thDon'    => 'Hóa đơn giá trị gia tăng',
					'khmshDon' => '1',
					'khhDon'   => 'C' . gmdate( 'y' ) . 'TAT',
					'sLuong'   => 100000,
					'cLai'     => 50000,
				),
			)
		);
	} elseif ( 'POST /api/invoice/create-invoice' === $route ) {
		$invoice = is_array( $body ) && is_array( $body[0] ?? null ) ? $body[0] : array();
		$json    = $ok(
			array(
				array(
					'errorCode' => 200,
					'message'   => 'Thành công',
					'data'      => array(
						'khmshDon' => $invoice['KHMSHDon'] ?? '',
						'khhDon'   => $invoice['KHHDon'] ?? '',
						'mtChieu'  => $invoice['MTChieu'] ?? '',
						'maTraCuu' => 'E2EFAKE',
						'shDon'    => 0,
						'maSoHDon' => base64_encode( 'e2e-fake-' . ( $invoice['MTChieu'] ?? '' ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- fake ID.
						'nLap'     => $invoice['NLap'] ?? '',
					),
					'success'   => true,
				),
			)
		);
		update_option(
			'einv_e2e_last_create',
			wp_json_encode(
				array(
					'MTChieu'  => $invoice['MTChieu'] ?? '',
					'NMua_Ten' => $invoice['NMua_Ten'] ?? '',
				)
			),
			false
		);
	} elseif ( 'POST /api/invoice/invoice-detail-batch' === $route ) {
		$json = array(
			'errorCode' => 301,
			'message'   => 'Không tìm thấy hóa đơn',
			'data'      => array(
				'invoices' => array(),
				'notFound' => is_array( $body ) ? ( $body['danhSach'] ?? array() ) : array(),
			),
			'success'   => false,
		);
	} else {
		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => 404,
				'message' => 'Not Found',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	return array(
		'headers'  => array( 'content-type' => 'application/json' ),
		'body'     => (string) wp_json_encode( $json ),
		'response' => array(
			'code'    => 200,
			'message' => 'OK',
		),
		'cookies'  => array(),
		'filename' => null,
	);
}

/**
 * E2E only: completes an order and runs the due Action Scheduler actions, so the browser suite does not
 * have to drive the admin status dropdown. Refused unless the E2E mode option exists (set by configure.php).
 */
add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'einv-e2e/v1',
			'/complete/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'permission_callback' => static fn(): bool => false !== get_option( 'einv_e2e_mode', false ),
				'callback'            => static function ( WP_REST_Request $request ): array {
					$order = wc_get_order( (int) $request['id'] );
					if ( ! $order ) {
						return array( 'completed' => false );
					}
					$order->update_status( 'completed' );
					if ( class_exists( 'ActionScheduler_QueueRunner' ) ) {
						ActionScheduler_QueueRunner::instance()->run( 'E2E' );
					}
					return array( 'completed' => true );
				},
			)
		);
	}
);
