<?php
/**
 * Fake Mat Bao e-invoice API for the browser E2E suite.
 *
 * Run inside the WordPress container (tests/e2e/setup-site.sh): `php -S 127.0.0.1:8099 router.php`. The plugin
 * reaches it through the EINVOICE_MATBAO_BASE_URL constant; the test runner shares the container's network
 * namespace and steers it through /__control/*. Shapes follow docs/research/e2e-demo-results.md, including the
 * non-atomic duplicate check of the demo. State lives in one JSON file guarded by flock (the server may use
 * several workers). Synthetic data only; never ships in the plugin zip.
 *
 * Control (JSON in, JSON out):
 *   POST /__control/reset                 back to defaults, empty log
 *   POST /__control/config   {login_fail?: bool, templates?: list|null, lookup_total?: bool}
 *   POST /__control/script   {endpoint: "create-invoice"|"invoice-detail-batch"|"templates"|"login", steps: [Step]}
 *        Step: {http?: int, unreadable?: true, item_error?: {errorCode:int,message:string}, store?: true}
 *        One step is consumed per call, in order; `store` keeps the invoice even though the answer is an error
 *        (the lost-response case). Without a step the call is answered normally.
 *   POST /__control/delete   {mtChieu}    removes the draft from Mat Bao
 *   POST /__control/issue    {mtChieu}    marks it issued (no longer a draft)
 *   GET  /__control/requests[?endpoint=]  logged calls, passwords redacted
 *   GET  /__control/invoices              stored invoices
 *
 * @package EinvoiceMatbao
 */

// phpcs:ignoreFile -- E2E fixture, not plugin code.

declare(strict_types=1);

const EINV_FAKE_STATE = '/tmp/einv-fake-matbao.json';

/** @return array<string, mixed> */
function einv_fake_default(): array {
	return array(
		'login_fail'   => false,
		'templates'    => null,
		'lookup_total' => false,
		'script'       => array(),
		'invoices'     => array(),
		'requests'     => array(),
		'seq'          => 0,
	);
}

/**
 * Runs $fn on the state under an exclusive lock and saves what it leaves behind.
 *
 * @param callable(array<string, mixed>&): mixed $fn Mutator.
 */
function einv_fake_with_state( callable $fn ): mixed {
	$handle = fopen( EINV_FAKE_STATE, 'c+' );
	flock( $handle, LOCK_EX );
	$raw   = stream_get_contents( $handle );
	$state = '' === $raw || false === $raw ? einv_fake_default() : ( json_decode( $raw, true ) ?: einv_fake_default() );
	$out   = $fn( $state );
	ftruncate( $handle, 0 );
	rewind( $handle );
	fwrite( $handle, (string) json_encode( $state, JSON_UNESCAPED_UNICODE ) );
	fflush( $handle );
	flock( $handle, LOCK_UN );
	fclose( $handle );

	return $out;
}

function einv_fake_json( int $status, mixed $body ): never {
	http_response_code( $status );
	header( 'Content-Type: application/json' );
	echo json_encode( $body, JSON_UNESCAPED_UNICODE );
	exit;
}

function einv_fake_envelope( bool $success, int $code, string $message, mixed $data ): array {
	return array(
		'errorCode' => $code,
		'message'   => $message,
		'data'      => $data,
		'success'   => $success,
	);
}

function einv_fake_ok( mixed $data ): never {
	einv_fake_json( 200, einv_fake_envelope( true, 200, 'Thành công', $data ) );
}

$method = strtoupper( $_SERVER['REQUEST_METHOD'] ?? 'GET' );
$path   = (string) parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );
$raw    = (string) file_get_contents( 'php://input' );
$body   = json_decode( $raw, true );
$body   = is_array( $body ) ? $body : array();

// ---- Control plane -------------------------------------------------------------------------------------------
if ( str_starts_with( $path, '/__control/' ) ) {
	$action = substr( $path, strlen( '/__control/' ) );
	switch ( $action ) {
		case 'reset':
			einv_fake_with_state(
				static function ( array &$s ): void {
					$s = einv_fake_default();
				}
			);
			einv_fake_json( 200, array( 'ok' => true ) );
		case 'config':
			einv_fake_with_state(
				static function ( array &$s ) use ( $body ): void {
					foreach ( array( 'login_fail', 'templates', 'lookup_total' ) as $key ) {
						if ( array_key_exists( $key, $body ) ) {
							$s[ $key ] = $body[ $key ];
						}
					}
				}
			);
			einv_fake_json( 200, array( 'ok' => true ) );
		case 'script':
			$endpoint = (string) ( $body['endpoint'] ?? '' );
			einv_fake_with_state(
				static function ( array &$s ) use ( $endpoint, $body ): void {
					$s['script'][ $endpoint ] = array_merge( $s['script'][ $endpoint ] ?? array(), $body['steps'] ?? array() );
				}
			);
			einv_fake_json( 200, array( 'ok' => true ) );
		case 'delete':
		case 'issue':
			$reference = (string) ( $body['mtChieu'] ?? '' );
			$count     = einv_fake_with_state(
				static function ( array &$s ) use ( $action, $reference ): int {
					$n = 0;
					foreach ( $s['invoices'] as $key => &$invoice ) {
						if ( $invoice['mtChieu'] !== $reference ) {
							continue;
						}
						++$n;
						if ( 'delete' === $action ) {
							unset( $s['invoices'][ $key ] );
						} else {
							$invoice['maTThaiHDon']  = 4;
							$invoice['tenTThaiHDon'] = 'Đã cấp mã';
						}
					}
					return $n;
				}
			);
			einv_fake_json( 200, array( 'affected' => $count ) );
		case 'requests':
			$endpoint = (string) ( $_GET['endpoint'] ?? '' );
			$log      = einv_fake_with_state( static fn( array &$s ): array => $s['requests'] );
			if ( '' !== $endpoint ) {
				$log = array_values( array_filter( $log, static fn( array $r ): bool => $r['endpoint'] === $endpoint ) );
			}
			einv_fake_json( 200, $log );
		case 'invoices':
			einv_fake_json( 200, array_values( einv_fake_with_state( static fn( array &$s ): array => $s['invoices'] ) ) );
	}
	einv_fake_json( 404, array( 'error' => 'unknown control action' ) );
}

// ---- API -----------------------------------------------------------------------------------------------------
if ( ! str_starts_with( $path, '/api/' ) ) {
	einv_fake_json( 404, einv_fake_envelope( false, 404, 'Not Found', null ) );
}

$endpoint = match ( $method . ' ' . $path ) {
	'POST /api/auth/login'                   => 'login',
	'GET /api/invoice/templates'             => 'templates',
	'POST /api/invoice/create-invoice'       => 'create-invoice',
	'POST /api/invoice/invoice-detail-batch' => 'invoice-detail-batch',
	default                                  => '',
};
if ( '' === $endpoint ) {
	einv_fake_json( 404, einv_fake_envelope( false, 404, 'Not Found', null ) );
}

$logged = $body;
if ( isset( $logged['MKhau'] ) ) {
	$logged['MKhau'] = '[REDACTED]';
}

/** Take the first scripted step of this endpoint and log the call. */
$step = einv_fake_with_state(
	static function ( array &$s ) use ( $endpoint, $logged ): ?array {
		++$s['seq'];
		$s['requests'][] = array(
			'seq'      => $s['seq'],
			'endpoint' => $endpoint,
			'body'     => $logged,
			'auth'     => isset( $_SERVER['HTTP_AUTHORIZATION'] ),
		);
		$queue = $s['script'][ $endpoint ] ?? array();
		$step  = array_shift( $queue );
		$s['script'][ $endpoint ] = $queue;

		return is_array( $step ) ? $step : null;
	}
);

if ( 'login' !== $endpoint && ! isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
	einv_fake_json( 401, einv_fake_envelope( false, 401, 'Unauthorized', null ) );
}

/** Answer that does not depend on the endpoint: scripted HTTP status or garbage body. */
$respond_scripted = static function ( ?array $step ): void {
	if ( null === $step ) {
		return;
	}
	if ( ! empty( $step['unreadable'] ) ) {
		http_response_code( 200 );
		header( 'Content-Type: text/html' );
		echo '<html>upstream hiccup</html>';
		exit;
	}
	if ( isset( $step['http'] ) ) {
		einv_fake_json( (int) $step['http'], einv_fake_envelope( false, (int) $step['http'], 'Scripted HTTP ' . (int) $step['http'], null ) );
	}
};

switch ( $endpoint ) {
	case 'login':
		$respond_scripted( $step );
		$fail = einv_fake_with_state( static fn( array &$s ): bool => (bool) $s['login_fail'] );
		if ( $fail ) {
			einv_fake_json( 200, einv_fake_envelope( false, 401, 'Sai thông tin đăng nhập', null ) );
		}
		einv_fake_ok(
			array(
				'accessToken' => 'e2e-fake-token',
				'expiredDate' => ( new DateTimeImmutable( '+1 day', new DateTimeZone( 'Asia/Ho_Chi_Minh' ) ) )->format( 'Y-m-d\TH:i:s' ),
			)
		);

	case 'templates':
		$respond_scripted( $step );
		$templates = einv_fake_with_state( static fn( array &$s ): mixed => $s['templates'] );
		if ( null === $templates ) {
			$templates = array(
				array(
					'thDon'    => 'Hóa đơn giá trị gia tăng',
					'khmshDon' => '1',
					'khhDon'   => 'C' . gmdate( 'y' ) . 'TAT',
					'sLuong'   => 100000,
					'cLai'     => 50000,
				),
			);
		}
		einv_fake_ok( $templates );

	case 'create-invoice':
		$respond_scripted( $step && ! isset( $step['store'] ) ? $step : null );
		$invoice   = is_array( $body[0] ?? null ) ? $body[0] : array();
		$reference = (string) ( $invoice['MTChieu'] ?? '' );
		$form      = (string) ( $invoice['KHMSHDon'] ?? '' );
		$series    = (string) ( $invoice['KHHDon'] ?? '' );

		$store = static function () use ( $invoice, $reference, $form, $series ): array {
			return einv_fake_with_state(
				static function ( array &$s ) use ( $invoice, $reference, $form, $series ): array {
					$record = array(
						'khmshDon'     => $form,
						'khhDon'       => $series,
						'mtChieu'      => $reference,
						'maTraCuu'     => strtoupper( substr( md5( $reference . $form . $series . $s['seq'] ), 0, 10 ) ),
						'maSoHDon'     => base64_encode( 'e2e-fake-' . $reference . '-' . $s['seq'] ),
						'shDon'        => 0,
						'nLap'         => (string) ( $invoice['NLap'] ?? '' ),
						'maTThaiHDon'  => 1,
						'tenTThaiHDon' => 'Nháp',
						'tgTTTBSo'     => $invoice['TgTTTBSo'] ?? null,
						'nMua_Ten'     => $invoice['NMua_Ten'] ?? null,
					);
					$s['invoices'][ $reference . '|' . $form . '|' . $series . '|' . $s['seq'] ] = $record;

					return $record;
				}
			);
		};

		$exists = einv_fake_with_state(
			static function ( array &$s ) use ( $reference, $form, $series ): bool {
				foreach ( $s['invoices'] as $stored ) {
					if ( $stored['mtChieu'] === $reference && $stored['khmshDon'] === $form && $stored['khhDon'] === $series ) {
						return true;
					}
				}
				return false;
			}
		);

		// A scripted `store` keeps the draft whatever the answer is: the request reached Mat Bao, the reply was lost.
		if ( $step && ! empty( $step['store'] ) ) {
			if ( ! $exists ) {
				$store();
			}
			$respond_scripted( $step );
			if ( isset( $step['item_error'] ) ) {
				$item = $step['item_error'];
				einv_fake_json( 200, einv_fake_envelope( false, 201, 'Tạo hóa đơn không thành công', array( array_merge( array( 'success' => false ), $item ) ) ) );
			}
		}

		if ( $step && isset( $step['item_error'] ) ) {
			$item = $step['item_error'];
			einv_fake_json( 200, einv_fake_envelope( false, 201, 'Tạo hóa đơn không thành công', array( array_merge( array( 'success' => false ), $item ) ) ) );
		}

		if ( $exists && ! empty( $invoice['KTraMTChieuTrung'] ) ) {
			einv_fake_json(
				200,
				einv_fake_envelope(
					false,
					201,
					'Tạo hóa đơn không thành công',
					array(
						array(
							'success'   => false,
							'errorCode' => 305,
							'message'   => 'Trùng mã tham chiếu',
							'data'      => array( 'mtChieu' => $reference ),
						),
					)
				)
			);
		}

		$record = $store();
		einv_fake_ok(
			array(
				array(
					'errorCode' => 200,
					'message'   => 'Thành công',
					'data'      => $record,
					'success'   => true,
				),
			)
		);

	case 'invoice-detail-batch':
		$respond_scripted( $step );
		$references = array_map( 'strval', (array) ( $body['danhSach'] ?? array() ) );
		$form       = (string) ( $body['mauSo'] ?? '' );
		$series     = (string) ( $body['kiHieu'] ?? '' );
		$found      = einv_fake_with_state(
			static function ( array &$s ) use ( $references, $form, $series ): array {
				$rows = array();
				foreach ( $s['invoices'] as $stored ) {
					if ( in_array( $stored['mtChieu'], $references, true ) && $stored['khmshDon'] === $form && $stored['khhDon'] === $series ) {
						$row = $stored;
						if ( ! $s['lookup_total'] ) {
							unset( $row['tgTTTBSo'], $row['nMua_Ten'] );
						}
						$rows[] = $row;
					}
				}
				return $rows;
			}
		);
		if ( array() === $found ) {
			einv_fake_json( 200, einv_fake_envelope( false, 301, 'Không tìm thấy hóa đơn', array( 'invoices' => array(), 'notFound' => $references ) ) );
		}
		einv_fake_ok(
			array(
				'invoices' => $found,
				'notFound' => array(),
			)
		);
}
