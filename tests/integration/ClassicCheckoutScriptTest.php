<?php
/**
 * Classic checkout script: loaded on Classic checkout pages only.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Checkout\ClassicCheckout;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\InvoiceWorkflowTestCase;

/**
 * Both checkout scripts are plain browser scripts; loading the Classic one on Checkout Blocks
 * made it collide with the Blocks bundle and break the block.
 */
final class ClassicCheckoutScriptTest extends InvoiceWorkflowTestCase {

	public function tear_down(): void {
		wp_dequeue_script( ClassicCheckout::SCRIPT_HANDLE );
		$GLOBALS['wp_scripts'] = null;

		parent::tear_down();
	}

	public function test_script_is_not_enqueued_on_a_checkout_blocks_page(): void {
		$this->visit_checkout_page( '<!-- wp:woocommerce/checkout --><div class="wp-block-woocommerce-checkout"></div><!-- /wp:woocommerce/checkout -->' );

		ClassicCheckout::enqueue_script();

		$this->assertFalse( wp_script_is( ClassicCheckout::SCRIPT_HANDLE, 'enqueued' ) );
	}

	public function test_script_is_enqueued_on_a_classic_checkout_page(): void {
		$this->visit_checkout_page( '[woocommerce_checkout]' );

		ClassicCheckout::enqueue_script();

		$this->assertTrue( wp_script_is( ClassicCheckout::SCRIPT_HANDLE, 'enqueued' ) );
	}

	private function visit_checkout_page( string $content ): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => $content,
			)
		);
		update_option( 'woocommerce_checkout_page_id', $page_id );
		$this->go_to( get_permalink( $page_id ) );
	}
}
