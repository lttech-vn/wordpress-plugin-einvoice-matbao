<?php
/**
 * REST routes behind the htmx settings page.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Admin;

use LTTech\EinvoiceMatbao\CredentialStore;
use LTTech\EinvoiceMatbao\Encryption;
use LTTech\EinvoiceMatbao\Lark\LarkClient;
use LTTech\EinvoiceMatbao\Lark\LarkError;
use LTTech\EinvoiceMatbao\Lark\LarkWebhook;
use LTTech\EinvoiceMatbao\Matbao\MatbaoClient;
use LTTech\EinvoiceMatbao\Matbao\MatbaoCredentials;
use LTTech\EinvoiceMatbao\Matbao\MatbaoError;
use LTTech\EinvoiceMatbao\Matbao\MatbaoTemplate;
use LTTech\EinvoiceMatbao\Settings;
use WP_Error;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Settings routes under `einvoice-matbao/v1`. Every route returns an HTML fragment, is limited
 * to Administrators (`manage_options`) and requires a valid `wp_rest` nonce in `X-WP-Nonce`.
 */
final class SettingsController {

	public const NAMESPACE = 'einvoice-matbao/v1';

	/** Fired on the client (HX-Trigger) after Mat Bao credentials are saved, to reload templates. */
	public const EVENT_MATBAO_SAVED = 'einv-matbao-saved';

	/**
	 * Registers the routes. Hooked to `rest_api_init`.
	 */
	public static function register_routes(): void {
		$routes = array(
			'/settings/templates'   => array( WP_REST_Server::READABLE, 'list_templates' ),
			'/settings/matbao'      => array( WP_REST_Server::CREATABLE, 'save_matbao' ),
			'/settings/matbao/test' => array( WP_REST_Server::CREATABLE, 'test_matbao' ),
			'/settings/lark'        => array( WP_REST_Server::CREATABLE, 'save_lark' ),
			'/settings/lark/test'   => array( WP_REST_Server::CREATABLE, 'test_lark' ),
			'/settings/template'    => array( WP_REST_Server::CREATABLE, 'save_template' ),
			'/settings/invoicing'   => array( WP_REST_Server::CREATABLE, 'save_invoicing' ),
			'/settings/unit'        => array( WP_REST_Server::CREATABLE, 'save_unit' ),
		);

		foreach ( $routes as $route => list( $methods, $handler ) ) {
			register_rest_route(
				self::NAMESPACE,
				$route,
				array(
					'methods'             => $methods,
					'callback'            => array( new self(), $handler ),
					'permission_callback' => array( self::class, 'check_permission' ),
				)
			);
		}
	}

	/**
	 * Allows logged-in Administrators presenting a valid `wp_rest` nonce.
	 *
	 * Core only verifies the nonce for cookie-authenticated requests inside serve_request(), so it
	 * is checked here too: every route requires it, regardless of how the user authenticated.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function check_permission( WP_REST_Request $request ): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'Bạn cần đăng nhập để thực hiện thao tác này.', 'einvoice-matbao' ), array( 'status' => 401 ) );
		}

		$nonce = (string) $request->get_header( 'X-WP-Nonce' );
		if ( '' === $nonce || false === wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'rest_cookie_invalid_nonce', __( 'Phiên làm việc đã hết hạn. Hãy tải lại trang.', 'einvoice-matbao' ), array( 'status' => 403 ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Chỉ Administrator được quản lý cấu hình hóa đơn.', 'einvoice-matbao' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Serves plugin route responses as HTML instead of JSON. Hooked to `rest_pre_serve_request`.
	 *
	 * Error responses produced by core (permission, routing, validation) carry
	 * `{code, message, data}`; they are rendered as an error notice so htmx can swap them in.
	 *
	 * @param bool             $served  Whether the request has already been served.
	 * @param WP_HTTP_Response $result  Response to send.
	 * @param WP_REST_Request  $request Request.
	 * @param WP_REST_Server   $server  Server instance.
	 */
	public static function serve_html( $served, $result, $request, $server ): bool {
		if ( $served || ! str_starts_with( $request->get_route(), '/' . self::NAMESPACE . '/' ) ) {
			return (bool) $served;
		}

		$data = $result->get_data();
		if ( is_string( $data ) ) {
			$html = $data;
		} else {
			$message = is_array( $data ) && is_string( $data['message'] ?? null ) ? $data['message'] : __( 'Yêu cầu không hợp lệ.', 'einvoice-matbao' );
			$html    = Fragment::notice( Fragment::STATUS_ERROR, $message );
		}

		if ( ! headers_sent() ) {
			$server->send_header( 'Content-Type', 'text/html; charset=' . get_option( 'blog_charset' ) );
		}

		if ( 'HEAD' !== $request->get_method() ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fragments are escaped when built (Fragment, SettingsPage).
		}

		return true;
	}

	/**
	 * Saves Mat Bao credentials, encrypted. A blank password keeps the stored one.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function save_matbao( WP_REST_Request $request ): WP_REST_Response {
		$store = new CredentialStore();
		if ( ! ( new Encryption() )->is_available() ) {
			return self::missing_key_error();
		}

		$environment = self::text_param( $request, 'environment' );
		$mst         = self::text_param( $request, 'mst' );
		$username    = self::text_param( $request, 'username' );
		$password    = self::raw_param( $request, 'password' );
		$previous    = $store->matbao();

		if ( '' === $password && null !== $previous ) {
			$password = $previous->password;
		}

		if ( ! isset( MatbaoCredentials::BASE_URLS[ $environment ] ) ) {
			return Fragment::error( __( 'Môi trường không hợp lệ.', 'einvoice-matbao' ) );
		}
		if ( '' === $mst || '' === $username || '' === $password ) {
			return Fragment::error( __( 'Cần nhập đủ mã số thuế, tên đăng nhập và mật khẩu.', 'einvoice-matbao' ) );
		}
		if ( mb_strlen( $mst ) > MatbaoCredentials::MAX_MST_LENGTH
			|| mb_strlen( $username ) > MatbaoCredentials::MAX_USERNAME_LENGTH
			|| mb_strlen( $password ) > MatbaoCredentials::MAX_PASSWORD_LENGTH ) {
			return Fragment::error( __( 'Mã số thuế tối đa 20 ký tự, tên đăng nhập và mật khẩu tối đa 50 ký tự.', 'einvoice-matbao' ) );
		}

		if ( null !== $previous ) {
			( new MatbaoClient( $previous ) )->forget_token();
		}
		$store->save_matbao( new MatbaoCredentials( $environment, $mst, $username, $password ) );

		$response = Fragment::success( __( 'Đã lưu thông tin kết nối Mắt Bão (mã hóa).', 'einvoice-matbao' ) );
		$response->header( 'HX-Trigger', self::EVENT_MATBAO_SAVED );

		return $response;
	}

	/**
	 * Logs in to Mat Bao with the stored credentials and reports the outcome.
	 */
	public function test_matbao(): WP_REST_Response {
		$credentials = $this->stored_matbao_credentials();
		if ( $credentials instanceof WP_REST_Response ) {
			return $credentials;
		}

		try {
			( new MatbaoClient( $credentials ) )->test_connection();
		} catch ( MatbaoError $error ) {
			return self::matbao_error( $error );
		}

		return Fragment::success( __( 'Kết nối Mắt Bão thành công.', 'einvoice-matbao' ) );
	}

	/**
	 * Lists the VAT templates of the current year from Mat Bao as a selection form.
	 */
	public function list_templates(): WP_REST_Response {
		$credentials = $this->stored_matbao_credentials();
		if ( $credentials instanceof WP_REST_Response ) {
			return $credentials;
		}

		try {
			$templates = $this->vat_templates( $credentials );
		} catch ( MatbaoError $error ) {
			return self::matbao_error( $error );
		}

		return Fragment::response( SettingsPage::render_template_form( $templates, ( new Settings() )->default_template() ) );
	}

	/**
	 * Saves the default template after confirming Mat Bao still offers it with numbers left.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function save_template( WP_REST_Request $request ): WP_REST_Response {
		$credentials = $this->stored_matbao_credentials();
		if ( $credentials instanceof WP_REST_Response ) {
			return $credentials;
		}

		$choice = explode( '|', self::text_param( $request, 'template' ), 2 );
		if ( 2 !== count( $choice ) ) {
			return Fragment::error( __( 'Hãy chọn một mẫu hóa đơn.', 'einvoice-matbao' ) );
		}

		try {
			$templates = $this->vat_templates( $credentials );
		} catch ( MatbaoError $error ) {
			return self::matbao_error( $error );
		}

		foreach ( $templates as $template ) {
			if ( $template->form === $choice[0] && $template->series === $choice[1] && $template->has_remaining_numbers() ) {
				( new Settings() )->save_default_template( $template->form, $template->series );

				/* translators: 1: template form number (KHMSHDon), 2: template series (KHHDon). */
				return Fragment::success( sprintf( __( 'Đã chọn mẫu mặc định %1$s / %2$s.', 'einvoice-matbao' ), $template->form, $template->series ) );
			}
		}

		return Fragment::error( __( 'Mẫu này không còn hiệu lực hoặc đã hết số trên Mắt Bão. Hãy chọn mẫu khác.', 'einvoice-matbao' ) );
	}

	/**
	 * Saves the `MTChieu` prefix and the walk-in buyer name.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function save_invoicing( WP_REST_Request $request ): WP_REST_Response {
		$prefix = trim( self::raw_param( $request, 'reference_prefix' ) );
		$name   = self::text_param( $request, 'walk_in_buyer_name' );

		if ( ! Settings::is_valid_reference_prefix( $prefix ) ) {
			return Fragment::error(
				sprintf(
					/* translators: %d: maximum prefix length. */
					__( 'Prefix chỉ gồm chữ cái không dấu và chữ số, tối đa %d ký tự, để mã tham chiếu (MTChieu) không vượt 20 ký tự.', 'einvoice-matbao' ),
					Settings::MAX_REFERENCE_PREFIX_LENGTH
				)
			);
		}
		if ( mb_strlen( $name ) > Settings::MAX_BUYER_NAME_LENGTH ) {
			return Fragment::error( __( 'Tên người mua vãng lai tối đa 400 ký tự.', 'einvoice-matbao' ) );
		}

		$settings = new Settings();
		$settings->save_invoicing( $prefix, $name );

		return Fragment::success(
			sprintf(
				/* translators: 1: example reference code, 2: walk-in buyer name. */
				__( 'Đã lưu. Ví dụ MTChieu: %1$s. Tên người mua vãng lai: %2$s.', 'einvoice-matbao' ),
				$prefix . Settings::REFERENCE_MARKER . '1234',
				$settings->walk_in_buyer_name()
			)
		);
	}

	/**
	 * Saves the store-wide default unit of measure (`DVTinh`).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function save_unit( WP_REST_Request $request ): WP_REST_Response {
		$unit = self::text_param( $request, 'default_unit' );

		if ( mb_strlen( $unit ) > Settings::MAX_UNIT_LENGTH ) {
			return Fragment::error( __( 'Đơn vị tính tối đa 50 ký tự.', 'einvoice-matbao' ) );
		}

		$settings = new Settings();
		$settings->save_default_unit( $unit );

		return Fragment::success(
			sprintf(
				/* translators: %s: default unit of measure. */
				__( 'Đã lưu. Đơn vị tính mặc định: %s.', 'einvoice-matbao' ),
				$settings->default_unit()
			)
		);
	}

	/**
	 * Saves the Lark webhook, encrypted. Blank fields keep the stored values.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function save_lark( WP_REST_Request $request ): WP_REST_Response {
		$store = new CredentialStore();
		if ( ! ( new Encryption() )->is_available() ) {
			return self::missing_key_error();
		}

		$previous = $store->lark();
		$url      = trim( self::raw_param( $request, 'webhook_url' ) );
		$secret   = trim( self::raw_param( $request, 'secret' ) );

		$clear_secret = '' !== self::raw_param( $request, 'clear_secret' );

		if ( '' === $url && null !== $previous ) {
			$url = $previous->webhook_url;
		}
		// The stored secret belongs to the stored bot: keep it only for the same webhook, never send it to another one.
		if ( '' === $secret && ! $clear_secret && null !== $previous && $previous->webhook_url === $url ) {
			$secret = $previous->secret;
		}

		if ( ! LarkWebhook::is_valid_url( $url ) ) {
			return Fragment::error( __( 'Webhook phải là URL HTTPS của custom bot Lark (open.larksuite.com/open-apis/bot/v2/hook/...).', 'einvoice-matbao' ) );
		}

		$store->save_lark( new LarkWebhook( $url, $secret ) );

		return Fragment::success( __( 'Đã lưu webhook Lark (mã hóa).', 'einvoice-matbao' ) );
	}

	/**
	 * Sends a test message to the stored Lark webhook and reports the outcome.
	 */
	public function test_lark(): WP_REST_Response {
		$store   = new CredentialStore();
		$webhook = $store->lark();
		if ( null === $webhook ) {
			return $store->has_lark() ? self::undecryptable_error() : Fragment::error( __( 'Chưa có webhook Lark. Hãy lưu webhook trước.', 'einvoice-matbao' ) );
		}

		try {
			( new LarkClient( $webhook ) )->send_text(
				sprintf(
					/* translators: %s: site address. */
					__( '[Hóa đơn Mắt Bão] Tin nhắn kiểm tra kết nối từ %s.', 'einvoice-matbao' ),
					(string) wp_parse_url( home_url(), PHP_URL_HOST )
				)
			);
		} catch ( LarkError $error ) {
			return Fragment::response(
				Fragment::notice(
					Fragment::STATUS_ERROR,
					__( 'Lark không nhận tin nhắn kiểm tra.', 'einvoice-matbao' ),
					null === $error->lark_code ? $error->getMessage() : $error->lark_code . ': ' . $error->getMessage()
				)
			);
		}

		return Fragment::success( __( 'Đã gửi tin nhắn kiểm tra tới Lark.', 'einvoice-matbao' ) );
	}

	/**
	 * Year of the invoice templates to offer. Mat Bao series carry the Vietnam-time year (e.g. C26...),
	 * so it does not follow the site timezone.
	 *
	 * @param \DateTimeImmutable|null $now Instant to evaluate; defaults to the current time.
	 */
	public static function template_year( ?\DateTimeImmutable $now = null ): int {
		return (int) ( $now ?? new \DateTimeImmutable( 'now' ) )->setTimezone( new \DateTimeZone( 'Asia/Ho_Chi_Minh' ) )->format( 'Y' );
	}

	/**
	 * VAT templates of the current year, in Mat Bao order.
	 *
	 * @param MatbaoCredentials $credentials Stored credentials.
	 * @return list<MatbaoTemplate>
	 * @throws MatbaoError When Mat Bao cannot be reached or rejects the call.
	 */
	private function vat_templates( MatbaoCredentials $credentials ): array {
		$templates = ( new MatbaoClient( $credentials ) )->templates( self::template_year() );

		return array_values( array_filter( $templates, static fn( MatbaoTemplate $template ): bool => $template->is_vat() ) );
	}

	/**
	 * Stored Mat Bao credentials, or an error fragment explaining why they are unavailable.
	 */
	private function stored_matbao_credentials(): MatbaoCredentials|WP_REST_Response {
		$store       = new CredentialStore();
		$credentials = $store->matbao();
		if ( null !== $credentials ) {
			return $credentials;
		}

		return $store->has_matbao()
			? self::undecryptable_error()
			: Fragment::error( __( 'Chưa có thông tin kết nối Mắt Bão. Hãy lưu thông tin kết nối trước.', 'einvoice-matbao' ) );
	}

	/**
	 * Error fragment for a failed Mat Bao call, with the Mat Bao code but no secrets.
	 *
	 * @param MatbaoError $error Failure.
	 */
	private static function matbao_error( MatbaoError $error ): WP_REST_Response {
		if ( MatbaoError::KIND_TRANSPORT === $error->kind ) {
			return Fragment::response( Fragment::notice( Fragment::STATUS_ERROR, __( 'Không kết nối được tới Mắt Bão.', 'einvoice-matbao' ), $error->getMessage() ) );
		}

		$code = $error->api_code ?? $error->http_status;

		return Fragment::response(
			Fragment::notice(
				Fragment::STATUS_ERROR,
				$error->is_unauthorized() ? __( 'Mắt Bão từ chối thông tin đăng nhập.', 'einvoice-matbao' ) : __( 'Mắt Bão trả về lỗi.', 'einvoice-matbao' ),
				null === $code ? $error->getMessage() : $code . ': ' . $error->getMessage()
			)
		);
	}

	/**
	 * Refusal when the encryption key constant is missing.
	 */
	private static function missing_key_error(): WP_REST_Response {
		return Fragment::error(
			sprintf(
				/* translators: 1: constant name, 2: wp-config.php. */
				__( 'Không lưu được: chưa cấu hình khóa mã hóa. Thêm hằng %1$s (chuỗi ngẫu nhiên tối thiểu 32 ký tự) vào %2$s rồi thử lại.', 'einvoice-matbao' ),
				Encryption::KEY_CONSTANT,
				'wp-config.php'
			)
		);
	}

	/**
	 * Error when stored credentials exist but cannot be decrypted with the current key.
	 */
	private static function undecryptable_error(): WP_REST_Response {
		return Fragment::error(
			sprintf(
				/* translators: %s: constant name. */
				__( 'Không giải mã được thông tin đã lưu (thiếu hoặc đã đổi %s). Hãy nhập lại.', 'einvoice-matbao' ),
				Encryption::KEY_CONSTANT
			)
		);
	}

	/**
	 * A sanitized single-line text parameter.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $name    Parameter name.
	 */
	private static function text_param( WP_REST_Request $request, string $name ): string {
		return trim( sanitize_text_field( self::raw_param( $request, $name ) ) );
	}

	/**
	 * A raw string parameter, for secrets whose characters must be kept as typed.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $name    Parameter name.
	 */
	private static function raw_param( WP_REST_Request $request, string $name ): string {
		$value = $request->get_param( $name );

		return is_string( $value ) ? $value : '';
	}
}
