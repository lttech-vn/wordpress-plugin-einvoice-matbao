<?php
/**
 * Sentry SDK adapter: the only Monitoring class (besides WpHttpClient) that touches `Sentry\…`.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Monitoring;

use InvalidArgumentException;
use Sentry\Breadcrumb;
use Sentry\CheckInStatus;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\HttpClient\HttpClientInterface;
use Sentry\Logs\Log;
use Sentry\Logs\LogLevel;
use Sentry\MonitorConfig;
use Sentry\MonitorSchedule;
use Sentry\MonitorScheduleUnit;
use Sentry\Options;
use Sentry\Severity;
use Sentry\Stacktrace;
use Sentry\State\Hub;
use Sentry\State\Scope;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;

/**
 * Owns a private Sentry client and Hub. Nothing here reads or replaces the SDK's global hub
 * (`SentrySdk`), installs PHP handlers or uses the SDK's global log aggregator, so a site that
 * runs its own Sentry setup is left alone. Every payload passes the Redactor before it is sent;
 * when redaction fails the payload is dropped and the failure is rethrown to Monitor.
 */
final class SentryBackend implements Backend {

	/**
	 * Logs kept between flushes; older ones are dropped first.
	 */
	public const MAX_BUFFERED_LOGS = 100;

	private const LOG_LEVELS = array(
		'trace'   => 'trace',
		'debug'   => 'debug',
		'info'    => 'info',
		'warn'    => 'warn',
		'warning' => 'warn',
		'error'   => 'error',
		'fatal'   => 'fatal',
	);

	private const CHECK_IN_STATUSES = array(
		'in_progress' => 'inProgress',
		'ok'          => 'ok',
		'error'       => 'error',
	);

	private const SCHEDULE_UNITS = array( 'minute', 'hour', 'day', 'week', 'month', 'year' );

	/**
	 * Span statuses Monitor callers may pass; anything else becomes `unknown_error`.
	 */
	private const SPAN_STATUSES = array(
		'ok'             => 'ok',
		'internal_error' => 'internalError',
		'unavailable'    => 'unavailable',
		'unknown_error'  => 'unknownError',
	);

	/**
	 * Plugin-owned hub; never registered as the SDK's current hub.
	 *
	 * @var Hub
	 */
	private Hub $hub;

	/**
	 * Redactor for the payload being captured (base redactor plus the known values of the call).
	 *
	 * @var Redactor
	 */
	private Redactor $active;

	/**
	 * Failure raised by a before_send* callback during the current capture.
	 *
	 * @var \Throwable|null
	 */
	private ?\Throwable $scrub_failure = null;

	/**
	 * Open transaction, if any.
	 *
	 * @var Transaction|null
	 */
	private ?Transaction $transaction = null;

	/**
	 * Buffered logs, oldest first.
	 *
	 * @var list<Log>
	 */
	private array $logs = array();

	/**
	 * Builds the isolated client and hub.
	 *
	 * @param MonitoringConfig         $config   Active configuration.
	 * @param Redactor                 $redactor Redactor applied to every payload.
	 * @param HttpClientInterface|null $http     HTTP client; WpHttpClient by default.
	 * @throws InvalidArgumentException When the configuration has no DSN.
	 */
	public function __construct(
		private readonly MonitoringConfig $config,
		private readonly Redactor $redactor,
		?HttpClientInterface $http = null
	) {
		if ( null === $config->dsn ) {
			throw new InvalidArgumentException( 'Monitoring needs a DSN.' );
		}

		$this->active = $redactor;
		$client       = ( new ClientBuilder( new Options( $this->options( $config->dsn, $http ?? new WpHttpClient() ) ) ) )->getClient();
		$this->hub    = new Hub( $client );
		$site         = $config->site;
		$this->hub->configureScope(
			static function ( Scope $scope ) use ( $site ): void {
				$scope->setTag( 'site', $site );
			}
		);
	}

	/**
	 * Reports an exception as an issue.
	 *
	 * @param \Throwable           $e     Exception to report.
	 * @param array<string, mixed> $tags  Event tags.
	 * @param array<int, string>   $known Known personal values to mask.
	 */
	public function capture_exception( \Throwable $e, array $tags, array $known ): ?string {
		return $this->capture( $tags, array(), $known, fn(): ?\Sentry\EventId => $this->hub->captureException( $e ) );
	}

	/**
	 * Reports a message as an issue.
	 *
	 * @param string               $msg         Message.
	 * @param string               $level       Sentry severity.
	 * @param array<string, mixed> $tags        Event tags.
	 * @param array<int, string>   $fingerprint Grouping fingerprint.
	 * @param array<int, string>   $known       Known personal values to mask.
	 */
	public function capture_message( string $msg, string $level, array $tags, array $fingerprint, array $known ): ?string {
		$severity = new Severity( $level );

		return $this->capture( $tags, $fingerprint, $known, fn(): ?\Sentry\EventId => $this->hub->captureMessage( $msg, $severity ) );
	}

	/**
	 * Opens a transaction; an open one is closed first.
	 *
	 * @param string $name Transaction name.
	 * @param string $op   Operation.
	 */
	public function start_transaction( string $name, string $op ): void {
		if ( null !== $this->transaction ) {
			$this->finish_transaction( 'ok' );
		}

		$context = TransactionContext::make()->setName( $name );
		$context->setOp( $op );
		$this->transaction = $this->hub->startTransaction( $context );
		$this->hub->setSpan( $this->transaction );
	}

	/**
	 * Closes the open transaction (sent when sampled) and flushes buffered logs.
	 *
	 * @param string $status Span status.
	 */
	public function finish_transaction( string $status ): void {
		$transaction = $this->transaction;
		if ( null !== $transaction ) {
			$this->transaction = null;
			$this->hub->setSpan( null );
			$transaction->setStatus( self::span_status( $status ) );
			$this->with_redactor( array(), static fn(): ?\Sentry\EventId => $transaction->finish() );
		}

		$this->flush();
	}

	/**
	 * Runs $callback inside a child span of the open transaction; without one, $callback just runs.
	 *
	 * @param string             $op             Span operation.
	 * @param string             $description    Span description.
	 * @param callable           $callback       Code to measure; called exactly once.
	 * @param callable           $data_of_result Maps the result of $callback to span data.
	 * @param array<int, string> $known          Known personal values to mask in description and data.
	 * @return mixed Result of $callback.
	 * @throws \Throwable The exception of $callback, unchanged.
	 */
	public function span( string $op, string $description, callable $callback, callable $data_of_result, array $known ): mixed {
		$parent = $this->hub->getSpan();
		if ( null === $this->transaction || null === $parent ) {
			return $callback();
		}

		// Masked now: the transaction is sent later, possibly after the order context is gone.
		$redactor = $this->redactor->with_known_values( $known );
		$context  = SpanContext::make()->setOp( $op )->setDescription( $redactor->text( $description ) );
		$span     = $parent->startChild( $context );
		$this->hub->setSpan( $span );

		try {
			$result = $callback();
			$span->setStatus( SpanStatus::ok() );
			self::set_span_data( $span, $redactor, $data_of_result, $result );

			return $result;
		} catch ( \Throwable $failure ) {
			$span->setStatus( SpanStatus::internalError() );
			throw $failure;
		} finally {
			$span->finish();
			$this->hub->setSpan( $parent );
		}
	}

	/**
	 * Buffers a structured log, masked now so the order context still applies.
	 *
	 * @param string               $level Log level.
	 * @param string               $msg   Log body.
	 * @param array<string, mixed> $attrs Log attributes.
	 * @param array<int, string>   $known Known personal values to mask.
	 * @throws InvalidArgumentException For an unknown level.
	 */
	public function log( string $level, string $msg, array $attrs, array $known ): void {
		if ( ! isset( self::LOG_LEVELS[ $level ] ) ) {
			throw new InvalidArgumentException( 'Unknown log level.' );
		}

		$redactor = $this->redactor->with_known_values( $known );
		$log      = new Log( microtime( true ), $this->trace_id(), LogLevel::{self::LOG_LEVELS[ $level ]}(), $redactor->text( $msg ) );
		$log->setAttribute( 'sentry.release', $this->config->release );
		$log->setAttribute( 'sentry.environment', $this->config->environment );
		$log->setAttribute( 'site', $this->config->site );
		foreach ( $attrs as $key => $value ) {
			$log->setAttribute( (string) $key, $redactor->value( $value ) );
		}

		$this->logs[] = $log;
		if ( count( $this->logs ) > self::MAX_BUFFERED_LOGS ) {
			array_shift( $this->logs );
		}
	}

	/**
	 * Sends a cron monitor check-in.
	 *
	 * @param string               $slug     Monitor slug.
	 * @param string               $status   `in_progress`, `ok` or `error`.
	 * @param string|null          $id       Check-in ID of the matching `in_progress` check-in.
	 * @param float|null           $duration Run time in seconds.
	 * @param array<string, mixed> $schedule Monitor configuration, see Backend::check_in().
	 * @throws InvalidArgumentException For an unknown status.
	 */
	public function check_in( string $slug, string $status, ?string $id, ?float $duration, array $schedule ): ?string {
		if ( ! isset( self::CHECK_IN_STATUSES[ $status ] ) ) {
			throw new InvalidArgumentException( 'Unknown check-in status.' );
		}

		$check_in_status = CheckInStatus::{self::CHECK_IN_STATUSES[ $status ]}();
		$monitor_config  = self::monitor_config( $schedule );

		return $this->with_redactor( array(), fn(): ?string => $this->hub->captureCheckIn( $slug, $check_in_status, $duration, $monitor_config, $id ) );
	}

	/**
	 * Sends buffered logs through the plugin hub.
	 */
	public function flush(): void {
		if ( array() === $this->logs ) {
			return;
		}

		$logs       = $this->logs;
		$this->logs = array();
		$this->hub->captureEvent( Event::createLogs()->setLogs( $logs ) );
	}

	/**
	 * Client options: no integrations, no PII, the WordPress HTTP client and redaction callbacks.
	 *
	 * @param SentryDsn           $dsn  DSN.
	 * @param HttpClientInterface $http HTTP client.
	 * @return array<string, mixed>
	 */
	private function options( SentryDsn $dsn, HttpClientInterface $http ): array {
		return array(
			'dsn'                     => $dsn->value(),
			'environment'             => $this->config->environment,
			'release'                 => $this->config->release,
			'default_integrations'    => false,
			'integrations'            => array(),
			'send_default_pii'        => false,
			'server_name'             => '',
			'enable_logs'             => true,
			'traces_sample_rate'      => $this->config->traces_sample_rate,
			'http_client'             => $http,
			'before_send'             => fn( Event $event ): ?Event => $this->scrub_event( $event ),
			'before_send_transaction' => fn( Event $event ): ?Event => $this->scrub_event( $event ),
			'before_send_check_in'    => fn( Event $event ): ?Event => $this->scrub_event( $event ),
			'before_send_log'         => fn( Log $log ): ?Log => $this->scrub_log( $log ),
			'before_breadcrumb'       => fn( Breadcrumb $breadcrumb ): ?Breadcrumb => $this->scrub_breadcrumb( $breadcrumb ),
		);
	}

	/**
	 * Captures one issue with call-specific tags, fingerprint and known values.
	 *
	 * @param array<string, mixed>         $tags        Event tags.
	 * @param array<int, string>           $fingerprint Grouping fingerprint.
	 * @param array<int, string>           $known       Known personal values.
	 * @param callable(): ?\Sentry\EventId $send        Capture call on the hub.
	 */
	private function capture( array $tags, array $fingerprint, array $known, callable $send ): ?string {
		$event_id = $this->with_redactor(
			$known,
			fn(): ?\Sentry\EventId => $this->hub->withScope(
				static function ( Scope $scope ) use ( $tags, $fingerprint, $send ): ?\Sentry\EventId {
					$scope->setTags( array_map( 'strval', array_filter( $tags, 'is_scalar' ) ) );
					if ( array() !== $fingerprint ) {
						$scope->setFingerprint( array_values( $fingerprint ) );
					}

					return $send();
				}
			)
		);

		return null === $event_id ? null : (string) $event_id;
	}

	/**
	 * Runs a capture with the known values applied by the before_send* callbacks; a redaction
	 * failure inside a callback dropped the payload and is rethrown here.
	 *
	 * @template T
	 * @param array<int, string> $known Known personal values.
	 * @param callable(): T      $send  Capture call.
	 * @return T
	 * @throws \Throwable The redaction failure.
	 */
	private function with_redactor( array $known, callable $send ): mixed {
		$this->active        = $this->redactor->with_known_values( $known );
		$this->scrub_failure = null;
		try {
			$result = $send();
		} finally {
			$this->active = $this->redactor;
		}

		$failure = $this->take_scrub_failure();
		if ( null !== $failure ) {
			throw $failure;
		}

		return $result;
	}

	/**
	 * Returns and clears the failure recorded by a before_send* callback.
	 */
	private function take_scrub_failure(): ?\Throwable {
		$failure             = $this->scrub_failure;
		$this->scrub_failure = null;

		return $failure;
	}

	/**
	 * Callback for before_send*: removes host, request and user data and masks every string.
	 *
	 * @param Event $event Event about to be sent.
	 */
	private function scrub_event( Event $event ): ?Event {
		try {
			$event->setServerName( null );
			$event->setRequest( array() );
			$event->setUser( null );
			$event->setOsContext( null );
			$event->setTags( array_map( 'strval', $this->active->tags( $event->getTags() ) ) );
			$event->setExtra( (array) $this->active->value( $event->getExtra() ) );
			$this->scrub_message( $event );
			$this->scrub_exceptions( $event );
			$this->scrub_spans( $event );
			$transaction = $event->getTransaction();
			if ( null !== $transaction ) {
				$event->setTransaction( $this->active->text( $transaction ) );
			}

			return $event;
		} catch ( \Throwable $failure ) {
			$this->scrub_failure = $failure;

			return null;
		}
	}

	/**
	 * Masks the message and its parameters.
	 *
	 * @param Event $event Event.
	 */
	private function scrub_message( Event $event ): void {
		$message = $event->getMessage();
		if ( null === $message ) {
			return;
		}

		$formatted = $event->getMessageFormatted();
		$event->setMessage(
			$this->active->text( $message ),
			array_map( fn( string $param ): string => $this->active->text( $param ), $event->getMessageParams() ),
			null === $formatted ? null : $this->active->text( $formatted )
		);
	}

	/**
	 * Masks exception values and drops frame variables (function arguments) everywhere.
	 *
	 * @param Event $event Event.
	 */
	private function scrub_exceptions( Event $event ): void {
		foreach ( $event->getExceptions() as $exception ) {
			$exception->setValue( $this->active->text( $exception->getValue() ) );
			self::drop_frame_vars( $exception->getStacktrace() );
		}
		self::drop_frame_vars( $event->getStacktrace() );
	}

	/**
	 * Masks span descriptions and data of a transaction.
	 *
	 * @param Event $event Transaction event.
	 */
	private function scrub_spans( Event $event ): void {
		foreach ( $event->getSpans() as $span ) {
			$description = $span->getDescription();
			if ( null !== $description ) {
				$span->setDescription( $this->active->text( $description ) );
			}
			// setData() merges, so every key is overwritten with its masked value.
			$span->setData( (array) $this->active->value( $span->getData() ) );
		}
	}

	/**
	 * Callback for before_send_log; logs are already masked in log(), this guards any other path.
	 *
	 * @param Log $log Log about to be sent.
	 */
	private function scrub_log( Log $log ): ?Log {
		try {
			return $log->setBody( $this->active->text( $log->getBody() ) );
		} catch ( \Throwable $failure ) {
			$this->scrub_failure = $failure;

			return null;
		}
	}

	/**
	 * Callback for before_breadcrumb: masks the message and metadata.
	 *
	 * @param Breadcrumb $breadcrumb Breadcrumb about to be recorded.
	 */
	private function scrub_breadcrumb( Breadcrumb $breadcrumb ): ?Breadcrumb {
		try {
			$message = $breadcrumb->getMessage();
			$masked  = null === $message ? $breadcrumb : $breadcrumb->withMessage( $this->active->text( $message ) );
			foreach ( $breadcrumb->getMetadata() as $name => $value ) {
				$masked = $masked->withMetadata( (string) $name, $this->active->value( $value ) );
			}

			return $masked;
		} catch ( \Throwable $failure ) {
			$this->scrub_failure = $failure;

			return null;
		}
	}

	/**
	 * Trace ID of the current span, or of the plugin scope's propagation context.
	 */
	private function trace_id(): string {
		$span = $this->hub->getSpan();
		if ( null !== $span ) {
			return (string) $span->getTraceId();
		}

		$trace_id = '';
		$this->hub->configureScope(
			static function ( Scope $scope ) use ( &$trace_id ): void {
				$trace_id = (string) $scope->getPropagationContext()->getTraceId();
			}
		);

		return $trace_id;
	}

	/**
	 * Adds the masked data of a finished call to its span; a failing mapper only loses the data.
	 *
	 * @param Span     $span           Span.
	 * @param Redactor $redactor       Redactor with the call's known values.
	 * @param callable $data_of_result Maps the result to span data.
	 * @param mixed    $result         Result of the measured call.
	 */
	private static function set_span_data( Span $span, Redactor $redactor, callable $data_of_result, mixed $result ): void {
		try {
			$data = $data_of_result( $result );
			if ( is_array( $data ) ) {
				$span->setData( (array) $redactor->value( $data ) );
			}
		} catch ( \Throwable $failure ) {
			// Span data is optional; the caller's result matters more than the measurement.
			unset( $failure );
		}
	}

	/**
	 * Removes the recorded function arguments of every frame.
	 *
	 * @param Stacktrace|null $stacktrace Stack trace.
	 */
	private static function drop_frame_vars( ?Stacktrace $stacktrace ): void {
		foreach ( null === $stacktrace ? array() : $stacktrace->getFrames() as $frame ) {
			$frame->setVars( array() );
		}
	}

	/**
	 * SDK span status for a Monitor status string.
	 *
	 * @param string $status Status, for example `ok`.
	 */
	private static function span_status( string $status ): SpanStatus {
		return SpanStatus::{self::SPAN_STATUSES[ $status ] ?? 'unknownError'}();
	}

	/**
	 * Monitor configuration from the schedule array of Backend::check_in().
	 *
	 * @param array<string, mixed> $schedule Schedule settings.
	 * @throws InvalidArgumentException For an unknown unit.
	 */
	private static function monitor_config( array $schedule ): MonitorConfig {
		$unit = (string) ( $schedule['unit'] ?? '' );
		if ( ! in_array( $unit, self::SCHEDULE_UNITS, true ) ) {
			throw new InvalidArgumentException( 'Unknown monitor schedule unit.' );
		}

		return new MonitorConfig(
			MonitorSchedule::interval( (int) ( $schedule['interval'] ?? 0 ), MonitorScheduleUnit::{$unit}() ),
			(int) ( $schedule['checkin_margin'] ?? 0 ),
			(int) ( $schedule['max_runtime'] ?? 0 ),
			(string) ( $schedule['timezone'] ?? 'UTC' ),
			(int) ( $schedule['failure_issue_threshold'] ?? 1 ),
			(int) ( $schedule['recovery_threshold'] ?? 1 )
		);
	}
}
