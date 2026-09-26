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

/**
 * Action Scheduler's tables are per-site ($wpdb->prefix-scoped), same as
 * options - both have to be cleaned up inside each site's own switch_to_blog()
 * context, not just once in whichever site happened to be current when
 * uninstall ran.
 */
$oblio_fgwoo_cleanup_site = static function () use ( $wpdb, $oblio_fgwoo_like ): void {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $oblio_fgwoo_like ) );

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( '', array(), 'oblio' );
		as_unschedule_all_actions( '', array(), 'oblio_fgwoo' );
	}
};

$oblio_fgwoo_cleanup_site();

if ( is_multisite() ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$oblio_fgwoo_blog_ids = $wpdb->get_col( "SELECT blog_id FROM {$wpdb->blogs}" );
	foreach ( (array) $oblio_fgwoo_blog_ids as $oblio_fgwoo_blog_id ) {
		switch_to_blog( (int) $oblio_fgwoo_blog_id );
		$oblio_fgwoo_cleanup_site();
		restore_current_blog();
	}
}
