<?php
/**
 * The plugin's Sentry client stays out of the site's own Sentry setup.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration\Monitoring;

use LTTech\EinvoiceMatbao\Monitoring\Monitor;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\FakeHttp;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\MonitoringFixture;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\SentrySink;
use RuntimeException;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\Options;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use WP_UnitTestCase;

/**
 * The site's hub, log buffer and PHP error handlers are never read or replaced by the plugin.
 */
final class MonitorIsolationTest extends WP_UnitTestCase {

	use MonitoringFixture;

	/**
	 * HTTP fake.
	 *
	 * @var FakeHttp
	 */
	private FakeHttp $http;

	/**
	 * Fake Sentry endpoint of the plugin.
	 *
	 * @var SentrySink
	 */
	private SentrySink $sink;

	/**
	 * Hub that was current before the test.
	 *
	 * @var HubInterface
	 */
	private HubInterface $previous_hub;

	public function set_up(): void {
		parent::set_up();
		$this->http         = FakeHttp::install();
		$this->sink         = SentrySink::install( $this->http );
		$this->previous_hub = SentrySdk::getCurrentHub();
	}

	public function tear_down(): void {
		\Sentry\logger()->aggregator()->flush( new Hub() );
		SentrySdk::setCurrentHub( $this->previous_hub );
		$this->reset_monitoring();
		$this->http->uninstall();
		parent::tear_down();
	}

	public function test_global_hub_untouched(): void {
		$site_transport = self::recording_transport();
		$site_hub       = new Hub( self::site_client( $site_transport, false ) );
		SentrySdk::setCurrentHub( $site_hub );
		$this->enable_monitoring();

		Monitor::capture_exception( new RuntimeException( 'plugin failure' ) );
		Monitor::start_transaction( 't', 'queue.task' );
		Monitor::finish_transaction();
		Monitor::check_in( 'ok' );

		$this->assertCount( 1, $this->sink->events() );
		$this->assertSame( array(), $site_transport->events );
		$this->assertSame( $site_hub, SentrySdk::getCurrentHub() );
	}

	public function test_site_logs_untouched(): void {
		$site_transport = self::recording_transport();
		$site_hub       = new Hub( self::site_client( $site_transport, true ) );
		SentrySdk::setCurrentHub( $site_hub );
		\Sentry\logger()->info( 'site log' );
		$this->enable_monitoring();

		Monitor::log( 'info', 'p' );
		Monitor::flush();

		$this->assertSame( array( 'p' ), array_column( $this->sink->logs(), 'body' ) );
		$this->assertSame( array(), $site_transport->events );
		$site_logs = array_map( static fn( $log ): string => $log->getBody(), \Sentry\logger()->aggregator()->all() );
		$this->assertSame( array( 'site log' ), $site_logs );
		$this->assertSame( $site_hub, SentrySdk::getCurrentHub() );
	}

	public function test_spotlight_env_never_bypasses_the_wordpress_http_client(): void {
		$previous_server             = $_SERVER['SENTRY_SPOTLIGHT'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- test reads its own fixture.
		$previous_env                = getenv( 'SENTRY_SPOTLIGHT' );
		$_SERVER['SENTRY_SPOTLIGHT'] = '1';
		putenv( 'SENTRY_SPOTLIGHT=1' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- simulates the hosting environment.

		try {
			$this->enable_monitoring();
			Monitor::capture_exception( new RuntimeException( 'plugin failure' ) );
			Monitor::flush();

			$backend = ( new \ReflectionProperty( Monitor::class, 'backend' ) )->getValue();
			$hub     = ( new \ReflectionProperty( $backend, 'hub' ) )->getValue( $backend );
			$options = $hub->getClient()->getOptions();

			$this->assertFalse( $options->isSpotlightEnabled() );
			$this->assertCount( 1, $this->sink->events() );
		} finally {
			if ( null === $previous_server ) {
				unset( $_SERVER['SENTRY_SPOTLIGHT'] );
			} else {
				$_SERVER['SENTRY_SPOTLIGHT'] = $previous_server;
			}
			putenv( false === $previous_env ? 'SENTRY_SPOTLIGHT' : 'SENTRY_SPOTLIGHT=' . $previous_env ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- restoring.
		}
	}

	public function test_unrelated_warning_not_captured(): void {
		$swallowed = 0;
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- swallows the warning under test.
		set_error_handler(
			static function () use ( &$swallowed ): bool {
				++$swallowed;
				return true;
			}
		);
		$handlers_before = self::current_handlers();

		try {
			$this->enable_monitoring();
			Monitor::log( 'info', 'init' );
			$handlers_after = self::current_handlers();

			trigger_error( 'x', E_USER_WARNING ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error -- the warning under test.
			Monitor::flush();
		} finally {
			restore_error_handler();
		}

		$this->assertSame( 1, $swallowed );
		$this->assertSame( $handlers_before, $handlers_after );
		$this->assertSame( array(), $this->sink->events() );
	}

	/**
	 * Current error and exception handlers, read without changing them.
	 *
	 * @return array{error: mixed, exception: mixed}
	 */
	private static function current_handlers(): array {
		$error = set_error_handler( null ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- reading the handler.
		set_error_handler( $error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- restoring it.
		$exception = set_exception_handler( null );
		set_exception_handler( $exception );

		return array(
			'error'     => $error,
			'exception' => $exception,
		);
	}

	/**
	 * Client of the site's own Sentry setup.
	 *
	 * @param TransportInterface $transport Fake transport.
	 * @param bool               $logs      Whether logs are enabled.
	 */
	private static function site_client( TransportInterface $transport, bool $logs ): \Sentry\ClientInterface {
		return ( new ClientBuilder(
			new Options(
				array(
					'dsn'                  => 'https://site@site-sentry.test/1',
					'default_integrations' => false,
					'enable_logs'          => $logs,
				)
			)
		) )->setTransport( $transport )->getClient();
	}

	/**
	 * Transport that records events instead of sending them.
	 *
	 * @return TransportInterface&object{events: list<Event>}
	 */
	private static function recording_transport(): TransportInterface {
		return new class() implements TransportInterface {
			/**
			 * Received events.
			 *
			 * @var list<Event>
			 */
			public array $events = array();

			public function send( Event $event ): Result {
				$this->events[] = $event;

				return new Result( ResultStatus::success(), $event );
			}

			public function close( ?int $timeout = null ): Result {
				return new Result( ResultStatus::success() );
			}
		};
	}
}
