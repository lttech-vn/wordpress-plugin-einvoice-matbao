<?php
/**
 * Encryption round trip and rejection of damaged ciphertext.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Encryption;
use WP_UnitTestCase;

/**
 * Decryption never returns data for anything but an intact `v1.` ciphertext of the current key.
 */
final class EncryptionTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		add_filter( 'einvoice_matbao_encryption_key', static fn(): string => 'test-only-key-material-0123456789abcdef' );
	}

	public function tear_down(): void {
		remove_all_filters( 'einvoice_matbao_encryption_key' );
		parent::tear_down();
	}

	public function test_round_trip(): void {
		$encryption = new Encryption();

		$this->assertSame( 'secret', $encryption->decrypt( $encryption->encrypt( 'secret' ) ) );
	}

	public function test_ciphertext_without_version_prefix_is_rejected(): void {
		$encryption = new Encryption();
		$ciphertext = $encryption->encrypt( 'secret' );

		$this->assertNull( $encryption->decrypt( substr( $ciphertext, 3 ) ) );
		$this->assertNull( $encryption->decrypt( 'v2.' . substr( $ciphertext, 3 ) ) );
	}

	public function test_tampered_or_truncated_ciphertext_is_rejected(): void {
		$encryption = new Encryption();
		$ciphertext = $encryption->encrypt( 'secret' );
		$last       = substr( $ciphertext, -4, 1 );
		$tampered   = substr( $ciphertext, 0, -4 ) . ( 'A' === $last ? 'B' : 'A' ) . substr( $ciphertext, -3 );

		$this->assertNull( $encryption->decrypt( $tampered ) );
		$this->assertNull( $encryption->decrypt( substr( $ciphertext, 0, 20 ) ) );
		$this->assertNull( $encryption->decrypt( 'v1.' ) );
		$this->assertNull( $encryption->decrypt( 'v1.!!!not-base64!!!' ) );
		$this->assertNull( $encryption->decrypt( '' ) );
	}
}
