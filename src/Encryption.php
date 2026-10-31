<?php
/**
 * Symmetric encryption for secrets stored in the database.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao;

/**
 * Encrypts secrets with libsodium secretbox using key material from the
 * `EINVOICE_MATBAO_ENCRYPTION_KEY` constant in `wp-config.php`.
 *
 * Without that constant nothing can be encrypted, so callers must refuse to store secrets.
 */
final class Encryption {

	public const KEY_CONSTANT = 'EINVOICE_MATBAO_ENCRYPTION_KEY';

	/** Shortest accepted key material, so a trivial value cannot protect stored credentials. */
	public const MIN_KEY_LENGTH = 32;

	private const PREFIX = 'v1.';

	/** Domain-separation label for deriving the secretbox key from the configured material. */
	private const KDF_LABEL = 'einvoice-matbao-secretbox-v1';

	/**
	 * Whether a usable key is configured.
	 */
	public function is_available(): bool {
		return null !== $this->key();
	}

	/**
	 * Encrypts a plaintext secret.
	 *
	 * @param string $plaintext Secret to encrypt.
	 * @throws MissingEncryptionKey When no usable key is configured.
	 */
	public function encrypt( string $plaintext ): string {
		$key = $this->key();
		if ( null === $key ) {
			throw new MissingEncryptionKey();
		}

		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		return self::PREFIX . base64_encode( $nonce . sodium_crypto_secretbox( $plaintext, $nonce, $key ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext encoding.
	}

	/**
	 * Decrypts a value produced by encrypt(). Returns null without a key, with a different key
	 * or for corrupted data.
	 *
	 * @param string $ciphertext Value produced by encrypt().
	 */
	public function decrypt( string $ciphertext ): ?string {
		$key = $this->key();
		if ( null === $key || ! str_starts_with( $ciphertext, self::PREFIX ) ) {
			return null;
		}

		$raw = base64_decode( substr( $ciphertext, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary ciphertext encoding.
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}

		$plaintext = sodium_crypto_secretbox_open(
			substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			$key
		);

		return false === $plaintext ? null : $plaintext;
	}

	/**
	 * Derived 32-byte secretbox key, or null when the configured material is missing or too short.
	 */
	private function key(): ?string {
		$material = defined( self::KEY_CONSTANT ) ? constant( self::KEY_CONSTANT ) : '';

		/**
		 * Filters the encryption key material. Defaults to the `EINVOICE_MATBAO_ENCRYPTION_KEY`
		 * constant; intended for tests and hosts that inject secrets differently.
		 *
		 * @param mixed $material Key material.
		 */
		$material = apply_filters( 'einvoice_matbao_encryption_key', $material );

		if ( ! is_string( $material ) || strlen( $material ) < self::MIN_KEY_LENGTH ) {
			return null;
		}

		return sodium_crypto_generichash( $material, self::KDF_LABEL, SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
