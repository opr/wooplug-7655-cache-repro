# WOOPLUG-7655 stale HPOS cache reproduction

This local-only Docker harness deterministically models the stale-cache sequence reported in WOOPLUG-7655 / GitHub #68289.

It is **not** a replacement for Object Cache Pro and must not be used in production. Its `object-cache.php` persists only WooCommerce's `orders_data` group in container-local APCu. PHP workers A and B therefore share MariaDB but do not invalidate each other's cached order rows. This intentionally models a missed Relay/Object Cache Pro cross-worker invalidation.

The PHP image also installs the Relay extension and starts Redis so the image can later be adapted to use the real client. The deterministic fixture itself uses APCu.

## Architecture

- `localhost:8091` routes every request to PHP worker A.
- `localhost:8092` routes every request to PHP worker B.
- Both workers share WordPress files and MariaDB.
- Each worker has a separate APCu-backed `orders_data` cache.
- The WooCommerce plugin is bind-mounted from `WC_REPO_PATH`.
- A local gateway leaves checkout orders pending and reports a failed payment attempt.

## Start

Clone this repository, create its local environment file, and set `WC_REPO_PATH` to the WooCommerce plugin directory you want to test:

```sh
git clone https://github.com/opr/wooplug-7655-cache-repro.git
cd wooplug-7655-cache-repro
cp .env.example .env
$EDITOR .env
./bin/setup.sh
```

The setup output includes the reproduction product ID. Admin credentials are `admin` / `password`.

## Reproduce

All browser activity must use worker A on port 8091.

1. Open `http://localhost:8091/?add-to-cart=PRODUCT_ID`, replacing `PRODUCT_ID` with the setup output.
2. Open `http://localhost:8091/checkout/`.
3. Enter any valid billing details and choose **WOOPLUG-7655 leave payment pending**.
4. Click **Place order**. The gateway deliberately reports an error, leaving the order pending and the cart/session intact.
5. Find the order ID:

   ```sh
   ./bin/latest-order.sh
   ```

6. Ensure worker A has cached the pending order:

   ```sh
   ./bin/inspect.sh ORDER_ID
   ```

   Worker A should report `object_status: pending`, `database_status: wc-pending`, and `orders_data_found: true`.

7. Cancel the order through worker B:

   ```sh
   ./bin/cancel-on-worker-b.sh ORDER_ID
   ```

8. Demonstrate the stale read:

   ```sh
   ./bin/inspect.sh ORDER_ID
   ```

   Worker A should report an object/cache status of `pending` while the database status is `wc-cancelled`. Worker B should report `cancelled`.

9. Return to the still-open checkout on port 8091 and click **Place order** again.

With the WOOPLUG-7655 fix, checkout should fail with:

> This order cannot be completed without payment. Please try again.

The order remains cancelled and has no paid date. Run `./bin/inspect.sh ORDER_ID` to verify it.

Without the fix, the vulnerable checkout path calls `payment_complete()` after the refreshed order becomes cancelled. The cancelled, non-zero order can transition to a paid status without a successful gateway call.

## Reset

```sh
./bin/reset.sh
```

## Scope

This fixture proves WooCommerce must fail closed when an order initially appears payable but is no longer payable after checkout saves and reloads it. It does not prove that a specific Object Cache Pro or Relay release has an invalidation defect.
