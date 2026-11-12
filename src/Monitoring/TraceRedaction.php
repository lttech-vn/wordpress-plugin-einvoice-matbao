<?php
/**
 * Redaction of tracing metadata that keeps protocol identifiers intact.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Monitoring;

/**
 * Trace contexts and the dynamic sampling context mix identifiers that link events together
 * (trace and span IDs, sampling values, release) with free text (transaction name, op,
 * description, data). Passing the whole structure to the Redactor would mask the 32-hex trace
 * ID as an opaque token and break trace linking, so identifiers are kept when they have their
 * expected shape and everything else is masked. An identifier with an unexpected shape is
 * treated as free text (fail closed).
 */
final class TraceRedaction {

	private const HEX_32 = '/^[0-9a-f]{32}$/';
	private const HEX_16 = '/^[0-9a-f]{16}$/';

	/**
	 * Trace context keys that are identifiers or enums, with their expected shape.
	 */
	private const TRACE_CONTEXT_IDS = array(
		'trace_id'       => self::HEX_32,
		'span_id'        => self::HEX_16,
		'parent_span_id' => self::HEX_16,
		'origin'         => '/^[a-z_.]+$/',
		'status'         => '/^[a-z_]+$/',
	);

	/**
	 * Dynamic sampling context keys that are identifiers or numbers, with their expected shape.
	 * `release`, `environment` and `public_key` are checked against the configuration instead.
	 */
	private const DSC_IDS = array(
		'trace_id'    => self::HEX_32,
		'sample_rate' => '/^[0-9.eE+-]+$/',
		'sample_rand' => '/^[0-9.eE+-]+$/',
		'sampled'     => '/^(true|false)$/',
	);

	/**
	 * Creates the redaction for one client configuration.
	 *
	 * @param array<string, string> $configured DSC values that come from the configuration
	 *                                          (`release`, `environment`, `public_key`).
	 */
	public function __construct( private readonly array $configured ) {}

	/**
	 * Masks a `contexts.trace` array: identifiers kept, op/description/data/tags masked.
	 *
	 * @param array<array-key, mixed> $trace    Trace context.
	 * @param Redactor                $redactor Redactor with the current known values.
	 * @return array<array-key, mixed>
	 */
	public function trace_context( array $trace, Redactor $redactor ): array {
		$masked = array();
		foreach ( $trace as $key => $value ) {
			$pattern = self::TRACE_CONTEXT_IDS[ $key ] ?? null;
			if ( null !== $pattern && is_string( $value ) && 1 === preg_match( $pattern, $value ) ) {
				$masked[ $key ] = $value;
			} elseif ( 'data' === $key && is_array( $value ) ) {
				$masked[ $key ] = LogAttributes::normalize( $value, $redactor );
			} elseif ( 'tags' === $key && is_array( $value ) ) {
				$masked[ $key ] = $redactor->tags( $value );
			} else {
				$masked[ $key ] = $redactor->value( $value );
			}
		}

		return $masked;
	}

	/**
	 * Masks one dynamic sampling context entry: identifiers kept, free text (transaction) masked.
	 *
	 * @param string   $key      Entry key.
	 * @param string   $value    Entry value.
	 * @param Redactor $redactor Redactor with the current known values.
	 */
	public function dsc_entry( string $key, string $value, Redactor $redactor ): string {
		if ( isset( $this->configured[ $key ] ) && $this->configured[ $key ] === $value ) {
			return $value;
		}

		$pattern = self::DSC_IDS[ $key ] ?? null;
		if ( null !== $pattern && 1 === preg_match( $pattern, $value ) ) {
			return $value;
		}

		return $redactor->text( $value );
	}
}
