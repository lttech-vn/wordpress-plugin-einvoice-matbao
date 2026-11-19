<?php
/**
 * PdfResponder that records the response instead of sending it.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration\Support;

use LTTech\EinvoiceMatbao\Customer\PdfResponder;

/**
 * Overrides only the two output primitives; send(), headers() and redirect() stay production code.
 */
final class RecordingResponder extends PdfResponder {

	/**
	 * Headers given to emit(), or null when nothing was sent.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $headers = null;

	/**
	 * Body given to emit(), or null when nothing was sent.
	 *
	 * @var string|null
	 */
	public ?string $body = null;

	/**
	 * Number of times send() asked to clean output buffers.
	 *
	 * @var int
	 */
	public int $buffer_cleanings = 0;

	/**
	 * Records the call and keeps PHPUnit's own buffer open.
	 *
	 * @param int $keep Levels to keep open.
	 */
	protected function clean_buffers( int $keep = 0 ): void {
		++$this->buffer_cleanings;
	}

	/**
	 * Records headers and body.
	 *
	 * @param array<string, mixed> $headers Header name => value.
	 * @param string               $body    Response body.
	 */
	protected function emit( array $headers, string $body ): void {
		$this->headers = $headers;
		$this->body    = $body;
	}

	/**
	 * Stops the handler like `exit` would, without ending the test process.
	 *
	 * @throws ResponseFinished Always.
	 */
	protected function finish(): never {
		throw new ResponseFinished();
	}
}
