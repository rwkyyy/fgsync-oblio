<?php
/**
 * Uninstall routine.
 *
 * Removes plugin options and scheduled jobs. Order/product meta (issued invoice
 * numbers, links, etc.) is intentionally preserved, that is business data.
 *
 * @package FGSyncOblio
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$oblio_fgwoo_like = $wpdb->esc_like( 'oblio_fgwoo_' ) . '%';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $oblio_fgwoo_like ) );

if ( is_multisite() ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$oblio_fgwoo_blog_ids = $wpdb->get_col( "SELECT blog_id FROM {$wpdb->blogs}" );
	foreach ( (array) $oblio_fgwoo_blog_ids as $oblio_fgwoo_blog_id ) {
		switch_to_blog( (int) $oblio_fgwoo_blog_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $oblio_fgwoo_like ) );
		restore_current_blog();
	}
}

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'oblio' );
}
