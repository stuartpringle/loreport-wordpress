<?php
/**
 * Plugin Name:       LorePort
 * Plugin URI:        https://loreport.ca
 * Description:       Newsletter signup forms for LorePort authors: a signup block and shortcode, plus opt-in checkboxes on comments and WooCommerce checkout.
 * Version:           0.2.1
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            LorePort
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       loreport
 */

defined( 'ABSPATH' ) || exit;

define( 'LOREPORT_VERSION', '0.2.1' );
define( 'LOREPORT_FILE', __FILE__ );
define( 'LOREPORT_DIR', plugin_dir_path( __FILE__ ) );
define( 'LOREPORT_URL', plugin_dir_url( __FILE__ ) );

require_once LOREPORT_DIR . 'includes/class-loreport-client.php';
require_once LOREPORT_DIR . 'includes/class-loreport-settings.php';
require_once LOREPORT_DIR . 'includes/class-loreport-signups.php';
require_once LOREPORT_DIR . 'includes/class-loreport-form.php';
require_once LOREPORT_DIR . 'includes/class-loreport-comments.php';
require_once LOREPORT_DIR . 'includes/class-loreport-woocommerce.php';
require_once LOREPORT_DIR . 'includes/class-loreport-privacy.php';

add_action( 'plugins_loaded', array( 'LorePort_Settings', 'init' ) );
add_action( 'plugins_loaded', array( 'LorePort_Signups', 'init' ) );
add_action( 'plugins_loaded', array( 'LorePort_Form', 'init' ) );
add_action( 'plugins_loaded', array( 'LorePort_Comments', 'init' ) );
add_action( 'plugins_loaded', array( 'LorePort_WooCommerce', 'init' ) );
add_action( 'plugins_loaded', array( 'LorePort_Privacy', 'init' ) );

register_activation_hook( __FILE__, array( 'LorePort_Signups', 'schedule_retries' ) );
register_deactivation_hook( __FILE__, array( 'LorePort_Signups', 'unschedule_retries' ) );
