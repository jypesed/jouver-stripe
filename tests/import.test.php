<?php
// CSV import of dealers who paid before the plugin existed. php tests/import.test.php
define( 'ABSPATH', '/' ); define( 'ARRAY_A', 'ARRAY_A' );
$OPT = array(); $USERS = array( 42 => 'dealer@example.com', 77 => 'bert@example.com', 90 => 'other@example.com' );
function apply_filters( $t, $v ) { return $v; } function add_action() {}
function get_option( $k, $d = array() ) { global $OPT; return $OPT[ $k ] ?? $d; }
function get_userdata( $id ) { global $USERS; return isset( $USERS[ $id ] ) ? (object) array( 'ID' => $id ) : false; }
function get_user_by( $f, $v ) { global $USERS; $id = array_search( $v, $USERS, true ); return $id ? (object) array( 'ID' => $id ) : false; }
class WPDB {
	public $prefix = 'wp_'; public $sub = array();
	function prepare( $q, ...$a ) { foreach ( $a as $x ) { $q = preg_replace( '/%[sd]/', "'" . addslashes( $x ) . "'", $q, 1 ); } return $q; }
	function get_row( $q, $o = null ) { preg_match( "/user_id = '?(\d+)/", $q, $m ); return $this->sub[ (int) $m[1] ] ?? null; }
	function get_results( $q, $o = null ) { return array_values( $this->sub ); }
	function insert( $t, $d ) { $this->sub[ $d['user_id'] ] = $d; }
	function update( $t, $d, $w ) { $this->sub[ $w['user_id'] ] = $d + $this->sub[ $w['user_id'] ]; }
}
$GLOBALS['wpdb'] = new WPDB;
require __DIR__ . '/../includes/class-jouver-stripe-plan.php';
require __DIR__ . '/../includes/class-jouver-stripe-import.php';
function ok( $c, $m ) { global $bad; echo ( $c ? 'ok' : 'FAIL' ) . ": $m\n"; if ( ! $c ) { $bad = 1; } }

// Shaped like Stripe's Subscriptions export (header names with spaces and "(UTC)"), plus a hand-added user_id column.
$csv = "\xEF\xBB\xBFid,Customer ID,Customer Email,Customer Name,Plan,Product,Quantity,Interval,Amount,Currency,Status,Created (UTC),Current Period Start (UTC),Current Period End (UTC),Cancel At Period End,user_id\n"
	. "sub_bert,cus_bert,Bert@Example.com,\"Fairy Hill, Ltd\",price_ELITE_Y,prod_ELITE,1,year,490.00,gbp,active,2026-03-14 10:22,2026-03-14 10:22,2027-03-14 10:22,false,\n"
	. "sub_old,cus_old,dealer@example.com,Dealer,price_F1_M,prod_F1,1,month,99.00,usd,canceled,2025-01-01 00:00,2025-06-01 00:00,2025-07-01 00:00,false,\n"
	. "sub_new,cus_new,dealer@example.com,Dealer,price_F1_M,prod_F1,1,month,99.00,usd,active,2026-11-01 00:00,2026-11-01 00:00,2026-12-01 00:00,true,\n"
	. "sub_web,cus_web,someone@website.com,Web buyer,price_ELITE_M,prod_ELITE,1,month,49.00,eur,active,2026-11-10 00:00,2026-11-10 00:00,2026-12-10 00:00,false,\n"
	. "sub_mis,cus_mis,stripe-mail@gmail.com,Other Dealer,price_ELITE_M,prod_ELITE,1,month,49.00,eur,past_due,2026-11-10 00:00,2026-11-10 00:00,2026-12-10 00:00,false,90\n";

$rows = Jouver_Stripe_Import::parse_csv( $csv );
ok( 5 === count( $rows ) && 'cus_bert' === $rows[0]['customerid'] && '2027-03-14 10:22' === $rows[0]['currentperiodend'], 'CSV parsed: BOM stripped, headers normalised, quoted comma kept' );

// Without a plan map
$n = Jouver_Stripe_Import::normalise( $rows[0] );
ok( 'bert@example.com' === $n['email'] && 'active' === $n['fields']['status'] && 'cus_bert' === $n['fields']['stripe_customer_id'] && 'sub_bert' === $n['fields']['stripe_subscription_id'], 'row: e-mail lowered, ids kept' );
ok( '2027-03-14 10:22:00' === $n['fields']['current_period_end'] && 'GBP' === $n['fields']['currency'] && 0 === $n['fields']['cancel_at_period_end'], 'row: annual period end read as UTC, currency, cancel flag' );
ok( '' === $n['fields']['plan_key'] && '' === $n['fields']['plan_label'], 'row: plan never guessed without a map' );

// Preview writes nothing
$lines = Jouver_Stripe_Import::run( $rows, true );
ok( empty( $GLOBALS['wpdb']->sub ), 'preview: nothing written' );
ok( 'would import' === $lines[0]['result'] && 77 === $lines[0]['user_id'], 'preview: Bert matched by e-mail' );
ok( 0 === strpos( $lines[1]['result'], 'skipped (canceled)' ), 'preview: a canceled subscription is skipped' );
ok( 0 === strpos( $lines[3]['result'], 'unmatched' ), 'preview: a website customer with no dealer account is unmatched' );
ok( 90 === $lines[4]['user_id'] && 'would import' === $lines[4]['result'], 'preview: a hand-added user_id column wins over a different e-mail' );

// A user id typed on the preview screen
$lines = Jouver_Stripe_Import::run( $rows, true, array( 3 => 90 ) );
ok( 90 === $lines[3]['user_id'] && 'would import' === $lines[3]['result'], 'preview: a typed user id matches an unmatched row' );
ok( 0 === strpos( Jouver_Stripe_Import::run( $rows, true, array( 3 => 12345 ) )[3]['result'], 'unmatched: no WordPress user' ), 'preview: a typed id that is no user is refused' );
ok( 'prod_ELITE' === $lines[0]['product_id'] && 'price_ELITE_Y' === $lines[0]['price_id'], 'preview: raw price and product ids shown' );

// With the stored plan map (what Settings → Jouver Stripe saves)
$OPT['jouver_stripe_plan_map'] = array( 'prod_ELITE' => 'elite', 'price_F1_M' => 'formula1' );
$lines = Jouver_Stripe_Import::run( $rows, false );
$b = $GLOBALS['wpdb']->sub[77];
ok( 'imported' === $lines[0]['result'] && 'active' === $b['status'] && 'elite' === $b['plan_key'] && 'Elite' === $b['plan_label'] && 0 === $b['last_event_at'], 'import: Bert gets an active Elite plan, flagged as imported (last_event_at 0)' );
$d = Jouver_Stripe_Plan::for_user( 77 );
ok( 'active' === $d['state'] && 'Elite' === $d['plan_label'] && strtotime( '2027-03-14 10:22:00 UTC' ) === $d['period_end'], 'Bert now shows Elite until March 2027' );
ok( 'formula1' === $GLOBALS['wpdb']->sub[42]['plan_key'] && 1 === $GLOBALS['wpdb']->sub[42]['cancel_at_period_end'], 'the dealer\'s active row (not the canceled one) is imported, cancel flag kept' );
ok( 'past_due' === $GLOBALS['wpdb']->sub[90]['status'], 'past_due dealer imported via user_id column' );
ok( ! isset( $GLOBALS['wpdb']->sub[0] ) && 3 === count( $GLOBALS['wpdb']->sub ), 'nothing stored for unmatched or skipped rows' );

// Re-import never overwrites what the webhook wrote
$GLOBALS['wpdb']->sub[77]['last_event_at'] = 1800000000; $GLOBALS['wpdb']->sub[77]['status'] = 'canceled';
$lines = Jouver_Stripe_Import::run( $rows, false );
ok( 'kept' === $lines[0]['result'] && 'canceled' === $GLOBALS['wpdb']->sub[77]['status'], 're-import: a row written by the webhook is kept' );
ok( 'updated' === $lines[2]['result'], 're-import: an imported-only row is refreshed' );

// Re-applying a changed map fixes stored rows
$GLOBALS['wpdb']->sub[77]['plan_key'] = ''; $GLOBALS['wpdb']->sub[77]['plan_label'] = '';
ok( 1 === Jouver_Stripe_Plan::reapply_map() && 'elite' === $GLOBALS['wpdb']->sub[77]['plan_key'] && 'Elite' === $GLOBALS['wpdb']->sub[77]['plan_label'], 'reapply_map: unmapped rows get the plan from the map' );
exit( empty( $bad ) ? 0 : 1 );
