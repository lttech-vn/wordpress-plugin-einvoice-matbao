<?php
/**
 * Declared platform floor (plugin header and Composer).
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use WP_UnitTestCase;

/**
 * Platform requirements stay in sync with the supported floor.
 */
final class PlatformRequirementsTest extends WP_UnitTestCase {

	public function test_plugin_header_declares_platform_floor(): void {
		$data = get_file_data(
			EINVOICE_MATBAO_FILE,
			array(
				'wp'        => 'Requires at least',
				'php'       => 'Requires PHP',
				'wc'        => 'WC requires at least',
				'wc_tested' => 'WC tested up to',
			)
		);

		$this->assertSame( '6.6', $data['wp'] );
		$this->assertSame( '8.2', $data['php'] );
		$this->assertSame( '9.8', $data['wc'] );
		$this->assertSame( '11.2', $data['wc_tested'] );
	}

	public function test_composer_declares_php_floor(): void {
		$composer = wp_json_file_decode( dirname( EINVOICE_MATBAO_FILE ) . '/composer.json', array( 'associative' => true ) );

		$this->assertIsArray( $composer );
		$this->assertSame( '>=8.2', $composer['require']['php'] );
		$this->assertSame( '8.2.0', $composer['config']['platform']['php'] );
	}
}
