<?php
/**
 * WP-Cron registration for MyNest Trust & Growth Suite.
 * The actual work (expiring boosts) is hooked directly by
 * TNM_Trust_Boosts::init() onto the shared `tnm_trust_hourly_event`
 * action, which is scheduled on plugin activation in the main plugin file.
 *
 * @package MyNest_Trust_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TNM_Trust_Cron {

	/**
	 * Hook registration.
	 */
	public static function init() {
		// Safety net: re-schedule the hourly event if it was somehow lost
		// (e.g. after a migration) without requiring re-activation.
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
	}

	/**
	 * Ensure the hourly cron event is scheduled.
	 */
	public static function ensure_scheduled() {
		if ( ! wp_next_scheduled( 'tnm_trust_hourly_event' ) ) {
			wp_schedule_event( time(), 'hourly', 'tnm_trust_hourly_event' );
		}
	}
}
