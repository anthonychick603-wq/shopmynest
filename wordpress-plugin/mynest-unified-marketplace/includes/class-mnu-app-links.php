<?php
/**
 * Serve /.well-known/assetlinks.json so Android verifies our App Links.
 *
 * Google's App Links verifier fetches this file over HTTPS from
 * https://shopmynest.com/.well-known/assetlinks.json. When the fingerprint
 * inside matches the release keystore that signed the APK, Android opens
 * shopmynest.com URLs directly in the app instead of a browser chooser.
 *
 * The SHA256 fingerprint is stored in a WP option so it can be rotated from
 * the admin UI without shipping a new plugin build. Grab it with:
 *   keytool -list -v -keystore <keystore> -alias <alias>
 * and paste the "SHA256:" line into Settings -> Marketplace -> Android App Links.
 *
 * @package MyNestUnifiedMarketplace
 * @since   3.7.99
 */

defined( 'ABSPATH' ) || exit;

final class MNU_App_Links {
	private const OPT_KEY   = 'mnu_android_applink_sha256';
	// v3.7.122.3: package name flipped from com.thenest.marketplace to
	// com.shopmynest to match the app.json in mobile v1.0.99+.
	private const PACKAGE   = 'com.shopmynest';
	private const ROUTE_URL = '.well-known/assetlinks.json';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_rewrite' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve' ), 1 );
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 40 );

		// One-shot rewrite flush after this module is first loaded so the
		// .well-known route resolves without the site owner manually visiting
		// Settings -> Permalinks. Sentinel is bumped in v3.7.122.3 because
		// the original flush ran before the rewrite rule was registered on
		// this site, leaving /.well-known/assetlinks.json returning HTML 404.
		if ( get_option( 'mnu_applink_rewrite_flushed', '' ) !== '2' ) {
			add_action( 'shutdown', array( __CLASS__, 'flush_once' ) );
		}
	}

	public static function flush_once(): void {
		flush_rewrite_rules( false );
		update_option( 'mnu_applink_rewrite_flushed', '2', false );
	}

	public static function register_rewrite(): void {
		add_rewrite_rule( '^\.well-known/assetlinks\.json$', 'index.php?mnu_assetlinks=1', 'top' );
	}

	public static function query_vars( array $vars ): array {
		$vars[] = 'mnu_assetlinks';
		return $vars;
	}

	public static function maybe_serve(): void {
		if ( (int) get_query_var( 'mnu_assetlinks' ) !== 1 ) {
			return;
		}
		$fingerprints = self::fingerprints();
		$payload      = array();
		if ( ! empty( $fingerprints ) ) {
			$payload[] = array(
				'relation' => array( 'delegate_permission/common.handle_all_urls' ),
				'target'   => array(
					'namespace'                => 'android_app',
					'package_name'             => self::PACKAGE,
					'sha256_cert_fingerprints' => $fingerprints,
				),
			);
		}
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * Fingerprints stored as a newline-separated list in the option so the
	 * admin can paste multiple entries (e.g. debug + release + Play upload).
	 *
	 * @return array<int, string>
	 */
	private static function fingerprints(): array {
		$raw = (string) get_option( self::OPT_KEY, '' );
		if ( '' === $raw ) {
			return array();
		}
		$out = array();
		foreach ( preg_split( '/[\s,]+/', $raw ) as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}
			// Accept both "AA:BB:..." and raw hex "AABB...". Normalize to uppercase colon form.
			$hex = strtoupper( preg_replace( '/[^0-9A-Fa-f]/', '', $line ) );
			if ( strlen( $hex ) !== 64 ) {
				continue;
			}
			$out[] = trim( chunk_split( $hex, 2, ':' ), ':' );
		}
		return array_values( array_unique( $out ) );
	}

	public static function register_setting(): void {
		register_setting(
			'mnu_applinks',
			self::OPT_KEY,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'default'           => '',
				'show_in_rest'      => false,
			)
		);

		// v3.7.122.3: seed the fingerprint the first time this module loads
		// so /.well-known/assetlinks.json returns a real payload before an
		// admin has visited Settings -> Android App Links. Sentinel-guarded
		// so an admin edit can never be silently overwritten.
		if ( get_option( 'mnu_applink_seeded_v1', '' ) !== '1' && '' === (string) get_option( self::OPT_KEY, '' ) ) {
			update_option( self::OPT_KEY, self::SEED_FINGERPRINT, false );
			update_option( 'mnu_applink_seeded_v1', '1', false );
		}
	}

	// v3.7.122.3 seed value — SHA-256 of the current release upload
	// keystore (EAS Build Credentials 3UmT3RqB2b). Formatted as the
	// admin UI expects: colon-separated uppercase hex, one per line.
	private const SEED_FINGERPRINT = 'EF:C5:9D:54:E9:C9:A9:6D:D4:86:26:E0:DA:1D:8B:30:E0:8C:4C:7D:9E:43:29:B0:C5:4E:C8:83:6E:22:82:1C';

	public static function menu(): void {
		add_submenu_page(
			'options-general.php',
			'Android App Links',
			'Android App Links',
			'manage_options',
			'mnu-android-app-links',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$current = (string) get_option( self::OPT_KEY, '' );
		$assetlinks_url = home_url( '/.well-known/assetlinks.json' );
		?>
		<div class="wrap">
			<h1>Android App Links</h1>
			<p>Paste the SHA256 fingerprint(s) of the release keystore(s) that sign the ShopMyNest Android app (package <code><?php echo esc_html( self::PACKAGE ); ?></code>). One fingerprint per line. Accepted formats: <code>AA:BB:CC:...</code> (64 hex chars separated by colons) or the raw 64-char hex.</p>
			<p>Get the fingerprint with:</p>
			<pre><code>keytool -list -v -keystore &lt;keystore&gt; -alias &lt;alias&gt;</code></pre>
			<p>The manifest is served at <a href="<?php echo esc_url( $assetlinks_url ); ?>" target="_blank" rel="noreferrer"><?php echo esc_html( $assetlinks_url ); ?></a>. Google's Digital Asset Links tester validates it: <a href="https://developers.google.com/digital-asset-links/tools/generator" target="_blank" rel="noreferrer">Statement List Generator &amp; Tester</a>.</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'mnu_applinks' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( self::OPT_KEY ); ?>">SHA256 fingerprints</label></th>
						<td><textarea id="<?php echo esc_attr( self::OPT_KEY ); ?>" name="<?php echo esc_attr( self::OPT_KEY ); ?>" rows="6" cols="70" class="large-text code"><?php echo esc_textarea( $current ); ?></textarea></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}

MNU_App_Links::init();
