<?php
/**
 * Lark custom bot webhook configuration.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Lark;

/**
 * Webhook URL of a Lark custom bot and its optional signing secret. Both are credentials.
 */
final class LarkWebhook {

	/** Hosts that serve Lark (international) and Feishu custom bot webhooks. */
	private const HOSTS = array( 'open.larksuite.com', 'open.feishu.cn' );

	private const PATH_PREFIX = '/open-apis/bot/v2/hook/';

	/**
	 * Creates the webhook configuration.
	 *
	 * @param string $webhook_url Custom bot webhook URL.
	 * @param string $secret      Signature secret; empty when signing is disabled on the bot.
	 */
	public function __construct(
		#[\SensitiveParameter] public readonly string $webhook_url,
		#[\SensitiveParameter] public readonly string $secret,
	) {
	}

	/**
	 * Whether a URL is an HTTPS Lark/Feishu custom bot webhook.
	 *
	 * @param string $url Candidate webhook URL.
	 */
	public static function is_valid_url( string $url ): bool {
		$parts = wp_parse_url( $url );

		return is_array( $parts )
			&& 'https' === ( $parts['scheme'] ?? '' )
			&& in_array( $parts['host'] ?? '', self::HOSTS, true )
			&& ! isset( $parts['port'], $parts['user'], $parts['pass'] )
			&& str_starts_with( $parts['path'] ?? '', self::PATH_PREFIX )
			&& strlen( $parts['path'] ?? '' ) > strlen( self::PATH_PREFIX );
	}

	/**
	 * Hides the secrets from var_dump() and print_r().
	 *
	 * @return array<string, string>
	 */
	public function __debugInfo(): array {
		return array(
			'webhook_url' => '[REDACTED]',
			'secret'      => '[REDACTED]',
		);
	}
}
