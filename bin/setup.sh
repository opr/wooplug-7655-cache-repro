#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

if [[ ! -f .env ]]; then
	cp .env.example .env
fi

if grep -q '^WC_REPO_PATH=$' .env; then
	echo "Set WC_REPO_PATH in $(pwd)/.env before running setup." >&2
	exit 1
fi

docker compose up -d --build

until docker compose exec -T php-a test -f /var/www/html/wp-load.php; do
	sleep 2
done

wp() {
	docker compose exec -T php-a wp --allow-root --path=/var/www/html "$@"
}

if ! wp core is-installed >/dev/null 2>&1; then
	wp core install \
		--url=http://localhost:8091 \
		--title='WOOPLUG-7655 cache reproduction' \
		--admin_user=admin \
		--admin_password=password \
		--admin_email=admin@example.test \
		--skip-email
fi

wp plugin activate woocommerce
wp option update woocommerce_custom_orders_table_enabled yes
wp option update woocommerce_hpos_datastore_caching_enabled yes
wp option update woocommerce_enable_guest_checkout yes
wp option update woocommerce_enable_checkout_login_reminder no
wp option update woocommerce_currency USD
wp option update permalink_structure '/%postname%/'

wp eval '
$page_id = wc_get_page_id( "checkout" );
if ( $page_id <= 0 ) {
	$page_id = wp_insert_post(
		array(
			"post_title"   => "Checkout",
			"post_name"    => "checkout",
			"post_status"  => "publish",
			"post_type"    => "page",
			"post_content" => "[woocommerce_checkout]",
		)
	);
	update_option( "woocommerce_checkout_page_id", $page_id );
} else {
	wp_update_post( array( "ID" => $page_id, "post_content" => "[woocommerce_checkout]" ) );
}

$product_id = wc_get_product_id_by_sku( "wooplug-7655-repro" );
if ( ! $product_id ) {
	$product = new WC_Product_Simple();
	$product->set_name( "WOOPLUG-7655 reproduction product" );
	$product->set_sku( "wooplug-7655-repro" );
	$product->set_regular_price( "25.00" );
	$product->set_status( "publish" );
	$product_id = $product->save();
}

echo "PRODUCT_ID=$product_id\n";
echo "CHECKOUT_PAGE_ID=$page_id\n";
'

wp rewrite flush
wp cache flush >/dev/null

echo
echo "Runtime checks:"
docker compose exec -T php-a php -r 'printf("APCu: %s\nRelay: %s\n", extension_loaded("apcu") ? "yes" : "no", extension_loaded("relay") ? "yes" : "no");'
wp eval 'printf( "External object cache: %s\nHPOS: %s\nHPOS data cache: %s\n", wp_using_ext_object_cache() ? "yes" : "no", Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? "yes" : "no", Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_datastore_cache_enabled() ? "yes" : "no" );'

echo
echo "Ready:"
echo "  Worker A/store: http://localhost:8091"
echo "  Worker B/control: http://localhost:8092"
echo "  Admin: admin / password"
echo
echo "Follow README.md for the reproduction sequence."
