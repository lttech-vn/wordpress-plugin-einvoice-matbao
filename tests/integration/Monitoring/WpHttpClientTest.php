<?php
/**
 * Sentry HTTP client over the WordPress HTTP API.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration\Monitoring;

use LTTech\EinvoiceMatbao\Monitoring\WpHttpClient;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\FakeHttp;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\SentrySink;
use LTTech\EinvoiceMatbao\Uninstall\Uninstaller;
use Sentry\HttpClient\Request;
use Sentry\Options;
use WP_UnitTestCase;

/**
 * Short timeouts, no redirects, and a breaker that stops sending after any failure.
 */
final class WpHttpClientTest extends WP_UnitTestCase {

	private const T0 = 1_800_000_000;

	/**
	 * HTTP fake.
	 *
	 * @var FakeHttp
	 */
	private FakeHttp $http;

	/**
	 * Fake Sentry endpoint.
	 *
	 * @var SentrySink
	 */
	private SentrySink $sink;

	public function set_up(): void {
		parent::set_up();
		$this->http = FakeHttp::install();
		$this->sink = SentrySink::install( $this->http );
	}

	public function tear_down(): void {
		$this->http->uninstall();
		parent::tear_down();
	}

	/**
	 * Client with a fixed clock.
	 *
	 * @param int $now Unix time.
	 */
	private static function client( int $now ): WpHttpClient {
		return new WpHttpClient( static fn(): int => $now );
	}

	/**
	 * Sends one envelope.
	 *
	 * @param WpHttpClient $client Client.
	 */
	private static function send( WpHttpClient $client ): int {
		$request = new Request();
		$request->setStringBody( "{}\n{\"type\":\"event\"}\n{}" );

		return $client->sendRequest( $request, new Options( array( 'dsn' => 'https://k@sentry.test/3' ) ) )->getStatusCode();
	}

	public function test_timeout_values_are_passed(): void {
		$this->assertSame( 200, self::send( self::client( self::T0 ) ) );

		$requests = $this->http->raw_requests();
		$this->assertCount( 1, $requests );
		$this->assertSame( 'https://sentry.test/api/3/envelope/', $requests[0]['url'] );
		$this->assertSame( 3.0, $requests[0]['args']['timeout'] );
		$this->assertSame( 0, $requests[0]['args']['redirection'] );
		$this->assertSame( 'application/x-sentry-envelope', $requests[0]['args']['headers']['Content-Type'] );
		$this->assertStringContainsString( 'sentry_key=k', $requests[0]['args']['headers']['X-Sentry-Auth'] );
		$this->assertStringContainsString( 'sentry_version=7', $requests[0]['args']['headers']['X-Sentry-Auth'] );
	}

	/**
	 * Connect timeout applied by the Requests hook, keyed by request URL.
	 *
	 * @param string $url Request URL.
	 * @return float|int|null Connect timeout, or null when the hook left it alone.
	 */
	private static function connect_timeout_for( string $url ) {
		$headers = array();
		$data    = array();
		$type    = 'POST';
		$options = array( 'connect_timeout' => null );
		do_action_ref_array( 'requests-requests.before_request', array( &$url, &$headers, &$data, &$type, &$options ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's Requests hook, invoked as WP_HTTP_Requests_Hooks does.

		return $options['connect_timeout'];
	}

	public function test_connect_timeout_applies_to_sentry_url_only_during_request(): void {
		$seen = array();
		$this->http->respond_with(
			'POST /api/3/envelope/',
			function ( array $args, string $url ) use ( &$seen ): array {
				$seen = array(
					'sentry' => self::connect_timeout_for( $url ),
					'other'  => self::connect_timeout_for( 'https://api.example.test/x' ),
				);

				return FakeHttp::json_response( 200, array() );
			}
		);

		self::send( self::client( self::T0 ) );

		$this->assertSame( 2.0, $seen['sentry'] );
		$this->assertNull( $seen['other'] );
		$this->assertNull( self::connect_timeout_for( 'https://sentry.test/api/3/envelope/' ), 'Hook removed after the request.' );
	}

	public function test_connect_timeout_hook_removed_after_transport_error(): void {
		$this->sink->fail_transport();

		self::send( self::client( self::T0 ) );

		$this->assertNull( self::connect_timeout_for( 'https://sentry.test/api/3/envelope/' ) );
	}

	public function test_connect_timeout_hook_removed_when_http_layer_throws(): void {
		$this->http->respond_with(
			'POST /api/3/envelope/',
			static function (): array {
				throw new \RuntimeException( 'transport exploded' );
			}
		);

		try {
			self::send( self::client( self::T0 ) );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'transport exploded', $e->getMessage() );
		}

		$this->assertNull( self::connect_timeout_for( 'https://sentry.test/api/3/envelope/' ) );
	}

	public function test_success_does_not_back_off(): void {
		self::send( self::client( self::T0 ) );
		self::send( self::client( self::T0 ) );

		$this->assertSame( 2, $this->sink->request_count() );
		$this->assertFalse( get_option( Uninstaller::SENTRY_BACKOFF_OPTION ) );
	}

	public function test_failure_opens_breaker_for_request(): void {
		$this->sink->respond_with( 503 );
		$client = self::client( self::T0 );

		$this->assertSame( 503, self::send( $client ) );
		delete_option( Uninstaller::SENTRY_BACKOFF_OPTION );
		self::send( $client );

		$this->assertSame( 1, $this->sink->request_count() );
	}

	public function test_transport_error_opens_breaker(): void {
		$this->sink->fail_transport();
		$client = self::client( self::T0 );

		$this->assertSame( 0, self::send( $client ) );
		self::send( $client );

		$this->assertSame( 1, $this->sink->request_count() );
	}

	public function test_backoff_across_requests(): void {
		$this->sink->respond_with( 503 );
		self::send( self::client( self::T0 ) );
		$this->sink->respond_with( 200 );

		self::send( self::client( self::T0 + 30 ) );
		$this->assertSame( 1, $this->sink->request_count() );

		self::send( self::client( self::T0 + 61 ) );
		$this->assertSame( 2, $this->sink->request_count() );
	}

	public function test_backoff_option_is_not_autoloaded(): void {
		global $wpdb;
		$this->sink->respond_with( 503 );
		self::send( self::client( self::T0 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- asserting the stored autoload flag.
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Uninstaller::SENTRY_BACKOFF_OPTION ) );
		$this->assertContains( $autoload, array( 'no', 'off' ) );
		$this->assertSame( self::T0 + 60, (int) get_option( Uninstaller::SENTRY_BACKOFF_OPTION ) );
	}

	/**
	 * Retry-After handling.
	 *
	 * @dataProvider retry_after_cases
	 *
	 * @param string $retry_after Header value.
	 * @param int    $blocked     Offset at which sending is still blocked.
	 * @param int    $allowed     Offset at which sending resumes.
	 */
	public function test_retry_after( string $retry_after, int $blocked, int $allowed ): void {
		$this->sink->respond_with( 429, array( 'Retry-After' => $retry_after ) );
		self::send( self::client( self::T0 ) );
		$this->sink->respond_with( 200 );

		self::send( self::client( self::T0 + $blocked ) );
		$this->assertSame( 1, $this->sink->request_count(), 'Blocked during backoff.' );

		self::send( self::client( self::T0 + $allowed ) );
		$this->assertSame( 2, $this->sink->request_count(), 'Sends again after backoff.' );
	}

	/**
	 * Header value, last blocked offset, first allowed offset.
	 *
	 * @return array<string, array{string, int, int}>
	 */
	public static function retry_after_cases(): array {
		return array(
			'seconds'   => array( '120', 119, 121 ),
			'capped'    => array( '99999', 599, 601 ),
			'http date' => array( 'Wed, 21 Oct 2015 07:28:00 GMT', 59, 61 ),
			'malformed' => array( 'soon', 59, 61 ),
			'zero'      => array( '0', 59, 61 ),
			'blank'     => array( '', 59, 61 ),
		);
	}

	public function test_4xx_also_backs_off(): void {
		$this->sink->respond_with( 401 );
		self::send( self::client( self::T0 ) );
		$this->sink->respond_with( 200 );

		self::send( self::client( self::T0 + 59 ) );
		$this->assertSame( 1, $this->sink->request_count() );

		self::send( self::client( self::T0 + 61 ) );
		$this->assertSame( 2, $this->sink->request_count() );
	}
}
