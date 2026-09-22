<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Auth;

use Esanj\RemoteEloquent\Contracts\AccessTokenProvider;
use Esanj\RemoteEloquent\Exceptions\RemoteAuthenticationException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

final class AccessTokenManager implements AccessTokenProvider
{
    private ?string $token = null;

    private int $tokenExpiresAt = 0;

    /**
     * @param array<string, string> $headers extra headers for the token call
     */
    public function __construct(
        private readonly HttpFactory     $http,
        private readonly CacheRepository $cache,
        private readonly ?string         $tokenUrl,
        private readonly ?string         $clientId,
        private readonly ?string         $clientSecret,
        private readonly string          $scope = '',
        private readonly string          $cacheKey = 'esanj:remote_eloquent:token',
        private readonly int             $refreshBufferSeconds = 60,
        private readonly float           $timeout = 5.0,
        private readonly float           $connectTimeout = 2.0,
        private readonly array           $headers = [],
    )
    {
    }

    /**
     * @throws RemoteAuthenticationException
     */
    public function getAccessToken(bool $forceFresh = false): string
    {
        if (!$forceFresh) {
            $cached = $this->cachedToken();

            if ($cached !== null) {
                return $cached;
            }
        }

        return $this->issueToken();
    }

    public function forget(): void
    {
        $this->token = null;
        $this->tokenExpiresAt = 0;

        $this->cache->forget($this->cacheKey);
    }

    public function expiresAt(): int
    {
        return $this->tokenExpiresAt;
    }

    private function cachedToken(): ?string
    {
        $now = time();

        if ($this->token !== null && $this->tokenExpiresAt > $now) {
            return $this->token;
        }

        $entry = $this->cache->get($this->cacheKey);

        if (!is_array($entry)) {
            return null;
        }

        $token = $entry['token'] ?? null;
        $expiresAt = (int)($entry['expires_at'] ?? 0);

        if (!is_string($token) || $token === '' || $expiresAt <= $now) {
            return null;
        }

        $this->token = $token;
        $this->tokenExpiresAt = $expiresAt;

        return $token;
    }

    /**
     * @throws RemoteAuthenticationException
     */
    private function issueToken(): string
    {
        $url = $this->requireSetting($this->tokenUrl, 'auth.token_url');
        $clientId = $this->requireSetting($this->clientId, 'auth.client_id');
        $clientSecret = $this->requireSetting($this->clientSecret, 'auth.client_secret');

        $form = [
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ];

        if ($this->scope !== '') {
            $form['scope'] = $this->scope;
        }

        try {
            $response = $this->http
                ->asForm()->withoutRedirecting()
                ->acceptJson()
                ->withHeaders($this->headers)
                ->timeout($this->timeout)
                ->connectTimeout($this->connectTimeout)
                ->post($url, $form);
        } catch (ConnectionException $e) {
            throw RemoteAuthenticationException::tokenRequestFailed(
                'the authorization server could not be reached',
                ['token_url' => $url],
                $e
            );
        } catch (Throwable $e) {
            throw RemoteAuthenticationException::tokenRequestFailed(
                'the token request failed before it could be read',
                ['token_url' => $url],
                $e
            );
        }

        if (!$response->successful()) {
            throw RemoteAuthenticationException::tokenRequestFailed(
                sprintf('the authorization server answered %d', $response->status()),
                [
                    'token_url' => $url,
                    'status' => $response->status(),
                    'oauth_error' => $this->oauthError($response->json()),
                ]
            );
        }

        $payload = $response->json();

        if (!is_array($payload)) {
            throw RemoteAuthenticationException::tokenRequestFailed(
                'the authorization server did not answer with JSON',
                ['token_url' => $url, 'status' => $response->status()]
            );
        }

        $token = $payload['access_token'] ?? null;

        if (!is_string($token) || $token === '') {
            throw RemoteAuthenticationException::tokenRequestFailed(
                'the authorization server returned no access_token',
                ['token_url' => $url, 'status' => $response->status()]
            );
        }

        $this->remember($token, (int)($payload['expires_in'] ?? 0));

        return $token;
    }

    private function remember(string $token, int $expiresIn): void
    {
        $lifetime = $expiresIn > 0 ? $expiresIn : 60;
        $usable = $lifetime - max(0, $this->refreshBufferSeconds);

        $this->token = $token;
        $this->tokenExpiresAt = time() + max(1, $usable);

        if ($usable > 0) {
            $this->cache->put(
                $this->cacheKey,
                ['token' => $token, 'expires_at' => $this->tokenExpiresAt],
                $usable
            );

            return;
        }

        $this->cache->forget($this->cacheKey);
    }

    /**
     * @throws RemoteAuthenticationException
     */
    private function requireSetting(?string $value, string $setting): string
    {
        $value = $value !== null ? trim($value) : '';

        if ($value === '') {
            throw RemoteAuthenticationException::missingCredentials($setting);
        }

        return $value;
    }

    private function oauthError(mixed $body): ?string
    {
        if (is_array($body) && isset($body['error']) && is_string($body['error'])) {
            return $body['error'];
        }

        return null;
    }
}
