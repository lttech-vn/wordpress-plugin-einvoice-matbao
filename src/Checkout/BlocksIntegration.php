<?php
/**
 * Script integration of the Checkout block.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Checkout;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;
use LTTech\EinvoiceMatbao\Buyer\BuyerRules;
use LTTech\EinvoiceMatbao\Encryption;

/**
 * Loads the block's scripts in the checkout and the editor and hands them the messages.
 */
final class BlocksIntegration implements IntegrationInterface {

	public const FRONTEND_HANDLE = 'einvoice-matbao-checkout-blocks';
	public const EDITOR_HANDLE   = 'einvoice-matbao-checkout-blocks-editor';

	/**
	 * Integration name, also the key of the script data setting.
	 */
	public function get_name(): string {
		return BlocksCheckout::EXTENSION_NAMESPACE;
	}

	/**
	 * Registers the scripts.
	 */
	public function initialize(): void {
		$dependencies = array( 'wc-blocks-checkout', 'wc-settings', 'wp-data', 'wp-element', 'wp-i18n' );
		wp_register_script( self::FRONTEND_HANDLE, plugins_url( 'assets/dist/checkout-blocks.js', EINVOICE_MATBAO_FILE ), $dependencies, EINVOICE_MATBAO_VERSION, true );
		wp_register_script( self::EDITOR_HANDLE, plugins_url( 'assets/dist/checkout-blocks-editor.js', EINVOICE_MATBAO_FILE ), array( 'wp-blocks', 'wp-block-editor', 'wp-element', 'wp-i18n' ), EINVOICE_MATBAO_VERSION, true );
	}

	/**
	 * Scripts of the storefront.
	 *
	 * @return string[]
	 */
	public function get_script_handles(): array {
		return array( self::FRONTEND_HANDLE );
	}

	/**
	 * Scripts of the block editor.
	 *
	 * @return string[]
	 */
	public function get_editor_script_handles(): array {
		return array( self::EDITOR_HANDLE );
	}

	/**
	 * Data for the storefront script: the same Vietnamese messages as Classic.
	 *
	 * @return array<string, mixed>
	 */
	public function get_script_data(): array {
		return array(
			'messages'            => BuyerRules::messages(),
			'encryptionAvailable' => ( new Encryption() )->is_available(),
		);
	}
}
