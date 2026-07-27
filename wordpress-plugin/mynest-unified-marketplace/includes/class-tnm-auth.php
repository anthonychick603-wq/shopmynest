<?php

defined( 'ABSPATH' ) || exit;

final class TNM_Auth {
    public static function init(): void {
        add_filter( 'determine_current_user', array( __CLASS__, 'authenticate_bearer' ), 30 );
    }

    public static function authenticate_bearer( int|false $user_id ): int|false {
        if ( $user_id ) {
            return $user_id;
        }
        $token = tnm_request_bearer_token();
        if ( ! $token ) {
            return $user_id;
        }
        $payload = self::decode_token( $token );
        if ( ! is_wp_error( $payload ) ) {
            return (int) $payload['sub'];
        }

        // Preserve sessions issued by earlier The Nest mobile plugins during migration.
        $legacy_users = get_users(
            array(
                'meta_key'   => '_nest_mobile_token',
                'meta_value' => sanitize_text_field( $token ),
                'number'     => 1,
                'fields'     => 'ID',
            )
        );
        return $legacy_users ? (int) $legacy_users[0] : $user_id;
    }

    public static function issue_token( int $user_id ): string|WP_Error {
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return tnm_json_error( 'invalid_user', 'The user does not exist.', 404 );
        }
        $now     = time();
        $days    = max( 1, min( 365, (int) tnm_get_option( 'token_lifetime_days', 30 ) ) );
        $version = (int) get_user_meta( $user_id, 'tnm_token_version', true );
        $payload = array(
            'iss' => home_url( '/' ),
            'sub' => $user_id,
            'iat' => $now,
            'exp' => $now + ( $days * DAY_IN_SECONDS ),
            'ver' => $version,
        );
        $body      = tnm_base64url_encode( wp_json_encode( $payload ) );
        $signature = tnm_base64url_encode( hash_hmac( 'sha256', $body, wp_salt( 'auth' ), true ) );
        return $body . '.' . $signature;
    }

    public static function decode_token( string $token ): array|WP_Error {
        $parts = explode( '.', $token );
        if ( 2 !== count( $parts ) ) {
            return tnm_json_error( 'invalid_token', 'Invalid authentication token.', 401 );
        }
        [ $body, $signature ] = $parts;
        $expected = tnm_base64url_encode( hash_hmac( 'sha256', $body, wp_salt( 'auth' ), true ) );
        if ( ! hash_equals( $expected, $signature ) ) {
            return tnm_json_error( 'invalid_token', 'Invalid authentication token.', 401 );
        }
        $decoded = tnm_base64url_decode( $body );
        $payload = $decoded ? json_decode( $decoded, true ) : null;
        if ( ! is_array( $payload ) || empty( $payload['sub'] ) || empty( $payload['exp'] ) ) {
            return tnm_json_error( 'invalid_token', 'Invalid authentication token.', 401 );
        }
        if ( (int) $payload['exp'] < time() ) {
            return tnm_json_error( 'expired_token', 'Authentication token has expired.', 401 );
        }
        $user = get_userdata( (int) $payload['sub'] );
        if ( ! $user ) {
            return tnm_json_error( 'invalid_token_user', 'Authentication user no longer exists.', 401 );
        }
        $version = (int) get_user_meta( $user->ID, 'tnm_token_version', true );
        if ( $version !== (int) ( $payload['ver'] ?? 0 ) ) {
            return tnm_json_error( 'revoked_token', 'Authentication token has been revoked.', 401 );
        }
        return $payload;
    }

    public static function revoke_all( int $user_id ): void {
        $version = (int) get_user_meta( $user_id, 'tnm_token_version', true );
        update_user_meta( $user_id, 'tnm_token_version', $version + 1 );
    }
}
