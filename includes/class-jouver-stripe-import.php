<?php
/**
 * One-time import of dealers who subscribed before this plugin existed.
 *
 * Source: Stripe dashboard → Subscriptions → Export (CSV). No API key is
 * used. Each row is matched to a WordPress user by exact e-mail, or by a
 * `user_id` column you add to the CSV by hand for dealers whose Stripe
 * e-mail differs. Rows the webhook has already written are never touched.
 *
 * @package Jouver_Stripe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Jouver_Stripe_Import {

	/** Statuses worth a row. Anything else (canceled, incomplete…) is skipped: the dealer is free anyway. */
	const KEEP = array( 'active', 'trialing', 'past_due', 'unpaid' );

	/**
	 * Stripe's CSV → list of assoc rows keyed by a normalised header
	 * ("Current Period End (UTC)" → "currentperiodend").
	 */
	public static function parse_csv( $csv ) {
		$csv   = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $csv );
		$lines = preg_split( '/\r\n|\n|\r/', trim( $csv ) );
		if ( count( $lines ) < 2 ) {
			return array();
		}
		$head = array_map( array( __CLASS__, 'norm' ), str_getcsv( array_shift( $lines ) ) );
		$rows = array();
		foreach ( $lines as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$cells = str_getcsv( $line );
			$row   = array();
			foreach ( $head as $i => $h ) {
				$row[ $h ] = isset( $cells[ $i ] ) ? trim( (string) $cells[ $i ] ) : '';
			}
			$rows[] = $row;
		}
		return $rows;
	}

	private static function norm( $h ) {
		$h = strtolower( (string) $h );
		$h = str_replace( array( '(utc)', '(local)' ), '', $h );
		return preg_replace( '/[^a-z0-9]/', '', $h );
	}

	private static function pick( array $row, array $names ) {
		foreach ( $names as $n ) {
			if ( isset( $row[ $n ] ) && '' !== $row[ $n ] ) {
				return $row[ $n ];
			}
		}
		return '';
	}

	/**
	 * One CSV row → {email, user_id, status, fields} as the plan table wants it.
	 */
	public static function normalise( array $row ) {
		$status     = strtolower( self::pick( $row, array( 'status' ) ) );
		$price_id   = self::pick( $row, array( 'plan', 'price', 'priceid', 'planid' ) );
		$product_id = self::pick( $row, array( 'product', 'productid' ) );
		$end        = self::pick( $row, array( 'currentperiodend', 'periodend' ) );
		$end_ts     = '' !== $end ? strtotime( $end . ' UTC' ) : 0;
		$cancel     = strtolower( self::pick( $row, array( 'cancelatperiodend' ) ) );
		$key        = Jouver_Stripe_Plan::plan_from( $price_id, $product_id );
		$label      = self::pick( $row, array( 'nickname', 'pricenickname', 'planname', 'productname' ) );
		$uid        = self::pick( $row, array( 'userid', 'wordpressuserid', 'wpuserid' ) );
		return array(
			'email'   => strtolower( self::pick( $row, array( 'customeremail', 'email' ) ) ),
			'user_id' => ctype_digit( $uid ) ? (int) $uid : 0,
			'status'  => $status,
			'fields'  => array(
				'status'                 => $status,
				'stripe_customer_id'     => self::pick( $row, array( 'customerid', 'customer' ) ),
				'stripe_subscription_id' => self::pick( $row, array( 'id', 'subscriptionid' ) ),
				'price_id'               => $price_id,
				'product_id'             => $product_id,
				'plan_key'               => $key,
				'plan_label'             => '' !== $label ? $label : Jouver_Stripe_Plan::label_for_key( $key ),
				'currency'               => strtoupper( substr( self::pick( $row, array( 'currency' ) ), 0, 3 ) ),
				'current_period_end'     => $end_ts > 0 ? gmdate( 'Y-m-d H:i:s', $end_ts ) : null,
				'cancel_at_period_end'   => in_array( $cancel, array( 'true', '1', 'yes' ), true ) ? 1 : 0,
			),
		);
	}

	/**
	 * Run the import. With $dry_run nothing is written.
	 *
	 * @return array[] one line per CSV row: {email, user_id, status, plan_key, result}
	 */
	public static function run( array $rows, $dry_run = true, array $overrides = array() ) {
		$out = array();
		foreach ( array_values( $rows ) as $i => $row ) {
			$n    = self::normalise( $row );
			$line = array( 'email' => $n['email'], 'user_id' => 0, 'status' => $n['status'], 'plan_key' => $n['fields']['plan_key'], 'price_id' => $n['fields']['price_id'], 'product_id' => $n['fields']['product_id'], 'period_end' => (string) $n['fields']['current_period_end'], 'result' => '' );
			if ( ! in_array( $n['status'], self::KEEP, true ) ) {
				$line['result'] = 'skipped (' . ( '' === $n['status'] ? 'no status' : $n['status'] ) . ')';
				$out[]          = $line;
				continue;
			}
			// A user id typed on the preview screen wins, then a user_id column, then the e-mail.
			if ( ! empty( $overrides[ $i ] ) && ctype_digit( (string) $overrides[ $i ] ) ) {
				$n['user_id'] = (int) $overrides[ $i ];
			}
			$user_id = 0;
			if ( $n['user_id'] > 0 ) {
				if ( ! get_userdata( $n['user_id'] ) ) {
					$line['result'] = 'unmatched: no WordPress user with id ' . $n['user_id'];
					$out[]          = $line;
					continue;
				}
				$user_id = $n['user_id'];
			} elseif ( '' !== $n['email'] ) {
				$u       = get_user_by( 'email', $n['email'] );
				$user_id = $u ? (int) $u->ID : 0;
			}
			if ( ! $user_id ) {
				$line['result'] = 'unmatched: no dealer with this e-mail. Type the user id and import again.';
				$out[]          = $line;
				continue;
			}
			$line['user_id'] = $user_id;
			$line['result']  = $dry_run ? 'would import' : Jouver_Stripe_Plan::import_row( $user_id, $n['fields'] );
			$out[]           = $line;
		}
		return $out;
	}
}
