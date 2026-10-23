<?php

declare(strict_types=1);

namespace App\Lib;

use App\Models\Session as SessionModel;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Shopify\Auth\OAuth;
use Shopify\Context;
use Shopify\Utils;

/**
 * Keeps the shop's offline access token valid, using Shopify's expiring offline tokens.
 *
 * Shopify stops accepting non-expiring offline tokens for public apps on January 1, 2027. Expiring tokens are valid for
 * 1 hour and are renewed with a refresh token (valid up to 90 days, rotated on every refresh).
 *
 * - Existing non-expiring tokens are migrated with token exchange (the old token stops working once migrated).
 * - Expiring tokens are refreshed shortly before they expire.
 * - When Shopify rejects the refresh token, the stored token is cleared so the merchant re-authorizes the app.
 *
 * Every write is conditional on the token that was used for the request, so a refresh or migration that overlaps a
 * re-installation can never overwrite the newer token.
 *
 * https://shopify.dev/docs/apps/build/authentication-authorization/migrate-to-expiring-offline-access-tokens
 */
class OfflineTokenManager
{
    /** Refresh this many seconds before the access token expires */
    private const REFRESH_MARGIN_SECONDS = 300;

    /** Back-off after Shopify refused a migration (4xx) */
    private const MIGRATION_RETRY_AFTER_REFUSAL_SECONDS = 3600;

    /** Back-off after a temporary migration failure (network, 5xx) */
    private const MIGRATION_RETRY_AFTER_ERROR_SECONDS = 60;

    private const TOKEN_TYPE_OFFLINE = 'urn:shopify:params:oauth:token-type:offline-access-token';

    private const GRANT_TYPE_TOKEN_EXCHANGE = 'urn:ietf:params:oauth:grant-type:token-exchange';

    /** Errors Shopify returns for a revoked, replaced or expired refresh token */
    private const REJECTED_REFRESH_TOKEN_ERRORS = ['invalid_request', 'invalid_grant', 'invalid_token'];

    /**
     * Returns a usable access token for the shop's offline session.
     *
     * @param bool $allowReauthorize false keeps the stored token even when Shopify rejects it (used during OAuth,
     *                               where clearing the token that was just issued would restart OAuth endlessly)
     *
     * @return array{token: string|null, reauthorize: bool}
     *   token: the access token to use, or null when none is available
     *   reauthorize: true when the merchant must re-authorize the app (the stored token was revoked or expired)
     */
    public static function accessToken(string $shop, bool $allowReauthorize = true): array
    {
        $shop = Utils::sanitizeShopDomain($shop);

        if ($shop === null) {
            return ['token' => null, 'reauthorize' => false];
        }

        $record = self::offlineRecord($shop);

        if (!$record || !$record->access_token) {
            return ['token' => null, 'reauthorize' => true];
        }

        // Fast path without locking: an expiring token that is still valid
        if ($record->refresh_token && !self::needsRefresh($record)) {
            return ['token' => $record->access_token, 'reauthorize' => false];
        }

        if (!$record->refresh_token && Cache::has(self::migrationRetryKey($shop))) {
            return ['token' => $record->access_token, 'reauthorize' => false];
        }

        try {
            // Only one request per shop may migrate or refresh at a time, because each refresh rotates the tokens
            return Cache::lock('shopify-offline-token:' . $shop, 30)->block(20, function () use ($shop, $allowReauthorize) {
                return self::renewIfNeeded($shop, $allowReauthorize);
            });
        } catch (LockTimeoutException $exception) {
            Log::warning('Timed out waiting for the offline token lock', ['shop' => $shop]);
        } catch (\Throwable $exception) {
            Log::error('Could not renew the offline access token', ['shop' => $shop, 'exception' => $exception]);
        }

        $record = self::offlineRecord($shop);
        $usable = $record && $record->access_token && !self::isExpired($record);

        return ['token' => $usable ? $record->access_token : null, 'reauthorize' => false];
    }

    /**
     * Converts the shop's non-expiring offline token into an expiring one right after installation.
     * Never clears the token, so a failure can't restart OAuth. Returns the token to use for the next API requests.
     */
    public static function migrateAfterInstall(string $shop): ?string
    {
        return self::accessToken($shop, false)['token'];
    }

    /**
     * Clears the shop's token when Shopify rejected it for an API request (e.g. non-expiring tokens after Shopify's
     * deadline), so the merchant re-authorizes the app. Only clears it if it is still the token that was rejected.
     */
    public static function invalidate(string $shop, string $rejectedToken): void
    {
        $shop = Utils::sanitizeShopDomain($shop);

        if ($shop === null) {
            return;
        }

        SessionModel::where('session_id', OAuth::getOfflineSessionId($shop))
            ->where('access_token', $rejectedToken)
            ->update(self::clearedColumns());
    }

    /**
     * Keeps the expiring-token data consistent when the library stores the offline session:
     * - OAuth::begin stores the session with a null token: keep the existing token, so starting OAuth (which anyone
     *   can trigger with ?shop=) doesn't log the shop out. The callback replaces it with the new token.
     * - A new token (e.g. after re-installation) invalidates the previous refresh token.
     */
    public static function prepareSessionRecord(SessionModel $record, bool $isOnline, ?string $newAccessToken): bool
    {
        if (!$record->exists) {
            return false;
        }

        if (!$isOnline && $newAccessToken === null && $record->access_token) {
            return true; // keep the existing token and its refresh data
        }

        if ($record->access_token !== $newAccessToken) {
            foreach (self::clearedColumns() as $column => $value) {
                if ($column !== 'access_token') {
                    $record->{$column} = $value;
                }
            }
        }

        return false;
    }

    private static function renewIfNeeded(string $shop, bool $allowReauthorize): array
    {
        // Re-read inside the lock: another request may have renewed the token in the meantime
        $record = self::offlineRecord($shop);

        if (!$record || !$record->access_token) {
            return ['token' => null, 'reauthorize' => true];
        }

        if (!$record->refresh_token) {
            return self::migrate($record, $allowReauthorize);
        }

        if (!self::needsRefresh($record)) {
            return ['token' => $record->access_token, 'reauthorize' => false];
        }

        return self::refresh($record, $allowReauthorize);
    }

    private static function migrate(SessionModel $record, bool $allowReauthorize): array
    {
        if (Cache::has(self::migrationRetryKey($record->shop))) {
            return ['token' => $record->access_token, 'reauthorize' => false];
        }

        $parameters = [
            'grant_type' => self::GRANT_TYPE_TOKEN_EXCHANGE,
            'client_id' => Context::$API_KEY,
            'client_secret' => Context::$API_SECRET_KEY,
            'subject_token' => $record->access_token,
            'subject_token_type' => self::TOKEN_TYPE_OFFLINE,
            'requested_token_type' => self::TOKEN_TYPE_OFFLINE,
            'expiring' => '1',
        ];

        $response = self::tokenRequest($record->shop, $parameters);

        // Shopify may have migrated the token even if the response was lost; repeating the same request returns the
        // same token pair (documented recovery), so retry once right away.
        if ($response === null || $response->serverError()) {
            $response = self::tokenRequest($record->shop, $parameters);
        }

        if ($response !== null && $response->successful()) {
            $stored = self::storeTokens($record, (array) $response->json(), 'access_token', $record->access_token);

            if ($stored !== null) {
                Log::info('Migrated the offline access token to an expiring token', ['shop' => $record->shop]);

                return ['token' => $stored, 'reauthorize' => false];
            }
        }

        // Shopify no longer accepts the stored token (e.g. it was revoked): the merchant has to re-authorize the app
        if ($response !== null && $response->status() === 400 && $response->json('error') === 'invalid_subject_token') {
            Log::warning('Shopify rejected the stored offline token during migration', [
                'shop' => $record->shop,
                'reauthorize' => $allowReauthorize,
            ]);

            if ($allowReauthorize) {
                self::clearTokens($record, 'access_token', $record->access_token);

                return ['token' => null, 'reauthorize' => true];
            }
        }

        // The non-expiring token keeps working until Shopify's deadline, so a failed migration must not break the
        // app. Retry later instead of on every request.
        $refused = $response !== null && $response->clientError();
        Cache::put(
            self::migrationRetryKey($record->shop),
            true,
            $refused ? self::MIGRATION_RETRY_AFTER_REFUSAL_SECONDS : self::MIGRATION_RETRY_AFTER_ERROR_SECONDS
        );

        Log::warning('Could not migrate the offline access token to an expiring token, will retry later', [
            'shop' => $record->shop,
            'status' => $response ? $response->status() : null,
            'error' => $response ? $response->json('error') : 'request failed',
        ]);

        return ['token' => self::currentToken($record->shop), 'reauthorize' => false];
    }

    private static function refresh(SessionModel $record, bool $allowReauthorize): array
    {
        $response = self::tokenRequest($record->shop, [
            'grant_type' => 'refresh_token',
            'client_id' => Context::$API_KEY,
            'client_secret' => Context::$API_SECRET_KEY,
            'refresh_token' => $record->refresh_token,
        ]);

        if ($response !== null && $response->successful()) {
            $stored = self::storeTokens($record, (array) $response->json(), 'refresh_token', $record->refresh_token);

            if ($stored !== null) {
                return ['token' => $stored, 'reauthorize' => false];
            }
        }

        // The refresh token was revoked, replaced or expired. The merchant has to open the app again, which runs OAuth
        // and stores a new token. Other errors (e.g. invalid_client after a configuration mistake, 5xx) are treated
        // as temporary, so merchants are never logged out by mistake.
        if ($response !== null && self::isRejectedRefreshToken($response)) {
            Log::warning('Shopify rejected the refresh token, the merchant has to re-authorize the app', [
                'shop' => $record->shop,
                'status' => $response->status(),
                'error' => $response->json('error'),
            ]);

            if ($allowReauthorize && self::clearTokens($record, 'refresh_token', $record->refresh_token)) {
                return ['token' => null, 'reauthorize' => true];
            }
        } else {
            Log::warning('Could not refresh the offline access token', [
                'shop' => $record->shop,
                'status' => $response ? $response->status() : null,
                'error' => $response ? $response->json('error') : 'request failed',
            ]);
        }

        // Keep using the current token while it is still valid (it may also have been renewed by a re-installation)
        $current = self::offlineRecord($record->shop);
        $usable = $current && $current->access_token && !self::isExpired($current);

        return ['token' => $usable ? $current->access_token : null, 'reauthorize' => false];
    }

    private static function isRejectedRefreshToken($response): bool
    {
        return in_array($response->status(), [400, 401], true)
            && in_array($response->json('error'), self::REJECTED_REFRESH_TOKEN_ERRORS, true);
    }

    /**
     * @return \Illuminate\Http\Client\Response|null null when the request could not be sent
     */
    private static function tokenRequest(string $shop, array $parameters)
    {
        try {
            return Http::asForm()
                ->acceptJson()
                ->timeout(20)
                ->post("https://{$shop}/admin/oauth/access_token", $parameters);
        } catch (\Throwable $exception) {
            Log::warning('Offline access token request failed', ['shop' => $shop, 'message' => $exception->getMessage()]);

            return null;
        }
    }

    /**
     * Stores the new token pair, but only if the record still has the token that was used for the request.
     *
     * @return string|null the access token to use, or null when the response was unusable
     */
    private static function storeTokens(SessionModel $record, array $data, string $matchColumn, string $matchValue): ?string
    {
        $accessToken = $data['access_token'] ?? null;
        $refreshToken = $data['refresh_token'] ?? null;
        $expiresIn = $data['expires_in'] ?? null;

        if (!is_string($accessToken) || $accessToken === '' || !is_string($refreshToken) || $refreshToken === ''
            || !is_numeric($expiresIn)) {
            Log::error('Unexpected offline access token response from Shopify', ['shop' => $record->shop]);
            return null;
        }

        $columns = [
            'access_token' => $accessToken,
            'access_token_expires_at' => Carbon::now()->addSeconds((int) $expiresIn)->toDateTimeString(),
            'refresh_token' => $refreshToken,
            'refresh_token_expires_at' => is_numeric($data['refresh_token_expires_in'] ?? null)
                ? Carbon::now()->addSeconds((int) $data['refresh_token_expires_in'])->toDateTimeString()
                : null,
        ];

        if (is_string($data['scope'] ?? null) && $data['scope'] !== '') {
            $columns['scope'] = $data['scope'];
        }

        $updated = SessionModel::where('id', $record->id)->where($matchColumn, $matchValue)->update($columns);

        if ($updated === 0) {
            // A re-installation stored a newer token in the meantime: that one wins
            Log::info('Offline token changed while renewing it, keeping the newer token', ['shop' => $record->shop]);

            return self::currentToken($record->shop);
        }

        return $accessToken;
    }

    /**
     * Clears the token, but only if the record still has the token that was rejected.
     */
    private static function clearTokens(SessionModel $record, string $matchColumn, string $matchValue): bool
    {
        return SessionModel::where('id', $record->id)
            ->where($matchColumn, $matchValue)
            ->update(self::clearedColumns()) > 0;
    }

    private static function clearedColumns(): array
    {
        return [
            'access_token' => null,
            'access_token_expires_at' => null,
            'refresh_token' => null,
            'refresh_token_expires_at' => null,
        ];
    }

    private static function offlineRecord(string $shop): ?SessionModel
    {
        return SessionModel::where('session_id', OAuth::getOfflineSessionId($shop))->first();
    }

    private static function currentToken(string $shop): ?string
    {
        $record = self::offlineRecord($shop);

        return $record && $record->access_token && !self::isExpired($record) ? $record->access_token : null;
    }

    private static function needsRefresh(SessionModel $record): bool
    {
        if (!$record->access_token_expires_at) {
            return true;
        }

        return Carbon::parse($record->access_token_expires_at)->subSeconds(self::REFRESH_MARGIN_SECONDS)->isPast();
    }

    private static function isExpired(SessionModel $record): bool
    {
        if (!$record->refresh_token) {
            return false; // non-expiring token
        }

        return !$record->access_token_expires_at || Carbon::parse($record->access_token_expires_at)->isPast();
    }

    private static function migrationRetryKey(string $shop): string
    {
        return 'shopify-offline-token-migration-retry:' . $shop;
    }
}
