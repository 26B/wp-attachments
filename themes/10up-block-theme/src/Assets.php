<?php
/**
 * Assets module.
 *
 * @package TenupBlockTheme
 */

namespace TenupBlockTheme;

use TenupFramework\Assets\GetAssetInfo;
use TenupFramework\Module;
use TenupFramework\ModuleInterface;

/**
 * Assets module.
 *
 * @package TenupBlockTheme
 */
class Assets implements ModuleInterface {

	use Module;
	use GetAssetInfo;

	/**
	 * Can this module be registered?
	 *
	 * @return bool
	 */
	public function can_register() {
		return true;
	}

	/**
	 * Register any hooks and filters.
	 *
	 * @return void
	 */
	public function register() {
		$this->setup_asset_vars(
			dist_path: TENUP_BLOCK_THEME_DIST_PATH,
			fallback_version: TENUP_BLOCK_THEME_VERSION
		);
		add_action( 'init', [ $this, 'scripts' ] );
		add_action( 'init', [ $this, 'register_all_icons' ], 10 );
		add_action( 'wp_enqueue_scripts', [ $this, 'styles' ] );
		add_action( 'enqueue_block_editor_assets', [ $this, 'editor_style_overrides' ] );
	}

	/**
	 * Enqueue scripts for front-end.
	 *
	 * @return void
	 */
	public function scripts() {
		wp_enqueue_script(
			'frontend',
			TENUP_BLOCK_THEME_TEMPLATE_URL . '/dist/js/frontend.js',
			$this->get_asset_info( 'frontend', 'dependencies' ),
			$this->get_asset_info( 'frontend', 'version' ),
			[
				'strategy' => 'defer',
			]
		);
	}

	/**
	 * Enqueue styles for front-end.
	 *
	 * @return void
	 */
	public function styles() {
		wp_enqueue_style(
			'tenup-theme-styles',
			TENUP_BLOCK_THEME_TEMPLATE_URL . '/dist/css/frontend.css',
			[],
			$this->get_asset_info( 'frontend', 'version' )
		);
	}

	/**
	 * Enqueue styles for editor only.
	 *
	 * @return void
	 */
	public function editor_style_overrides() {
		wp_enqueue_style(
			'tenup-theme-editor-style-overrides',
			TENUP_BLOCK_THEME_TEMPLATE_URL . '/dist/css/editor-style-overrides.css',
			[],
			TENUP_BLOCK_THEME_VERSION
		);

		wp_enqueue_script(
			'tenup-theme-block-extensions',
			TENUP_BLOCK_THEME_TEMPLATE_URL . '/dist/js/block-extensions.js',
			$this->get_asset_info( 'block-extensions', 'dependencies' ),
			$this->get_asset_info( 'block-extensions', 'version' ),
			true
		);
	}

	/**
	 * register all icons located in the dist/svg folder
	 *
	 * @return void
	 */
	public function register_all_icons() {
		if ( ! function_exists( '\UIKitCore\Helpers\register_icons' ) ) {
			return;
		}

		$icon_paths = glob( TENUP_BLOCK_THEME_DIST_PATH . 'svg/*.svg' );

		if ( ! $icon_paths ) {
			return;
		}

		$icons = array_map(
			function ( $icon_path ) {
				$icon_name = preg_replace( '#\..*$#', '', basename( $icon_path ) );

				if ( ! $icon_name || ! class_exists( '\UIKitCore\Icon' ) ) {
					return false;
				}

				return new \UIKitCore\Icon(
					$icon_name,
					ucwords( str_replace( '-', ' ', $icon_name ) ),
					$icon_path
				);
			},
			$icon_paths
		);

		\UIKitCore\Helpers\register_icons(
			[
				'name'  => 'tenup',
				'label' => 'Theme Icons',
				'icons' => $icons,
			]
		);
	}
}
