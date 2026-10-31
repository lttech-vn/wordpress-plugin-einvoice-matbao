<?php
/**
 * Removes sensitive text before it is shown to an admin.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Invoice;

/**
 * Stored reasons and Mat Bao messages may echo buyer data or URLs. Everything shown in the admin
 * goes through here: national ID numbers (CMND 9 digits, CCCD 12 digits) keep only their last
 * four digits and URLs are dropped.
 */
final class Redactor {

	/**
	 * Masks ID numbers and removes URLs.
	 *
	 * @param string $text Plain text.
	 */
	public static function redact( string $text ): string {
		$text = (string) preg_replace( '#https?://\S+#i', '[' . __( 'đã ẩn', 'einvoice-matbao' ) . ']', $text );

		return (string) preg_replace_callback(
			'/(?<!\d)\d{9}(?:\d{3})?(?!\d)/',
			static fn( array $found ): string => str_repeat( '*', strlen( $found[0] ) - 4 ) . substr( $found[0], -4 ),
			$text
		);
	}
}
