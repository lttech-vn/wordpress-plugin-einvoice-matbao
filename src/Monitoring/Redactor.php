<?php
/**
 * Contract for removing personal data from monitoring payloads.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Monitoring;

interface Redactor {

	/**
	 * Masks personal data in a string.
	 *
	 * @param string $s Raw text.
	 */
	public function text( string $s ): string;

	/**
	 * Masks every string inside a scalar or nested array; other types pass through.
	 *
	 * @param mixed $v Raw value.
	 */
	public function value( mixed $v ): mixed;

	/**
	 * Keeps only allow-listed tags and masks their values.
	 *
	 * @param array<string, mixed> $tags Raw tags.
	 * @return array<string, mixed>
	 */
	public function tags( array $tags ): array;

	/**
	 * Returns a redactor that also masks the given exact values.
	 *
	 * @param array<int, string> $values Known sensitive values (for example the buyer's name).
	 */
	public function with_known_values( array $values ): static;
}
