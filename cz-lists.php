<?php
/**
 * Plugin Name: CZ Lists
 * Description: Liste numeriche del buddhismo (CPT "Liste") con elementi strutturati, varianti e collegamenti ai lemmi.
 * Version: 1.1.0
 * Author: Roberto Mauro
 * Text Domain: cz-lists
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CZ_LISTS_VERSION', '1.1.0' );
define( 'CZ_LISTS_PATH', plugin_dir_path( __FILE__ ) );
define( 'CZ_LISTS_URL', plugin_dir_url( __FILE__ ) );

require_once CZ_LISTS_PATH . 'includes/class-cz-lists-cpt.php';
require_once CZ_LISTS_PATH . 'includes/class-cz-lists-data.php';
require_once CZ_LISTS_PATH . 'includes/class-cz-lists-admin.php';
require_once CZ_LISTS_PATH . 'includes/class-cz-lists-frontend.php';
require_once CZ_LISTS_PATH . 'includes/class-cz-lists-linker.php';
require_once CZ_LISTS_PATH . 'includes/class-cz-lists-search.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once CZ_LISTS_PATH . 'includes/class-cz-lists-cli.php';
	WP_CLI::add_command( 'cz-lists', 'CZ_Lists_CLI' );
}

class CZ_Lists_Plugin {
	/**
	 * @var CZ_Lists_Plugin|null
	 */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( 'CZ_Lists_CPT', 'register' ) );
		add_action( 'init', array( 'CZ_Lists_Data', 'register_meta' ) );

		// I lemmi possono essere salvati anche via REST, quindi non solo in admin.
		new CZ_Lists_Linker();
		new CZ_Lists_Search();

		if ( is_admin() ) {
			new CZ_Lists_Admin();
		} else {
			new CZ_Lists_Frontend();
		}
	}

	public static function activate() {
		CZ_Lists_CPT::register();
		CZ_Lists_CPT::seed_count_terms();
		flush_rewrite_rules();
	}

	public static function deactivate() {
		flush_rewrite_rules();
	}
}

register_activation_hook( __FILE__, array( 'CZ_Lists_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CZ_Lists_Plugin', 'deactivate' ) );

CZ_Lists_Plugin::instance();
