<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Auth;

use Closure;
use Esanj\AuthBridge\Contracts\AuthBridgeServiceInterface;
use Esanj\RemoteEloquent\Contracts\ActorTokenProvider;
use Esanj\RemoteEloquent\Exceptions\RemoteAuthenticationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use SensitiveParameter;
use Throwable;

final class ActorTokenManager implements ActorTokenProvider
{
    private const GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:token-exchange';

    private const SUBJECT_TOKEN_TYPE = 'urn:ietf:params:oauth:token-type:access_token';

    private const EXPIRY_BUFFER_SECONDS = 5;

    /** @var array<string, array{token: string, expires_at: int}> */
    private array $memo = [];

    private ?string $pinnedSubjectToken = null;

    private bool $subjectTokenIsPinned = false;

    public function __construct(
        private readonly HttpFactory                 $http,
        private readonly ?CacheRepository            $cache = null,
        private readonly AuthBridgeServiceInterface|Closure|null $bridge = null,
        private readonly ?string                     $exchangeUrl = null,
        private readonly ?string                     $clientId = null,
        private readonly ?string                     $clientSecret = null,
        private readonly string                      $tokenType = 'Bearer',
        private readonly int                         $ttl = 60,
        private readonly string                      $scope = '',
        private readonly string                      $audience = '',
        private readonly string                      $cachePrefix = 'esanj:remote_eloquent:',
        private readonly float                       $timeout = 5.0,
        private readonly float                       $connectTimeout = 2.0,
    )
    {
    }

    public function tokenType(): string
    {
        return $this->tokenType === '' ? 'Bearer' : $this->tokenType;
    }

    /**
     * The signed-in user's own access token, or null when there is none.
     */
    public function userToken(): ?string
    {
        try {
            $token = $this->subjectTokenIsPinned ? $this->pinnedSubjectToken : $this->bridge()?->getValidAccessToken();
        } catch (Throwable) {
            return null;
        }

        return is_string($token) && trim($token) !== '' ? trim($token) : null;
    }

    /**
     * Resolved per call: the bridge is request-scoped and this manager is not.
     */
    private function bridge(): ?AuthBridgeServiceInterface
    {
        $bridge = $this->bridge instanceof Closure ? ($this->bridge)() : $this->bridge;

        return $bridge instanceof AuthBridgeServiceInterface ? $bridge : null;
    }

    /**
     * @throws RemoteAuthenticationException
     */
    public function actorTokenFor(?Authenticatable $user): ?string
    {
        if ($user === null) {
            return null;
        }

        if ($this->exchangeUrl === null || trim($this->exchangeUrl) === '') {
            return null;
        }

        $identifier = $user->getAuthIdentifier();

        if ($identifier === null || (string)$identifier === '') {
            throw RemoteAuthenticationException::actorExchangeFailed(
                'the actor has no identifier to exchange a token for',
                ['actor' => $user::class],
            );
        }

        $subject = $this->subjectToken();
        $this->assertSubjectIs($subject, (string)$identifier);

        $cacheKey = $this->cacheKey($subject, (string)$identifier);
        $cached = $this->cachedToken($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        return $this->exchange($subject, $cacheKey, (string)$identifier);
    }

    public function withSubjectToken(#[SensitiveParameter] ?string $token): self
    {
        $copy = new self(
            $this->http,
            $this->cache,
            $this->bridge,
            $this->exchangeUrl,
            $this->clientId,
            $this->clientSecret,
            $this->tokenType,
            $this->ttl,
            $this->scope,
            $this->audience,
            $this->cachePrefix,
            $this->timeout,
            $this->connectTimeout,
        );

        $copy->pinnedSubjectToken = $token === null || trim($token) === '' ? null : trim($token);
        $copy->subjectTokenIsPinned = true;

        return $copy;
    }

    public function forget(?Authenticatable $user): void
    {
        $identifier = $user?->getAuthIdentifier();

        if ($identifier === null || (string)$identifier === '') {
            return;
        }

        $subject = $this->subjectTokenIsPinned
            ? $this->pinnedSubjectToken
            : $this->bridge()?->getValidAccessToken();

        if (!is_string($subject) || $subject === '') {
            return;
        }

        $key = $this->cacheKey($subject, (string)$identifier);

        unset($this->memo[$key]);
        $this->cache?->forget($key);
    }

    /**
     * @throws RemoteAuthenticationException
     */
    private function subjectToken(): string
    {
        $token = $this->subjectTokenIsPinned
            ? $this->pinnedSubjectToken
            : $this->bridge()?->getValidAccessToken();

        $token = is_string($token) ? trim($token) : '';

        if ($token === '') {
            throw RemoteAuthenticationException::actorExchangeFailed(
                'there is no signed-in user token to exchange. A write that speaks for a person has to carry that '
                . 'person: run it inside the request that signed them in, or pass the actor token the job was '
                . 'dispatched with to withSubjectToken(). Leave the actor unset to write as the application alone.',
                ['exchange_url' => $this->exchangeUrl],
            );
        }

        return $token;
    }

    private function assertSubjectIs(#[SensitiveParameter] string $subject, string $identifier): void
    {
        $sub = $this->subjectClaim($subject);

        if ($sub === null || $sub === $identifier) {
            return;
        }

        throw RemoteAuthenticationException::actorExchangeFailed(
            'the signed-in user is not the actor this write names',
            ['actor' => $identifier, 'subject' => $sub],
        );
    }

    private function subjectClaim(#[SensitiveParameter] string $token): ?string
    {
        $segments = explode('.', $token);

        if (count($segments) !== 3) {
            return null;
        }

        $encoded = strtr($segments[1], '-_', '+/');
        $padding = strlen($encoded) % 4;

        if ($padding > 0) {
            $encoded .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($encoded, true);

        if ($decoded === false) {
            return null;
        }

        $claims = json_decode($decoded, true);
        $sub = is_array($claims) ? ($claims['sub'] ?? null) : null;

        return is_scalar($sub) && (string)$sub !== '' ? (string)$sub : null;
    }

    private function cachedToken(string $cacheKey): ?string
    {
        $now = time();
        $entry = $this->memo[$cacheKey] ?? $this->cache?->get($cacheKey);

        if (!is_array($entry)) {
            return null;
        }

        $token = $entry['token'] ?? null;
        $expiresAt = (int)($entry['expires_at'] ?? 0);

        if (!is_string($token) || $token === '' || $expiresAt <= $now) {
            unset($this->memo[$cacheKey]);

            return null;
        }

        $this->memo[$cacheKey] = ['token' => $token, 'expires_at' => $expiresAt];

        return $token;
    }

    /**
     * @throws RemoteAuthenticationException
     */
    private function exchange(#[SensitiveParameter] string $subject, string $cacheKey, string $identifier): string
    {
        $url = (string)$this->exchangeUrl;

        $form = [
            'grant_type' => self::GRANT_TYPE,
            'subject_token' => $subject,
            'subject_token_type' => self::SUBJECT_TOKEN_TYPE,
            'client_id' => $this->requireSetting($this->clientId, 'auth.client_id'),
            'client_secret' => $this->requireSetting($this->clientSecret, 'auth.client_secret'),
        ];

        if ($this->scope !== '') {
            $form['scope'] = $this->scope;
        }

        if ($this->audience !== '') {
            $form['audience'] = $this->audience;
        }

        try {
            $response = $this->http
                ->asForm()->withoutRedirecting()
                ->acceptJson()
                ->timeout($this->timeout)
                ->connectTimeout($this->connectTimeout)
                ->post($url, $form);
        } catch (Throwable $e) {
            throw RemoteAuthenticationException::actorExchangeFailed(
                'the authorization server could not be reached',
                ['exchange_url' => $url, 'actor' => $identifier],
                $e,
            );
        }

        if (!$response->successful()) {
            throw RemoteAuthenticationException::actorExchangeFailed(
                sprintf('the authorization server answered %d', $response->status()),
                [
                    'exchange_url' => $url,
                    'actor' => $identifier,
                    'status' => $response->status(),
                    'oauth_error' => $this->oauthError($response->json()),
                ],
            );
        }

        $payload = $response->json();
        $token = is_array($payload) ? ($payload['access_token'] ?? null) : null;

        if (!is_string($token) || $token === '') {
            throw RemoteAuthenticationException::actorExchangeFailed(
                'the authorization server returned no access_token',
                ['exchange_url' => $url, 'actor' => $identifier, 'status' => $response->status()],
            );
        }

        $this->remember($cacheKey, $token, (int)(is_array($payload) ? ($payload['expires_in'] ?? 0) : 0));

        return $token;
    }

    private function remember(string $cacheKey, #[SensitiveParameter] string $token, int $expiresIn): void
    {
        $lifetime = $expiresIn > 0 ? min($expiresIn, max(1, $this->ttl)) : max(1, $this->ttl);
        $usable = $lifetime - self::EXPIRY_BUFFER_SECONDS;

        if ($usable < 1) {
            return;
        }

        $now = time();

        $this->memo = array_filter($this->memo, static fn (array $held): bool => $held['expires_at'] > $now);

        $entry = ['token' => $token, 'expires_at' => $now + $usable];

        $this->memo[$cacheKey] = $entry;
        $this->cache?->put($cacheKey, $entry, $usable);
    }

    private function cacheKey(#[SensitiveParameter] string $subject, string $identifier): string
    {
        return $this->cachePrefix . 'actor:' . hash('sha256', $subject) . ':' . $identifier;
    }

    /**
     * @throws RemoteAuthenticationException
     */
    private function requireSetting(?string $value, string $setting): string
    {
        $value = $value !== null ? trim($value) : '';

        if ($value === '') {
            throw RemoteAuthenticationException::missingCredentials($setting, [
                'exchange_url' => $this->exchangeUrl,
            ]);
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
