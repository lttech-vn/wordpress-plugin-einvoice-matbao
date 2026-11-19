<?php
/**
 * Sends the invoice PDF or the error redirect to the browser.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Customer;

/**
 * Thin output seam: tests override only the output primitives (clean_buffers(), disable_compression(),
 * emit(), finish()), so the real header list is observed.
 */
class PdfResponder {

	/**
	 * Sends the PDF as an uncached attachment and ends the request.
	 *
	 * @param string $filename Safe file name (ASCII letters, digits, `-`, `.pdf`).
	 * @param string $bytes    PDF bytes.
	 */
	public function send( string $filename, string $bytes ): void {
		$this->clean_buffers();
		$this->disable_compression();
		$this->emit( self::headers( $filename, strlen( $bytes ) ) + wp_get_nocache_headers(), $bytes );
		$this->finish();
	}

	/**
	 * Redirects within the site and ends the request.
	 *
	 * @param string $url Target URL.
	 */
	public function redirect( string $url ): void {
		wp_safe_redirect( $url );
		$this->finish();
	}

	/**
	 * Headers of the PDF response, without the no-cache headers.
	 *
	 * @param string $filename Safe file name.
	 * @param int    $length   Byte length of the PDF.
	 * @return array<string, string>
	 */
	public static function headers( string $filename, int $length ): array {
		return array(
			'Content-Type'           => 'application/pdf',
			'Content-Disposition'    => 'attachment; filename="' . $filename . '"',
			'X-Content-Type-Options' => 'nosniff',
			'Content-Length'         => (string) $length,
		);
	}

	/**
	 * Closes output buffers so stray output or buffering cannot corrupt the PDF bytes.
	 *
	 * @param int $keep Number of outer buffer levels to leave open (0 in production).
	 */
	protected function clean_buffers( int $keep = 0 ): void {
		if ( 0 === $keep && function_exists( 'wc_clean_buffers' ) ) {
			wc_clean_buffers();
			return;
		}
		while ( ob_get_level() > $keep ) {
			ob_end_clean();
		}
	}

	/**
	 * Whether a `zlib.output_compression` value means PHP gzips the response.
	 *
	 * @param string|false $setting Value from ini_get(); false when zlib is not loaded.
	 */
	public static function compression_is_on( string|false $setting ): bool {
		if ( false === $setting ) {
			return false;
		}
		$setting = strtolower( trim( $setting ) );

		return '' !== $setting && 'off' !== $setting && '0' !== $setting;
	}

	/**
	 * Turns off PHP's own gzip so Content-Length matches the bytes sent (as WooCommerce downloads do).
	 */
	protected function disable_compression(): void {
		if ( self::compression_is_on( ini_get( 'zlib.output_compression' ) ) ) {
			ini_set( 'zlib.output_compression', 'Off' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- binary download must not be gzipped behind Content-Length.
		}
	}

	/**
	 * Writes headers and body. A `false` value (from wp_get_nocache_headers()) removes the header.
	 *
	 * @param array<string, string|false> $headers Header name => value.
	 * @param string                      $body    Response body.
	 */
	protected function emit( array $headers, string $body ): void {
		foreach ( $headers as $name => $value ) {
			if ( false === $value ) {
				header_remove( $name );
			} else {
				header( $name . ': ' . $value );
			}
		}
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF bytes sent as the response body.
	}

	/**
	 * Ends the request.
	 */
	protected function finish(): never {
		exit;
	}
}
