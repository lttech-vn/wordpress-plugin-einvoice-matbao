<?php
/**
 * Shared setup for tests at the admin REST seam.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration\Support;

use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Boots a fresh REST server, fakes HTTP and provides helpers to call plugin routes as htmx would.
 */
abstract class SettingsRestTestCase extends WP_UnitTestCase {

	protected const NAMESPACE = '/einvoice-matbao/v1';

	/** Dummy key material for tests only; not a real secret. */
	protected const TEST_ENCRYPTION_KEY = 'test-only-key-material-0123456789abcdef';

	/** Dummy Mat Bao credentials used only against the fake HTTP boundary. */
	protected const MST      = '0000000000';
	protected const USERNAME = 'fake-user';
	protected const PASSWORD = 'fake-password-Zq7';

	protected const LARK_WEBHOOK = 'https://open.larksuite.com/open-apis/bot/v2/hook/00000000-fake-hook';
	protected const LARK_SECRET  = 'fake-lark-sign-secret';

	protected FakeHttp $http;

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- fresh REST server per test.
		rest_get_server();

		$this->http = FakeHttp::install();
	}

	public function tear_down(): void {
		$this->http->uninstall();
		remove_all_filters( 'einvoice_matbao_encryption_key' );

		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- fresh REST server per test.

		parent::tear_down();
	}

	/**
	 * Provides the encryption key the way a `wp-config.php` constant would.
	 */
	protected function with_encryption_key(): void {
		add_filter( 'einvoice_matbao_encryption_key', static fn(): string => self::TEST_ENCRYPTION_KEY );
	}

	/**
	 * Logs in a fresh user with the given role.
	 *
	 * @param string $role WordPress role.
	 */
	protected function login_as( string $role ): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
	}

	/**
	 * Dispatches a plugin route like htmx does: form-encoded body plus the `X-WP-Nonce` header.
	 *
	 * @param string                $method HTTP method.
	 * @param string                $route  Route below the plugin namespace, e.g. "/settings/matbao".
	 * @param array<string, string> $params Form or query parameters.
	 * @param string|null           $nonce  Nonce header value; defaults to a valid `wp_rest` nonce.
	 */
	protected function htmx( string $method, string $route, array $params = array(), ?string $nonce = null ): WP_REST_Response {
		$request = new WP_REST_Request( $method, self::NAMESPACE . $route );
		$request->set_header( 'X-WP-Nonce', $nonce ?? wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'HX-Request', 'true' );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_header( 'Content-Type', 'application/x-www-form-urlencoded' );
			$request->set_body_params( $params );
		}

		return rest_do_request( $request );
	}

	/**
	 * The HTML fragment of a successful or validation response.
	 *
	 * @param WP_REST_Response $response REST response.
	 */
	protected function html( WP_REST_Response $response ): string {
		$data = $response->get_data();
		$this->assertIsString( $data, 'Expected an HTML fragment, got ' . wp_json_encode( $data ) );

		return $data;
	}

	/**
	 * Fakes a successful Mat Bao login and records nothing else.
	 */
	protected function fake_matbao_login_ok( string $token = 'fake-token-1' ): void {
		$this->http->respond_json(
			'POST /api/auth/login',
			200,
			array(
				'errorCode' => 200,
				'message'   => 'Thành công',
				'data'      => array(
					'accessToken' => $token,
					'expiredDate' => gmdate( 'Y-m-d\TH:i:s', time() + 7 * HOUR_IN_SECONDS + DAY_IN_SECONDS ),
				),
				'success'   => true,
			)
		);
	}

	/**
	 * Fakes the Mat Bao templates list: two VAT (GTGT) templates and one sales-invoice template.
	 */
	protected function fake_matbao_templates(): void {
		$this->http->respond_json(
			'GET /api/invoice/templates',
			200,
			array(
				'errorCode' => 200,
				'message'   => 'Thành công',
				'data'      => array(
					array(
						'thDon'    => 'Hóa đơn giá trị gia tăng',
						'khmshDon' => '1',
						'khhDon'   => 'C26TAT',
						'sLuong'   => 100000,
						'cLai'     => 54518,
					),
					array(
						'thDon'    => 'Hóa đơn giá trị gia tăng',
						'khmshDon' => '1',
						'khhDon'   => 'C26TXX',
						'sLuong'   => 10,
						'cLai'     => 0,
					),
					array(
						'thDon'    => 'Hóa đơn bán hàng',
						'khmshDon' => '2',
						'khhDon'   => 'C26TMB',
						'sLuong'   => 701,
						'cLai'     => 370,
					),
				),
				'success'   => true,
			)
		);
	}

	/**
	 * Saves Mat Bao credentials through the REST route (requires the encryption key).
	 */
	protected function save_matbao_credentials(): void {
		$response = $this->htmx(
			'POST',
			'/settings/matbao',
			array(
				'environment' => 'demo',
				'mst'         => self::MST,
				'username'    => self::USERNAME,
				'password'    => self::PASSWORD,
			)
		);
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
	}

	/**
	 * Raw values of every plugin option, for asserting secrets are never stored in clear text.
	 */
	protected function stored_plugin_options(): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test inspects raw storage on purpose.
		$rows = $wpdb->get_col( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'einvoice\\_matbao\\_%'" );

		return implode( "\n", $rows );
	}
}
