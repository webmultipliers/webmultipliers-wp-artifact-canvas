<?php
/**
 * Plugin Name:       WP Artifact Canvas
 * Plugin URI:        https://github.com/webmultipliers/webmultipliers-wp-artifact-canvas
 * Description:       Host fully-rendered HTML artifacts on WordPress — paste a complete HTML document and serve it 1:1 on the front end, with zero theme interference.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Web Multipliers, LLC
 * Author URI:        https://webmultipliers.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       webmultipliers-wp-artifact-canvas
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WMAC_VERSION', '1.0.0' );
define( 'WMAC_FILE', __FILE__ );
define( 'WMAC_PATH', plugin_dir_path( __FILE__ ) );
define( 'WMAC_URL', plugin_dir_url( __FILE__ ) );

if ( PHP_VERSION_ID < 80100 ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>'
			. esc_html__( 'WP Artifact Canvas requires PHP 8.1 or higher.', 'webmultipliers-wp-artifact-canvas' )
			. '</p></div>';
		}
	);
	return;
}

if ( file_exists( WMAC_PATH . 'vendor/autoload.php' ) ) {
	require_once WMAC_PATH . 'vendor/autoload.php';
} else {
	// Fallback PSR-4 loader for checkouts without a composer install.
	spl_autoload_register(
		static function ( string $class ): void {
			$prefix = 'WebMultipliers\\ArtifactCanvas\\';
			if ( ! str_starts_with( $class, $prefix ) ) {
				return;
			}
			$file = WMAC_PATH . 'src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
	);
}

use WebMultipliers\ArtifactCanvas\Activation;
use WebMultipliers\ArtifactCanvas\Plugin;

register_activation_hook( __FILE__, array( Activation::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Activation::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		Plugin::instance()->init();
	}
);
