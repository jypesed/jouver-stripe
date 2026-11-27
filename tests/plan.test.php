<?php
// Stripe webhook logic: signature, event -> fields, plan mapping, state. php tests/subscription.test.php
define( 'ABSPATH', '/' );
$FILTER = array();
function apply_filters( $tag, $v ) { global $FILTER; return isset( $FILTER[ $tag ] ) ? $FILTER[ $tag ] : $v; }
function add_action() {} function get_option( $k, $d = array() ) { return $d; }
function ok( $c, $m ) { global $bad; echo ( $c ? 'ok' : 'FAIL' ) . ": $m\n"; if ( ! $c ) { $bad = 1; } }
require __DIR__ . '/../includes/class-jouver-stripe-plan.php';
$S = 'Jouver_Stripe_Plan';

// Signature
$secret = 'whsec_test'; $body = '{"id":"evt_1"}'; $t = 1700000000;
$good = 't=' . $t . ',v1=' . hash_hmac( 'sha256', $t . '.' . $body, $secret );
ok( $S::verify_signature( $body, $good, $secret, $t + 10 ), 'a correctly signed event is accepted' );
ok( ! $S::verify_signature( $body . ' ', $good, $secret, $t + 10 ), 'a changed body is refused' );
ok( ! $S::verify_signature( $body, $good, 'whsec_other', $t + 10 ), 'a wrong secret is refused' );
ok( ! $S::verify_signature( $body, $good, $secret, $t + 301 ), 'an event older than 5 minutes is refused (replay)' );
ok( ! $S::verify_signature( $body, 'v1=abc', $secret, $t ), 'no timestamp is refused' );
ok( ! $S::verify_signature( $body, $good, '', $t ), 'no configured secret is refused' );
ok( $S::verify_signature( $body, 't=' . $t . ',v1=deadbeef,v1=' . hash_hmac( 'sha256', $t . '.' . $body, $secret ), $secret, $t ), 'any of several v1 signatures may match' );

// Checkout completed
$co = $S::normalise( array( 'type' => 'checkout.session.completed', 'created' => 100, 'data' => array( 'object' => array(
	'client_reference_id' => '42', 'customer' => 'cus_9', 'subscription' => 'sub_7', 'payment_status' => 'paid',
	'customer_details' => array( 'email' => ' Dealer@Example.COM ' ) ) ) ) );
ok( 42 === $co['user_id'] && 'cus_9' === $co['customer'] && 'dealer@example.com' === $co['email'], 'checkout: user id from client_reference_id, email lowered' );
ok( 'active' === $co['fields']['status'] && 'sub_7' === $co['fields']['stripe_subscription_id'], 'checkout: paid means active, subscription kept' );
$unpaid = $S::normalise( array( 'type' => 'checkout.session.completed', 'data' => array( 'object' => array( 'client_reference_id' => 'abc', 'customer' => 'cus_1', 'payment_status' => 'unpaid' ) ) ) );
ok( 0 === $unpaid['user_id'] && ! isset( $unpaid['fields']['status'] ), 'checkout: non-numeric reference is no user, unpaid sets no status' );
$oneoff = $S::normalise( array( 'type' => 'checkout.session.completed', 'data' => array( 'object' => array( 'mode' => 'payment', 'client_reference_id' => '42', 'customer' => 'cus_9', 'payment_status' => 'paid' ) ) ) );
ok( 42 === $oneoff['user_id'] && 'cus_9' === $oneoff['fields']['stripe_customer_id'] && ! isset( $oneoff['fields']['status'] ), 'checkout: a one-off payment (ads credit) links the customer but never sets the plan active' );

// Subscription updated (old API: period on the subscription; new API: on the item)
$sub = function ( $extra ) {
	return array( 'type' => 'customer.subscription.updated', 'created' => 200, 'data' => array( 'object' => array_merge( array(
		'id' => 'sub_7', 'customer' => 'cus_9', 'status' => 'active', 'cancel_at_period_end' => false,
		'items' => array( 'data' => array( array( 'price' => array( 'id' => 'price_A', 'product' => 'prod_A', 'nickname' => 'Elite', 'currency' => 'usd', 'metadata' => array() ) ) ) ) ), $extra ) ) );
};
$n = $S::normalise( $sub( array( 'current_period_end' => 1800000000 ) ) );
ok( '2027-01-15 08:00:00' === $n['fields']['current_period_end'], 'period end read from the subscription' );
$ev = $sub( array() ); $ev['data']['object']['items']['data'][0]['current_period_end'] = 1800000000;
ok( '2027-01-15 08:00:00' === $S::normalise( $ev )['fields']['current_period_end'], 'period end read from the item (newer API)' );
ok( 'Elite' === $n['fields']['plan_label'] && 'USD' === $n['fields']['currency'] && 'price_A' === $n['fields']['price_id'] && 'prod_A' === $n['fields']['product_id'], 'label, currency and ids kept' );
ok( '' === $n['fields']['plan_key'], 'plan key is NOT guessed when nothing maps it' );
$FILTER['jouver_stripe_plan_map'] = array( 'prod_A' => 'Elite' );
ok( 'elite' === $S::normalise( $sub( array() ) )['fields']['plan_key'], 'plan key from the filter map (product id)' );
$ev = $sub( array() ); $ev['data']['object']['items']['data'][0]['price']['metadata'] = array( 'jouver_plan' => 'formula1' );
ok( 'formula1' === $S::normalise( $ev )['fields']['plan_key'], 'price metadata jouver_plan wins over the map' );
$FILTER = array();
$ev = $sub( array( 'cancel_at_period_end' => true ) );
ok( 1 === $S::normalise( $ev )['fields']['cancel_at_period_end'], 'cancel at period end kept' );
$del = $sub( array() ); $del['type'] = 'customer.subscription.deleted';
ok( 'canceled' === $S::normalise( $del )['fields']['status'], 'deleted means canceled' );
$pf = $S::normalise( array( 'type' => 'invoice.payment_failed', 'data' => array( 'object' => array( 'customer' => 'cus_9', 'subscription' => 'sub_7' ) ) ) );
ok( 'past_due' === $pf['fields']['status'], 'a failed invoice means past_due' );
$inv = $S::normalise( array( 'type' => 'invoice.paid', 'created' => 300, 'data' => array( 'object' => array(
	'customer' => 'cus_old', 'customer_email' => 'Bert@Example.com', 'subscription' => 'sub_old', 'currency' => 'gbp',
	'lines' => array( 'data' => array( array( 'period' => array( 'end' => 1800000000 ), 'price' => array( 'id' => 'price_E', 'product' => 'prod_E', 'nickname' => 'Elite', 'metadata' => array( 'jouver_plan' => 'elite' ) ) ) ) ) ) ) ) );
ok( 'bert@example.com' === $inv['email'] && 'cus_old' === $inv['customer'] && 'sub_old' === $inv['subscription'], 'invoice.paid: e-mail, customer and subscription read (links a pre-plugin dealer)' );
ok( 'active' === $inv['fields']['status'] && 'elite' === $inv['fields']['plan_key'] && 'GBP' === $inv['fields']['currency'] && '2027-01-15 08:00:00' === $inv['fields']['current_period_end'], 'invoice.paid: plan, currency and period end from the invoice line' );
ok( null === $S::normalise( array( 'type' => 'invoice.paid', 'data' => array( 'object' => array( 'customer' => 'cus_1', 'customer_email' => 'a@b.c', 'lines' => array( 'data' => array() ) ) ) ) ), 'invoice.paid without a subscription (one-off invoice) is not a plan' );
ok( 'bert@example.com' === $S::normalise( array( 'type' => 'invoice.payment_failed', 'data' => array( 'object' => array( 'customer' => 'cus_old', 'customer_email' => 'Bert@Example.com', 'subscription' => 'sub_old' ) ) ) )['email'], 'invoice.payment_failed carries the e-mail too' );
ok( null === $S::normalise( array( 'type' => 'charge.refunded', 'data' => array( 'object' => array() ) ) ), 'other events are ignored' );

// What the screens see
$d = $S::describe( null );
ok( 'free' === $d['state'] && ! $d['active'], 'no row is the free plan' );
$d = $S::describe( array( 'status' => 'active', 'plan_key' => 'elite', 'plan_label' => 'Elite', 'current_period_end' => '2027-01-15 08:00:00', 'cancel_at_period_end' => 1 ) );
ok( 'active' === $d['state'] && $d['active'] && 1800000000 === $d['period_end'] && $d['cancel_at_period_end'], 'active row: dates and cancel flag' );
ok( 'past_due' === $S::describe( array( 'status' => 'past_due' ) )['state'] && $S::describe( array( 'status' => 'past_due' ) )['active'], 'past_due keeps the plan while the card is retried' );
ok( ! $S::describe( array( 'status' => 'canceled' ) )['active'] && 'canceled' === $S::describe( array( 'status' => 'canceled' ) )['state'], 'canceled has no plan' );
ok( 'incomplete' === $S::describe( array( 'status' => 'incomplete' ) )['state'], 'incomplete is its own state' );
exit( empty( $bad ) ? 0 : 1 );
