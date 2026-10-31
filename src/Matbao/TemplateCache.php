<?php
/**
 * Short-lived cache of the Mat Bao templates list.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Matbao;

/**
 * Keeps the templates of one year for one Mat Bao account in a transient for a few minutes, so
 * the check before each create call does not cost one GET per order. A single entry is kept:
 * another account or year is a miss. Dropped when the default template or the credentials are
 * saved, and when Mat Bao rejects or no longer lists the default template.
 */
final class TemplateCache {

	private const TRANSIENT = 'einvoice_matbao_templates';

	private const TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Drops the cached list.
	 */
	public static function forget(): void {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Templates of a year, from the cache or from Mat Bao.
	 *
	 * @param MatbaoClient      $client      Client for the account.
	 * @param MatbaoCredentials $credentials Account the client logs in with.
	 * @param int               $year        Template year.
	 * @return list<MatbaoTemplate>
	 * @throws MatbaoError When the templates call fails; nothing is cached then.
	 */
	public function templates( MatbaoClient $client, MatbaoCredentials $credentials, int $year ): array {
		$account = md5( $credentials->environment . '|' . $credentials->mst . '|' . $credentials->username );
		$cached  = self::cached( $account, $year );
		if ( null !== $cached ) {
			return $cached;
		}

		$templates = $client->templates( $year );
		set_transient(
			self::TRANSIENT,
			array(
				'account'   => $account,
				'year'      => $year,
				'templates' => array_map(
					static fn( MatbaoTemplate $template ): array => array(
						'name'      => $template->name,
						'form'      => $template->form,
						'series'    => $template->series,
						'total'     => $template->total,
						'remaining' => $template->remaining,
					),
					$templates
				),
			),
			self::TTL
		);

		return $templates;
	}

	/**
	 * Cached templates of an account and year, or null on a miss or an unreadable entry.
	 *
	 * @param string $account Account fingerprint.
	 * @param int    $year    Template year.
	 * @return list<MatbaoTemplate>|null
	 */
	private static function cached( string $account, int $year ): ?array {
		$cached = get_transient( self::TRANSIENT );
		if ( ! is_array( $cached ) || ( $cached['account'] ?? null ) !== $account || ( $cached['year'] ?? null ) !== $year || ! is_array( $cached['templates'] ?? null ) ) {
			return null;
		}

		$templates = array();
		foreach ( $cached['templates'] as $item ) {
			if ( ! is_array( $item ) || ! is_string( $item['name'] ?? null ) || ! is_string( $item['form'] ?? null ) || ! is_string( $item['series'] ?? null ) || ! is_int( $item['total'] ?? null ) || ! is_int( $item['remaining'] ?? null ) ) {
				return null;
			}
			$templates[] = new MatbaoTemplate( $item['name'], $item['form'], $item['series'], $item['total'], $item['remaining'] );
		}

		return $templates;
	}
}
