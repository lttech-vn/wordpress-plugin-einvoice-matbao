<?php
/**
 * Plugin Name: E-Invoice Mat Bao E2E HTTP guard
 * Description: E2E only (copied into mu-plugins by tests/e2e/setup-site.sh). Never ship.
 *
 * Every mode: a Mat Bao request to any host other than the local fake or the demo API is refused before it
 * leaves. Mode `fake` (option `einv_e2e_mode`, the CI default): EINVOICE_MATBAO_BASE_URL points at the fake
 * server in tests/e2e/fake-matbao, so no request reaches Mat Bao. Mode `demo`: requests go to the demo API.
 *
 * Also registers the `einv-e2e/v1` REST seams the browser suite uses to arrange and inspect state the UI
 * cannot reach quickly (create/complete/refund orders, make retries due, read rows). They answer only on a
 * site that ran configure.php (option `einv_e2e_mode`), which exists on throwaway E2E containers only.
 *
 * @package EinvoiceMatbao
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- E2E fixture, functions are prefixed einv_e2e_.

const EINV_E2E_DEMO_HOST = 'demo-api-hddt.matbao.in';
const EINV_E2E_FAKE_HOST = '127.0.0.1';

/*
 * Every mode: a Mat Bao request goes either to the local fake server (mode `fake`, base URL set by
 * EINVOICE_MATBAO_BASE_URL in wp-config.php) or to the demo API (mode `demo`). Anything else that looks
 * like Mat Bao is refused before it leaves, so the production host can never be reached from a test.
 */
add_filter(
	'pre_http_request',
	static function ( $preempt, array $args, string $url ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$mode = (string) get_option( 'einv_e2e_mode', 'fake' );
		if ( EINV_E2E_FAKE_HOST === $host && 'fake' === $mode ) {
			return $preempt;
		}
		if ( ! str_contains( $host, 'matbao' ) ) {
			return $preempt;
		}
		if ( 'demo' === $mode && EINV_E2E_DEMO_HOST === $host && 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) ) {
			return $preempt;
		}

		return new WP_Error( 'einv_e2e_blocked', 'E2E: only the local fake or the Mat Bao demo API is allowed.' );
	},
	1,
	3
);

/**
 * Runs the due Action Scheduler actions of the plugin (and the rest of the queue).
 */
function einv_e2e_run_queue(): int {
	if ( ! class_exists( 'ActionScheduler_QueueRunner' ) ) {
		return 0;
	}
	return (int) ActionScheduler_QueueRunner::instance()->run( 'E2E' );
}

/**
 * Makes every pending plugin action due now (stands in for waiting out the 1 min ... 6 h backoff).
 */
function einv_e2e_make_due(): int {
	global $wpdb;
	$now = gmdate( 'Y-m-d H:i:s' );

	return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"UPDATE {$wpdb->prefix}actionscheduler_actions a JOIN {$wpdb->prefix}actionscheduler_groups g ON g.group_id = a.group_id
			SET a.scheduled_date_gmt = %s, a.scheduled_date_local = %s WHERE a.status = 'pending' AND g.slug = 'einvoice-matbao'",
			$now,
			$now
		)
	);
}

/**
 * Deletes plugin data and queued actions, so every test starts from an empty ledger.
 */
function einv_e2e_reset_data(): void {
	global $wpdb;
	$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}einvoice_matbao_attempt" ); // phpcs:ignore WordPress.DB
	$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}einvoice_matbao_invoice" ); // phpcs:ignore WordPress.DB
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'einvoice\\_matbao\\_alert\\_%' OR option_name LIKE '\\_transient\\_einvoice\\_matbao%' OR option_name LIKE '\\_transient\\_timeout\\_einvoice\\_matbao%'" ); // phpcs:ignore WordPress.DB
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		foreach ( array( 'einvoice_matbao_create_draft', 'einvoice_matbao_send_alert' ) as $hook ) {
			as_unschedule_all_actions( $hook );
		}
	}
	delete_option( 'einvoice_matbao_token_keys' );
	// Every plugin transient (token, templates cache) goes by name, also under a persistent object cache.
	delete_transient( 'einvoice_matbao_templates' );
}

add_action(
	'rest_api_init',
	static function (): void {
		$permission = static fn(): bool => false !== get_option( 'einv_e2e_mode', false );
		$route      = static function ( string $path, callable $callback, string $methods = 'POST' ) use ( $permission ): void {
			register_rest_route(
				'einv-e2e/v1',
				$path,
				array(
					'methods'             => $methods,
					'permission_callback' => $permission,
					'callback'            => $callback,
				)
			);
		};

		// Completes an order and runs the queue.
		$route(
			'/complete/(?P<id>\d+)',
			static function ( WP_REST_Request $request ): array {
				$order = wc_get_order( (int) $request['id'] );
				if ( ! $order ) {
					return array( 'completed' => false );
				}
				$order->update_status( 'completed' );
				einv_e2e_run_queue();
				return array( 'completed' => true );
			}
		);

		// Sets any order status and runs the queue: {status}.
		$route(
			'/status/(?P<id>\d+)',
			static function ( WP_REST_Request $request ): array {
				$order = wc_get_order( (int) $request['id'] );
				if ( ! $order ) {
					return array( 'ok' => false );
				}
				$order->update_status( (string) $request->get_param( 'status' ) );
				if ( false !== $request->get_param( 'run_queue' ) ) {
					einv_e2e_run_queue();
				}
				return array( 'ok' => true );
			}
		);

		// Creates a VND order from product SKUs. Body: lines (sku and qty), optional billing, request (buyer type fields), total, status.
		$route(
			'/order',
			static function ( WP_REST_Request $request ): array {
				$order = wc_create_order();
				$order->set_currency( 'VND' );
				$billing = array_merge(
					array(
						'first_name' => 'Nguyễn',
						'last_name'  => 'Văn Thử',
						'email'      => 'e2e-buyer@example.invalid',
						'phone'      => '0912345678',
						'address_1'  => '3 Đường Thử Nghiệm',
						'city'       => 'Hồ Chí Minh',
						'country'    => 'VN',
					),
					(array) $request->get_param( 'billing' )
				);
				$order->set_address( $billing, 'billing' );
				foreach ( (array) $request->get_param( 'lines' ) as $line ) {
					$id      = wc_get_product_id_by_sku( (string) ( $line['sku'] ?? '' ) );
					$product = $id ? wc_get_product( $id ) : null;
					if ( $product ) {
						$order->add_product( $product, (int) ( $line['qty'] ?? 1 ) );
					}
				}
				$order->calculate_totals();
				if ( null !== $request->get_param( 'total' ) ) {
					$order->set_total( (string) $request->get_param( 'total' ) ); // Tamper: lines no longer add up to the total.
				}
				$buyer = (array) $request->get_param( 'request' );
				if ( array() !== $buyer && class_exists( \LTTech\EinvoiceMatbao\Buyer\BuyerRequestStore::class ) ) {
					$input = new \LTTech\EinvoiceMatbao\Buyer\BuyerInput(
						(string) ( $buyer['type'] ?? '' ),
						(string) ( $buyer['full_name'] ?? '' ),
						(string) ( $buyer['business_name'] ?? '' ),
						(string) ( $buyer['tax_code'] ?? '' ),
						(string) ( $buyer['address'] ?? '' ),
						(string) ( $buyer['id_number'] ?? '' ),
						(string) ( $buyer['phone'] ?? '' ),
						(string) ( $buyer['email'] ?? '' )
					);
					( new \LTTech\EinvoiceMatbao\Buyer\BuyerRequestStore() )->save(
						$order,
						\LTTech\EinvoiceMatbao\Buyer\BuyerRequestStore::from_input( $input, new \LTTech\EinvoiceMatbao\Encryption() )
					);
				}
				$order->save();
				$status = (string) $request->get_param( 'status' );
				if ( '' !== $status ) {
					$order->update_status( $status );
					einv_e2e_run_queue();
				}
				return array(
					'id'    => $order->get_id(),
					'total' => (string) $order->get_total(),
				);
			}
		);

		// Edits an order: {billing?:{...}, line_total?: number}; runs the queue (OrderChangeWatcher fires at shutdown).
		$route(
			'/edit/(?P<id>\d+)',
			static function ( WP_REST_Request $request ): array {
				$order = wc_get_order( (int) $request['id'] );
				if ( ! $order ) {
					return array( 'ok' => false );
				}
				if ( is_array( $request->get_param( 'billing' ) ) ) {
					$order->set_address( array_merge( $order->get_address( 'billing' ), $request->get_param( 'billing' ) ), 'billing' );
				}
				if ( null !== $request->get_param( 'total' ) ) {
					$order->set_total( (string) $request->get_param( 'total' ) );
				}
				$order->save();
				return array( 'ok' => true );
			}
		);

		// Refunds an order: {amount}.
		$route(
			'/refund/(?P<id>\d+)',
			static function ( WP_REST_Request $request ): array {
				$refund = wc_create_refund(
					array(
						'order_id' => (int) $request['id'],
						'amount'   => (string) $request->get_param( 'amount' ),
						'reason'   => 'E2E',
					)
				);
				return array( 'ok' => ! is_wp_error( $refund ) );
			}
		);

		$route(
			'/run-queue',
			static fn(): array => array( 'ran' => einv_e2e_run_queue() )
		);

		// Makes retries due now and runs them: {rounds?}.
		$route(
			'/tick',
			static function ( WP_REST_Request $request ): array {
				$ran = 0;
				for ( $i = 0, $n = max( 1, (int) $request->get_param( 'rounds' ) ); $i < $n; ++$i ) {
					einv_e2e_make_due();
					$ran += einv_e2e_run_queue();
				}
				return array( 'ran' => $ran );
			}
		);

		// Empties invoices, attempts, alerts and plugin transients; leaves orders, products and settings.
		$route(
			'/reset',
			static function (): array {
				einv_e2e_reset_data();
				return array( 'ok' => true );
			}
		);

		// Plugin rows of an order, for assertions the UI does not show: no CCCD ciphertext is returned.
		$route(
			'/invoice/(?P<id>\d+)',
			static function ( WP_REST_Request $request ): array {
				global $wpdb;
				$row      = $wpdb->get_row( $wpdb->prepare( "SELECT id, order_id, reference, status, error_code, error_kind, alert_cycle FROM {$wpdb->prefix}einvoice_matbao_invoice WHERE order_id = %d", (int) $request['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
				$attempts = $row ? $wpdb->get_results( $wpdb->prepare( "SELECT outcome, http_status, api_code FROM {$wpdb->prefix}einvoice_matbao_attempt WHERE invoice_id = %d ORDER BY id", (int) $row['id'] ), ARRAY_A ) : array(); // phpcs:ignore WordPress.DB
				return array(
					'invoice'  => $row,
					'attempts' => $attempts,
				);
			},
			'GET'
		);

		// Order meta the plugin keeps about the buyer, to prove the citizen ID is never stored in the clear.
		$route(
			'/order-meta/(?P<id>\\d+)',
			static function ( WP_REST_Request $request ): array {
				$order = wc_get_order( (int) $request['id'] );
				return array(
					'request'  => $order ? $order->get_meta( '_einvoice_buyer_request' ) : null,
					'snapshot' => $order ? $order->get_meta( '_einvoice_buyer_snapshot' ) : null,
				);
			},
			'GET'
		);

		// Product ID of a seeded SKU.
		$route(
			'/product-id/(?P<sku>[A-Za-z0-9-]+)',
			static fn( WP_REST_Request $request ): array => array( 'id' => (int) wc_get_product_id_by_sku( (string) $request['sku'] ) ),
			'GET'
		);

		// Removes the stored Mat Bao credentials (settings tests that start unconfigured).
		$route(
			'/credentials/clear',
			static function (): array {
				delete_option( \LTTech\EinvoiceMatbao\CredentialStore::OPTION );
				return array( 'ok' => true );
			}
		);

		// Puts the fake-mode Mat Bao credentials (the ones configure.php stores) back.
		$route(
			'/credentials/restore',
			static function (): array {
				( new \LTTech\EinvoiceMatbao\CredentialStore() )->save_matbao( new \LTTech\EinvoiceMatbao\Matbao\MatbaoCredentials( 'demo', '0000000000', 'e2e', 'e2e-fake' ) );
				delete_transient( 'einvoice_matbao_templates' );
				return array( 'ok' => true );
			}
		);

		// Raw value of one plugin option, for "the secret is not stored in the clear" assertions.
		$route(
			'/option/(?P<name>einvoice_matbao_[a-z_]+)',
			static fn( WP_REST_Request $request ): array => array( 'value' => get_option( (string) $request['name'], null ) ),
			'GET'
		);

		// Number of queued Lark/draft actions (for "nothing repeats" assertions).
		$route(
			'/queued',
			static function (): array {
				return array(
					'drafts' => count(
						as_get_scheduled_actions(
							array(
								'hook'     => 'einvoice_matbao_create_draft',
								'status'   => 'pending',
								'per_page' => 100,
							),
							'ids'
						)
					),
				);
			},
			'GET'
		);

		// Creates a WordPress user for permission tests: {role}. Returns the login and a one-time password.
		$route(
			'/user',
			static function ( WP_REST_Request $request ): array {
				$login    = 'e2e-' . wp_generate_password( 6, false );
				$password = wp_generate_password( 20, false );
				$id       = wp_insert_user(
					array(
						'user_login' => $login,
						'user_pass'  => $password,
						'user_email' => $login . '@example.invalid',
						'role'       => (string) $request->get_param( 'role' ),
					)
				);
				return is_wp_error( $id ) ? array( 'ok' => false ) : array(
					'ok'       => true,
					'login'    => $login,
					'password' => $password,
				);
			}
		);

		// Sets plugin settings. Body: any of prefix (with walk_in), template (form and series), unit.
		$route(
			'/settings',
			static function ( WP_REST_Request $request ): array {
				$settings = new \LTTech\EinvoiceMatbao\Settings();
				if ( null !== $request->get_param( 'prefix' ) ) {
					$settings->save_invoicing( (string) $request->get_param( 'prefix' ), (string) $request->get_param( 'walk_in' ) );
				}
				$template = $request->get_param( 'template' );
				if ( is_array( $template ) ) {
					$settings->save_default_template( (string) $template['form'], (string) $template['series'] );
				}
				if ( null !== $request->get_param( 'unit' ) ) {
					$settings->save_default_unit( (string) $request->get_param( 'unit' ) );
				}
				delete_transient( 'einvoice_matbao_templates' );
				return array( 'ok' => true );
			}
		);
	}
);
