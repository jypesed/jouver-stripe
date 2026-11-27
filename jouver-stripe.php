<?php
/**
 * Plugin Name:       Jouver Stripe
 * Description:       Receives Stripe's signed webhook and keeps each dealer's plan. JAPP and Jouver Core read it with jouver_stripe_plan( $user_id ).
 * Version:           0.1.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Jouver
 * Text Domain:       jouver-stripe
 *
 * @package Jouver_Stripe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'JOUVER_STRIPE_VERSION', '0.1.1' );
define( 'JOUVER_STRIPE_DIR', plugin_dir_path( __FILE__ ) );

require_once JOUVER_STRIPE_DIR . 'includes/class-jouver-stripe-schema.php';
require_once JOUVER_STRIPE_DIR . 'includes/class-jouver-stripe-plan.php';
require_once JOUVER_STRIPE_DIR . 'includes/class-jouver-stripe-admin.php';

/**
 * The dealer's plan. Safe to call from any plugin once this one is active
 * (check function_exists first).
 *
 * @param int $user_id WordPress user id; 0 = the current user.
 * @return array {state: free|active|past_due|canceled|incomplete, active: bool,
 *               plan_key, plan_label, period_end (unix, 0 unknown), cancel_at_period_end}
 */
function jouver_stripe_plan( $user_id = 0 ) {
	$user_id = (int) $user_id > 0 ? (int) $user_id : get_current_user_id();
	return Jouver_Stripe_Plan::for_user( $user_id );
}

register_activation_hook( __FILE__, array( 'Jouver_Stripe_Schema', 'run' ) );
add_action( 'plugins_loaded', array( 'Jouver_Stripe_Schema', 'maybe_migrate' ) );
add_action( 'plugins_loaded', array( 'Jouver_Stripe_Plan', 'init' ) );
if ( is_admin() ) {
	add_action( 'plugins_loaded', array( 'Jouver_Stripe_Admin', 'init' ) );
}
