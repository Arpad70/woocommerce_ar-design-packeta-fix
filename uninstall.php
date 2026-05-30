<?php
/**
 * Uninstall hook for AR Design Packeta Fix for WooCommerce.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'ar_design_packeta_surcharge_monitor_state' );

if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
	wp_clear_scheduled_hook( 'ard_packeta_surcharge_sync_event' );
}

// Shipping nastavení a order meta ponecháváme zachované,
// protože jejich mazání musí řídit samostatná retenční politika.
