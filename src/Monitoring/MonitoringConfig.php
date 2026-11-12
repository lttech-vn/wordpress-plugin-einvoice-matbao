<?php
/**
 * Effective monitoring configuration.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Monitoring;

use LTTech\EinvoiceMatbao\CredentialStore;
use LTTech\EinvoiceMatbao\Settings;

/**
 * Resolves the on/off switch, DSN and sample rate from wp-config constants (which win) and the
 * stored settings. Monitoring runs only when enabled, a valid DSN exists and mbstring is loaded.
 */
final class MonitoringConfig {

	public const DSN_CONSTANT     = 'EINVOICE_MATBAO_SENTRY_DSN';
	public const ENABLED_CONSTANT = 'EINVOICE_MATBAO_SENTRY_ENABLED';

	/**
	 * Use current().
	 *
	 * @param bool           $enabled                Whether monitoring is switched on.
	 * @param SentryDsn|null $dsn                  Effective DSN.
	 * @param string         $dsn_source             'constant', 'ui' or 'none'.
	 * @param bool           $dsn_constant_invalid   A DSN constant is defined but unusable.
	 * @param bool           $stored_dsn_unreadable  A stored DSN exists but cannot be decrypted.
	 * @param bool           $enabled_from_constant  The switch comes from a constant.
	 * @param float          $traces_sample_rate     Transaction sample rate in [0, 1].
	 * @param string         $environment            wp_get_environment_type().
	 * @param string         $release                `einvoice-matbao@<version>`.
	 * @param string         $site                   Lowercase host of home_url().
	 * @param bool           $mbstring               Whether ext-mbstring is available.
	 */
	private function __construct(
		public readonly bool $enabled,
		public readonly ?SentryDsn $dsn,
		public readonly string $dsn_source,
		public readonly bool $dsn_constant_invalid,
		public readonly bool $stored_dsn_unreadable,
		public readonly bool $enabled_from_constant,
		public readonly float $traces_sample_rate,
		public readonly string $environment,
		public readonly string $release,
		public readonly string $site,
		private readonly bool $mbstring
	) {}

	/**
	 * Reads the configuration as it is now.
	 */
	public static function current(): self {
		$constants = self::constants();
		$settings  = new Settings();
		$store     = new CredentialStore();

		$enabled_from_constant = array_key_exists( self::ENABLED_CONSTANT, $constants );
		$enabled               = $enabled_from_constant ? (bool) $constants[ self::ENABLED_CONSTANT ] : $settings->monitoring_enabled();

		$constant_dsn         = null;
		$dsn_constant_invalid = false;
		if ( array_key_exists( self::DSN_CONSTANT, $constants ) ) {
			$raw                  = $constants[ self::DSN_CONSTANT ];
			$constant_dsn         = is_string( $raw ) ? SentryDsn::parse( $raw ) : null;
			$dsn_constant_invalid = null === $constant_dsn;
		}

		$stored_raw            = $store->sentry_dsn();
		$stored_dsn_unreadable = null === $stored_raw && $store->has_sentry_dsn();
		$ui_dsn                = null === $stored_raw ? null : SentryDsn::parse( $stored_raw );

		if ( null !== $constant_dsn ) {
			$dsn    = $constant_dsn;
			$source = 'constant';
		} elseif ( null !== $ui_dsn ) {
			$dsn    = $ui_dsn;
			$source = 'ui';
		} else {
			$dsn    = null;
			$source = 'none';
		}

		$mbstring = self::mbstring_available();
		$host     = wp_parse_url( home_url(), PHP_URL_HOST );

		return new self(
			$enabled,
			$dsn,
			$source,
			$dsn_constant_invalid,
			$stored_dsn_unreadable,
			$enabled_from_constant,
			$settings->traces_sample_rate(),
			wp_get_environment_type(),
			'einvoice-matbao@' . EINVOICE_MATBAO_VERSION,
			is_string( $host ) ? strtolower( $host ) : '',
			$mbstring
		);
	}

	/**
	 * Whether events may be sent: switched on, valid DSN, mbstring present.
	 */
	public function active(): bool {
		return $this->enabled && null !== $this->dsn && $this->mbstring;
	}

	/**
	 * Defined configuration constants by name.
	 *
	 * @return array<string, mixed>
	 */
	private static function constants(): array {
		$constants = array();
		foreach ( array( self::DSN_CONSTANT, self::ENABLED_CONSTANT ) as $name ) {
			if ( defined( $name ) ) {
				$constants[ $name ] = constant( $name );
			}
		}

		if ( MonitoringOverrides::allowed_now() ) {
			$constants = apply_filters( 'einvoice_matbao_monitoring_constants', $constants );
		}

		return is_array( $constants ) ? $constants : array();
	}

	/**
	 * Whether ext-mbstring is available (overridable in tests only).
	 */
	private static function mbstring_available(): bool {
		$loaded = extension_loaded( 'mbstring' );
		if ( MonitoringOverrides::allowed_now() ) {
			$loaded = (bool) apply_filters( 'einvoice_matbao_monitoring_mbstring', $loaded );
		}

		return $loaded;
	}
}
