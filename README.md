# HP Funnel Bridge

Multi‑funnel bridge exposing REST endpoints for checkout, shipping rates (via EAO ShipStation), totals, and one‑click upsells using Stripe. It reuses the Enhanced Admin Order (EAO) plugin for Stripe keys, ShipStation utilities, and YITH Points—without modifying EAO.

## Endpoints
- POST `/wp-json/hp-funnel/v1/customer`
- POST `/wp-json/hp-funnel/v1/shipstation/rates`
- POST `/wp-json/hp-funnel/v1/totals`
- POST `/wp-json/hp-funnel/v1/checkout/intent`
- POST `/wp-json/hp-funnel/v1/stripe/webhook`
- POST `/wp-json/hp-funnel/v1/upsell/charge`

## Requirements
- WordPress 6+, WooCommerce 7+
- EAO plugin active (for Stripe keys, ShipStation, YITH Points)

## Settings
Settings → HP Funnel Bridge:
- Environment: staging (Stripe test) / production (Stripe live)
- Allowed Origins (CORS)
- Optional HMAC shared secret (X-HPFB-HMAC)
- Simple Funnel Registry (IDs)

## CI/CD
Push/merge to `dev` deploys to staging (Kinsta). Manual `workflow_dispatch` for staging/production. Secrets mirror EAO:
`KINSTA_HOST, KINSTA_PORT, KINSTA_USER, KINSTA_PLUGINS_BASE, KINSTA_SSH_KEY`
`KINSTAPROD_HOST, KINSTAPROD_PORT, KINSTAPROD_USER, KINSTAPROD_PLUGINS_BASE, KINSTAPROD_SSH_KEY`


