<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Auth;

use Esanj\RemoteEloquent\Contracts\AccessTokenProviderInterface;
use Esanj\RemoteEloquent\DTOs\TokenData;
use Esanj\RemoteEloquent\Exceptions\TokenRequestException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Issues and caches the OAuth 2.0 access token used for every remote query.
 *
 * The token is cached (keyed by client id + scope) until shortly before it
 * expires, so a token endpoint round-trip does not happen on every query. When
 * the cached token is expiring it is refreshed automatically — through the
 * refresh_token grant if the server issued a refresh token, otherwise by
 * requesting a fresh client_credentials token.
 */
final class AccessTokenManager implements AccessTokenProviderInterface
{
    /**
     * @param  array<string, mixed>  $config  The config('esanj.remote_eloquent.auth') array.
     */
    public function __construct(
        private readonly array $config,
    ) {}

    public function getAccessToken(bool $forceFresh = false): string
    {
        return $this->getToken($forceFresh)->accessToken;
    }

    public function getAuthorizationHeader(bool $forceFresh = false): string
    {
        return $this->getToken($forceFresh)->getAuthorizationHeader();
    }

    public function forget(): void
    {
        $this->cache()->forget($this->cacheKey());
    }

    /**
     * @throws TokenRequestException
     */
    private function getToken(bool $forceFresh): TokenData
    {
        if (! $forceFresh) {
            $cached = $this->cache()->get($this->cacheKey());

            if ($cached instanceof TokenData && ! $cached->isExpiring($this->buffer())) {
                return $cached;
            }

            if ($cached instanceof TokenData && $cached->hasRefreshToken()) {
                try {
                    return $this->store($this->requestRefresh((string) $cached->refreshToken));
                } catch (TokenRequestException) {
                    // Refresh token no longer valid — fall through to a fresh grant.
                }
            }
        }

        return $this->store($this->requestClientCredentials());
    }

    /**
     * @throws TokenRequestException
     */
    private function requestClientCredentials(): TokenData
    {
        return $this->requestToken([
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'scope' => (string) ($this->config['scope'] ?? '*'),
        ]);
    }

    /**
     * @throws TokenRequestException
     */
    private function requestRefresh(string $refreshToken): TokenData
    {
        return $this->requestToken([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'scope' => (string) ($this->config['scope'] ?? '*'),
        ]);
    }

    /**
     * @param  array<string, string>  $payload
     *
     * @throws TokenRequestException
     */
    private function requestToken(array $payload): TokenData
    {
        $url = (string) ($this->config['token_url'] ?? '');

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw TokenRequestException::invalidTokenUrl($url);
        }

        try {
            $response = Http::asForm()->acceptJson()->post($url, $payload);
        } catch (ConnectionException $e) {
            throw TokenRequestException::connectionFailed($e->getMessage(), $e);
        }

        if ($response->failed()) {
            $error = $response->json('error_description', $response->json('error', 'Unknown error'));

            throw TokenRequestException::failed((string) $error, $response->status());
        }

        $token = TokenData::fromArray((array) $response->json());

        if ($token->accessToken === '') {
            throw TokenRequestException::malformedResponse();
        }

        return $token;
    }

    private function store(TokenData $token): TokenData
    {
        $ttl = max($token->secondsUntilExpiry() - $this->buffer(), 1);

        $this->cache()->put($this->cacheKey(), $token, $ttl);

        return $token;
    }

    private function cache(): CacheRepository
    {
        $store = $this->config['cache_store'] ?? null;

        return $store !== null ? Cache::store($store) : Cache::store();
    }

    private function cacheKey(): string
    {
        $prefix = (string) ($this->config['cache_prefix'] ?? 'remote_eloquent_token_');
        $identifier = $this->clientId().'|'.(string) ($this->config['scope'] ?? '*');

        return $prefix.hash('sha256', $identifier);
    }

    private function buffer(): int
    {
        return (int) ($this->config['cache_buffer_seconds'] ?? 60);
    }

    /**
     * @throws TokenRequestException
     */
    private function clientId(): string
    {
        $id = (string) ($this->config['client_id'] ?? '');

        if ($id === '') {
            throw TokenRequestException::missingCredentials();
        }

        return $id;
    }

    /**
     * @throws TokenRequestException
     */
    private function clientSecret(): string
    {
        $secret = (string) ($this->config['client_secret'] ?? '');

        if ($secret === '') {
            throw TokenRequestException::missingCredentials();
        }

        return $secret;
    }
}
