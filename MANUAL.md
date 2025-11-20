# HP Funnel Bridge Plugin Manual
**Version:** 0.2.45

## 1. Architecture Overview

The **HP Funnel Bridge** is a WordPress plugin designed to act as a headless middleware between standalone React/Vite funnels and a WooCommerce backend. It abstracts complex logic—payments, shipping, order creation, and upsells—so funnels can remain lightweight and focused on UI.

It leverages existing infrastructure from the **Enhanced Admin Order (EAO)** plugin (Stripe integration, ShipStation connection, YITH Points logic) to avoid code duplication and ensure data consistency.

### System Flow
1.  **Frontend (Funnel)**: A React SPA running on a subpath (e.g., `/funnels/fastingkit`). It communicates solely with the Bridge via REST API.
2.  **Middleware (Bridge Plugin)**:
    -   Exposes `hp-funnel/v1` REST endpoints.
    -   Handles CORS securely with an allow-list registry.
    -   Orchestrates Stripe Payment Intents (Checkout & Upsell).
    -   Calculates shipping via ShipStation API.
    -   Manages global discounts and point redemptions.
    -   Creates/Updates WooCommerce orders via Stripe Webhooks or direct API calls.
3.  **Backend (WooCommerce)**: Stores products, orders, and customers. The Bridge ensures orders created here look "native" to admin tools.

---

## 2. Setup & Guidelines

### 2.1 Installation
1.  Install and activate `hp-funnel-bridge`.
2.  Ensure dependencies are active:
    -   **WooCommerce**
    -   **Enhanced Admin Order (EAO)** (v5.2.76+) - *Required for Stripe & ShipStation logic.*
    -   **HP ShipStation Rates** - *Optional, used for rate filtering.*

### 2.2 Funnel Registry (Admin Settings)
To connect a new funnel:
1.  Go to **WooCommerce > Settings > HP Funnel Bridge**.
2.  **Funnel Registry**: Add a new row.
    -   **Name**: Internal identifier (e.g., `fastingkit`).
    -   **Staging Origin**: URL of the staging funnel (e.g., `https://staging.holisticpeople.com`).
    -   **Production Origin**: URL of the live funnel (e.g., `https://holisticpeople.com`).
    -   **Mode**: Set independent modes per environment:
        -   `Off`: Funnel redirects users away (kill switch).
        -   `Test`: Uses Stripe Test keys.
        -   `Live`: Uses Stripe Live keys.

### 2.3 Stripe Configuration
-   **API Keys**: The Bridge reads Stripe keys directly from **EAO Settings**. Ensure EAO is configured with both Test and Live keys.
-   **Webhooks**:
    -   Go to Stripe Dashboard > Developers > Webhooks.
    -   Add Endpoint: `https://YOUR_SITE/wp-json/hp-funnel/v1/stripe/webhook`.
    -   Select events: `payment_intent.succeeded`.
    -   Copy the **Signing Secret** (`whsec_...`) to **HP Funnel Bridge Settings** (store separate secrets for Test and Live modes).

### 2.4 Development Guidelines
-   **Products**: Use **SKUs** in frontend API calls, not IDs. This allows product IDs to change between staging/prod without breaking the funnel.
-   **Pricing**: Do not hardcode prices in React. Use the `/catalog/prices` endpoint to fetch MSRP and calculate discounts dynamically.
-   **Images**: Use optimized assets. The Bridge returns product thumbnail URLs in order summaries.

---

## 3. API Documentation

**Base URL**: `/wp-json/hp-funnel/v1`

### 3.1 General Status
**GET** `/status`
-   **Purpose**: Check if the funnel is enabled and get current mode.
-   **Params**: `?funnel_name=fastingkit`
-   **Response**: `{ "mode": "live" | "test" | "off" }`

### 3.2 Catalog & Pricing
**GET** `/catalog/prices`
-   **Purpose**: Fetch current WooCommerce MSRP for a list of products.
-   **Params**: `?skus=SKU1,SKU2`
-   **Response**: `{ "ok": true, "prices": { "SKU1": 24.00, "SKU2": 149.99 } }`

### 3.3 Shipping Rates
**POST** `/shipstation/rates`
-   **Purpose**: Get real-time shipping quotes.
-   **Body**:
    ```json
    {
      "recipient": { "name": "...", "street1": "...", "city": "...", "state": "...", "zip": "...", "country": "US" },
      "items": [ { "sku": "...", "qty": 1 } ]
    }
    ```
-   **Response**: Returns array of rates (carrier, service, cost, delivery days).

### 3.4 Cart Totals
**POST** `/checkout/totals`
-   **Purpose**: Calculate grand total including discounts, shipping, and points.
-   **Body**:
    ```json
    {
      "items": [ { "sku": "...", "qty": 1 } ],
      "shipping_cost": 12.50,
      "points_redemption": 500
    }
    ```
-   **Response**:
    ```json
    {
      "ok": true,
      "subtotal": 100.00,
      "global_discount": 10.00,
      "discounted_subtotal": 90.00,
      "shipping": 12.50,
      "points_discount": 5.00,
      "grand_total": 97.50
    }
    ```

### 3.5 Checkout (Payment Intent)
**POST** `/checkout/intent`
-   **Purpose**: Create a Stripe PaymentIntent for the main order.
-   **Body**: Same as `/checkout/totals` + `customer_info` (email, name, address).
-   **Response**: `{ "clientSecret": "pi_..._secret_...", "customerId": "cus_...", "pi_id": "pi_..." }`
-   **Behavior**: Creates a "pending" intent. Order is NOT created in WC until payment succeeds (via webhook).

### 3.6 Order Resolution
**GET** `/orders/resolve`
-   **Purpose**: Find a WooCommerce Order ID given a Stripe Payment Intent ID.
-   **Params**: `?pi_id=pi_...`
-   **Response**: `{ "order_id": 12345, "status": "processing" }` (Returns 404 if webhook hasn't processed yet).

**GET** `/orders/summary`
-   **Purpose**: Get full details for a Thank You page.
-   **Params**: `?order_id=12345&pi_id=pi_xxx`
-   **Security**: Requires `pi_id` to match the order (acts as authorization token).
-   **Response**: Detailed JSON with items (names, images, SKUs), financial breakdown (shipping, fees, points), and totals.

### 3.7 One-Click Upsell
**POST** `/upsell/charge`
-   **Purpose**: Charge an existing customer immediately (Off-Session).
-   **Body**:
    ```json
    {
      "parent_order_id": 12345,
      "parent_pi_id": "pi_xxx", 
      "items": [ { "sku": "UPSELL-SKU", "qty": 1 } ],
      "funnel_name": "fastingkit"
    }
    ```
-   **Security**: Requires `parent_pi_id` to match the order (acts as authorization token).
-   **Response**: `{ "ok": true, "order_id": 12345 }`
-   **Behavior**:
    -   Reuses payment method from parent order.
    -   Adds upsell items directly to the **parent** WC order.
    -   Updates Stripe PI/Charge description to include "Upsell".
    -   **Note**: Does NOT create a separate "Off The Fast Kit" fee unless no items are provided.

### 3.8 Webhooks
**POST** `/stripe/webhook`
-   **Purpose**: Ingest `payment_intent.succeeded` events from Stripe.
-   **Process**:
    1.  Verifies signature.
    2.  Creates WooCommerce order.
    3.  Applies global 10% discount (adjusting line item totals).
    4.  Records point redemption (YITH).
    5.  Sets payment method to "HP Funnel Bridge (Stripe - Live/Test)".

---

## 4. Hosted Confirmation Page
For 3D Secure or simple payment flow completion, the Bridge includes a hosted page:
-   **URL**: `https://YOUR_SITE/?hp_fb_confirm=1&cs=CLIENT_SECRET&pk=PUBLISHABLE_KEY`
-   **Function**: Mounts Stripe Elements, handles final confirmation, and redirects back to the funnel (e.g., `.../funnels/fastingkit/#upsell`).
