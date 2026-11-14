<?php
/**
 * Removes everything the plugin stored when it's deleted.
 *
 * @package LorePort
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'loreport_api_key', 'loreport_default_list', 'loreport_comments', 'loreport_woocommerce', 'loreport_popup', 'loreport_signup_queue' ) as $loreport_option ) {
	delete_option( $loreport_option );
}
delete_transient( 'loreport_lists' );
delete_transient( 'loreport_overview' );
delete_transient( 'loreport_recent' );
wp_clear_scheduled_hook( 'loreport_retry_signups' );
