<?php
/**
 * Settings page under the WooCommerce menu.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Admin;

use LTTech\EinvoiceMatbao\CredentialStore;
use LTTech\EinvoiceMatbao\Encryption;
use LTTech\EinvoiceMatbao\Matbao\MatbaoCredentials;
use LTTech\EinvoiceMatbao\Matbao\MatbaoTemplate;
use LTTech\EinvoiceMatbao\Settings;
use LTTech\EinvoiceMatbao\Uninstall\UninstallSettingsController;

/**
 * Renders the htmx settings page inside `#einv-app` and loads its scoped assets.
 * Forms post to SettingsController routes; secrets are never rendered back.
 */
final class SettingsPage {

	public const SLUG   = 'einvoice-matbao-settings';
	public const HANDLE = 'einvoice-matbao-admin';

	/** Capability required for the page and every settings route. */
	public const CAPABILITY = 'manage_options';

	private const FIELD     = 'mt-1 block w-full max-w-md rounded-einv-sm border border-einv-hairline-input bg-white px-3 py-2 text-[15px] text-einv-ink shadow-none focus:border-einv-primary focus:outline-none';
	private const LABEL     = 'block text-[13px] font-medium text-einv-ink-secondary';
	private const HELP      = 'mt-1 text-[13px] text-einv-ink-mute';
	private const BUTTON    = 'inline-flex cursor-pointer items-center rounded-full border-0 bg-einv-primary px-4 py-2 text-[14px] font-normal text-white hover:bg-einv-primary-deep active:bg-einv-primary-press';
	private const SECONDARY = 'inline-flex cursor-pointer items-center rounded-full border border-einv-hairline bg-white px-4 py-2 text-[14px] font-normal text-einv-primary hover:border-einv-primary';
	private const CARD      = 'mb-6 rounded-einv-lg border border-einv-hairline bg-white p-6 shadow-einv-card';
	private const TITLE     = 'm-0 mb-1 text-[20px] font-light tracking-[-0.2px] text-einv-ink';
	private const TAB_BAR   = 'flex gap-6 overflow-x-auto border-b border-einv-hairline';
	private const TAB_LINK  = '-mb-px whitespace-nowrap border-b-2 px-1 pb-3 pt-1 text-[14px] no-underline focus:!shadow-none focus-visible:!outline-2 focus-visible:!outline-solid focus-visible:!outline-offset-2 focus-visible:!outline-einv-primary';

	/**
	 * Hooks the menu and assets.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ), 60 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		InvoicePage::register();
	}

	/**
	 * Adds the submenu under WooCommerce.
	 */
	public static function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Hóa đơn Mắt Bão', 'einvoice-matbao' ),
			__( 'Hóa đơn Mắt Bão', 'einvoice-matbao' ),
			self::CAPABILITY,
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * Loads the scoped CSS and vendored htmx on this page only, and attaches the REST nonce to
	 * every htmx request through `htmx:configRequest`.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( 'woocommerce_page_' . self::SLUG !== $hook_suffix ) {
			return;
		}

		$base = plugins_url( 'assets/dist/', EINVOICE_MATBAO_FILE );
		wp_enqueue_style( self::HANDLE, $base . 'admin.css', array(), EINVOICE_MATBAO_VERSION );
		wp_enqueue_script( self::HANDLE, $base . 'htmx.min.js', array(), EINVOICE_MATBAO_VERSION, true );
		wp_add_inline_script( self::HANDLE, self::htmx_config_script( wp_create_nonce( 'wp_rest' ) ) );
	}

	/**
	 * Renders the page. Outputs nothing for users without the capability.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$has_key = ( new Encryption() )->is_available();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only view switch, whitelisted by AdminTabs::resolve.
		$tab = AdminTabs::resolve( isset( $_GET['tab'] ) ? wp_unslash( $_GET['tab'] ) : '' );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Hóa đơn Mắt Bão', 'einvoice-matbao' ); ?></h1>
			<div id="einv-app" class="mt-4 font-einv text-einv-ink" hx-swap="innerHTML">

				<?php if ( ! $has_key ) : ?>
					<div class="mb-6 max-w-3xl rounded-einv-md border border-einv-error/30 bg-einv-error-bg px-4 py-3 text-[13px] text-einv-error" role="alert" data-einv-status="error">
						<?php
						printf(
							/* translators: 1: constant name, 2: wp-config.php. */
							esc_html__( 'Chưa cấu hình khóa mã hóa: thêm hằng %1$s (chuỗi ngẫu nhiên tối thiểu 32 ký tự) vào %2$s. Khi thiếu khóa, plugin không lưu credential Mắt Bão và Lark.', 'einvoice-matbao' ),
							'<code>' . esc_html( Encryption::KEY_CONSTANT ) . '</code>',
							'<code>wp-config.php</code>'
						);
						?>
					</div>
				<?php endif; ?>

				<div id="einv-tabs" hx-history-elt>
					<?php echo self::render_tabs( $tab ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_tabs returns escaped HTML. ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * The tab bar and the panel of one tab; shared by the page and the tab fragment route.
	 *
	 * @param string $tab Tab key, already resolved through AdminTabs::resolve().
	 * @return string Escaped HTML.
	 */
	public static function render_tabs( string $tab ): string {
		$links = '';
		foreach ( AdminTabs::labels() as $key => $text ) {
			$url    = esc_url( AdminTabs::url( $key ) );
			$active = $key === $tab;
			$links .= sprintf(
				'<a href="%1$s" hx-get="%2$s" hx-target="#einv-tabs" hx-push-url="%1$s" data-einv-tab-link="%3$s" class="%4$s"%5$s>%6$s</a>',
				$url,
				esc_url( rest_url( SettingsController::NAMESPACE . '/tabs/' . $key ) ),
				esc_attr( $key ),
				esc_attr( self::TAB_LINK . ( $active ? ' border-einv-primary font-medium !text-einv-primary focus:!text-einv-primary' : ' border-transparent !text-einv-ink-mute hover:!text-einv-ink focus:!text-einv-ink' ) ),
				$active ? ' aria-current="page"' : '',
				esc_html( $text )
			);
		}

		$panel = match ( $tab ) {
			'connection' => self::render_connection_panel(),
			'invoicing'  => self::render_invoicing_panel(),
			'lark'       => self::render_lark_panel(),
			'advanced'   => UninstallSettingsController::render_section(),
			default      => InvoicePage::render_panel(),
		};
		if ( 'invoices' !== $tab ) {
			$panel = '<div class="max-w-3xl">' . $panel . '</div>';
		}

		return sprintf(
			'<nav class="%1$s" aria-label="%2$s">%3$s</nav><div id="einv-tab-panel" class="mt-6" data-einv-tab="%4$s">%5$s</div>',
			esc_attr( self::TAB_BAR ),
			esc_attr__( 'Hóa đơn Mắt Bão', 'einvoice-matbao' ),
			$links,
			esc_attr( $tab ),
			$panel
		);
	}

	/**
	 * Connection and default template cards.
	 */
	private static function render_connection_panel(): string {
		$store       = new CredentialStore();
		$settings    = new Settings();
		$credentials = $store->matbao();
		$template    = $settings->default_template();
		$environment = null !== $credentials ? $credentials->environment : 'demo';

		ob_start();
		?>
				<section class="<?php echo esc_attr( self::CARD ); ?>">
					<h2 class="<?php echo esc_attr( self::TITLE ); ?>"><?php esc_html_e( 'Kết nối Mắt Bão', 'einvoice-matbao' ); ?></h2>
					<p class="<?php echo esc_attr( self::HELP ); ?> mb-4"><?php esc_html_e( 'Thông tin đăng nhập API lấy trong mục cấu hình API của trang quản lý hóa đơn Mắt Bão. Mật khẩu được lưu mã hóa và không hiển thị lại.', 'einvoice-matbao' ); ?></p>
					<form hx-post="<?php echo esc_url( rest_url( SettingsController::NAMESPACE . '/settings/matbao' ) ); ?>" hx-target="next .einv-result" class="space-y-4">
						<div>
							<label class="<?php echo esc_attr( self::LABEL ); ?>" for="einv-environment"><?php esc_html_e( 'Môi trường', 'einvoice-matbao' ); ?></label>
							<select id="einv-environment" name="environment" class="<?php echo esc_attr( self::FIELD ); ?>">
								<option value="demo" <?php selected( 'demo', $environment ); ?>><?php esc_html_e( 'Demo (thử nghiệm)', 'einvoice-matbao' ); ?></option>
								<option value="production" <?php selected( 'production', $environment ); ?>><?php esc_html_e( 'Production (thật)', 'einvoice-matbao' ); ?></option>
							</select>
						</div>
						<div>
							<label class="<?php echo esc_attr( self::LABEL ); ?>" for="einv-mst"><?php esc_html_e( 'Mã số thuế', 'einvoice-matbao' ); ?></label>
							<input id="einv-mst" name="mst" type="text" autocomplete="off" maxlength="<?php echo esc_attr( (string) MatbaoCredentials::MAX_MST_LENGTH ); ?>" class="<?php echo esc_attr( self::FIELD ); ?>" value="<?php echo esc_attr( null !== $credentials ? $credentials->mst : '' ); ?>" />
						</div>
						<div>
							<label class="<?php echo esc_attr( self::LABEL ); ?>" for="einv-username"><?php esc_html_e( 'Tên đăng nhập', 'einvoice-matbao' ); ?></label>
							<input id="einv-username" name="username" type="text" autocomplete="off" maxlength="<?php echo esc_attr( (string) MatbaoCredentials::MAX_USERNAME_LENGTH ); ?>" class="<?php echo esc_attr( self::FIELD ); ?>" value="<?php echo esc_attr( null !== $credentials ? $credentials->username : '' ); ?>" />
						</div>
						<div>
							<label class="<?php echo esc_attr( self::LABEL ); ?>" for="einv-password"><?php esc_html_e( 'Mật khẩu', 'einvoice-matbao' ); ?></label>
							<input id="einv-password" name="password" type="password" autocomplete="new-password" maxlength="<?php echo esc_attr( (string) MatbaoCredentials::MAX_PASSWORD_LENGTH ); ?>" class="<?php echo esc_attr( self::FIELD ); ?>" value="" placeholder="<?php echo esc_attr( $store->has_matbao() ? __( 'Đã lưu, để trống nếu không đổi', 'einvoice-matbao' ) : '' ); ?>" />
							<?php self::render_secret_state( $store->has_matbao() ); ?>
						</div>
						<div class="flex flex-wrap gap-2">
							<button type="submit" class="<?php echo esc_attr( self::BUTTON ); ?>"><?php esc_html_e( 'Lưu kết nối', 'einvoice-matbao' ); ?></button>
							<button type="button" class="<?php echo esc_attr( self::SECONDARY ); ?>" hx-post="<?php echo esc_url( rest_url( SettingsController::NAMESPACE . '/settings/matbao/test' ) ); ?>" hx-target="next .einv-result" hx-params="none"><?php esc_html_e( 'Kiểm tra kết nối', 'einvoice-matbao' ); ?></button>
						</div>
					</form>
					<div class="einv-result" aria-live="polite"></div>
				</section>

				<section class="<?php echo esc_attr( self::CARD ); ?>">
					<h2 class="<?php echo esc_attr( self::TITLE ); ?>"><?php esc_html_e( 'Mẫu hóa đơn mặc định', 'einvoice-matbao' ); ?></h2>
					<p class="<?php echo esc_attr( self::HELP ); ?> mb-4">
						<?php
						if ( null === $template ) {
							esc_html_e( 'Chưa chọn mẫu. Danh sách lấy từ Mắt Bão (mẫu GTGT của năm hiện tại).', 'einvoice-matbao' );
						} else {
							printf(
								/* translators: 1: form number, 2: series. */
								esc_html__( 'Đang dùng: %1$s / %2$s.', 'einvoice-matbao' ),
								esc_html( $template['form'] ),
								esc_html( $template['series'] )
							);
						}
						?>
					</p>
					<div hx-get="<?php echo esc_url( rest_url( SettingsController::NAMESPACE . '/settings/templates' ) ); ?>" hx-trigger="<?php echo esc_attr( ( $store->has_matbao() ? 'load, ' : '' ) . SettingsController::EVENT_MATBAO_SAVED . ' from:body, click from:#einv-reload-templates' ); ?>" hx-target="this" class="einv-templates">
						<p class="<?php echo esc_attr( self::HELP ); ?>"><?php echo esc_html( $store->has_matbao() ? __( 'Đang tải danh sách mẫu…', 'einvoice-matbao' ) : __( 'Lưu kết nối Mắt Bão để tải danh sách mẫu.', 'einvoice-matbao' ) ); ?></p>
					</div>
					<button type="button" id="einv-reload-templates" class="<?php echo esc_attr( self::SECONDARY ); ?> mt-3"><?php esc_html_e( 'Tải lại danh sách mẫu', 'einvoice-matbao' ); ?></button>
				</section>

		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Reference prefix, walk-in buyer and unit cards.
	 */
	private static function render_invoicing_panel(): string {
		$settings = new Settings();

		ob_start();
		?>
				<section class="<?php echo esc_attr( self::CARD ); ?>">
					<h2 class="<?php echo esc_attr( self::TITLE ); ?>"><?php esc_html_e( 'Mã tham chiếu và người mua vãng lai', 'einvoice-matbao' ); ?></h2>
					<form hx-post="<?php echo esc_url( rest_url( SettingsController::NAMESPACE . '/settings/invoicing' ) ); ?>" hx-target="next .einv-result" class="mt-4 space-y-4">
						<div>
							<label class="<?php echo esc_attr( self::LABEL ); ?>" for="einv-reference-prefix"><?php esc_html_e( 'Prefix mã tham chiếu (MTChieu)', 'einvoice-matbao' ); ?></label>
							<input id="einv-reference-prefix" name="reference_prefix" type="text" maxlength="<?php echo esc_attr( (string) Settings::MAX_REFERENCE_PREFIX_LENGTH ); ?>" pattern="[A-Za-z0-9]*" class="<?php echo esc_attr( self::FIELD ); ?>" value="<?php echo esc_attr( $settings->reference_prefix() ); ?>" />
							<p class="<?php echo esc_attr( self::HELP ); ?>">
								<?php
								printf(
									/* translators: 1: maximum prefix length, 2: example reference. */
									esc_html__( 'MTChieu = prefix + WC + mã đơn, tối đa 20 ký tự. Prefix tối đa %1$d chữ cái không dấu/chữ số. Ví dụ: %2$s.', 'einvoice-matbao' ),
									(int) Settings::MAX_REFERENCE_PREFIX_LENGTH,
									'<code>' . esc_html( $settings->reference_prefix() . Settings::REFERENCE_MARKER . '1234' ) . '</code>'
								);
								?>
							</p>
						</div>
						<div>
							<label class="<?php echo esc_attr( self::LABEL ); ?>" for="einv-walk-in-name"><?php esc_html_e( 'Tên người mua vãng lai (NMua_Ten)', 'einvoice-matbao' ); ?></label>
							<input id="einv-walk-in-name" name="walk_in_buyer_name" type="text" maxlength="<?php echo esc_attr( (string) Settings::MAX_BUYER_NAME_LENGTH ); ?>" class="<?php echo esc_attr( self::FIELD ); ?>" value="<?php echo esc_attr( $settings->walk_in_buyer_name() ); ?>" placeholder="<?php echo esc_attr( Settings::DEFAULT_WALK_IN_BUYER_NAME ); ?>" />
							<p class="<?php echo esc_attr( self::HELP ); ?>"><?php esc_html_e( 'Dùng cho đơn không yêu cầu xuất hóa đơn. Để trống để dùng giá trị mặc định.', 'einvoice-matbao' ); ?></p>
						</div>
						<button type="submit" class="<?php echo esc_attr( self::BUTTON ); ?>"><?php esc_html_e( 'Lưu', 'einvoice-matbao' ); ?></button>
					</form>
					<div class="einv-result" aria-live="polite"></div>
				</section>

				<section class="<?php echo esc_attr( self::CARD ); ?>">
					<h2 class="<?php echo esc_attr( self::TITLE ); ?>"><?php esc_html_e( 'Đơn vị tính', 'einvoice-matbao' ); ?></h2>
					<form hx-post="<?php echo esc_url( rest_url( SettingsController::NAMESPACE . '/settings/unit' ) ); ?>" hx-target="next .einv-result" class="mt-4 space-y-4">
						<div>
							<label class="<?php echo esc_attr( self::LABEL ); ?>" for="einv-default-unit"><?php esc_html_e( 'Đơn vị tính mặc định (DVTinh)', 'einvoice-matbao' ); ?></label>
							<input id="einv-default-unit" name="default_unit" type="text" maxlength="<?php echo esc_attr( (string) Settings::MAX_UNIT_LENGTH ); ?>" class="<?php echo esc_attr( self::FIELD ); ?>" value="<?php echo esc_attr( $settings->default_unit() ); ?>" placeholder="<?php echo esc_attr( Settings::DEFAULT_UNIT ); ?>" />
							<p class="<?php echo esc_attr( self::HELP ); ?>"><?php esc_html_e( 'Dùng cho sản phẩm không có đơn vị tính riêng, phí và vận chuyển. Có thể đặt riêng ở từng sản phẩm. Tối đa 50 ký tự; để trống để dùng "Gói".', 'einvoice-matbao' ); ?></p>
						</div>
						<button type="submit" class="<?php echo esc_attr( self::BUTTON ); ?>"><?php esc_html_e( 'Lưu', 'einvoice-matbao' ); ?></button>
					</form>
					<div class="einv-result" aria-live="polite"></div>
				</section>

		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Lark alert card.
	 */
	private static function render_lark_panel(): string {
		$store = new CredentialStore();

		ob_start();
		?>
				<section class="<?php echo esc_attr( self::CARD ); ?>">
					<h2 class="<?php echo esc_attr( self::TITLE ); ?>"><?php esc_html_e( 'Cảnh báo Lark', 'einvoice-matbao' ); ?></h2>
					<p class="<?php echo esc_attr( self::HELP ); ?> mb-4"><?php esc_html_e( 'Webhook của custom bot Lark trong group nhận cảnh báo. Webhook và secret được lưu mã hóa và không hiển thị lại.', 'einvoice-matbao' ); ?></p>
					<form hx-post="<?php echo esc_url( rest_url( SettingsController::NAMESPACE . '/settings/lark' ) ); ?>" hx-target="next .einv-result" class="space-y-4">
						<div>
							<label class="<?php echo esc_attr( self::LABEL ); ?>" for="einv-lark-webhook"><?php esc_html_e( 'Webhook URL', 'einvoice-matbao' ); ?></label>
							<input id="einv-lark-webhook" name="webhook_url" type="password" autocomplete="off" class="<?php echo esc_attr( self::FIELD ); ?>" value="" placeholder="<?php echo esc_attr( $store->has_lark() ? __( 'Đã lưu, để trống nếu không đổi', 'einvoice-matbao' ) : 'https://open.larksuite.com/open-apis/bot/v2/hook/…' ); ?>" />
							<?php self::render_secret_state( $store->has_lark() ); ?>
						</div>
						<div>
							<label class="<?php echo esc_attr( self::LABEL ); ?>" for="einv-lark-secret"><?php esc_html_e( 'Secret ký (nếu bật trên bot)', 'einvoice-matbao' ); ?></label>
							<input id="einv-lark-secret" name="secret" type="password" autocomplete="off" class="<?php echo esc_attr( self::FIELD ); ?>" value="" />
							<label class="<?php echo esc_attr( self::HELP ); ?> mt-2 flex items-center gap-2"><input type="checkbox" name="clear_secret" value="1" /> <?php esc_html_e( 'Xóa secret đã lưu (tắt ký)', 'einvoice-matbao' ); ?></label>
						</div>
						<div class="flex flex-wrap gap-2">
							<button type="submit" class="<?php echo esc_attr( self::BUTTON ); ?>"><?php esc_html_e( 'Lưu webhook', 'einvoice-matbao' ); ?></button>
							<button type="button" class="<?php echo esc_attr( self::SECONDARY ); ?>" hx-post="<?php echo esc_url( rest_url( SettingsController::NAMESPACE . '/settings/lark/test' ) ); ?>" hx-target="next .einv-result" hx-params="none"><?php esc_html_e( 'Gửi tin nhắn kiểm tra', 'einvoice-matbao' ); ?></button>
						</div>
					</form>
					<div class="einv-result" aria-live="polite"></div>
				</section>

		<?php
		return (string) ob_get_clean();
	}

	/**
	 * The template selection form returned by the templates route.
	 *
	 * @param MatbaoTemplate[]                         $templates VAT templates from Mat Bao.
	 * @param array{form: string, series: string}|null $selected  Current default template.
	 * @return string Escaped HTML.
	 */
	public static function render_template_form( array $templates, ?array $selected ): string {
		if ( array() === $templates ) {
			return Fragment::notice( Fragment::STATUS_ERROR, __( 'Mắt Bão không có mẫu hóa đơn GTGT nào cho năm hiện tại.', 'einvoice-matbao' ) );
		}

		$current = null === $selected ? '' : $selected['form'] . '|' . $selected['series'];
		$options = '';
		foreach ( $templates as $template ) {
			$value    = $template->form . '|' . $template->series;
			$options .= sprintf(
				'<option value="%1$s"%2$s%3$s>%4$s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				disabled( ! $template->has_remaining_numbers(), true, false ),
				esc_html(
					sprintf(
						/* translators: 1: form number, 2: series, 3: template name, 4: remaining numbers. */
						__( '%1$s / %2$s – %3$s (còn %4$s số)', 'einvoice-matbao' ),
						$template->form,
						$template->series,
						$template->name,
						number_format_i18n( $template->remaining )
					)
				)
			);
		}

		return sprintf(
			'<form hx-post="%1$s" hx-target="next .einv-result" class="flex flex-wrap items-end gap-2"><div><label class="block text-[13px] font-medium text-einv-ink-secondary" for="einv-template">%2$s</label><select id="einv-template" name="template" class="mt-1 block min-w-72 rounded-einv-sm border border-einv-hairline-input bg-white px-3 py-2 text-[15px] text-einv-ink">%3$s</select></div><button type="submit" class="inline-flex cursor-pointer items-center rounded-full border-0 bg-einv-primary px-4 py-2 text-[14px] text-white hover:bg-einv-primary-deep">%4$s</button></form><div class="einv-result" aria-live="polite"></div>',
			esc_url( rest_url( SettingsController::NAMESPACE . '/settings/template' ) ),
			esc_html__( 'Mẫu số / ký hiệu', 'einvoice-matbao' ),
			$options,
			esc_html__( 'Lưu mẫu mặc định', 'einvoice-matbao' )
		);
	}

	/**
	 * Whether a secret is stored, without ever showing it.
	 *
	 * @param bool $is_stored Whether the secret is stored.
	 */
	private static function render_secret_state( bool $is_stored ): void {
		printf(
			'<p class="mt-1 text-[13px] %1$s">%2$s</p>',
			esc_attr( $is_stored ? 'text-einv-success' : 'text-einv-ink-mute' ),
			esc_html( $is_stored ? __( 'Đã lưu (mã hóa).', 'einvoice-matbao' ) : __( 'Chưa lưu.', 'einvoice-matbao' ) )
		);
	}

	/**
	 * Inline script: send the REST nonce on every htmx request and swap 4xx error fragments.
	 *
	 * @param string $nonce `wp_rest` nonce.
	 */
	private static function htmx_config_script( string $nonce ): string {
		return sprintf(
			'(function(){var nonce=%s;document.addEventListener("htmx:configRequest",function(e){e.detail.headers["X-WP-Nonce"]=nonce;});document.addEventListener("htmx:beforeSwap",function(e){var s=e.detail.xhr.status;if(s>=400&&s<500){e.detail.shouldSwap=true;e.detail.isError=false;}});if(window.htmx){htmx.config.refreshOnHistoryMiss=true;}})();',
			wp_json_encode( $nonce )
		);
	}
}
