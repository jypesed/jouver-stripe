<?php
/**
 * Settings → Jouver Stripe: is it set up, what has Stripe told us, the plan
 * map (price/product id => plan key) and the one-time CSV import of dealers
 * who paid before this plugin existed. Administrators only. Shows no secret.
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
		$notice = self::handle_post();
		$secret = defined( 'JOUVER_STRIPE_WEBHOOK_SECRET' ) && '' !== (string) JOUVER_STRIPE_WEBHOOK_SECRET;
		$url    = rest_url( 'jouver/v1/stripe/webhook' );
		$ev     = $wpdb->prefix . 'jouver_stripe_event';
		$sb     = $wpdb->prefix . 'jouver_subscription';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$events = (array) $wpdb->get_results( "SELECT event_id, event_type, outcome, received_at FROM {$ev} ORDER BY received_at DESC LIMIT 25" );
		$subs   = (array) $wpdb->get_results( "SELECT user_id, status, plan_key, plan_label, price_id, product_id, current_period_end FROM {$sb} ORDER BY updated_at DESC LIMIT 25" );
		// phpcs:enable
		echo '<div class="wrap"><h1>Jouver Stripe</h1>';
		if ( $notice ) {
			echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts below
		}
		echo '<table class="widefat striped" style="max-width:760px"><tbody>';
		echo '<tr><th>Webhook URL (add this in Stripe)</th><td><code>' . esc_html( $url ) . '</code></td></tr>';
		echo '<tr><th>Signing secret</th><td>' . ( $secret ? '<strong style="color:#15803d">configured</strong>' : '<strong style="color:#b91c1c">missing</strong>: add <code>define( \'JOUVER_STRIPE_WEBHOOK_SECRET\', \'whsec_…\' );</code> to wp-config.php' ) . '</td></tr>';
		echo '<tr><th>Events to send</th><td><code>checkout.session.completed</code>, <code>customer.subscription.created</code>, <code>customer.subscription.updated</code>, <code>customer.subscription.deleted</code>, <code>invoice.paid</code>, <code>invoice.payment_failed</code></td></tr>';
		echo '<tr><th>Plan names</th><td>Set the metadata <code>jouver_plan</code> (for example <code>elite</code>) on each Stripe price, or map ids in the <strong>Plan map</strong> below. Plans are never guessed.</td></tr>';
		echo '</tbody></table>';

		self::forms();

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

	/** Plan map and CSV import forms. */
	private static function forms() {
		$map   = Jouver_Stripe_Plan::stored_map();
		$lines = array();
		foreach ( $map as $id => $key ) {
			$lines[] = $id . ' = ' . $key;
		}
		echo '<h2>Plan map</h2>';
		echo '<form method="post" style="max-width:760px">';
		wp_nonce_field( 'jouver_stripe_admin', 'jouver_stripe_nonce' );
		echo '<p>One line per Stripe price or product id, for example <code>price_1Abc… = elite</code> or <code>prod_Xyz… = formula1</code>. Plan keys: <code>elite</code>, <code>formula1</code>. Saving re-applies the map to every dealer already stored.</p>';
		echo '<textarea name="jouver_stripe_map" rows="6" style="width:100%;font-family:monospace">' . esc_textarea( implode( "\n", $lines ) ) . '</textarea>';
		echo '<p><button class="button button-primary" name="jouver_stripe_action" value="map">Save plan map</button></p></form>';

		echo '<h2>Import existing dealers</h2>';
		echo '<form method="post" enctype="multipart/form-data" style="max-width:760px">';
		wp_nonce_field( 'jouver_stripe_admin', 'jouver_stripe_nonce' );
		echo '<p>For dealers who paid before this plugin was installed. In Stripe open <strong>Subscriptions → Export</strong> (all columns, CSV) and upload the file here. Each row is matched to a dealer by exact e-mail. For a dealer whose Stripe e-mail differs, add a <code>user_id</code> column to the CSV with the WordPress user id. Nothing the webhook has already written is changed. Fill the plan map first so the imported dealers get a plan name.</p>';
		echo '<p><input type="file" name="jouver_stripe_csv" accept=".csv,text/csv" required></p>';
		echo '<p><button class="button button-primary" name="jouver_stripe_action" value="import">Upload and preview</button> Nothing is written until you confirm on the preview.</p></form>';
	}

	/** The preview table with a user id box per row and the Import button. */
	private static function preview( array $lines, $token, $written ) {
		$html  = '<div class="notice notice-' . ( $written ? 'success' : 'info' ) . '" style="padding-bottom:12px"><p><strong>' . ( $written ? 'Imported.' : 'Preview, nothing written yet.' ) . '</strong> ' . count( $lines ) . ' row(s). Rows in yellow need attention: type the WordPress user id for an unmatched dealer, fill the Plan map for "not mapped".</p>';
		$html .= '<form method="post">' . wp_nonce_field( 'jouver_stripe_admin', 'jouver_stripe_nonce', true, false ) . '<input type="hidden" name="jouver_stripe_token" value="' . esc_attr( $token ) . '">';
		$html .= '<table class="widefat striped" style="margin:8px 0"><thead><tr><th>E-mail</th><th>User id</th><th>Status</th><th>Plan</th><th>Price id</th><th>Product id</th><th>Period end</th><th>Result</th></tr></thead><tbody>';
		foreach ( $lines as $i => $l ) {
			$skipped = 0 === strpos( $l['result'], 'skipped' );
			$bad     = ! $skipped && ( 0 === strpos( $l['result'], 'unmatched' ) || '' === $l['plan_key'] );
			$uid     = $skipped ? '' : '<input type="number" name="jouver_stripe_uid[' . (int) $i . ']" value="' . ( $l['user_id'] ? (int) $l['user_id'] : '' ) . '" style="width:7em" placeholder="user id">';
			$html   .= '<tr' . ( $bad ? ' style="background:#fffbeb"' : '' ) . '><td>' . esc_html( $l['email'] ) . '</td><td>' . $uid . '</td><td>' . esc_html( $l['status'] ) . '</td><td>' . ( '' === $l['plan_key'] ? '<em>not mapped</em>' : esc_html( $l['plan_key'] ) ) . '</td><td><code>' . esc_html( $l['price_id'] ) . '</code></td><td><code>' . esc_html( $l['product_id'] ) . '</code></td><td>' . esc_html( $l['period_end'] ) . '</td><td>' . esc_html( $l['result'] ) . '</td></tr>';
		}
		$html .= '</tbody></table>';
		if ( ! $written ) {
			$html .= '<p><button class="button" name="jouver_stripe_action" value="preview">Refresh preview</button> <button class="button button-primary" name="jouver_stripe_action" value="write">Import these dealers</button> Unmatched and skipped rows are left out.</p>';
		}
		return $html . '</form></div>';
	}

	/** @return string HTML notice, or '' */
	private static function handle_post() {
		if ( empty( $_POST['jouver_stripe_action'] ) || ! current_user_can( 'manage_options' ) ) {
			return '';
		}
		check_admin_referer( 'jouver_stripe_admin', 'jouver_stripe_nonce' );
		$action = sanitize_key( wp_unslash( $_POST['jouver_stripe_action'] ) );

		if ( 'map' === $action ) {
			$map = array();
			foreach ( preg_split( '/\r\n|\n|\r/', (string) wp_unslash( $_POST['jouver_stripe_map'] ?? '' ) ) as $line ) {
				if ( ! preg_match( '/^\s*([A-Za-z0-9_]+)\s*[=:,\s]\s*([A-Za-z0-9_-]+)\s*$/', $line, $m ) ) {
					continue;
				}
				$map[ $m[1] ] = strtolower( $m[2] );
			}
			update_option( 'jouver_stripe_plan_map', $map, false );
			$changed = Jouver_Stripe_Plan::reapply_map();
			return '<div class="notice notice-success"><p>Plan map saved: ' . count( $map ) . ' id(s). ' . (int) $changed . ' dealer row(s) updated.</p></div>';
		}

		// Upload: parse, keep the rows for an hour, show the preview.
		if ( 'import' === $action ) {
			if ( empty( $_FILES['jouver_stripe_csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['jouver_stripe_csv']['tmp_name'] ) ) {
				return '<div class="notice notice-error"><p>No file received.</p></div>';
			}
			if ( (int) $_FILES['jouver_stripe_csv']['size'] > 5 * 1024 * 1024 ) {
				return '<div class="notice notice-error"><p>File larger than 5 MB.</p></div>';
			}
			$csv  = file_get_contents( $_FILES['jouver_stripe_csv']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$rows = Jouver_Stripe_Import::parse_csv( $csv );
			if ( ! $rows ) {
				return '<div class="notice notice-error"><p>No rows found. Is this Stripe\'s Subscriptions CSV export?</p></div>';
			}
			$token = wp_generate_password( 20, false );
			set_transient( 'jouver_stripe_import_' . $token, $rows, HOUR_IN_SECONDS );
			return self::preview( Jouver_Stripe_Import::run( $rows, true ), $token, false );
		}

		// Preview again with typed user ids, or write.
		if ( 'preview' === $action || 'write' === $action ) {
			$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_POST['jouver_stripe_token'] ?? '' ) );
			$rows  = '' !== $token ? get_transient( 'jouver_stripe_import_' . $token ) : false;
			if ( ! is_array( $rows ) ) {
				return '<div class="notice notice-error"><p>The preview has expired (one hour). Upload the CSV again.</p></div>';
			}
			$overrides = array();
			foreach ( (array) ( $_POST['jouver_stripe_uid'] ?? array() ) as $i => $v ) {
				$v = trim( (string) wp_unslash( $v ) );
				if ( ctype_digit( $v ) && (int) $v > 0 ) {
					$overrides[ (int) $i ] = (int) $v;
				}
			}
			$write = 'write' === $action;
			$lines = Jouver_Stripe_Import::run( $rows, ! $write, $overrides );
			if ( $write ) {
				delete_transient( 'jouver_stripe_import_' . $token );
			}
			return self::preview( $lines, $token, $write );
		}
		return '';
	}
}
