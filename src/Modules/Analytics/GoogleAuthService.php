<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Modules\Analytics;

use BBAB\ServiceCenter\Utils\Cache;
use BBAB\ServiceCenter\Utils\Logger;

/**
 * Google OAuth2 Service Account Authentication.
 *
 * Generates access tokens using JWT (JSON Web Token) for Google APIs.
 * Tokens are cached for 55 minutes (they expire after 60).
 *
 * Migrated from: WPCode Snippet #2020
 *
 * Required constant:
 *   BBAB_GOOGLE_CREDENTIALS_PATH - Path to service account JSON file
 */
class GoogleAuthService {

    private const TOKEN_CACHE_SECONDS = 55 * MINUTE_IN_SECONDS;

    /**
     * Get an access token for the specified Google API scopes.
     *
     * @param array $scopes OAuth scopes needed (e.g., ['https://www.googleapis.com/auth/analytics.readonly'])
     * @return string|null Access token or null on failure
     */
    public static function getAccessToken(array $scopes): ?string {
        $credentials_path = self::getCredentialsPath();

        if (empty($credentials_path) || !file_exists($credentials_path)) {
            Logger::error('GoogleAuth', 'Credentials file not found', ['path' => $credentials_path]);
            return null;
        }

        // Cache key based on scopes
        $cache_key = 'google_token_' . md5(implode(',', $scopes));

        // Check cache first
        $cached_token = Cache::get($cache_key);
        if ($cached_token !== null) {
            return $cached_token;
        }

        // Load credentials
        $credentials_json = file_get_contents($credentials_path);
        if ($credentials_json === false) {
            Logger::error('GoogleAuth', 'Failed to read credentials file');
            return null;
        }

        $credentials = json_decode($credentials_json, true);
        if (!$credentials || empty($credentials['private_key'])) {
            Logger::error('GoogleAuth', 'Invalid credentials file - missing private_key');
            return null;
        }

        // Generate JWT and exchange for access token
        $token = self::generateAndExchangeJWT($credentials, $scopes);

        if ($token) {
            Cache::set($cache_key, $token, self::TOKEN_CACHE_SECONDS);
        }

        return $token;
    }

    /**
     * Generate a JWT and exchange it for an access token.
     *
     * @param array $credentials Service account credentials
     * @param array $scopes      OAuth scopes
     * @return string|null Access token or null on failure
     */
    private static function generateAndExchangeJWT(array $credentials, array $scopes): ?string {
        $now = time();
        $expiry = $now + 3600; // 1 hour

        // JWT Header
        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT'
        ];

        // JWT Claim Set
        $claim = [
            'iss' => $credentials['client_email'],
            'scope' => implode(' ', $scopes),
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $expiry
        ];

        // Base64url encode header and claim
        $base64_header = self::base64UrlEncode(json_encode($header));
        $base64_claim = self::base64UrlEncode(json_encode($claim));

        // Create signature
        $signature_input = $base64_header . '.' . $base64_claim;
        $signature = '';

        $private_key = openssl_pkey_get_private($credentials['private_key']);
        if (!$private_key) {
            Logger::error('GoogleAuth', 'Failed to parse private key', [
                'error' => openssl_error_string()
            ]);
            return null;
        }

        if (!openssl_sign($signature_input, $signature, $private_key, OPENSSL_ALGO_SHA256)) {
            Logger::error('GoogleAuth', 'Failed to sign JWT', [
                'error' => openssl_error_string()
            ]);
            return null;
        }

        $jwt = $signature_input . '.' . self::base64UrlEncode($signature);

        // Exchange JWT for access token
        return self::exchangeJWTForToken($jwt);
    }

    /**
     * Exchange a JWT assertion for an access token.
     *
     * @param string $jwt The signed JWT
     * @return string|null Access token or null on failure
     */
    private static function exchangeJWTForToken(string $jwt): ?string {
        $response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'timeout' => 30,
            'body' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt
            ]
        ]);

        if (is_wp_error($response)) {
            Logger::error('GoogleAuth', 'Token request failed', [
                'error' => $response->get_error_message()
            ]);
            return null;
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($http_code !== 200 || empty($body['access_token'])) {
            Logger::error('GoogleAuth', 'Token exchange failed', [
                'http_code' => $http_code,
                'response' => $body
            ]);
            return null;
        }

        Logger::debug('GoogleAuth', 'Successfully obtained access token');

        return $body['access_token'];
    }

    /**
     * Base64url encode (URL-safe base64).
     *
     * @param string $data Data to encode
     * @return string Encoded string
     */
    private static function base64UrlEncode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Clear cached tokens (useful for debugging or forced refresh).
     */
    public static function clearTokenCache(): void {
        Cache::flushPattern('google_token_');
        Logger::debug('GoogleAuth', 'Token cache cleared');
    }

    /**
     * Get the credentials file path based on environment.
     *
     * Checks in order:
     * 1. BBAB_GOOGLE_CREDENTIALS_PATH_LOCAL (for local dev override)
     * 2. BBAB_GOOGLE_CREDENTIALS_PATH (production default)
     *
     * @return string The credentials file path
     */
    private static function getCredentialsPath(): string {
        // Check for local dev override first (useful for Windows dev environments)
        if (defined('BBAB_GOOGLE_CREDENTIALS_PATH_LOCAL') && BBAB_GOOGLE_CREDENTIALS_PATH_LOCAL) {
            return BBAB_GOOGLE_CREDENTIALS_PATH_LOCAL;
        }

        // Fall back to production path
        if (defined('BBAB_GOOGLE_CREDENTIALS_PATH')) {
            return BBAB_GOOGLE_CREDENTIALS_PATH;
        }

        return '';
    }
}
