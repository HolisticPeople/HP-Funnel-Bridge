<!-- mirrored from wc.plan.md; keep in sync -->
# HP Funnel Bridge: Multi‑Funnel, Reusable, 1‑Click Stripe Upsell (no EAO changes)

Goals

- Reusable for any future funnel; no 3rd‑party plugins; do not modify EAO.
- One‑click post‑purchase upsell (no payment re‑entry) using Stripe.
- Reuse EAO Stripe keys and ShipStation utilities; avoid duplication.
- Pass‑through ShipStation rates (no overrides/policies), link orders to existing users by email, add funnel note, customer lookup + points redemption.

## Architecture

- Frontend (any funnel): collects items, email, address, shipping, coupon; renders Stripe Payment Element; shows post‑purchase offer.
- Backend: NEW first‑party plugin “HP Funnel Bridge” (separate repo; mu‑plugin OK), REST base `/hp-funnel/v1`.
- Depends on EAO active; reads Stripe creds via `get_option('eao_stripe_settings')`.
- Delegates ShipStation rate fetching to EAO helpers (pure pass‑through).
- Own Stripe webhook (separate from EAO) for PaymentIntent events.

## Multi‑funnel capability

- “Funnel Registry” admin page (simple for now):
- Allowed origins (CORS), `funnel_id` + `funnel_name`, optional HMAC shared secret per origin.
- Skip carrier overrides and all tax/shipping policy flags for now.
- All endpoints accept and persist: `funnel_id`, `funnel_name`, `campaign`, `utm_*` to order meta.
- Idempotency supported via `X-Idempotency-Key`.

## Endpoints (generic, product‑agnostic)

- POST `/hp-funnel/v1/customer`
- Input: `email`
- Server: if a WP user exists, return `{ user_id, default_billing, default_shipping, points_balance }` (via Woo + YITH APIs). Else `{ user_id: 0, points_balance: 0 }`.

- POST `/hp-funnel/v1/shipstation/rates`
- Input: `funnel_id`, `address`, `items[]` (+ optional package hints).
- Server: build a transient Woo order from input, call EAO helpers `eao_build_shipstation_rates_request` → `eao_get_shipstation_carrier_rates` → format; return rates as‑is.

- POST `/hp-funnel/v1/totals`
- Input: `funnel_id`, `items[]`, `address`, `coupon_codes[]`, optional `selected_rate`, optional `points_to_redeem`.
- Server: compute totals via Woo tax/shipping calculators; apply coupons; if `points_to_redeem`, compute discount via YITH and include as a negative line/discount in totals response.

- POST `/hp-funnel/v1/checkout/intent`
- Input: `funnel_id`, `funnel_name`, `customer` (email, name), `shipping_address`, `items[]`, `coupon_codes[]`, optional `selected_rate`, optional `points_to_redeem`, analytics fields.
- Server: ensure/reuse Stripe Customer; compute final payable amount; create PaymentIntent with automatic payment methods and `setup_future_usage=off_session`; persist a draft keyed by `order_draft_id` containing all order data + analytics; return `{ client_secret, publishable, order_draft_id }`.

- POST `/hp-funnel/v1/stripe/webhook`
- On `payment_intent.succeeded`:
  - Resolve `order_draft_id`; create Woo order (lines, shipping, taxes, coupons) from the draft.
  - If a WP user with the draft email exists, set `customer_id` on the order (even if not logged in at purchase time).
  - Apply points redemption: decrease customer’s points via YITH and add a matching discount line.
  - Store Stripe identifiers (`cus_`, default `pm_` if available, `pi_`, `charge`) in order meta.
  - Add order note: `Funnel: {funnel_name}` and persist funnel analytics meta.
- On `payment_intent.payment_failed`: mark draft failed.

- POST `/hp-funnel/v1/upsell/charge`
- Input: `parent_order_id`, `items[]`, optional `amount_override`, analytics.
- Server: read `cus_` and default payment method meta from parent order; create off‑session PI (`confirm=true`); on success, create child order linked to parent, copy `customer_id` and analytics meta, add `Funnel: {funnel_name} (upsell)` note.

## Repository and file layout (separate project)

- Repo root: `HP-Funnel-Bridge/`
- `hp-funnel-bridge.php` (plugin bootstrap; contains `Version:` header and `HP_FB_PLUGIN_VERSION` constant)
- `includes/`
  - `Admin/SettingsPage.php` (Funnel Registry: origins, funnel ids/names, HMAC)
  - `Rest/CustomerController.php`
  - `Rest/CheckoutController.php`
  - `Rest/ShipStationController.php`
  - `Rest/TotalsController.php`
  - `Rest/UpsellController.php`
  - `Rest/WebhookController.php`
  - `Services/OrderDraftStore.php` (transient/CPT)
  - `Services/PointsService.php` (YITH integration)
  - `Stripe/Client.php` (simple Stripe API wrapper using EAO keys)
- `.github/workflows/deploy.yml` (see CI/CD below)
- `uninstall.php`
- `readme.txt`, `README.md`
- `docs/PLAN.md` (this plan)
- `docs/Agent-SSH-Runbook.md` (optional: mirror EAO runbook with Bridge paths)

## CI/CD & deployments (mirror EAO)

- Branching
- `dev`: integration branch. Push/merge to `dev` auto‑deploys to staging.
- `main`: protected. Merge from `dev` manually. Production deploy is manual.
- Versioning
- Bump the plugin header `Version:` and `HP_FB_PLUGIN_VERSION` for any test release you want staged; commit to `dev`.
- GitHub Actions (similar to EAO’s `.github/workflows/deploy.yml`)
- Trigger: on push to `dev` → Deploy to STAGING.
- Trigger: manual `workflow_dispatch` with input `deploy: production|staging`.
- Steps (env‑agnostic):
  - `actions/checkout@v4`
  - `webfactory/ssh-agent@v0.9.0` load SSH private key from secrets
  - Add host key to `known_hosts` via `ssh-keyscan`
  - `appleboy/ssh-action` to ensure plugin dir exists
  - `rsync -az --delete` over SSH to `${PLUGINS_BASE_DEFAULT or *_PLUGINS_BASE}/${PLUGIN_SLUG}`
  - Post‑deploy via `wp` CLI: cache flush and `plugin activate hp-funnel-bridge || true`
- Env/secrets (staging): `KINSTA_HOST`, `KINSTA_PORT`, `KINSTA_USER`, `KINSTA_PLUGINS_BASE` (optional), `KINSTA_SSH_KEY`
- Env/secrets (production): `KINSTAPROD_HOST`, `KINSTAPROD_PORT`, `KINSTAPROD_USER`, `KINSTAPROD_PLUGINS_BASE` (optional), `KINSTAPROD_SSH_KEY`
- Workflow `env`:
  - `PLUGIN_SLUG: hp-funnel-bridge`
  - `PLUGINS_BASE_DEFAULT: public/wp-content/plugins`
- Manual deploys
- Use `workflow_dispatch` to run staging/prod on demand from any branch; production runs only when you choose `production`.
- Merging `dev` → `main` is manual; production deploy from `main` is manual.

## Frontend usage (any funnel)

- Env: `VITE_FUNNEL_API_BASE`, `VITE_STRIPE_PUBLISHABLE_KEY`, `VITE_APP_ORIGIN`.
- Flow:
- On email blur: call `/customer` → prefill address, show `points_balance` with a control to redeem.
- Shipping: call `/shipstation/rates` (pass‑through), then `/totals` (optionally with `points_to_redeem`).
- Payment: render Stripe Payment Element; call `/checkout/intent`; confirm clientSecret.
- Post‑purchase: route `/post-purchase?order=...`; offer upsell; on accept call `/upsell/charge`.

## Security

- CORS allowlist per origin via registry; optional HMAC header per origin validated by Bridge endpoints.
- Separate Stripe webhook secret for Bridge; EAO webhook unchanged.
- Server validates item IDs/prices against Woo catalog to prevent tampering.

## Staging → Production

- Staging: register staging funnel origin; Stripe test webhook to Bridge; verify ShipStation pass‑through; test existing‑user linking, points redemption, order notes.
- Production: register prod origin; live webhook; switch to live keys; smoke test end‑to‑end.

## Extensibility

- Filters/actions for: item normalization, totals adjustments, analytics meta, post‑order hooks.
- Future (not now): per‑funnel carrier overrides, tax/shipping policies, multi‑gateway.

## Deployment (dev → staging) and next steps

1) Branching
- Work on `dev`; pushing or merging to `dev` auto‑deploys to staging via `.github/workflows/deploy.yml`.
- `main` is protected; production deploy is manual via workflow_dispatch.

2) Version bump
- Update `Version:` in `hp-funnel-bridge.php` and `HP_FB_PLUGIN_VERSION` constant for every staging test release.

3) GitHub Actions (environment secrets)
- Staging environment must provide: `KINSTA_HOST, KINSTA_PORT, KINSTA_USER, KINSTA_PLUGINS_BASE (optional), KINSTA_SSH_KEY`.
- Production environment must provide: `KINSTAPROD_HOST, KINSTAPROD_PORT, KINSTAPROD_USER, KINSTAPROD_PLUGINS_BASE (optional), KINSTAPROD_SSH_KEY`.

4) Stripe webhook (staging)
- Add `https://<staging-site>/wp-json/hp-funnel/v1/stripe/webhook` for events `payment_intent.succeeded`, `payment_intent.payment_failed`.

5) Staging QA checklist
- Confirm endpoints from the funnel:
  - `/customer` prefill existing user and points
  - `/shipstation/rates` returns pass‑through rates
  - `/totals` reflects coupons, shipping, and preview points discount
  - `/checkout/intent` returns `client_secret` and succeeds with Stripe test cards
  - `/stripe/webhook` creates Woo orders, links to existing user by email, adds `Funnel: {funnel_name}` note, applies points redemption
  - `/upsell/charge` creates child order off‑session

6) Production cutover (manual)
- Switch plugin settings to Production environment; set Stripe live webhook (same route).
- Run workflow_dispatch → production.
- Smoke test: $1 order and upsell.


