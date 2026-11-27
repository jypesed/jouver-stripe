<?php
// Webhook end to end with an in-memory database: order, replay, unmatched customers. php tests/plan-flow.test.php
define( 'ABSPATH', '/' ); define( 'DAY_IN_SECONDS', 86400 ); define( 'ARRAY_A', 'ARRAY_A' ); define( 'JOUVER_STRIPE_WEBHOOK_SECRET', 'whsec_t' );
class WP_REST_Request { function __construct( $b, $sig ) { $this->b = $b; $this->sig = $sig; } function get_body() { return $this->b; } function get_header( $h ) { return $this->sig; } }
class WP_REST_Response { function __construct( $d, $s = 200 ) { $this->data = $d; $this->status = $s; } }
$TR = array(); $USERS = array( 42 => 'dealer@example.com' ); $FILTER = array();
function apply_filters( $t, $v ) { return $v; } function do_action() {} function add_action() {}
function get_userdata( $id ) { global $USERS; return isset( $USERS[ $id ] ) ? (object) array( 'ID' => $id ) : false; }
function get_user_by( $f, $v ) { global $USERS; $id = array_search( $v, $USERS, true ); return $id ? (object) array( 'ID' => $id ) : false; }
function get_transient( $k ) { global $TR; return $TR[ $k ] ?? false; } function set_transient( $k, $v ) { global $TR; $TR[ $k ] = $v; } function delete_transient( $k ) { global $TR; unset( $TR[ $k ] ); }
class WPDB {
	public $prefix = 'wp_'; public $sub = array(); public $ev = array();
	function prepare( $q, ...$a ) { foreach ( $a as $x ) { $q = preg_replace( '/%[sd]/', "'" . addslashes( $x ) . "'", $q, 1 ); } return $q; }
	function get_row( $q, $o = null ) { preg_match( "/user_id = '?(\d+)/", $q, $m ); return $this->sub[ (int) $m[1] ] ?? null; }
	function get_var( $q ) {
		if ( strpos( $q, 'jouver_stripe_event' ) ) { preg_match( "/event_id = '([^']+)'/", $q, $m ); return isset( $this->ev[ $m[1] ] ) ? $m[1] : null; }
		preg_match( "/stripe_customer_id = '([^']+)'/", $q, $m );
		foreach ( $this->sub as $u => $r ) { if ( ( $r['stripe_customer_id'] ?? '' ) === $m[1] ) { return $u; } }
		return null;
	}
	function insert( $t, $d ) { $this->sub[ $d['user_id'] ] = $d + array( 'last_event_at' => 0, 'stripe_customer_id' => '' ); }
	function update( $t, $d, $w ) { $this->sub[ $w['user_id'] ] = $d + $this->sub[ $w['user_id'] ]; }
	function query( $q ) { if ( preg_match( "/VALUES \('([^']+)', '([^']*)', '([^']*)'/", $q, $m ) ) { $this->ev[ $m[1] ] = $m[3]; } }
}
$GLOBALS['wpdb'] = new WPDB;
require __DIR__ . '/../includes/class-jouver-stripe-plan.php';
function ok( $c, $m ) { global $bad; echo ( $c ? 'ok' : 'FAIL' ) . ": $m\n"; if ( ! $c ) { $bad = 1; } }
function send( $event ) { $b = json_encode( $event ); $t = time(); $sig = 't=' . $t . ',v1=' . hash_hmac( 'sha256', $t . '.' . $b, 'whsec_t' ); return Jouver_Stripe_Plan::rest_webhook( new WP_REST_Request( $b, $sig ) ); }
$sub = function ( $id, $created, $status, $cus = 'cus_9' ) { return array( 'id' => $id, 'type' => 'customer.subscription.updated', 'created' => $created, 'data' => array( 'object' => array( 'id' => 'sub_7', 'customer' => $cus, 'status' => $status, 'current_period_end' => 1800000000, 'items' => array( 'data' => array( array( 'price' => array( 'id' => 'price_A', 'product' => 'prod_A', 'nickname' => 'Elite', 'currency' => 'usd' ) ) ) ) ) ) ); };
$co = array( 'id' => 'evt_co', 'type' => 'checkout.session.completed', 'created' => 100, 'data' => array( 'object' => array( 'client_reference_id' => '42', 'customer' => 'cus_9', 'subscription' => 'sub_7', 'payment_status' => 'paid' ) ) );

$r = Jouver_Stripe_Plan::rest_webhook( new WP_REST_Request( '{}', 'bad' ) );
ok( 400 === $r->status, 'unsigned request is refused and stores nothing' );

// Subscription event arrives BEFORE checkout.session.completed
$r = send( $sub( 'evt_1', 90, 'active' ) );
ok( 'unmatched' === $r->data['outcome'] && empty( $GLOBALS['wpdb']->sub ), 'early subscription event: no dealer yet, nothing stored, kept pending' );
$r = send( $co );
$row = $GLOBALS['wpdb']->sub[42];
ok( 'matched_by_reference' === $r->data['outcome'] && 'cus_9' === $row['stripe_customer_id'], 'checkout links the dealer to the Stripe customer' );
ok( 'Elite' === ( $row['plan_label'] ?? '' ) && 'active' === $row['status'], 'the pending subscription event is replayed: plan label arrives' );
$d = Jouver_Stripe_Plan::for_user( 42 );
ok( 'active' === $d['state'] && 'Elite' === $d['plan_label'], 'the dealer now has an active Elite plan' );

// Replay of the same event
$r = send( $co );
ok( ! empty( $r->data['duplicate'] ), 'the same event id twice is applied once' );

// Later events: past due, then an old one arriving late
send( $sub( 'evt_2', 300, 'past_due' ) );
ok( 'past_due' === $GLOBALS['wpdb']->sub[42]['status'], 'a newer event changes the status' );
$r = send( $sub( 'evt_3', 200, 'active' ) );
ok( 'stale' === $r->data['outcome'] && 'past_due' === $GLOBALS['wpdb']->sub[42]['status'], 'an older event arriving late does not undo the newer one' );
send( array( 'id' => 'evt_4', 'type' => 'customer.subscription.deleted', 'created' => 400, 'data' => array( 'object' => $sub( 'x', 1, 'active' )['data']['object'] ) ) );
ok( 'canceled' === Jouver_Stripe_Plan::for_user( 42 )['state'], 'cancellation ends the plan' );

// A website customer who is not a dealer
$r = send( $sub( 'evt_5', 500, 'active', 'cus_web' ) );
ok( 'unmatched' === $r->data['outcome'] && 200 === $r->status, 'a customer we do not know is answered 200 (Stripe does not retry for days)' );
exit( empty( $bad ) ? 0 : 1 );
