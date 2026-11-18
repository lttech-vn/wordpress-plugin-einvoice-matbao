<?php
/**
 * The "Ánh xạ thuế Mắt Bão" settings card.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Admin;

use LTTech\EinvoiceMatbao\Invoice\TaxRateMap;
use LTTech\EinvoiceMatbao\Settings;

/**
 * Renders the whole card; both REST routes return it so badges and pickers always match the stored table.
 */
final class TaxRateMapCard {

	public const ID        = 'einv-tax-rate-map';
	public const NOTICE_ID = 'einv-tax-rate-map-notice';

	/**
	 * The card.
	 *
	 * @param string $notice_html Already-escaped error notice from `Fragment::notice()`; '' for none (success goes out-of-band).
	 */
	public static function render( string $notice_html = '' ): string {
		$map       = ( new Settings() )->tax_rate_map();
		$table     = new TaxRateMap( $map );
		$rates     = TaxRateCatalog::all_rates();
		$by_id     = array_column( $rates, null, 'id' );
		$available = array_values( array_filter( $rates, static fn( array $rate ): bool => null === $table->group_of( $rate['id'] ) ) );
		$no_rates  = array() === $rates && array() === array_filter( $map );

		ob_start();
		?>
				<section id="<?php echo esc_attr( self::ID ); ?>" class="<?php echo esc_attr( SettingsPage::CARD ); ?>">
					<h2 class="<?php echo esc_attr( SettingsPage::TITLE ); ?>"><?php esc_html_e( 'Ánh xạ thuế Mắt Bão', 'einvoice-matbao' ); ?></h2>
					<p class="<?php echo esc_attr( SettingsPage::HELP ); ?> mb-4"><?php esc_html_e( 'Chọn thuế suất WooCommerce ứng với từng loại thuế Mắt Bão. Hóa đơn chỉ gửi được dòng có thuế suất nằm trong bảng; hàng Khác gửi đúng phần trăm của thuế suất.', 'einvoice-matbao' ); ?></p>
					<?php if ( $no_rates ) : ?>
						<p class="<?php echo esc_attr( SettingsPage::HELP ); ?>"><?php esc_html_e( 'Chưa có thuế suất nào trong WooCommerce. Tạo thuế suất ở WooCommerce → Cài đặt → Thuế rồi tải lại trang.', 'einvoice-matbao' ); ?></p>
					<?php endif; ?>
					<?php foreach ( TaxRateMap::groups() as $group ) : ?>
						<?php echo self::render_row( $group, $map[ $group ] ?? array(), $by_id, $available ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_row(). ?>
					<?php endforeach; ?>
					<?php echo $notice_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by Fragment::notice(). ?>
				</section>

		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Persistent live region next to the card; it is never swapped away, so screen readers announce what lands in it.
	 */
	public static function notice_region(): string {
		return '<div id="' . esc_attr( self::NOTICE_ID ) . '" aria-live="polite"></div>';
	}

	/**
	 * Out-of-band swap that fills (or, with '', clears) the live region.
	 *
	 * @param string $notice_html Already-escaped notice from `Fragment::notice()`.
	 */
	public static function oob_notice( string $notice_html ): string {
		return '<div hx-swap-oob="innerHTML:#' . esc_attr( self::NOTICE_ID ) . '">' . $notice_html . '</div>';
	}

	/**
	 * One group row: its badges and the picker to add another rate.
	 *
	 * @param string                                                                                    $group     Group code.
	 * @param array<int>                                                                                $ids       Rate IDs in the group.
	 * @param array<int, array{id: int, name: string, class: string, country: string, percent: string}> $by_id     Catalog by ID.
	 * @param array<array{id: int, name: string, class: string, country: string, percent: string}>      $available Rates not in the table.
	 */
	private static function render_row( string $group, array $ids, array $by_id, array $available ): string {
		$label    = TaxRateMap::label( $group );
		$field_id = 'einv-map-' . $group;
		$remove   = rest_url( SettingsController::NAMESPACE . '/settings/tax-rate-map/remove' );
		$add      = rest_url( SettingsController::NAMESPACE . '/settings/tax-rate-map/add' );

		ob_start();
		?>
					<div class="border-t border-einv-hairline py-3" data-einv-map-group="<?php echo esc_attr( $group ); ?>">
						<div class="flex flex-wrap items-center gap-2">
							<strong class="w-16 text-[14px] text-einv-ink"><?php echo esc_html( $label ); ?></strong>
							<?php foreach ( $ids as $id ) : ?>
								<?php $name = isset( $by_id[ $id ] ) ? $by_id[ $id ]['name'] : '#' . $id; ?>
								<span class="inline-flex items-center gap-1 rounded-full border border-einv-hairline bg-white px-3 py-1 text-[13px] text-einv-ink" data-einv-badge="<?php echo esc_attr( (string) $id ); ?>">
									<?php echo esc_html( isset( $by_id[ $id ] ) ? self::badge_text( $by_id[ $id ] ) : sprintf( /* translators: %d: tax rate ID. */ __( '#%d (đã xóa)', 'einvoice-matbao' ), $id ) ); ?>
									<form hx-post="<?php echo esc_url( $remove ); ?>" hx-target="#<?php echo esc_attr( self::ID ); ?>" hx-swap="outerHTML" hx-sync="#<?php echo esc_attr( self::ID ); ?>:queue all" hx-disabled-elt="find button" class="m-0 inline">
										<input type="hidden" name="group" value="<?php echo esc_attr( $group ); ?>" />
										<input type="hidden" name="rate_id" value="<?php echo esc_attr( (string) $id ); ?>" />
										<button type="submit" class="cursor-pointer border-0 bg-transparent px-1 text-einv-ink-mute" aria-label="<?php echo esc_attr( sprintf( /* translators: 1: tax rate name, 2: group label. */ __( 'Bỏ %1$s khỏi %2$s', 'einvoice-matbao' ), $name, $label ) ); ?>">×</button>
									</form>
								</span>
							<?php endforeach; ?>
						</div>
						<?php if ( array() !== $available ) : ?>
							<form hx-post="<?php echo esc_url( $add ); ?>" hx-target="#<?php echo esc_attr( self::ID ); ?>" hx-swap="outerHTML" hx-sync="#<?php echo esc_attr( self::ID ); ?>:queue all" hx-disabled-elt="find button" class="mt-2 flex flex-wrap items-center gap-2">
								<input type="hidden" name="group" value="<?php echo esc_attr( $group ); ?>" />
								<label class="sr-only" for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( sprintf( /* translators: %s: group label. */ __( 'Thêm thuế suất vào %s', 'einvoice-matbao' ), $label ) ); ?></label>
								<select id="<?php echo esc_attr( $field_id ); ?>" name="rate_id" class="<?php echo esc_attr( SettingsPage::FIELD ); ?>">
									<?php foreach ( $available as $rate ) : ?>
										<option value="<?php echo esc_attr( (string) $rate['id'] ); ?>"><?php echo esc_html( self::badge_text( $rate ) . ' · ' . $rate['percent'] . '%' ); ?></option>
									<?php endforeach; ?>
								</select>
								<button type="submit" class="<?php echo esc_attr( SettingsPage::SECONDARY ); ?>"><?php esc_html_e( 'Thêm', 'einvoice-matbao' ); ?></button>
							</form>
						<?php endif; ?>
					</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Badge text: name, class and country.
	 *
	 * @param array{id: int, name: string, class: string, country: string, percent: string} $rate Catalog entry.
	 */
	private static function badge_text( array $rate ): string {
		return $rate['name'] . ' · ' . $rate['class'] . ' · ' . $rate['country'];
	}
}
