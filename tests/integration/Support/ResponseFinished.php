<?php
/**
 * Stands in for `exit` at the end of a response in tests.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration\Support;

/**
 * Thrown by RecordingResponder::finish() so the test keeps running after the response.
 */
final class ResponseFinished extends \RuntimeException {
}
