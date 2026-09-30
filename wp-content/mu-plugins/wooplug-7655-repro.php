<?php
/**
 * Local-only helpers for reproducing WOOPLUG-7655.
 */

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		class WOOPLUG_7655_Pending_Gateway extends WC_Payment_Gateway {
			public function __construct() {
				$this->id                 = 'wooplug_7655_pending';
				$this->title              = 'WOOPLUG-7655 leave payment pending';
				$this->method_title       = 'WOOPLUG-7655 leave payment pending';
				$this->method_description = 'Local-only gateway that leaves an order pending and reports a failed attempt.';
				$this->enabled            = 'yes';
				$this->has_fields         = false;
			}

			public function process_payment( $order_id ) {
				$order = wc_get_order( $order_id );
				$order->add_order_note( 'WOOPLUG-7655 gateway called; order deliberately left pending.' );
				$order->save();

				wc_add_notice( 'The local reproduction gateway deliberately left payment pending.', 'error' );

				return array( 'result' => 'failure' );
			}
		}

		add_filter(
			'woocommerce_payment_gateways',
			function ( $gateways ) {
				$gateways[] = WOOPLUG_7655_Pending_Gateway::class;
				return $gateways;
			}
		);
	},
	20
);

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'wooplug-7655/v1',
			'/latest',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => function () {
					$orders = wc_get_orders(
						array(
							'limit'   => 1,
							'orderby' => 'date',
							'order'   => 'DESC',
							'return'  => 'ids',
						)
					);

					return rest_ensure_response(
						array(
							'order_id' => $orders ? (int) $orders[0] : 0,
							'worker'   => gethostname(),
						)
					);
				},
			)
		);

		register_rest_route(
			'wooplug-7655/v1',
			'/inspect/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => function ( WP_REST_Request $request ) {
					global $wpdb;

					$order_id = (int) $request['id'];
					$order    = wc_get_order( $order_id );
					$cached = wc_get_container()
						->get( Automattic\WooCommerce\Caching\WPCacheEngine::class )
						->get_cached_object( (string) $order_id, 'orders_data' );
					$db_status = $wpdb->get_var(
						$wpdb->prepare( "SELECT status FROM {$wpdb->prefix}wc_orders WHERE id = %d", $order_id )
					);

					return rest_ensure_response(
						array(
							'order_id'            => $order_id,
							'worker'              => gethostname(),
							'object_status'       => $order instanceof WC_Order ? $order->get_status() : null,
							'database_status'     => $db_status,
							'orders_data_found'   => null !== $cached,
							'orders_data_status'  => is_object( $cached ) && isset( $cached->status ) ? $cached->status : null,
							'date_paid'           => $order instanceof WC_Order && $order->get_date_paid() ? $order->get_date_paid()->date( DATE_ATOM ) : null,
							'transaction_id'      => $order instanceof WC_Order ? $order->get_transaction_id() : null,
						)
					);
				},
			)
		);

		register_rest_route(
			'wooplug-7655/v1',
			'/cancel/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => function ( WP_REST_Request $request ) {
					$order = wc_get_order( (int) $request['id'] );
					if ( ! $order instanceof WC_Order ) {
						return new WP_Error( 'missing_order', 'Order not found.', array( 'status' => 404 ) );
					}

					$order->update_status( 'cancelled', 'WOOPLUG-7655 worker B cancelled the order.' );

					return rest_ensure_response(
						array(
							'order_id' => $order->get_id(),
							'status'   => $order->get_status(),
							'worker'   => gethostname(),
						)
					);
				},
			)
		);
	}
);
