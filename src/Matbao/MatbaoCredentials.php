<?php
/**
 * Mat Bao API login credentials.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Matbao;

/**
 * Login for the Mat Bao e-invoice proxy API (`/api/auth/login`) and the environment it targets.
 */
final class MatbaoCredentials {

	/**
	 * API base URL per environment, from the Mat Bao API document (sections IV and V).
	 */
	public const BASE_URLS = array(
		'demo'       => 'https://demo-api-hddt.matbao.in:11443',
		'production' => 'https://api-hddt.matbao.in:11443',
	);

	/** Field limits from the login API definition. */
	public const MAX_MST_LENGTH      = 20;
	public const MAX_USERNAME_LENGTH = 50;
	public const MAX_PASSWORD_LENGTH = 50;

	/**
	 * Creates the credentials.
	 *
	 * @param string $environment Key of BASE_URLS.
	 * @param string $mst         Seller tax code (`MST`).
	 * @param string $username    Login name (`TDNhap`).
	 * @param string $password    Password (`MKhau`).
	 */
	public function __construct(
		public readonly string $environment,
		public readonly string $mst,
		public readonly string $username,
		#[\SensitiveParameter] public readonly string $password,
	) {
	}

	/**
	 * API base URL of the selected environment.
	 */
	public function base_url(): string {
		return self::BASE_URLS[ $this->environment ] ?? self::BASE_URLS['demo'];
	}

	/**
	 * Hides the password from var_dump() and print_r().
	 *
	 * @return array<string, string>
	 */
	public function __debugInfo(): array {
		return array(
			'environment' => $this->environment,
			'mst'         => $this->mst,
			'username'    => $this->username,
			'password'    => '[REDACTED]',
		);
	}
}
