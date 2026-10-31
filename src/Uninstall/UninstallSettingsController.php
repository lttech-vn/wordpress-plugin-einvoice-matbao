<?php
/**
 * The "delete all data on uninstall" switch on the settings page.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Uninstall;

use LTTech\EinvoiceMatbao\Admin\Fragment;
use LTTech\EinvoiceMatbao\Admin\SettingsController;
use LTTech\EinvoiceMatbao\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `POST einvoice-matbao/v1/settings/uninstall`, with the same Administrator and nonce check as
 * every settings route. Turning the switch on requires typing CONFIRMATION; turning it off does not.
 */
final class UninstallSettingsController {

	public const ROUTE = '/settings/uninstall';

	/** Text the admin must type to turn the switch on. */
	public const CONFIRMATION = 'XOA HET DU LIEU';

	/**
	 * Registers the route. Hooked to `rest_api_init`.
	 */
	public static function register_routes(): void {
		register_rest_route(
			SettingsController::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'save' ),
				'permission_callback' => array( SettingsController::class, 'check_permission' ),
			)
		);
	}

	/**
	 * Saves the switch.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function save( WP_REST_Request $request ): WP_REST_Response {
		$enable       = '1' === $request->get_param( 'delete_data_on_uninstall' );
		$confirmation = $request->get_param( 'confirmation' );

		if ( $enable && ( ! is_string( $confirmation ) || self::CONFIRMATION !== trim( $confirmation ) ) ) {
			return Fragment::error(
				sprintf(
					/* translators: %s: confirmation text. */
					__( 'Chưa bật: gõ đúng "%s" để xác nhận xóa toàn bộ dữ liệu hóa đơn khi gỡ plugin.', 'einvoice-matbao' ),
					self::CONFIRMATION
				)
			);
		}

		( new Settings() )->save_delete_data_on_uninstall( $enable );

		return Fragment::success(
			$enable
				? __( 'Đã bật: khi xóa plugin, toàn bộ dữ liệu hóa đơn, nhật ký gửi, thông tin người mua trên đơn và cài đặt sẽ bị xóa vĩnh viễn.', 'einvoice-matbao' )
				: __( 'Đã tắt: khi xóa plugin, dữ liệu hóa đơn được giữ lại; chỉ credential và bộ nhớ đệm bị xóa.', 'einvoice-matbao' )
		);
	}

	/**
	 * The settings page section. Off by default; the checkbox reflects the stored value.
	 */
	public static function render_section(): void {
		$enabled = ( new Settings() )->delete_data_on_uninstall();
		?>
		<section class="mb-6 max-w-3xl rounded-einv-lg border border-einv-error/30 bg-white p-6 shadow-einv-card" data-einv-section="uninstall">
			<h2 class="m-0 mb-1 text-[20px] font-light tracking-[-0.2px] text-einv-ink"><?php esc_html_e( 'Gỡ plugin', 'einvoice-matbao' ); ?></h2>
			<p class="mb-4 mt-1 text-[13px] text-einv-ink-mute"><?php esc_html_e( 'Khi xóa plugin, credential Mắt Bão/Lark và bộ nhớ đệm luôn bị xóa. Mặc định dữ liệu hóa đơn (bảng hóa đơn, nhật ký gửi, thông tin người mua trên đơn) được giữ lại. Hóa đơn đã tạo trên Mắt Bão không bị ảnh hưởng.', 'einvoice-matbao' ); ?></p>
			<form hx-post="<?php echo esc_url( rest_url( SettingsController::NAMESPACE . self::ROUTE ) ); ?>" hx-target="next .einv-result" class="space-y-4">
				<label class="flex items-center gap-2 text-[14px] text-einv-ink">
					<input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( $enabled ); ?> />
					<?php esc_html_e( 'Xóa toàn bộ dữ liệu của plugin khi xóa plugin (không thể khôi phục)', 'einvoice-matbao' ); ?>
				</label>
				<div>
					<label class="block text-[13px] font-medium text-einv-ink-secondary" for="einv-uninstall-confirmation">
						<?php
						printf(
							/* translators: %s: confirmation text. */
							esc_html__( 'Để bật, gõ %s', 'einvoice-matbao' ),
							'<code>' . esc_html( self::CONFIRMATION ) . '</code>'
						);
						?>
					</label>
					<input id="einv-uninstall-confirmation" name="confirmation" type="text" autocomplete="off" class="mt-1 block w-full max-w-md rounded-einv-sm border border-einv-hairline-input bg-white px-3 py-2 text-[15px] text-einv-ink shadow-none focus:border-einv-primary focus:outline-none" value="" />
				</div>
				<button type="submit" class="inline-flex cursor-pointer items-center rounded-full border-0 bg-einv-error px-4 py-2 text-[14px] font-normal text-white"><?php esc_html_e( 'Lưu', 'einvoice-matbao' ); ?></button>
			</form>
			<div class="einv-result" aria-live="polite"></div>
		</section>
		<?php
	}
}
