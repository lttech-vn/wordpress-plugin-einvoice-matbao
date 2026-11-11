<?php
/**
 * Records what a caller's callable did inside instrumentation.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Monitoring;

/**
 * Remembers the result or exception of one callable run, so Monitor can hand it back unchanged
 * even when the instrumentation around the call fails afterwards, without running it twice.
 */
final class CallOutcome {

	/**
	 * Whether the callable ran.
	 *
	 * @var bool
	 */
	private bool $has_run = false;

	/**
	 * Value returned by the callable.
	 *
	 * @var mixed
	 */
	private mixed $result = null;

	/**
	 * Exception thrown by the callable.
	 *
	 * @var \Throwable|null
	 */
	private ?\Throwable $failure = null;

	/**
	 * Runs the callable once and records its outcome.
	 *
	 * @param callable $callback Caller's code.
	 * @return mixed Result of $callback.
	 * @throws \Throwable The exception of $callback, unchanged.
	 */
	public function run( callable $callback ): mixed {
		$this->has_run = true;
		try {
			$this->result = $callback();

			return $this->result;
		} catch ( \Throwable $failure ) {
			$this->failure = $failure;
			throw $failure;
		}
	}

	/**
	 * Whether the callable ran.
	 */
	public function has_run(): bool {
		return $this->has_run;
	}

	/**
	 * Whether the given exception is the one the callable threw.
	 *
	 * @param \Throwable $failure Exception.
	 */
	public function is_failure( \Throwable $failure ): bool {
		return null !== $this->failure && $failure === $this->failure;
	}

	/**
	 * Returns the recorded result again, or rethrows the recorded exception.
	 *
	 * @return mixed Recorded result.
	 * @throws \Throwable The recorded exception.
	 */
	public function replay(): mixed {
		if ( null !== $this->failure ) {
			throw $this->failure;
		}

		return $this->result;
	}
}
