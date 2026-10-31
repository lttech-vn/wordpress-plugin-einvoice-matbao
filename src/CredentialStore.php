<?php
/**
 * Encrypted storage for Mat Bao and Lark credentials.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao;

use LTTech\EinvoiceMatbao\Lark\LarkWebhook;
use LTTech\EinvoiceMatbao\Matbao\MatbaoCredentials;
use LTTech\EinvoiceMatbao\Matbao\TemplateCache;

/**
 * Stores each credential set as one encrypted JSON blob in a non-autoloaded option.
 * Nothing is ever written in clear text: without an encryption key, saving throws.
 */
final class CredentialStore {

	public const OPTION = 'einvoice_matbao_credentials';

	private const MATBAO = 'matbao';
	private const LARK   = 'lark';

	/**
	 * Creates the store.
	 *
	 * @param Encryption $encryption Encryption service.
	 */
	public function __construct( private readonly Encryption $encryption = new Encryption() ) {
	}

	/**
	 * Whether Mat Bao credentials have been saved (even if they cannot be decrypted now).
	 */
	public function has_matbao(): bool {
		return isset( $this->stored()[ self::MATBAO ] );
	}

	/**
	 * Whether a Lark webhook has been saved (even if it cannot be decrypted now).
	 */
	public function has_lark(): bool {
		return isset( $this->stored()[ self::LARK ] );
	}

	/**
	 * Decrypted Mat Bao credentials, or null when not saved or not decryptable with the current key.
	 */
	public function matbao(): ?MatbaoCredentials {
		$fields = $this->decrypted( self::MATBAO );
		if ( null === $fields ) {
			return null;
		}

		return new MatbaoCredentials(
			self::string_field( $fields, 'environment' ),
			self::string_field( $fields, 'mst' ),
			self::string_field( $fields, 'username' ),
			self::string_field( $fields, 'password' )
		);
	}

	/**
	 * Decrypted Lark webhook, or null when not saved or not decryptable with the current key.
	 */
	public function lark(): ?LarkWebhook {
		$fields = $this->decrypted( self::LARK );
		if ( null === $fields ) {
			return null;
		}

		return new LarkWebhook( self::string_field( $fields, 'webhook_url' ), self::string_field( $fields, 'secret' ) );
	}

	/**
	 * Encrypts and saves Mat Bao credentials.
	 *
	 * @param MatbaoCredentials $credentials Credentials to store.
	 * @throws MissingEncryptionKey When no usable key is configured.
	 */
	public function save_matbao( MatbaoCredentials $credentials ): void {
		$this->save(
			self::MATBAO,
			array(
				'environment' => $credentials->environment,
				'mst'         => $credentials->mst,
				'username'    => $credentials->username,
				'password'    => $credentials->password,
			)
		);
		TemplateCache::forget();
	}

	/**
	 * Encrypts and saves the Lark webhook.
	 *
	 * @param LarkWebhook $webhook Webhook to store.
	 * @throws MissingEncryptionKey When no usable key is configured.
	 */
	public function save_lark( LarkWebhook $webhook ): void {
		$this->save(
			self::LARK,
			array(
				'webhook_url' => $webhook->webhook_url,
				'secret'      => $webhook->secret,
			)
		);
	}

	/**
	 * Encrypts one credential set and writes it.
	 *
	 * @param string                $name   Credential set name.
	 * @param array<string, string> $fields Plaintext fields.
	 * @throws MissingEncryptionKey When no usable key is configured.
	 */
	private function save( string $name, array $fields ): void {
		$stored          = $this->stored();
		$stored[ $name ] = $this->encryption->encrypt( (string) wp_json_encode( $fields ) );

		update_option( self::OPTION, $stored, false );
	}

	/**
	 * Decrypted fields of one credential set.
	 *
	 * @param string $name Credential set name.
	 * @return array<mixed>|null
	 */
	private function decrypted( string $name ): ?array {
		$ciphertext = $this->stored()[ $name ] ?? null;
		if ( null === $ciphertext ) {
			return null;
		}

		$json = $this->encryption->decrypt( $ciphertext );
		if ( null === $json ) {
			return null;
		}

		$fields = json_decode( $json, true );

		return is_array( $fields ) ? $fields : null;
	}

	/**
	 * Encrypted credential sets keyed by name.
	 *
	 * @return array<string, string>
	 */
	private function stored(): array {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? array_filter( $stored, 'is_string' ) : array();
	}

	/**
	 * String value of a decoded field, or empty string.
	 *
	 * @param array<mixed> $fields Decoded fields.
	 * @param string       $key    Field name.
	 */
	private static function string_field( array $fields, string $key ): string {
		return isset( $fields[ $key ] ) && is_string( $fields[ $key ] ) ? $fields[ $key ] : '';
	}
}
