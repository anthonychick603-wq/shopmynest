<?php
/**
 * MyNest Marketplace child theme functions.
 *
 * Child theme of "Assembler". Keeps behavior minimal and additive:
 * - Loads the parent stylesheet, then this child's stylesheet.
 * - Loads the Satoshi display font from Fontshare (display=swap).
 * - Loads the marketplace design-system stylesheet (front-end + block editor).
 *
 * No database writes. No plugin activation/deactivation. No settings changes.
 *
 * @package MyNest_Marketplace
 */

declare( strict_types = 1 );

if ( ! function_exists( 'mynest_marketplace_enqueue_styles' ) ) :
	/**
	 * Enqueue parent + child stylesheets and the Satoshi font.
	 *
	 * The parent "assembler" theme already registers/enqueues its own
	 * "assembler-style" handle via `assembler_styles()` on `wp_enqueue_scripts`.
	 * We defensively re-register it here (wp_register_style is a no-op if the
	 * handle already exists) so the dependency chain is correct even if this
	 * theme is ever activated standalone against a different parent build.
	 *
	 * @return void
	 */
	function mynest_marketplace_enqueue_styles(): void {

		// Parent theme stylesheet (safe no-op if already registered by the parent).
		wp_register_style(
			'assembler-style',
			get_template_directory_uri() . '/style.css',
			array(),
			wp_get_theme( get_template() )->get( 'Version' )
		);
		wp_enqueue_style( 'assembler-style' );

		// Fontshare: Satoshi, swapped in to avoid invisible text during load.
		wp_enqueue_style(
			'mynest-font',
			'https://api.fontshare.com/v2/css?f[]=satoshi@400,500,700,900&display=swap',
			array(),
			null
		);

		// Child theme metadata stylesheet (loaded after the parent so child rules win).
		wp_enqueue_style(
			'mynest-marketplace-style',
			get_stylesheet_directory_uri() . '/style.css',
			array( 'assembler-style' ),
			wp_get_theme()->get( 'Version' )
		);

		// Marketplace design system — the bulk of the MyNest visual redesign.
		$marketplace_css      = get_stylesheet_directory() . '/assets/css/marketplace.css';
		$marketplace_css_ver  = file_exists( $marketplace_css ) ? (string) filemtime( $marketplace_css ) : wp_get_theme()->get( 'Version' );

		wp_enqueue_style(
			'mynest-marketplace',
			get_stylesheet_directory_uri() . '/assets/css/marketplace.css',
			array( 'assembler-style', 'mynest-marketplace-style' ),
			$marketplace_css_ver
		);
	}
endif;

add_action( 'wp_enqueue_scripts', 'mynest_marketplace_enqueue_styles' );

if ( ! function_exists( 'mynest_marketplace_enqueue_block_assets' ) ) :
	/**
	 * Load the font and marketplace design system inside the block editor too,
	 * so authors see an accurate preview of the front end while editing.
	 *
	 * @return void
	 */
	function mynest_marketplace_enqueue_block_assets(): void {

		wp_enqueue_style(
			'mynest-font',
			'https://api.fontshare.com/v2/css?f[]=satoshi@400,500,700,900&display=swap',
			array(),
			null
		);

		$marketplace_css     = get_stylesheet_directory() . '/assets/css/marketplace.css';
		$marketplace_css_ver = file_exists( $marketplace_css ) ? (string) filemtime( $marketplace_css ) : wp_get_theme()->get( 'Version' );

		wp_enqueue_style(
			'mynest-marketplace-editor',
			get_stylesheet_directory_uri() . '/assets/css/marketplace.css',
			array(),
			$marketplace_css_ver
		);
	}
endif;

add_action( 'enqueue_block_editor_assets', 'mynest_marketplace_enqueue_block_assets' );
