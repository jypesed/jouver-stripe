<?php
/**
 * Jouver_Stripe_Plan — the dealer's plan, kept here and fed by Stripe.
 *
 *   Stripe ──signed webhook──► POST /wp-json/jouver/v1/stripe/webhook ──► jouver_subscription
 *
 * JAPP and Jouver Core only READ the plan: jouver_stripe_plan( $user_id ) in PHP,
 * GET /wp-json/jouver/v1/plan in JavaScript. The CRM (Zoho) keeps receiving
 * payments from Stripe on its own and is not involved here.
 *
 * Setup (never commit these; put them in wp-config.php):
 *   define( 'JOUVER_STRIPE_WEBHOOK_SECRET', 'whsec_…' );   // from the Stripe webhook endpoint
 * and in the Stripe dashboard add an endpoint for the URL shown under
 * Settings → Jouver Stripe, with the events
 *   checkout.session.completed
 *   customer.subscription.created / .updated / .deleted
 *   invoice.paid, invoice.payment_failed
 *
 * No Stripe secret API key is needed: everything used is in the event itself.
 * Without the signing secret the endpoint answers 503 and stores nothing.
 *
 * Which dealer? The pricing table sends the WordPress user id as
 * client_reference_id; checkout.session.completed links that user to the
 * Stripe customer, and later subscription events find the user by customer.
 * Only if both fail is the e-mail used (one exact match). Dealers who paid
 * before this plugin existed are linked by e-mail from invoice.paid at their
 * next renewal; subscription events alone carry no e-mail.
 *
 * Which plan? Never guessed. In order: the price's metadata key `jouver_plan`
 * (set it on each Stripe price), then the `jouver_stripe_plan_map` filter
 * (price or product id => plan key). With neither, the plan key stays empty,
 * the label shows the price nickname, and the row keeps price_id/product_id
 * so the map can be filled in from real data.
 *
 * @package Jouver_Stripe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Jouver_Stripe_Plan {

	/** Stripe's own replay window for a signed event. */
	const TOLERANCE = 300;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			'jouver/v1',
			'/stripe/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_webhook' ),
				'permission_callback' => '__return_true', // authenticated by Stripe's signature
			)
		);
		register_rest_route(
			'jouver/v1',
			'/plan',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_status' ),
				'permission_callback' => function () {
					return is_user_logged_in();
				},
			)
		);
	}

	/* ---------------------------------------------------------------- */
	/* Pure logic (unit-tested)                                           */
	/* ---------------------------------------------------------------- */

	/**
	 * Stripe-Signature: "t=1700000000,v1=<hex>[,v1=<hex>]". The signed text is
	 * "<t>.<raw body>", HMAC-SHA256 with the endpoint's signing secret.
	 */
	public static function verify_signature( $payload, $header, $secret, $now = null, $tolerance = self::TOLERANCE ) {
		$secret = (string) $secret;
		if ( '' === $secret || '' === (string) $header ) {
			return false;
		}
		$t   = 0;
		$sig = array();
		foreach ( explode( ',', (string) $header ) as $part ) {
			$kv = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $kv ) ) {
				continue;
			}
			if ( 't' === $kv[0] ) {
				$t = (int) $kv[1];
			} elseif ( 'v1' === $kv[0] ) {
				$sig[] = $kv[1];
			}
		}
		if ( $t <= 0 || ! $sig ) {
			return false;
		}
		$now = null === $now ? time() : (int) $now;
		if ( abs( $now - $t ) > $tolerance ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $t . '.' . $payload, $secret );
		foreach ( $sig as $s ) {
			if ( hash_equals( $expected, (string) $s ) ) {
				return true;
			}
		}
		return false;
	}

	/** Plan key for a price: price metadata, then the filterable map. '' when unknown. */
	public static function plan_from( $price_id, $product_id, $metadata = array() ) {
		if ( is_array( $metadata ) && ! empty( $metadata['jouver_plan'] ) ) {
			return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $metadata['jouver_plan'] ) );
		}
		$map = apply_filters( 'jouver_stripe_plan_map', array() );
		$map = ( is_array( $map ) ? $map : array() ) + self::stored_map();
		foreach ( array( (string) $price_id, (string) $product_id ) as $id ) {
			if ( '' !== $id && ! empty( $map[ $id ] ) ) {
				return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $map[ $id ] ) );
			}
		}
		return '';
	}

	/** The map kept under Settings → Jouver Stripe: price or product id => plan key. */
	public static function stored_map() {
		$m = function_exists( 'get_option' ) ? get_option( 'jouver_stripe_plan_map', array() ) : array();
		return is_array( $m ) ? $m : array();
	}

	/** A readable name when Stripe's price has no nickname. */
	public static function label_for_key( $key ) {
		$names = array( 'free' => 'Freemium', 'elite' => 'Elite', 'formula1' => 'Formula 1' );
		return isset( $names[ $key ] ) ? $names[ $key ] : '';
	}

	private static function label_of( array $price, $price_id, $product_id ) {
		$l = (string) ( $price['nickname'] ?? ( $price['lookup_key'] ?? '' ) );
		return '' !== $l ? $l : self::label_for_key( self::plan_from( $price_id, $product_id, isset( $price['metadata'] ) && is_array( $price['metadata'] ) ? $price['metadata'] : array() ) );
	}

	/**
	 * Recompute plan_key (and an empty label) for every row from its stored
	 * price/product id. Run after the map changes. Returns rows changed.
	 */
	public static function reapply_map() {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows    = (array) $wpdb->get_results( "SELECT user_id, price_id, product_id, plan_key, plan_label FROM {$t}", ARRAY_A );
		$changed = 0;
		foreach ( $rows as $r ) {
			$key = self::plan_from( $r['price_id'], $r['product_id'] );
			if ( '' === $key || $key === $r['plan_key'] ) {
				continue;
			}
			$f = array( 'plan_key' => $key );
			if ( '' === (string) $r['plan_label'] ) {
				$f['plan_label'] = self::label_for_key( $key );
			}
			$wpdb->update( $t, $f, array( 'user_id' => (int) $r['user_id'] ) );
			$changed++;
		}
		return $changed;
	}

	/**
	 * One row from the Stripe Subscriptions CSV (Settings → Jouver Stripe →
	 * Import). Never overwrites what the webhook has written: a row with a
	 * real last_event_at is kept as it is.
	 *
	 * @return string imported|updated|kept
	 */
	public static function import_row( $user_id, array $fields ) {
		global $wpdb;
		$t   = self::table();
		$cur = self::row_for_user( $user_id );
		if ( $cur && (int) $cur['last_event_at'] > 0 ) {
			return 'kept';
		}
		$fields = array_filter( $fields, function ( $v ) { return null !== $v && '' !== $v; } );
		$fields['last_event_at'] = 0;
		$fields['updated_at']    = gmdate( 'Y-m-d H:i:s' );
		if ( $cur ) {
			$wpdb->update( $t, $fields, array( 'user_id' => (int) $user_id ) );
			return 'updated';
		}
		$wpdb->insert( $t, array_merge( array( 'user_id' => (int) $user_id, 'status' => 'incomplete' ), $fields ) );
		return 'imported';
	}

	private static function id_of( $v ) {
		if ( is_array( $v ) ) {
			return isset( $v['id'] ) ? (string) $v['id'] : '';
		}
		return is_scalar( $v ) ? (string) $v : '';
	}

	/**
	 * One Stripe event → what to remember, or null when JAPP does not care.
	 *
	 * @return array|null {type, created, customer, subscription, user_id, email, fields}
	 */
	public static function normalise( array $event ) {
		$type = isset( $event['type'] ) ? (string) $event['type'] : '';
		$obj  = isset( $event['data']['object'] ) && is_array( $event['data']['object'] ) ? $event['data']['object'] : array();
		$out  = array(
			'type'         => $type,
			'created'      => isset( $event['created'] ) ? (int) $event['created'] : 0,
			'customer'     => self::id_of( $obj['customer'] ?? '' ),
			'subscription' => '',
			'user_id'      => 0,
			'email'        => '',
			'fields'       => array(),
		);

		if ( 'checkout.session.completed' === $type ) {
			$ref = isset( $obj['client_reference_id'] ) ? (string) $obj['client_reference_id'] : '';
			$out['user_id']      = ctype_digit( $ref ) ? (int) $ref : 0;
			$out['subscription'] = self::id_of( $obj['subscription'] ?? '' );
			$email               = $obj['customer_details']['email'] ?? ( $obj['customer_email'] ?? '' );
			$out['email']        = is_string( $email ) ? strtolower( trim( $email ) ) : '';
			$paid                = in_array( (string) ( $obj['payment_status'] ?? '' ), array( 'paid', 'no_payment_required' ), true );
			$out['fields']       = array( 'stripe_customer_id' => $out['customer'], 'stripe_subscription_id' => $out['subscription'] );
			// Only a subscription checkout touches the plan. A one-off payment (ads
			// credit, mode=payment) just links the Stripe customer to the dealer.
			if ( $paid && '' !== $out['subscription'] ) {
				$out['fields']['status'] = 'active';
			}
			return $out;
		}

		if ( in_array( $type, array( 'customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted' ), true ) ) {
			$out['subscription'] = self::id_of( $obj['id'] ?? '' );
			$item  = isset( $obj['items']['data'][0] ) && is_array( $obj['items']['data'][0] ) ? $obj['items']['data'][0] : array();
			$price = isset( $item['price'] ) && is_array( $item['price'] ) ? $item['price'] : array();
			$price_id   = self::id_of( $price['id'] ?? '' );
			$product_id = self::id_of( $price['product'] ?? '' );
			// Newer Stripe API versions keep the period on the item, older ones on the subscription.
			$end = $obj['current_period_end'] ?? ( $item['current_period_end'] ?? 0 );
			$out['fields'] = array(
				'status'                 => 'customer.subscription.deleted' === $type ? 'canceled' : (string) ( $obj['status'] ?? '' ),
				'stripe_customer_id'     => $out['customer'],
				'stripe_subscription_id' => $out['subscription'],
				'price_id'               => $price_id,
				'product_id'             => $product_id,
				'plan_key'               => self::plan_from( $price_id, $product_id, isset( $price['metadata'] ) && is_array( $price['metadata'] ) ? $price['metadata'] : array() ),
				'plan_label'             => self::label_of( $price, $price_id, $product_id ),
				'currency'               => strtoupper( substr( (string) ( $price['currency'] ?? '' ), 0, 3 ) ),
				'current_period_end'     => (int) $end > 0 ? gmdate( 'Y-m-d H:i:s', (int) $end ) : null,
				'cancel_at_period_end'   => empty( $obj['cancel_at_period_end'] ) ? 0 : 1,
			);
			return $out;
		}

		// Invoices carry the customer's e-mail, which subscription events do not.
		// invoice.paid fires at every renewal, so a dealer who subscribed before
		// this plugin existed is linked, by e-mail, at the next monthly payment.
		if ( in_array( $type, array( 'invoice.paid', 'invoice.payment_succeeded', 'invoice.payment_failed' ), true ) ) {
			$out['subscription'] = self::id_of( $obj['subscription'] ?? ( $obj['parent']['subscription_details']['subscription'] ?? '' ) );
			$email               = $obj['customer_email'] ?? '';
			$out['email']        = is_string( $email ) ? strtolower( trim( $email ) ) : '';
			if ( 'invoice.payment_failed' === $type ) {
				$out['fields'] = array( 'status' => 'past_due', 'stripe_customer_id' => $out['customer'], 'stripe_subscription_id' => $out['subscription'] );
				return $out;
			}
			if ( '' === $out['subscription'] ) {
				return null; // A one-off invoice is not a plan.
			}
			$line  = isset( $obj['lines']['data'][0] ) && is_array( $obj['lines']['data'][0] ) ? $obj['lines']['data'][0] : array();
			$price = isset( $line['price'] ) && is_array( $line['price'] ) ? $line['price'] : ( $line['pricing']['price_details'] ?? array() );
			$price = is_array( $price ) ? $price : array();
			$price_id   = self::id_of( $price['id'] ?? ( $price['price'] ?? '' ) );
			$product_id = self::id_of( $price['product'] ?? '' );
			$out['fields'] = array(
				'status'                 => 'active',
				'stripe_customer_id'     => $out['customer'],
				'stripe_subscription_id' => $out['subscription'],
				'price_id'               => $price_id,
				'product_id'             => $product_id,
				'plan_key'               => self::plan_from( $price_id, $product_id, isset( $price['metadata'] ) && is_array( $price['metadata'] ) ? $price['metadata'] : array() ),
				'plan_label'             => self::label_of( $price, $price_id, $product_id ),
				'currency'               => strtoupper( substr( (string) ( $obj['currency'] ?? '' ), 0, 3 ) ),
				'current_period_end'     => isset( $line['period']['end'] ) && (int) $line['period']['end'] > 0 ? gmdate( 'Y-m-d H:i:s', (int) $line['period']['end'] ) : null,
			);
			return $out;
		}

		return null;
	}

	/**
	 * What the screens need, from a stored row (or null for "never paid").
	 *
	 * state: free | active | past_due | canceled | incomplete
	 */
	public static function describe( $row ) {
		$row = is_object( $row ) ? (array) $row : $row;
		if ( ! is_array( $row ) || empty( $row['status'] ) ) {
			return array( 'state' => 'free', 'active' => false, 'plan_key' => '', 'plan_label' => '', 'period_end' => 0, 'cancel_at_period_end' => false );
		}
		$status = (string) $row['status'];
		if ( in_array( $status, array( 'active', 'trialing' ), true ) ) {
			$state = 'active';
		} elseif ( in_array( $status, array( 'past_due', 'unpaid' ), true ) ) {
			$state = 'past_due';
		} elseif ( in_array( $status, array( 'canceled', 'incomplete_expired' ), true ) ) {
			$state = 'canceled';
		} else {
			$state = 'incomplete';
		}
		$end = ! empty( $row['current_period_end'] ) ? (int) strtotime( $row['current_period_end'] . ' UTC' ) : 0;
		return array(
			'state'                => $state,
			// past_due keeps the plan while Stripe retries the card.
			'active'               => in_array( $state, array( 'active', 'past_due' ), true ),
			'plan_key'             => (string) ( $row['plan_key'] ?? '' ),
			'plan_label'           => (string) ( $row['plan_label'] ?? '' ),
			'period_end'           => $end,
			'cancel_at_period_end' => ! empty( $row['cancel_at_period_end'] ),
		);
	}

	/* ---------------------------------------------------------------- */
	/* Storage                                                            */
	/* ---------------------------------------------------------------- */

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'jouver_subscription';
	}

	private static function events_table() {
		global $wpdb;
		return $wpdb->prefix . 'jouver_stripe_event';
	}

	public static function row_for_user( $user_id ) {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE user_id = %d LIMIT 1", (int) $user_id ), ARRAY_A );
	}

	/** The dealer's plan for the screens. */
	public static function for_user( $user_id ) {
		return self::describe( self::row_for_user( $user_id ) );
	}

	private static function user_for_customer( $customer ) {
		global $wpdb;
		if ( '' === (string) $customer ) {
			return 0;
		}
		$t = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$t} WHERE stripe_customer_id = %s LIMIT 1", $customer ) );
	}

	private static function save( $user_id, array $fields, $event_created ) {
		global $wpdb;
		$t   = self::table();
		$now = gmdate( 'Y-m-d H:i:s' );
		$cur = self::row_for_user( $user_id );
		$fields = array_filter( $fields, function ( $v ) { return null !== $v && '' !== $v; } );
		// An older subscription event must not undo a newer one.
		if ( $cur && (int) $cur['last_event_at'] > (int) $event_created && (int) $event_created > 0 ) {
			return 'stale';
		}
		$fields['last_event_at'] = max( (int) $event_created, $cur ? (int) $cur['last_event_at'] : 0 );
		$fields['updated_at']    = $now;
		if ( $cur ) {
			$wpdb->update( $t, $fields, array( 'user_id' => (int) $user_id ) );
		} else {
			$wpdb->insert( $t, array_merge( array( 'user_id' => (int) $user_id, 'status' => 'incomplete' ), $fields ) );
		}
		return 'saved';
	}

	/**
	 * Apply one normalised event. Returns an outcome word for the event log.
	 */
	public static function apply( array $n ) {
		$user_id = 0;
		$how     = '';
		if ( $n['user_id'] > 0 && get_userdata( $n['user_id'] ) ) {
			$user_id = $n['user_id'];
			$how     = 'reference';
		}
		if ( ! $user_id ) {
			$user_id = self::user_for_customer( $n['customer'] );
			$how     = $user_id ? 'customer' : '';
		}
		if ( ! $user_id && '' !== $n['email'] ) {
			$u = get_user_by( 'email', $n['email'] );
			if ( $u ) {
				$user_id = (int) $u->ID;
				$how     = 'email';
			}
		}
		if ( ! $user_id ) {
			// Subscription events can beat checkout.session.completed. Keep them
			// for two days so the checkout can replay them; Stripe also sends
			// website customers' events here, which simply expire.
			if ( '' !== $n['customer'] && 0 === strpos( $n['type'], 'customer.subscription' ) ) {
				set_transient( 'jouver_stripe_pending_' . md5( $n['customer'] ), $n, 2 * DAY_IN_SECONDS );
			}
			return 'unmatched';
		}
		$result = self::save( $user_id, $n['fields'], $n['created'] );
		if ( 'stale' === $result ) {
			return 'stale';
		}
		if ( in_array( $n['type'], array( 'checkout.session.completed', 'invoice.paid', 'invoice.payment_succeeded' ), true ) && '' !== $n['customer'] ) {
			$key     = 'jouver_stripe_pending_' . md5( $n['customer'] );
			$pending = get_transient( $key );
			if ( is_array( $pending ) ) {
				delete_transient( $key );
				// 0 = no staleness check: this event is older than the checkout that
				// just created the row, but it is the only source of the plan.
				self::save( $user_id, $pending['fields'], 0 );
			}
		}
		do_action( 'jouver_stripe_plan_changed', $user_id, self::for_user( $user_id ) );
		return 'matched_by_' . $how;
	}

	private static function secret() {
		$s = defined( 'JOUVER_STRIPE_WEBHOOK_SECRET' ) ? (string) JOUVER_STRIPE_WEBHOOK_SECRET : '';
		return (string) apply_filters( 'jouver_stripe_webhook_secret', $s );
	}

	/* ---------------------------------------------------------------- */
	/* REST                                                               */
	/* ---------------------------------------------------------------- */

	public static function rest_webhook( WP_REST_Request $req ) {
		global $wpdb;
		$secret = self::secret();
		if ( '' === $secret ) {
			return new WP_REST_Response( array( 'error' => 'not_configured' ), 503 );
		}
		$body = (string) $req->get_body();
		if ( ! self::verify_signature( $body, (string) $req->get_header( 'stripe-signature' ), $secret ) ) {
			return new WP_REST_Response( array( 'error' => 'bad_signature' ), 400 );
		}
		$event = json_decode( $body, true );
		if ( ! is_array( $event ) || empty( $event['id'] ) ) {
			return new WP_REST_Response( array( 'error' => 'bad_payload' ), 400 );
		}
		$id = substr( (string) $event['id'], 0, 80 );
		$et = self::events_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$et} WHERE event_id = %s LIMIT 1", $id ) ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'duplicate' => true ), 200 );
		}
		$n       = self::normalise( $event );
		$outcome = null === $n ? 'ignored' : self::apply( $n );
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"INSERT IGNORE INTO {$et} (event_id, event_type, outcome, received_at) VALUES (%s, %s, %s, %s)",
			$id,
			substr( (string) ( $event['type'] ?? '' ), 0, 60 ),
			$outcome,
			gmdate( 'Y-m-d H:i:s' )
		) );
		return new WP_REST_Response( array( 'ok' => true, 'outcome' => $outcome ), 200 );
	}

	public static function rest_status() {
		$s = self::for_user( get_current_user_id() );
		return new WP_REST_Response(
			array(
				'state'      => $s['state'],
				'active'     => $s['active'],
				'plan_label' => $s['plan_label'],
				'plan_key'   => $s['plan_key'],
				'period_end' => $s['period_end'],
				'cancel_at_period_end' => $s['cancel_at_period_end'],
			),
			200
		);
	}
}
