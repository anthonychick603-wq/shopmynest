<?php
/**
 * v3.7.82 — Per-seller Shippo Connect (B2 bridge + B1 OAuth stub).
 *
 * B2 (active): sellers paste their own Shippo API token, we validate it
 * against Shippo, and store it encrypted in user_meta `_mnu_shippo_token`.
 * From that point on, any /rates or /transactions call scoped to that
 * seller uses their token instead of the platform token, and postage is
 * billed to their Shippo balance (skipping the payout debit).
 *
 * B1 (dormant): OAuth 2.0 authorization-code + refresh flow, activated
 * once Shippo Platform hands us a client_id/secret. The endpoints are
 * registered so the seller UI can already point at them; the callback
 * short-circuits with a "not yet activated" response until the platform
 * credentials option is populated.
 *
 * Storage
 * - user_meta `_mnu_shippo_token`         : ShippoToken (encrypted at rest)
 * - user_meta `_mnu_shippo_token_source`  : 'manual' | 'oauth'
 * - user_meta `_mnu_shippo_connected_at`  : ISO8601 UTC
 * - user_meta `_mnu_shippo_account_meta`  : json { email, first_name, last_name } from /v1/accounts/me
 * - user_meta `_mnu_shippo_oauth_refresh` : refresh_token (encrypted, B1 only)
 * - user_meta `_mnu_shippo_oauth_expires` : unix ts (B1 only)
 * - option    `mnu_shippo_platform`       : { client_id, client_secret, authorize_url, token_url, scopes }
 *
 * @package MyNestUnifiedMarketplace
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'MNU_SHIPPO_CONNECT_NS' ) ) {
    define( 'MNU_SHIPPO_CONNECT_NS', 'nest-connect/v1' );
}

/**
 * Symmetric obfuscation for tokens at rest. Uses WordPress's AUTH_KEY /
 * SECURE_AUTH_SALT if available; falls back to a per-site option so a
 * fresh install still gets a stable key. Not FIPS-grade; goal is to keep
 * tokens out of casual DB dumps, not to defeat root-on-server attackers.
 */
function mnu_shippo_secret(): string {
    $material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '' );
    if ( '' === $material ) {
        $material = get_option( 'mnu_shippo_local_secret' );
        if ( ! $material ) {
            $material = wp_generate_password( 64, true, true );
            update_option( 'mnu_shippo_local_secret', $material, false );
        }
    }
    return hash( 'sha256', 'mnu-shippo-token|' . $material, true );
}

function mnu_shippo_encrypt( string $plain ): string {
    if ( '' === $plain ) {
        return '';
    }
    if ( ! function_exists( 'openssl_encrypt' ) ) {
        // Extension missing; store as-is with a marker so decrypt is a no-op.
        return 'plain$' . $plain;
    }
    $iv     = random_bytes( 16 );
    $cipher = openssl_encrypt( $plain, 'AES-256-CBC', mnu_shippo_secret(), OPENSSL_RAW_DATA, $iv );
    return 'enc$' . base64_encode( $iv . $cipher );
}

function mnu_shippo_decrypt( string $blob ): string {
    if ( '' === $blob ) {
        return '';
    }
    if ( str_starts_with( $blob, 'plain$' ) ) {
        return substr( $blob, 6 );
    }
    if ( str_starts_with( $blob, 'enc$' ) ) {
        $bin = base64_decode( substr( $blob, 4 ), true );
        if ( false === $bin || strlen( $bin ) < 17 ) {
            return '';
        }
        $iv     = substr( $bin, 0, 16 );
        $cipher = substr( $bin, 16 );
        $plain  = openssl_decrypt( $cipher, 'AES-256-CBC', mnu_shippo_secret(), OPENSSL_RAW_DATA, $iv );
        return is_string( $plain ) ? $plain : '';
    }
    // Legacy: unwrapped tokens stored before encryption existed.
    return $blob;
}

/**
 * Ask Shippo which account a token belongs to. Also serves as a validator
 * for manual paste-your-token flow: a valid live/test token returns 200
 * with the account row; anything else is a bad or revoked token.
 */
function mnu_shippo_validate_token( string $token ): array {
    $token = trim( $token );
    if ( '' === $token ) {
        return array( 'ok' => false, 'error' => 'empty', 'message' => 'Paste your Shippo API token to connect.' );
    }
    if ( ! preg_match( '/^shippo_(test|live)_[a-z0-9]{20,}$/i', $token ) ) {
        return array(
            'ok'      => false,
            'error'   => 'malformed',
            'message' => 'That doesn’t look like a Shippo API token. It should start with shippo_live_ or shippo_test_ and can be copied from Shippo → Settings → API.',
        );
    }
    $response = wp_remote_get(
        'https://api.goshippo.com/v1/accounts/me',
        array(
            'headers' => array(
                'Authorization'      => 'ShippoToken ' . $token,
                'Accept'             => 'application/json',
                'SHIPPO-API-VERSION' => '2018-02-08',
            ),
            'timeout' => 20,
        )
    );
    if ( is_wp_error( $response ) ) {
        return array( 'ok' => false, 'error' => 'network', 'message' => $response->get_error_message() );
    }
    $code = (int) wp_remote_retrieve_response_code( $response );
    $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
    if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
        return array(
            'ok'      => false,
            'error'   => 'rejected',
            'status'  => $code,
            'message' => 'Shippo rejected that token. Double-check that you copied the whole token and that it isn’t revoked.',
        );
    }
    return array(
        'ok'      => true,
        'mode'    => str_starts_with( strtolower( $token ), 'shippo_live_' ) ? 'live' : 'test',
        'account' => array(
            'email'      => (string) ( $body['email'] ?? '' ),
            'first_name' => (string) ( $body['first_name'] ?? '' ),
            'last_name'  => (string) ( $body['last_name'] ?? '' ),
            'company'    => (string) ( $body['company_name'] ?? '' ),
        ),
    );
}

/**
 * Return the connection status for the given seller. Reads the meta rows
 * but never returns the raw token — only a boolean + safe metadata.
 */
function mnu_shippo_status_for_seller( int $seller_id ): array {
    if ( $seller_id <= 0 ) {
        return array( 'connected' => false, 'source' => null );
    }
    $token = trim( (string) get_user_meta( $seller_id, '_mnu_shippo_token', true ) );
    $meta  = get_user_meta( $seller_id, '_mnu_shippo_account_meta', true );
    $meta  = is_array( $meta ) ? $meta : array();
    return array(
        'connected'    => '' !== $token,
        'source'       => (string) get_user_meta( $seller_id, '_mnu_shippo_token_source', true ) ?: null,
        'connected_at' => (string) get_user_meta( $seller_id, '_mnu_shippo_connected_at', true ) ?: null,
        'mode'         => str_starts_with( strtolower( $token ), 'shippo_live_' ) ? 'live' : ( '' !== $token ? 'test' : null ),
        'account'      => array(
            'email'   => (string) ( $meta['email'] ?? '' ),
            'name'    => trim( ( $meta['first_name'] ?? '' ) . ' ' . ( $meta['last_name'] ?? '' ) ),
            'company' => (string) ( $meta['company'] ?? '' ),
        ),
        'oauth_ready'  => mnu_shippo_oauth_configured(),
    );
}

/**
 * Persist a manually-pasted token for a seller. Called from the REST
 * endpoint after validation succeeded.
 */
function mnu_shippo_persist_token( int $seller_id, string $token, string $source, array $account_meta ): void {
    update_user_meta( $seller_id, '_mnu_shippo_token', mnu_shippo_encrypt( $token ) );
    update_user_meta( $seller_id, '_mnu_shippo_token_source', $source );
    update_user_meta( $seller_id, '_mnu_shippo_connected_at', gmdate( 'c' ) );
    update_user_meta( $seller_id, '_mnu_shippo_account_meta', $account_meta );
}

/**
 * Remove all Shippo connection meta for a seller.
 */
function mnu_shippo_clear_seller( int $seller_id ): void {
    delete_user_meta( $seller_id, '_mnu_shippo_token' );
    delete_user_meta( $seller_id, '_mnu_shippo_token_source' );
    delete_user_meta( $seller_id, '_mnu_shippo_connected_at' );
    delete_user_meta( $seller_id, '_mnu_shippo_account_meta' );
    delete_user_meta( $seller_id, '_mnu_shippo_oauth_refresh' );
    delete_user_meta( $seller_id, '_mnu_shippo_oauth_expires' );
}

/**
 * Filter that lets mnu_labels_resolve_token() decrypt what we stored.
 * Runs late so we don’t interfere with unrelated user_meta reads.
 */
add_filter(
    'get_user_metadata',
    static function ( $value, int $object_id, string $meta_key, bool $single ) {
        if ( '_mnu_shippo_token' !== $meta_key || ! $single ) {
            return $value;
        }
        // Read the raw stored value directly to avoid recursion.
        remove_filter( 'get_user_metadata', __FUNCTION__, 10 );
        $raw = get_user_meta( $object_id, $meta_key, true );
        add_filter( 'get_user_metadata', __FUNCTION__, 10, 4 );
        if ( ! is_string( $raw ) || '' === $raw ) {
            return $value;
        }
        return array( mnu_shippo_decrypt( $raw ) );
    },
    10,
    4
);

// ---------------------------------------------------------------------------
// B1 (dormant) — platform-side OAuth config.
// ---------------------------------------------------------------------------

/**
 * Read the OAuth client config. Empty defaults so the flow is inert until
 * an administrator populates it under the plugin’s Shippo settings.
 */
function mnu_shippo_oauth_settings(): array {
    $defaults = array(
        'client_id'     => '',
        'client_secret' => '',
        // Shippo’s published Platform Accounts URLs. Actual values will be
        // confirmed once we get platform credentials — admins can override.
        'authorize_url' => 'https://apps.goshippo.com/oauth/authorize',
        'token_url'     => 'https://apps.goshippo.com/oauth/access_token',
        'scopes'        => 'shipments:read shipments:write transactions:read transactions:write',
    );
    $stored = get_option( 'mnu_shippo_platform', array() );
    return array_merge( $defaults, is_array( $stored ) ? $stored : array() );
}

function mnu_shippo_oauth_configured(): bool {
    $s = mnu_shippo_oauth_settings();
    return '' !== trim( (string) $s['client_id'] ) && '' !== trim( (string) $s['client_secret'] );
}

function mnu_shippo_oauth_redirect_uri(): string {
    return rest_url( MNU_SHIPPO_CONNECT_NS . '/oauth/callback' );
}

// ---------------------------------------------------------------------------
// REST endpoints.
// ---------------------------------------------------------------------------

add_action(
    'rest_api_init',
    static function () {
        register_rest_route(
            MNU_SHIPPO_CONNECT_NS,
            '/seller/status',
            array(
                'methods'             => 'GET',
                'callback'            => 'mnu_shippo_rest_status',
                'permission_callback' => 'mnu_shippo_auth_seller',
            )
        );

        register_rest_route(
            MNU_SHIPPO_CONNECT_NS,
            '/seller/manual',
            array(
                'methods'             => 'POST',
                'callback'            => 'mnu_shippo_rest_manual_connect',
                'permission_callback' => 'mnu_shippo_auth_seller',
                'args'                => array(
                    'token' => array( 'required' => true, 'type' => 'string' ),
                ),
            )
        );

        register_rest_route(
            MNU_SHIPPO_CONNECT_NS,
            '/seller/disconnect',
            array(
                'methods'             => 'POST',
                'callback'            => 'mnu_shippo_rest_disconnect',
                'permission_callback' => 'mnu_shippo_auth_seller',
            )
        );

        // B1 OAuth entry point. Redirects the seller to Shippo’s consent
        // screen when platform credentials are configured; otherwise returns
        // a clear "not activated" error so the mobile app hides the button.
        register_rest_route(
            MNU_SHIPPO_CONNECT_NS,
            '/oauth/start',
            array(
                'methods'             => 'GET',
                'callback'            => 'mnu_shippo_rest_oauth_start',
                'permission_callback' => 'mnu_shippo_auth_seller',
            )
        );

        register_rest_route(
            MNU_SHIPPO_CONNECT_NS,
            '/oauth/callback',
            array(
                'methods'             => 'GET',
                'callback'            => 'mnu_shippo_rest_oauth_callback',
                'permission_callback' => '__return_true',
            )
        );
    }
);

/**
 * Seller-scope auth: any authenticated user can manage their own Shippo
 * connection. Admins can pass ?seller_id= to act on behalf of a seller.
 */
function mnu_shippo_auth_seller( WP_REST_Request $request ) {
    $user_id = get_current_user_id();
    if ( $user_id <= 0 ) {
        return new WP_Error( 'rest_forbidden', 'You must be signed in.', array( 'status' => 401 ) );
    }
    $override = (int) $request->get_param( 'seller_id' );
    if ( $override > 0 && $override !== $user_id ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'rest_forbidden', 'You can only manage your own Shippo connection.', array( 'status' => 403 ) );
        }
    }
    return true;
}

function mnu_shippo_seller_from_request( WP_REST_Request $request ): int {
    $override = (int) $request->get_param( 'seller_id' );
    if ( $override > 0 && current_user_can( 'manage_options' ) ) {
        return $override;
    }
    return (int) get_current_user_id();
}

function mnu_shippo_rest_status( WP_REST_Request $request ): WP_REST_Response {
    $seller_id = mnu_shippo_seller_from_request( $request );
    return new WP_REST_Response( mnu_shippo_status_for_seller( $seller_id ), 200 );
}

function mnu_shippo_rest_manual_connect( WP_REST_Request $request ) {
    $seller_id = mnu_shippo_seller_from_request( $request );
    if ( $seller_id <= 0 ) {
        return new WP_Error( 'rest_forbidden', 'Sign in required.', array( 'status' => 401 ) );
    }
    $token = trim( (string) $request->get_param( 'token' ) );
    $check = mnu_shippo_validate_token( $token );
    if ( empty( $check['ok'] ) ) {
        return new WP_Error( 'shippo_token_invalid', (string) ( $check['message'] ?? 'That token isn’t valid.' ), array( 'status' => 422, 'reason' => $check['error'] ?? 'invalid' ) );
    }
    mnu_shippo_persist_token( $seller_id, $token, 'manual', (array) ( $check['account'] ?? array() ) );
    return new WP_REST_Response(
        array(
            'ok'     => true,
            'status' => mnu_shippo_status_for_seller( $seller_id ),
        ),
        200
    );
}

function mnu_shippo_rest_disconnect( WP_REST_Request $request ): WP_REST_Response {
    $seller_id = mnu_shippo_seller_from_request( $request );
    mnu_shippo_clear_seller( $seller_id );
    return new WP_REST_Response(
        array(
            'ok'     => true,
            'status' => mnu_shippo_status_for_seller( $seller_id ),
        ),
        200
    );
}

/**
 * B1: Start OAuth by handing the seller a URL to Shippo’s consent screen.
 * Until Shippo Platform has approved us and admins populate client_id/
 * client_secret, this returns 409 so the seller UI can hide the button.
 */
function mnu_shippo_rest_oauth_start( WP_REST_Request $request ) {
    if ( ! mnu_shippo_oauth_configured() ) {
        return new WP_Error(
            'shippo_oauth_not_activated',
            'One-click Shippo Connect isn’t available yet. For now, connect by pasting your Shippo API token.',
            array( 'status' => 409 )
        );
    }
    $seller_id = mnu_shippo_seller_from_request( $request );
    $state     = wp_generate_password( 32, false, false );
    set_transient( 'mnu_shippo_oauth_state_' . $state, $seller_id, 15 * MINUTE_IN_SECONDS );

    $settings = mnu_shippo_oauth_settings();
    $params   = array(
        'client_id'     => $settings['client_id'],
        'response_type' => 'code',
        'redirect_uri'  => mnu_shippo_oauth_redirect_uri(),
        'scope'         => $settings['scopes'],
        'state'         => $state,
    );

    return new WP_REST_Response(
        array(
            'authorize_url' => add_query_arg( array_map( 'rawurlencode', $params ), $settings['authorize_url'] ),
            'expires_in'    => 15 * MINUTE_IN_SECONDS,
        ),
        200
    );
}

/**
 * B1: OAuth callback. Exchanges the code for an access + refresh token,
 * fetches the account row so we can show who’s connected, and persists.
 * Kept as a stub-safe implementation so nothing runs until credentials
 * exist.
 */
function mnu_shippo_rest_oauth_callback( WP_REST_Request $request ) {
    if ( ! mnu_shippo_oauth_configured() ) {
        return new WP_REST_Response(
            array( 'ok' => false, 'error' => 'shippo_oauth_not_activated' ),
            409
        );
    }
    $state = (string) $request->get_param( 'state' );
    $code  = (string) $request->get_param( 'code' );
    if ( '' === $state || '' === $code ) {
        return new WP_Error( 'shippo_oauth_bad_state', 'Missing OAuth state or code.', array( 'status' => 400 ) );
    }
    $seller_id = (int) get_transient( 'mnu_shippo_oauth_state_' . $state );
    delete_transient( 'mnu_shippo_oauth_state_' . $state );
    if ( $seller_id <= 0 ) {
        return new WP_Error( 'shippo_oauth_expired', 'The connect link expired. Please try again from the app.', array( 'status' => 400 ) );
    }
    $settings = mnu_shippo_oauth_settings();
    $response = wp_remote_post(
        $settings['token_url'],
        array(
            'timeout' => 20,
            'headers' => array( 'Accept' => 'application/json' ),
            'body'    => array(
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'redirect_uri'  => mnu_shippo_oauth_redirect_uri(),
                'client_id'     => $settings['client_id'],
                'client_secret' => $settings['client_secret'],
            ),
        )
    );
    if ( is_wp_error( $response ) ) {
        return new WP_Error( 'shippo_oauth_network', $response->get_error_message(), array( 'status' => 502 ) );
    }
    $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
    $code_hdr = (int) wp_remote_retrieve_response_code( $response );
    if ( $code_hdr < 200 || $code_hdr >= 300 || ! is_array( $body ) || empty( $body['access_token'] ) ) {
        return new WP_Error( 'shippo_oauth_rejected', 'Shippo rejected the connection. Please try again.', array( 'status' => 502, 'body' => $body ) );
    }

    $access  = (string) $body['access_token'];
    $refresh = (string) ( $body['refresh_token'] ?? '' );
    $expires = time() + (int) ( $body['expires_in'] ?? 3600 );

    $check = mnu_shippo_validate_token( $access );
    $meta  = ! empty( $check['ok'] ) ? (array) $check['account'] : array();
    mnu_shippo_persist_token( $seller_id, $access, 'oauth', $meta );
    if ( '' !== $refresh ) {
        update_user_meta( $seller_id, '_mnu_shippo_oauth_refresh', mnu_shippo_encrypt( $refresh ) );
    }
    update_user_meta( $seller_id, '_mnu_shippo_oauth_expires', $expires );

    return new WP_REST_Response(
        array( 'ok' => true, 'status' => mnu_shippo_status_for_seller( $seller_id ) ),
        200
    );
}
