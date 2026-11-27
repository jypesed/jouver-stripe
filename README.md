# Jouver Stripe

Keeps each dealer's **plan**, fed by Stripe's signed webhook. JAPP and Jouver Core only read it.

```
Stripe ──signed webhook──► POST /wp-json/jouver/v1/stripe/webhook ──► table jouver_subscription
                                                                         │
            PHP:  jouver_stripe_plan( $user_id )  ◄──────────────────────┤
            JS:   GET /wp-json/jouver/v1/plan  (logged in, own plan)  ◄──┘
```

The CRM (Zoho) keeps receiving payments from Stripe on its own. No Stripe secret API key is used;
everything needed is inside the event.

## Setup (once)
1. `wp-config.php` (never in the repo or in a chat): `define( 'JOUVER_STRIPE_WEBHOOK_SECRET', 'whsec_…' );`
2. Stripe → Developers → Webhooks → add the URL shown under **Settings → Jouver Stripe**, with the events
   `checkout.session.completed`, `customer.subscription.created`, `.updated`, `.deleted`, `invoice.paid`, `invoice.payment_failed`.
   Copy that endpoint's signing secret into step 1.
3. Name the plans: on each Stripe **price** add metadata `jouver_plan` = `elite` / `formula1` / `free`.
   Or map ids: `add_filter( 'jouver_stripe_plan_map', fn() => array( 'prod_…' => 'elite' ) );`
   Plan keys are never guessed; Settings → Jouver Stripe lists dealers whose plan is "not mapped".
4. The pricing table must pass the WordPress user id as `client-reference-id` (JAPP's billing page does).

## Reading the plan
```php
if ( function_exists( 'jouver_stripe_plan' ) ) {
	$p = jouver_stripe_plan( $user_id );   // 0 = current user
	// state: free | active | past_due | canceled | incomplete
	// active (bool: active or past_due), plan_key, plan_label, period_end (unix), cancel_at_period_end
}
```
Action `jouver_stripe_plan_changed( $user_id, $plan )` fires after each change.

## How a dealer is found
`checkout.session.completed` carries the user id and links it to the Stripe customer; later events find the
user by customer. Fallback: one exact e-mail match. Subscription events that arrive before the checkout are
held for two days and replayed. A customer we do not know (a website customer) is answered 200 and ignored.

**Dealers who paid before this plugin existed** have no row and show as free. Subscription events carry no
e-mail, so they cannot be matched on their own. `invoice.paid` does carry the e-mail and fires at every renewal,
so each existing dealer is linked (by exact e-mail match) at the next monthly payment, within one billing cycle.
Dealers whose Stripe e-mail differs from their WordPress e-mail stay unmatched; the admin page lists the events.

## Safety
- Stripe's signature is checked (HMAC-SHA256, 5 minute window). No secret configured → 503, nothing stored.
- A repeated event id is ignored; an older event arriving late never undoes a newer one.
- `past_due` keeps the plan while Stripe retries the card; `canceled` returns to free.
- A one-off Checkout payment (ads credit) never changes the plan; it only links the Stripe customer to the dealer.

## Tests
`bash tests/run.sh` (PHP only; no WordPress needed).
