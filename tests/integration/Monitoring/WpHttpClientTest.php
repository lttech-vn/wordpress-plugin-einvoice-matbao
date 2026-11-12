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

	/**
	 * The SDK writes the DSN into the envelope header; the DSN must not leave the site.
	 */
	public function test_envelope_header_dsn_is_stripped(): void {
		$header = '{"event_id":"abc","sent_at":"2026-01-01T00:00:00Z","dsn":"https://k@sentry.test/3","sdk":{"name":"x"}}';
		$items  = "{\"type\":\"event\",\"length\":2}\n{\"a\":\"https://k@sentry.test/3\"}";
		$this->post_raw( $header . "\n" . $items );

		$bodies = $this->sink->raw_bodies();
		$this->assertCount( 1, $bodies );
		$this->assertStringNotContainsString( '"dsn"', strtok( $bodies[0], "\n" ) );
		$this->assertSame(
			array(
				'event_id' => 'abc',
				'sent_at'  => '2026-01-01T00:00:00Z',
				'sdk'      => array( 'name' => 'x' ),
			),
			json_decode( strtok( $bodies[0], "\n" ), true )
		);
		$this->assertSame( $items, substr( $bodies[0], (int) strpos( $bodies[0], "\n" ) + 1 ) );
		$this->assertStringContainsString( 'sentry_key=k', $this->http->raw_requests()[0]['args']['headers']['X-Sentry-Auth'] );
	}

	public function test_malformed_envelope_header_is_not_sent(): void {
		$this->assertSame( 0, $this->post_raw( "not json\n{}" ) );
		$this->assertSame( 0, $this->post_raw( "[1,2]\n{}" ) );

		$this->assertSame( array(), $this->http->raw_requests() );
		$this->assertFalse( get_option( Uninstaller::SENTRY_BACKOFF_OPTION ) );
		$this->assertSame( 200, self::send( self::client( self::T0 ) ) );
	}

	/**
	 * Posts a raw envelope body.
	 *
	 * @param string $body Envelope.
	 */
	private function post_raw( string $body ): int {
		$request = new Request();
		$request->setStringBody( $body );

		return self::client( self::T0 )->sendRequest( $request, new Options( array( 'dsn' => 'https://k@sentry.test/3' ) ) )->getStatusCode();
	}

	/**
	 * Header line with the DSN member at different positions; other members carry escaped slashes and unicode.
	 *
	 * @return array<string, array{string, string}> Input header, expected header.
	 */
	public static function dsn_positions(): array {
		$dsn = '"dsn":"https:\/\/k@sentry.test\/3"';
		$sdk = '"sdk":{"name":"sentry.php\/\u0111","version":"1.0"}';
		$etc = '"trace":{"public_key":"k","release":"app\/v1 \u0111"}';

		return array(
			'first'  => array( '{' . $dsn . ',' . $sdk . ',' . $etc . '}', '{' . $sdk . ',' . $etc . '}' ),
			'middle' => array( '{' . $sdk . ',' . $dsn . ',' . $etc . '}', '{' . $sdk . ',' . $etc . '}' ),
			'last'   => array( '{' . $sdk . ',' . $etc . ',' . $dsn . '}', '{' . $sdk . ',' . $etc . '}' ),
			'only'   => array( '{' . $dsn . '}', '{}' ),
		);
	}

	/**
	 * Only the DSN member is cut out of the header line.
	 *
	 * @dataProvider dsn_positions
	 *
	 * @param string $header   Input header line.
	 * @param string $expected Header line without the DSN member, bytes otherwise unchanged.
	 */
	public function test_only_the_dsn_member_is_removed( string $header, string $expected ): void {
		$items = "{\"type\":\"event\"}\n{\"u\":\"a\\/b \\u0111\"}";
		$this->post_raw( $header . "\n" . $items );

		$this->assertSame( array( $expected . "\n" . $items ), $this->sink->raw_bodies() );
	}

	public function test_envelope_without_dsn_is_sent_unchanged(): void {
		$body = "{\"sdk\":{\"name\":\"a\\/b\\u0111\"},\"x\":1.0}\n{\"type\":\"client_report\"}\n{\"timestamp\":1}";
		$this->post_raw( $body );

		$this->assertSame( array( $body ), $this->sink->raw_bodies() );
	}
}
