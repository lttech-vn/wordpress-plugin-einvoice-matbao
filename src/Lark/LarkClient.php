<?php
/**
 * Sends text messages to a Lark custom bot webhook.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Lark;

/**
 * Minimal Lark custom bot client. Uses the WordPress HTTP API (fake-able in tests) and never
 * puts the webhook URL or secret into error messages.
 */
final class LarkClient {

	private const TIMEOUT_SECONDS = 10;

	/**
	 * Creates the client.
	 *
	 * @param LarkWebhook $webhook Webhook and signing secret.
	 */
	public function __construct( private readonly LarkWebhook $webhook ) {
	}

	/**
	 * Sends a plain-text message.
	 *
	 * @param string $text Message text; must not contain personal data.
	 * @throws LarkError When Lark does not accept the message.
	 */
	public function send_text( string $text ): void {
		$body = array(
			'msg_type' => 'text',
			'content'  => array( 'text' => $text ),
		);

		if ( '' !== $this->webhook->secret ) {
			$timestamp         = (string) time();
			$body['timestamp'] = $timestamp;
			$body['sign']      = self::sign( $timestamp, $this->webhook->secret );
		}

		$response = wp_remote_post(
			$this->webhook->webhook_url,
			array(
				'headers'     => array( 'Content-Type' => 'application/json' ),
				'body'        => (string) wp_json_encode( $body ),
				'timeout'     => self::TIMEOUT_SECONDS,
				'redirection' => 0,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new LarkError( 'Request did not complete (' . $response->get_error_code() . ').' );
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		$decoded = is_array( $decoded ) ? $decoded : array();
		$code    = is_numeric( $decoded['code'] ?? $decoded['StatusCode'] ?? null ) ? (int) ( $decoded['code'] ?? $decoded['StatusCode'] ) : null;
		$message = $decoded['msg'] ?? $decoded['StatusMessage'] ?? '';

		if ( $status < 200 || $status >= 300 || 0 !== $code ) {
			throw new LarkError( is_string( $message ) && '' !== $message ? $message : 'HTTP ' . $status, $code );
		}
	}

	/**
	 * Lark custom bot signature: base64(HMAC-SHA256(key = "timestamp\nsecret", message = "")).
	 *
	 * @param string $timestamp Unix timestamp in seconds.
	 * @param string $secret    Signing secret.
	 */
	private static function sign( string $timestamp, #[\SensitiveParameter] string $secret ): string {
		return base64_encode( hash_hmac( 'sha256', '', $timestamp . "\n" . $secret, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Lark signature format.
	}
}
