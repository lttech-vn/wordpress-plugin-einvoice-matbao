<?php
/**
 * HTML fragments returned to htmx by the admin REST routes.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Admin;

use WP_REST_Response;

/**
 * Builds REST responses whose data is an HTML fragment and status notices shared by all routes.
 * Every dynamic value is escaped here; callers pass plain text.
 */
final class Fragment {

	public const STATUS_SUCCESS = 'success';
	public const STATUS_ERROR   = 'error';

	/**
	 * A response carrying an HTML fragment.
	 *
	 * @param string $html   Already-escaped HTML.
	 * @param int    $status HTTP status; htmx is configured to swap 4xx fragments too.
	 */
	public static function response( string $html, int $status = 200 ): WP_REST_Response {
		$response = new WP_REST_Response( $html, $status );
		$response->header( 'Content-Type', 'text/html; charset=' . get_option( 'blog_charset' ) );

		return $response;
	}

	/**
	 * A success notice response.
	 *
	 * @param string $message Plain-text message.
	 */
	public static function success( string $message ): WP_REST_Response {
		return self::response( self::notice( self::STATUS_SUCCESS, $message ) );
	}

	/**
	 * An error notice response.
	 *
	 * @param string $message Plain-text message.
	 * @param int    $status  HTTP status.
	 * @param string $detail  Optional plain-text detail, e.g. an API error code.
	 */
	public static function error( string $message, int $status = 422, string $detail = '' ): WP_REST_Response {
		return self::response( self::notice( self::STATUS_ERROR, $message, $detail ), $status );
	}

	/**
	 * A status notice. `data-einv-status` lets tests and scripts read the outcome without parsing text.
	 *
	 * @param string $status  STATUS_SUCCESS or STATUS_ERROR.
	 * @param string $message Plain-text message.
	 * @param string $detail  Optional plain-text detail.
	 */
	public static function notice( string $status, string $message, string $detail = '' ): string {
		$is_success = self::STATUS_SUCCESS === $status;
		$classes    = $is_success
			? 'border-einv-success/30 bg-einv-success-bg text-einv-success'
			: 'border-einv-error/30 bg-einv-error-bg text-einv-error';

		$html = sprintf(
			'<div class="mt-3 rounded-einv-md border px-3 py-2 text-[13px] leading-snug %1$s" role="%2$s" data-einv-status="%3$s"><strong class="font-medium">%4$s</strong> %5$s',
			esc_attr( $classes ),
			$is_success ? 'status' : 'alert',
			esc_attr( $status ),
			esc_html( $is_success ? __( 'Thành công:', 'einvoice-matbao' ) : __( 'Lỗi:', 'einvoice-matbao' ) ),
			esc_html( $message )
		);

		if ( '' !== $detail ) {
			$html .= sprintf( ' <span class="text-einv-ink-mute">(%s)</span>', esc_html( $detail ) );
		}

		return $html . '</div>';
	}
}
