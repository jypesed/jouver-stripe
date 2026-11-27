<?php
/**
 * Tables. CREATE TABLE IF NOT EXISTS, run on activation and whenever the
 * stored version differs from the code's.
 *
 * @package Jouver_Stripe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Jouver_Stripe_Schema {

	const VERSION = '1';
	const OPTION  = 'jouver_stripe_schema';

	public static function maybe_migrate() {
		if ( get_option( self::OPTION ) !== self::VERSION ) {
			self::run();
			update_option( self::OPTION, self::VERSION );
		}
	}

	public static function run() {
		global $wpdb;
		$prefix  = $wpdb->prefix;
		$charset = $wpdb->get_charset_collate();

		// One row per dealer: the plan, written only by the signed webhook.
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$prefix}jouver_subscription (
			user_id                BIGINT UNSIGNED NOT NULL,
			status                 VARCHAR(24)     NOT NULL DEFAULT 'incomplete',
			plan_key               VARCHAR(40)     NOT NULL DEFAULT '',
			plan_label             VARCHAR(120)    NOT NULL DEFAULT '',
			price_id               VARCHAR(64)     NOT NULL DEFAULT '',
			product_id             VARCHAR(64)     NOT NULL DEFAULT '',
			stripe_customer_id     VARCHAR(64)     NOT NULL DEFAULT '',
			stripe_subscription_id VARCHAR(64)     NOT NULL DEFAULT '',
			currency               CHAR(3)         NOT NULL DEFAULT '',
			current_period_end     DATETIME        DEFAULT NULL,
			cancel_at_period_end   TINYINT(1)      NOT NULL DEFAULT 0,
			last_event_at          BIGINT UNSIGNED NOT NULL DEFAULT 0,
			updated_at             DATETIME        NOT NULL,
			PRIMARY KEY (user_id),
			KEY idx_sub_customer (stripe_customer_id)
		) {$charset}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Every event id seen, so a repeated delivery is applied once.
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$prefix}jouver_stripe_event (
			event_id    VARCHAR(80) NOT NULL,
			event_type  VARCHAR(60) NOT NULL DEFAULT '',
			outcome     VARCHAR(40) NOT NULL DEFAULT '',
			received_at DATETIME    NOT NULL,
			PRIMARY KEY (event_id),
			KEY idx_event_time (received_at)
		) {$charset}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
