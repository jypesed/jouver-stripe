<?php
/**
 * Settings → Jouver Stripe: is it set up, and what has Stripe told us.
 * Read-only; administrators only. Shows no secret.
 *
 * @package Jouver_Stripe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Jouver_Stripe_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
	}

	public static function menu() {
		add_options_page( 'Jouver Stripe', 'Jouver Stripe', 'manage_options', 'jouver-stripe', array( __CLASS__, 'page' ) );
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		$secret = defined( 'JOUVER_STRIPE_WEBHOOK_SECRET' ) && '' !== (string) JOUVER_STRIPE_WEBHOOK_SECRET;
		$url    = rest_url( 'jouver/v1/stripe/webhook' );
		$ev     = $wpdb->prefix . 'jouver_stripe_event';
		$sb     = $wpdb->prefix . 'jouver_subscription';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$events = (array) $wpdb->get_results( "SELECT event_id, event_type, outcome, received_at FROM {$ev} ORDER BY received_at DESC LIMIT 25" );
		$subs   = (array) $wpdb->get_results( "SELECT user_id, status, plan_key, plan_label, price_id, product_id, current_period_end FROM {$sb} ORDER BY updated_at DESC LIMIT 25" );
		// phpcs:enable
		echo '<div class="wrap"><h1>Jouver Stripe</h1>';
		echo '<table class="widefat striped" style="max-width:760px"><tbody>';
		echo '<tr><th>Webhook URL (add this in Stripe)</th><td><code>' . esc_html( $url ) . '</code></td></tr>';
		echo '<tr><th>Signing secret</th><td>' . ( $secret ? '<strong style="color:#15803d">configured</strong>' : '<strong style="color:#b91c1c">missing</strong>: add <code>define( \'JOUVER_STRIPE_WEBHOOK_SECRET\', \'whsec_…\' );</code> to wp-config.php' ) . '</td></tr>';
		echo '<tr><th>Events to send</th><td><code>checkout.session.completed</code>, <code>customer.subscription.created</code>, <code>customer.subscription.updated</code>, <code>customer.subscription.deleted</code>, <code>invoice.paid</code>, <code>invoice.payment_failed</code></td></tr>';
		echo '<tr><th>Plan names</th><td>Set the metadata <code>jouver_plan</code> (for example <code>elite</code>) on each Stripe price, or map ids with the <code>jouver_stripe_plan_map</code> filter. Plans are never guessed.</td></tr>';
		echo '</tbody></table>';

		echo '<h2>Dealers (latest 25)</h2><table class="widefat striped"><thead><tr><th>User</th><th>Status</th><th>Plan</th><th>Label</th><th>Price id</th><th>Product id</th><th>Period end</th></tr></thead><tbody>';
		foreach ( $subs as $r ) {
			$unmapped = '' === $r->plan_key ? ' style="background:#fffbeb"' : '';
			echo '<tr' . $unmapped . '><td>' . (int) $r->user_id . '</td><td>' . esc_html( $r->status ) . '</td><td>' . ( '' === $r->plan_key ? '<em>not mapped</em>' : esc_html( $r->plan_key ) ) . '</td><td>' . esc_html( $r->plan_label ) . '</td><td><code>' . esc_html( $r->price_id ) . '</code></td><td><code>' . esc_html( $r->product_id ) . '</code></td><td>' . esc_html( (string) $r->current_period_end ) . '</td></tr>';
		}
		if ( ! $subs ) {
			echo '<tr><td colspan="7">No dealer has paid through Stripe yet.</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>Recent events (latest 25)</h2><table class="widefat striped"><thead><tr><th>Received (UTC)</th><th>Type</th><th>Outcome</th><th>Event</th></tr></thead><tbody>';
		foreach ( $events as $r ) {
			echo '<tr><td>' . esc_html( $r->received_at ) . '</td><td>' . esc_html( $r->event_type ) . '</td><td>' . esc_html( $r->outcome ) . '</td><td><code>' . esc_html( $r->event_id ) . '</code></td></tr>';
		}
		if ( ! $events ) {
			echo '<tr><td colspan="4">No event received yet.</td></tr>';
		}
		echo '</tbody></table></div>';
	}
}
