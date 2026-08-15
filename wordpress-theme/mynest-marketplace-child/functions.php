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

/* -----------------------------------------------------------------------------
 * Palette handoff: shopmynest-branding is the single source of truth.
 *
 * The branding plugin (shopmynest-branding.php) exposes smn_branding_palette().
 * We use that palette to override this theme's color presets at runtime, so
 * editing the plugin's palette re-colors:
 *   - WordPress Global Styles ( theme.json color slots reference presets )
 *   - marketplace.css and any other CSS reading --wp--preset--color--*
 *   - Block editor previews
 *   - Anywhere blocks / patterns use theme colors by slug
 *
 * If the branding plugin is inactive, the theme's baked-in placeholder palette
 * (Studio Clay defaults, matching plugin v1.3.0) remains in effect.
 * ---------------------------------------------------------------------------*/

if ( ! function_exists( 'mynest_marketplace_palette_from_plugin' ) ) :
	/**
	 * Fetch the palette hash from the branding plugin, or a safe default.
	 *
	 * @return array<string,string> primary/dark/accent/background/card/ink/border keys.
	 */
	function mynest_marketplace_palette_from_plugin(): array {
		if ( function_exists( 'smn_branding_palette' ) ) {
			$p = smn_branding_palette();
			if ( is_array( $p ) && ! empty( $p['primary'] ) ) {
				return $p;
			}
		}
		// Fallback: Modern Marketplace defaults (mirrors plugin v1.4.0).
		return array(
			'primary'    => '#3A3D8A',
			'dark'       => '#26295F',
			'accent'     => '#E27055',
			'background' => '#F8F5F0',
			'card'       => '#FFFFFF',
			'ink'        => '#1B1A21',
			'border'     => '#E4DED4',
			'secondary'  => '#E27055',
		);
	}
endif;

if ( ! function_exists( 'mynest_marketplace_theme_json_palette' ) ) :
	/**
	 * Map the plugin palette onto the child theme's theme.json color slugs.
	 *
	 * @return array<int,array<string,string>>
	 */
	function mynest_marketplace_theme_json_palette(): array {
		$p = mynest_marketplace_palette_from_plugin();
		return array(
			array( 'slug' => 'theme-1',     'color' => $p['card'],       'name' => 'Card surface' ),
			array( 'slug' => 'theme-2',     'color' => $p['background'], 'name' => 'Alt surface' ),
			array( 'slug' => 'theme-3',     'color' => $p['border'],     'name' => 'Border' ),
			array( 'slug' => 'theme-4',     'color' => $p['ink'],        'name' => 'Body text' ),
			array( 'slug' => 'theme-5',     'color' => '#131210',        'name' => 'Dark sections' ),
			array( 'slug' => 'brand',       'color' => $p['primary'],    'name' => 'Brand' ),
			array( 'slug' => 'brand-dark',  'color' => $p['dark'],       'name' => 'Brand dark' ),
			array( 'slug' => 'accent-warm', 'color' => $p['accent'],     'name' => 'Accent warm' ),
		);
	}
endif;

/**
 * Filter theme.json data at load time so the color palette comes from the
 * branding plugin. This runs BEFORE Global Styles are compiled, meaning the
 * change flows through core's --wp--preset--color--* variables site-wide.
 */
add_filter(
	'wp_theme_json_data_theme',
	function ( $theme_json ) {
		if ( ! is_object( $theme_json ) || ! method_exists( $theme_json, 'update_with' ) ) {
			return $theme_json;
		}
		$theme_json->update_with(
			array(
				'version'  => 3,
				'settings' => array(
					'color' => array(
						'palette' => mynest_marketplace_theme_json_palette(),
					),
				),
			)
		);
		return $theme_json;
	}
);

/**
 * Emit an inline stylesheet that redefines --wp--preset--color--* on :root
 * with priority 1000. WordPress core writes these variables in its own inline
 * <style> earlier in the head; our declaration wins the cascade and covers
 * the edge case where a customized wp_global_styles CPT overrides theme.json.
 */
add_action(
	'wp_enqueue_scripts',
	function () {
		$p = mynest_marketplace_palette_from_plugin();
		$css = ':root{'
			. '--wp--preset--color--theme-1:'     . $p['card']       . ';'
			. '--wp--preset--color--theme-2:'     . $p['background'] . ';'
			. '--wp--preset--color--theme-3:'     . $p['border']     . ';'
			. '--wp--preset--color--theme-4:'     . $p['ink']        . ';'
			. '--wp--preset--color--brand:'       . $p['primary']    . ';'
			. '--wp--preset--color--brand-dark:'  . $p['dark']       . ';'
			. '--wp--preset--color--accent-warm:' . $p['accent']     . ';'
			. '}';
		wp_register_style( 'mynest-palette-runtime', false, array(), '1.0.4' );
		wp_enqueue_style( 'mynest-palette-runtime' );
		wp_add_inline_style( 'mynest-palette-runtime', $css );
	},
	100 // late so it comes after core's global-styles handle.
);
