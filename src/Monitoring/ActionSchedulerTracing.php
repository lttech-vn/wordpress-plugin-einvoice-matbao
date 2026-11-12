<?php
/**
 * Transactions and the recovery cron monitor for the plugin's Action Scheduler actions.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Monitoring;

use ActionScheduler;
use LTTech\EinvoiceMatbao\Invoice\DraftInvoiceWorkflow;

/**
 * Opens a `queue.task` transaction named after the hook for every action of the plugin's group,
 * and sends `in_progress` then `ok`/`error` check-ins around the recovery sweep.
 *
 * Runs are tracked by action ID from `action_scheduler_begin_execute` (fired only once the
 * runner accepted the pending action, unlike `before_execute`) to `after_execute`,
 * `failed_execution` or, when the runner gives up on a corrupted action, its cancellation.
 */
final class ActionSchedulerTracing {

	private const OP = 'queue.task';

	private const COMPONENT = 'action_scheduler';

	/**
	 * Instance wired to the hooks; registering twice must not report twice.
	 *
	 * @var self|null
	 */
	private static ?self $registered = null;

	/**
	 * Runs in progress by action ID.
	 *
	 * @var array<int, array{recovery: bool, check_in: string|null, started: float, hook: string, order_id: int|null}>
	 */
	private array $runs = array();

	/**
	 * Hooks into the Action Scheduler runner.
	 */
	public static function register(): void {
		if ( null !== self::$registered ) {
			return;
		}

		$tracing          = new self();
		self::$registered = $tracing;
		add_action( 'action_scheduler_begin_execute', array( $tracing, 'on_begin' ), 10, 1 );
		add_action( 'action_scheduler_after_execute', array( $tracing, 'on_completed' ), 10, 1 );
		add_action( 'action_scheduler_failed_execution', array( $tracing, 'on_failed' ), 10, 2 );
		add_action( 'action_scheduler_canceled_corrupted_action', array( $tracing, 'on_abandoned' ), 10, 1 );
	}

	/**
	 * Starts tracing an accepted action of the plugin's group.
	 *
	 * @param int|string $action_id Action ID.
	 */
	public function on_begin( $action_id ): void {
		try {
			if ( ! Monitor::is_active() ) {
				return;
			}

			$action = ActionScheduler::store()->fetch_action( (string) $action_id );
			if ( DraftInvoiceWorkflow::GROUP !== $action->get_group() ) {
				return;
			}

			$hook     = $action->get_hook();
			$recovery = DraftInvoiceWorkflow::RECOVERY_ACTION === $hook;
			$first    = $action->get_args()[0] ?? null;
			Monitor::start_transaction( $hook, self::OP );
			$this->runs[ (int) $action_id ] = array(
				'recovery' => $recovery,
				'check_in' => $recovery ? Monitor::check_in( 'in_progress' ) : null,
				'started'  => microtime( true ),
				'hook'     => $hook,
				'order_id' => is_int( $first ) && $first > 0 ? $first : null,
			);
		} catch ( \Throwable $failure ) {
			InternalFailure::report( $failure );
		}
	}

	/**
	 * Closes a traced action that ran to completion.
	 *
	 * @param int|string $action_id Action ID.
	 */
	public function on_completed( $action_id ): void {
		$this->close( (int) $action_id, true );
	}

	/**
	 * Reports and closes a traced action that threw.
	 *
	 * @param int|string $action_id Action ID.
	 * @param mixed      $error     Exception passed by the runner (wrapping the original one).
	 */
	public function on_failed( $action_id, $error ): void {
		if ( ! isset( $this->runs[ (int) $action_id ] ) ) {
			return;
		}

		if ( $error instanceof \Throwable ) {
			$this->report_failure( $error->getPrevious() ?? $error, (int) $action_id );
		}
		$this->close( (int) $action_id, false );
	}

	/**
	 * Sends the exception with the action and, when the action concerns an order, the order context.
	 *
	 * @param \Throwable $failure   Exception thrown by the action.
	 * @param int        $action_id Action ID.
	 */
	private function report_failure( \Throwable $failure, int $action_id ): void {
		$hook     = $this->runs[ $action_id ]['hook'];
		$order_id = $this->runs[ $action_id ]['order_id'];

		$tags = array( 'component' => self::COMPONENT );
		if ( null !== $order_id ) {
			$tags['order_id'] = (string) $order_id;
		}
		$contexts = static fn(): array => array(
			'action' => array(
				'hook'      => $hook,
				'action_id' => $action_id,
			),
		) + ( null === $order_id ? array() : InvoiceContext::for_order_or_empty( $order_id ) );

		if ( null === $order_id ) {
			Monitor::capture_exception( $failure, $tags, $contexts );
			return;
		}
		Monitor::with_order_context( $order_id, static fn() => Monitor::capture_exception( $failure, $tags, $contexts ) );
	}

	/**
	 * Closes a traced action the runner cancelled as corrupted, so its transaction does not stay open.
	 *
	 * @param int|string $action_id Action ID.
	 */
	public function on_abandoned( $action_id ): void {
		$this->close( (int) $action_id, false );
	}

	/**
	 * Sends the closing check-in and finishes the transaction of a traced run.
	 *
	 * @param int  $action_id Action ID.
	 * @param bool $succeeded Whether the action completed.
	 */
	private function close( int $action_id, bool $succeeded ): void {
		if ( ! isset( $this->runs[ $action_id ] ) ) {
			return;
		}

		$run = $this->runs[ $action_id ];
		unset( $this->runs[ $action_id ] );

		if ( $run['recovery'] ) {
			Monitor::check_in( $succeeded ? 'ok' : 'error', $run['check_in'], microtime( true ) - $run['started'] );
		}
		Monitor::finish_transaction( $succeeded ? 'ok' : 'internal_error' );
	}
}
