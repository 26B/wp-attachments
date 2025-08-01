<?php
/**
 * Core plugin functionality.
 *
 * @package TSB\WP\Plugin\Attachments
 */

namespace TSB\WP\Plugin\Attachments\Core;

use TenupFramework\Facades\WordPress;
use TenupFramework\ModuleInitialization;
use WP_Error;

/**
 * Default setup routine
 *
 * @return void
 */
function setup() {
	$n = function ( $function ) {
		return __NAMESPACE__ . "\\$function";
	};

	add_action( 'init', $n( 'i18n' ) );
	add_action( 'init', $n( 'cli' ) );
	add_action( 'init', $n( 'init' ) );
	add_action( 'wp_enqueue_scripts', $n( 'scripts' ) );
	add_action( 'wp_enqueue_scripts', $n( 'styles' ) );
	add_action( 'admin_enqueue_scripts', $n( 'admin_scripts' ) );
	add_action( 'admin_enqueue_scripts', $n( 'admin_styles' ) );

	// Hook to allow async or defer on asset loading.
	add_filter( 'script_loader_tag', $n( 'script_loader_tag' ), 10, 2 );

	/**
	 * Fires when the wp-attachments plugin has loaded.
	 */
	do_action( 'wp-attachments_loaded' );
}

/**
 * Registers the default textdomain.
 *
 * @return void
 */
function i18n() {
	$locale = apply_filters( 'plugin_locale', WordPress::get_locale(), 'wp-attachments' );
	load_textdomain( 'wp-attachments', WP_LANG_DIR . '/wp-attachments/wp-attachments-' . $locale . '.mo' );
	load_plugin_textdomain( 'wp-attachments', false, plugin_basename( TSB_WP_PLUGIN_ATTACHMENTS_PATH ) . '/languages/' );
}

function cli() {
	if ( ! class_exists( 'WP_CLI' ) ) {
		return;
	}

	// Register CLI commands.
}

/**
 * Initializes the plugin and fires an action other plugins can hook into.
 *
 * @return void
 */
function init() {
	/**
	 * Fires before the wp-attachments plugin is initialized.
	 */
	do_action( 'wp-attachments_before_init' );

	if ( ! class_exists( '\TenupFramework\ModuleInitialization' ) ) {
		add_action(
			'admin_notices',
			function () {
				$class = 'notice notice-error';

				printf(
					'<div class="%1$s"><p>%2$s</p></div>',
					esc_attr( $class ),
					wp_kses_post(
						__(
							'Please ensure the <a href="https://github.com/10up/wp-framework"><code>10up/wp-framework</code></a> composer package is installed.',
							'tenup-plugin'
						)
					)
				);
			}
		);

		return;
	}

	ModuleInitialization::instance()->init_classes( TSB_WP_PLUGIN_ATTACHMENTS_INC );

	/**
	 * Fires after the wp-attachments plugin is initialized.
	 */
	do_action( 'wp-attachments_init' );
}

/**
 * Activate the plugin
 *
 * @return void
 */
function activate() {
	// First load the init scripts in case any rewrite functionality is being loaded
	init();
	flush_rewrite_rules();
}

/**
 * Deactivate the plugin
 *
 * Uninstall routines should be in uninstall.php
 *
 * @return void
 */
function deactivate() {
}


/**
 * The list of knows contexts for enqueuing scripts/styles.
 *
 * @return array
 */
function get_enqueue_contexts() {
	return [];
}

/**
 * Generate an URL to a script, taking into account whether SCRIPT_DEBUG is enabled.
 *
 * @param string $script  Script file name (no .js extension)
 * @param string $context Context for the script ('index', 'dashboard')
 *
 * @return string|WP_Error URL
 */
function script_url( $script, $context ) {

	if ( ! in_array( $context, get_enqueue_contexts(), true ) ) {
		return new WP_Error( 'invalid_enqueue_context', 'Invalid $context specified in TSB\WP\Plugin\Attachments script loader.' );
	}

	return TSB_WP_PLUGIN_ATTACHMENTS_URL . "build/{$script}.js";
}

/**
 * Generate an URL to a stylesheet, taking into account whether SCRIPT_DEBUG is enabled.
 *
 * @param string $stylesheet Stylesheet file name (no .css extension)
 * @param string $context    Context for the script ('index', 'dashboard')
 *
 * @return string URL
 */
function style_url( $stylesheet, $context ) {

	if ( ! in_array( $context, get_enqueue_contexts(), true ) ) {
		return new WP_Error( 'invalid_enqueue_context', 'Invalid $context specified in TSB\WP\Plugin\Attachments stylesheet loader.' );
	}

	return TSB_WP_PLUGIN_ATTACHMENTS_URL . "build/{$stylesheet}.css";
}

/**
 * Enqueue scripts for front-end.
 *
 * @return void
 */
function scripts() {
	// No global frontend scripts.
}

/**
 * Enqueue scripts for admin.
 *
 * @return void
 */
function admin_scripts() {
	// No global admin scripts.
}

/**
 * Enqueue styles for front-end.
 *
 * @return void
 */
function styles() {
	// No global admin styles.
}

/**
 * Enqueue styles for admin.
 *
 * @return void
 */
function admin_styles() {
	// No global admin styles.
}

/**
 * Add async/defer attributes to enqueued scripts that have the specified script_execution flag.
 *
 * @link   https://core.trac.wordpress.org/ticket/12009
 * @param  string $tag    The script tag.
 * @param  string $handle The script handle.
 * @return string
 */
function script_loader_tag( $tag, $handle ) {
	$script_execution = wp_scripts()->get_data( $handle, 'script_execution' );

	if ( ! $script_execution ) {
		return $tag;
	}

	if ( 'async' !== $script_execution && 'defer' !== $script_execution ) {
		return $tag; // _doing_it_wrong()?
	}

	// Abort adding async/defer for scripts that have this script as a dependency. _doing_it_wrong()?
	foreach ( wp_scripts()->registered as $script ) {
		if ( in_array( $handle, $script->deps, true ) ) {
			return $tag;
		}
	}

	// Add the attribute if it hasn't already been added.
	if ( ! preg_match( ":\s$script_execution(=|>|\s):", $tag ) ) {
		$tag = preg_replace( ':(?=></script>):', " $script_execution", $tag, 1 );
	}

	return $tag;
}
