<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Auth;

use DomainException;
use Esanj\AuthBridge\Contracts\AuthBridgeServiceInterface;
use Esanj\RemoteEloquent\Exceptions\RemoteAuthenticationException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use InvalidArgumentException;
use LogicException;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Throwable;
use UnexpectedValueException;

final class AccountingGuard implements StatefulGuard
{
    private const DEFAULT_ALGORITHM = 'RS256';

    private const MAX_LEEWAY_SECONDS = 300;

    private const JWKS_TIMEOUT_SECONDS = 3.0;

    private const JWKS_CONNECT_TIMEOUT_SECONDS = 2.0;

    private ?Authenticatable $user = null;

    private bool $resolved = false;

    private bool $resolving = false;

    private ?Authenticatable $claimsUser = null;

    /** @var array<string, mixed>|null */
    private ?array $claims = null;

    private bool $claimsResolved = false;

    private ?string $token = null;

    private bool $loggedOut = false;

    /** @var array<string, Key>|null */
    private ?array $jwks = null;

    private const JWKS_REFETCH_SECONDS = 30;

    /** @var array<string, int> */
    private static array $lastRefetch = [];

    private ?string $publicKeyMaterial = null;

    /**
     * @param string $name the guard name, as config/auth.php calls it
     * @param string $input 'session' (auth-bridge) or 'bearer' (Authorization header)
     * @param list<string> $audiences accepted "aud" values; a token is refused while this is empty
     * @param list<string> $authorizedParties accepted "azp"/"client_id"; [] skips the check
     * @param list<string> $requiredScopes scopes the token must carry; [] skips the check
     * @param array<string, mixed> $meOptions fields/include for users/me
     */
    public function __construct(
        private readonly string                      $name,
        private RemoteUserProvider                   $provider,
        private readonly HttpFactory                 $http,
        private ?Request                             $request = null,
        private readonly ?AuthBridgeServiceInterface $bridge = null,
        private readonly ?CacheRepository            $cache = null,
        private readonly ?LoggerInterface            $logger = null,
        private readonly string                      $input = 'session',
        private readonly string                      $algorithm = self::DEFAULT_ALGORITHM,
        private readonly ?string                     $publicKey = null,
        private readonly ?string                     $publicKeyPath = null,
        private readonly ?string                     $jwksUrl = null,
        private readonly int                         $jwksTtl = 3600,
        private readonly array                       $audiences = [],
        private readonly ?string                     $issuer = null,
        private readonly array                       $authorizedParties = [],
        private readonly array                       $requiredScopes = [],
        private readonly int                         $leeway = 30,
        private readonly int                         $userTtl = 0,
        private readonly array                       $meOptions = [],
        private readonly string                      $cachePrefix = 'esanj:remote_eloquent:',
    )
    {
        // Asymmetric only: an HS* algorithm would accept a token signed with the public key as its secret.
        if (preg_match('/^(RS|PS)(256|384|512)$|^ES(256|384|512)$/', $this->algorithm) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'The "%s" guard\'s algorithm must be RS256/384/512, PS256/384/512 or ES256/384/512; "%s" given.',
                $this->name,
                $this->algorithm,
            ));
        }
    }

    public function name(): string
    {
        return $this->name;
    }

    public function check(): bool
    {
        if ($this->user !== null) {
            return true;
        }

        return $this->subject() !== null;
    }

    public function guest(): bool
    {
        return !$this->check();
    }

    /**
     * @return int|string|null
     */
    public function id()
    {
        if ($this->user !== null) {
            return $this->user->getAuthIdentifier();
        }

        return $this->subject();
    }

    public function hasUser(): bool
    {
        return $this->user !== null;
    }

    public function claims(): ?array
    {
        return $this->verifiedClaims();
    }

    public function user(): ?Authenticatable
    {
        if ($this->user !== null) {
            return $this->user;
        }

        if ($this->resolving) {
            return $this->claimsUser;
        }

        if ($this->resolved) {
            return null;
        }

        $subject = $this->subject();

        if ($subject === null) {
            $this->resolved = true;

            return null;
        }

        $this->resolving = true;
        $this->claimsUser = $this->provider->hydrate([
            $this->provider->createModel()->getKeyName() => $subject,
        ]);

        try {
            $user = $this->cachedUser() ?? $this->fetchUser();
        } finally {
            $this->resolving = false;
            $this->claimsUser = null;
            // A failed resolve is remembered for the request instead of costing another round trip per call.
            $this->resolved = true;
        }

        if ($user !== null) {
            $this->assertResolvedSubject($user, $subject);
        }

        return $this->user = $user;
    }

    /**
     * @throws AuthenticationException
     */
    public function authenticate(): Authenticatable
    {
        return $this->user() ?? throw new AuthenticationException(guards: [$this->name]);
    }

    public function validate(#[SensitiveParameter] array $credentials = []): never
    {
        throw $this->credentialsAreNotOurs('validate');
    }

    public function attempt(#[SensitiveParameter] array $credentials = [], $remember = false): never
    {
        throw $this->credentialsAreNotOurs('attempt');
    }

    public function once(#[SensitiveParameter] array $credentials = []): never
    {
        throw $this->credentialsAreNotOurs('once');
    }

    public function login(Authenticatable $user, $remember = false): void
    {
        $this->setUser($user);
    }

    public function loginUsingId($id, $remember = false)
    {
        $user = $this->provider->retrieveById($id);

        if ($user === null) {
            return false;
        }

        $this->login($user, $remember);

        return $user;
    }

    public function onceUsingId($id)
    {
        return $this->loginUsingId($id);
    }

    public function viaRemember(): bool
    {
        return false;
    }

    public function logout(): void
    {
        if ($this->userTtl > 0 && $this->cache !== null) {
            $token = $this->resolveToken();

            if ($token !== null) {
                $this->cache->forget($this->userCacheKey($token));
            }
        }

        try {
            $this->bridge?->revokeToken();
        } finally {
            $this->forgetUser();
            $this->loggedOut = true;
        }
    }

    public function setUser(Authenticatable $user): static
    {
        $this->user = $user;
        $this->resolved = true;
        $this->loggedOut = false;

        return $this;
    }

    public function forgetUser(): static
    {
        $this->user = null;
        $this->resolved = false;
        $this->claims = null;
        $this->claimsResolved = false;
        $this->token = null;

        return $this;
    }

    public function getProvider(): UserProvider
    {
        return $this->provider;
    }

    public function setProvider(UserProvider $provider): void
    {
        if (!$provider instanceof RemoteUserProvider) {
            throw new InvalidArgumentException(sprintf(
                'The "%s" guard needs a %s; got %s. Point auth.guards.%s.provider at a provider with driver "remote".',
                $this->name,
                RemoteUserProvider::class,
                $provider::class,
                $this->name,
            ));
        }

        $this->provider = $provider;
    }

    public function setRequest(?Request $request): static
    {
        $this->request = $request;

        return $this;
    }

    public function getRequest(): ?Request
    {
        return $this->request;
    }

    private function resolveToken(): ?string
    {
        if ($this->loggedOut) {
            return null;
        }

        if ($this->token !== null) {
            return $this->token;
        }

        $token = strtolower($this->input) === 'bearer'
            ? $this->request?->bearerToken()
            : $this->sessionToken();

        $token = is_string($token) ? trim($token) : '';

        return $this->token = $token === '' ? null : $token;
    }

    private function sessionToken(): ?string
    {
        if ($this->bridge === null) {
            throw new RemoteAuthenticationException(
                sprintf(
                    'The "%s" guard reads the signed-in user\'s token from esanj/auth-bridge, which is not installed '
                    . 'or not bound. Install and configure it, or set the guard\'s "input" to "bearer" to read the '
                    . 'token from the Authorization header instead.',
                    $this->name,
                ),
                'unauthenticated',
                0,
                null,
                ['guard' => $this->name, 'input' => $this->input],
            );
        }

        return $this->bridge->getValidAccessToken();
    }

    private function subject()
    {
        $claims = $this->verifiedClaims();
        $subject = $claims['sub'] ?? null;

        if (!is_scalar($subject) || (string)$subject === '') {
            return null;
        }

        $subject = (string)$subject;

        if ($this->provider->createModel()->getKeyType() !== 'int') {
            return $subject;
        }

        // A client-credentials token names the client in "sub"; that is an application, never a signed-in user.
        return ctype_digit($subject) ? (int)$subject : null;
    }

    private function verifiedClaims(): ?array
    {
        if ($this->claimsResolved) {
            return $this->claims;
        }

        $this->claimsResolved = true;

        $token = $this->resolveToken();

        return $this->claims = $token === null ? null : $this->verify($token);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function verify(#[SensitiveParameter] string $token): ?array
    {
        if (!class_exists(JWT::class)) {
            throw new RemoteAuthenticationException(
                'The accounting guard verifies the signed-in user\'s token locally, and firebase/php-jwt is not '
                . 'installed. composer require firebase/php-jwt — esanj/auth-bridge already depends on it.',
                'unauthenticated',
                0,
                null,
                ['guard' => $this->name],
            );
        }

        $header = $this->header($token);

        if ($header === null) {
            return $this->reject('the token is not a JWT');
        }

        $algorithm = $header['alg'] ?? null;

        if ($algorithm !== $this->algorithm) {
            return $this->reject('the token is signed with an unexpected algorithm', [
                'alg' => is_string($algorithm) ? $algorithm : null,
                'expected' => $this->algorithm,
            ]);
        }

        $kid = isset($header['kid']) && is_scalar($header['kid']) ? (string)$header['kid'] : null;
        $key = $this->key($kid);

        if ($key === null) {
            return $this->reject('no verification key matches the token', ['kid' => $kid]);
        }

        $previousLeeway = JWT::$leeway;
        JWT::$leeway = max(0, min($this->leeway, self::MAX_LEEWAY_SECONDS));

        try {
            $payload = JWT::decode($token, $key);
        } catch (UnexpectedValueException|DomainException|InvalidArgumentException $e) {
            return $this->reject('the token did not verify', ['reason' => $e->getMessage()]);
        } finally {
            JWT::$leeway = $previousLeeway;
        }

        /** @var array<string, mixed> $claims */
        $claims = json_decode(json_encode($payload) ?: '{}', true) ?: [];

        return $this->claimsAreAcceptable($claims) ? $claims : null;
    }

    private function header(#[SensitiveParameter] string $token): ?array
    {
        $segments = explode('.', $token);

        if (count($segments) !== 3) {
            return null;
        }

        $encoded = strtr($segments[0], '-_', '+/');
        $padding = strlen($encoded) % 4;

        if ($padding > 0) {
            $encoded .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($encoded, true);

        if ($decoded === false) {
            return null;
        }

        $header = json_decode($decoded, true);

        return is_array($header) ? $header : null;
    }

    private function claimsAreAcceptable(array $claims): bool
    {
        if ($this->audiences === []) {
            throw new RemoteAuthenticationException(
                sprintf(
                    'The "%s" guard has no audience to check tokens against, so it would accept a token issued to any '
                    . 'client. Set esanj.remote_eloquent.auth.client_id, esanj.auth_bridge.expected_audiences or the '
                    . 'guard\'s "audiences".',
                    $this->name,
                ),
                'unauthenticated',
                0,
                null,
                ['guard' => $this->name],
            );
        }

        if (!$this->claimMatches($claims['aud'] ?? null, $this->audiences)) {
            $this->reject('the token was issued for another audience', ['expected' => $this->audiences]);

            return false;
        }

        if ($this->issuer !== null && $this->issuer !== '' && ($claims['iss'] ?? null) !== $this->issuer) {
            $this->reject('the token was issued by another server', ['expected' => $this->issuer]);

            return false;
        }

        if ($this->authorizedParties !== []
            && !$this->claimMatches($claims['azp'] ?? $claims['client_id'] ?? null, $this->authorizedParties)) {
            $this->reject('the token was issued to another client', ['expected' => $this->authorizedParties]);

            return false;
        }

        $scopes = $this->scopes($claims);
        $missing = array_values(array_diff($this->requiredScopes, $scopes));

        if ($missing !== [] && !in_array('*', $scopes, true)) {
            $this->reject('the token is missing a required scope', ['missing' => $missing]);

            return false;
        }

        return true;
    }

    private function claimMatches(mixed $value, array $expected): bool
    {
        $values = array_map(
            static fn(mixed $one): string => (string)$one,
            array_filter(is_array($value) ? $value : [$value], 'is_scalar'),
        );

        return array_intersect($expected, $values) !== [];
    }

    private function scopes(array $claims): array
    {
        $scopes = $claims['scopes'] ?? $claims['scope'] ?? [];

        if (is_string($scopes)) {
            $scopes = preg_split('/\s+/', trim($scopes)) ?: [];
        }

        if (!is_array($scopes)) {
            return [];
        }

        return array_values(array_map(
            static fn(mixed $scope): string => (string)$scope,
            array_filter($scopes, 'is_scalar'),
        ));
    }

    private function reject(string $reason, array $context = []): null
    {
        $this->logger?->warning('Accounting guard: ' . $reason, $context + [
                'guard' => $this->name,
                'input' => $this->input,
            ]);

        return null;
    }

    private function key(?string $kid): ?Key
    {
        if ($this->jwksUrl === null || $this->jwksUrl === '') {
            return new Key($this->pinnedKey(), $this->algorithm);
        }

        $keys = $this->keySet();

        if ($kid === null) {
            return count($keys) === 1 ? $keys[(string)array_key_first($keys)] : null;
        }

        if (isset($keys[$kid])) {
            return $keys[$kid];
        }

        // An unknown kid from an anonymous caller must not turn into one JWKS request each.
        if (! $this->mayRefetchKeySet()) {
            return null;
        }

        $keys = $this->keySet(true);

        return $keys[$kid] ?? null;
    }

    private function mayRefetchKeySet(): bool
    {
        $key = $this->cachePrefix . 'jwks_refetch:' . sha1((string)$this->jwksUrl);

        if ($this->cache !== null) {
            try {
                return $this->cache->add($key, 1, self::JWKS_REFETCH_SECONDS);
            } catch (Throwable) {
                // Fall through to the per-process throttle.
            }
        }

        $last = self::$lastRefetch[$key] ?? 0;

        if (time() - $last < self::JWKS_REFETCH_SECONDS) {
            return false;
        }

        self::$lastRefetch[$key] = time();

        return true;
    }

    private function keySet(bool $fresh = false): array
    {
        if (!$fresh && $this->jwks !== null) {
            return $this->jwks;
        }

        $cacheKey = $this->cachePrefix . 'jwks:' . sha1((string)$this->jwksUrl);
        $cached = $fresh ? null : $this->cache?->get($cacheKey);

        if (is_array($cached)) {
            $parsed = $this->parseKeySet($cached);

            if ($parsed !== []) {
                return $this->jwks = $parsed;
            }
        }

        $document = $this->fetchKeySet();
        $parsed = $this->parseKeySet($document);

        if ($parsed === []) {
            throw RemoteAuthenticationException::tokenRequestFailed(
                sprintf('the JWKS at %s holds no %s key', $this->jwksUrl, $this->algorithm),
                ['guard' => $this->name, 'jwks_url' => $this->jwksUrl],
            );
        }

        if ($this->jwksTtl > 0) {
            $this->cache?->put($cacheKey, $document, $this->jwksTtl);
        }

        return $this->jwks = $parsed;
    }

    private function fetchKeySet(): array
    {
        try {
            $response = $this->http
                ->acceptJson()
                ->timeout(self::JWKS_TIMEOUT_SECONDS)
                ->connectTimeout(self::JWKS_CONNECT_TIMEOUT_SECONDS)
                ->get((string)$this->jwksUrl);
        } catch (Throwable $e) {
            throw RemoteAuthenticationException::tokenRequestFailed(
                'the JWKS endpoint could not be reached',
                ['guard' => $this->name, 'jwks_url' => $this->jwksUrl],
                $e,
            );
        }

        if (!$response->successful()) {
            throw RemoteAuthenticationException::tokenRequestFailed(
                sprintf('the JWKS endpoint answered %d', $response->status()),
                ['guard' => $this->name, 'jwks_url' => $this->jwksUrl, 'status' => $response->status()],
            );
        }

        $document = $response->json();

        if (!is_array($document) || !isset($document['keys'])) {
            throw RemoteAuthenticationException::tokenRequestFailed(
                'the JWKS endpoint did not answer with a key set',
                ['guard' => $this->name, 'jwks_url' => $this->jwksUrl],
            );
        }

        return $document;
    }

    private function parseKeySet(array $document): array
    {
        try {
            $keys = JWK::parseKeySet($document, $this->algorithm);
        } catch (UnexpectedValueException|DomainException|InvalidArgumentException $e) {
            $this->reject('the JWKS could not be parsed', ['reason' => $e->getMessage()]);

            return [];
        }

        return array_filter($keys, fn(Key $key): bool => $key->getAlgorithm() === $this->algorithm);
    }

    private function pinnedKey(): string
    {
        if ($this->publicKeyMaterial !== null) {
            return $this->publicKeyMaterial;
        }

        $inline = trim((string)$this->publicKey);

        if ($inline !== '') {
            if (!str_contains($inline, 'BEGIN PUBLIC KEY') && !str_contains($inline, 'BEGIN RSA PUBLIC KEY')) {
                throw new RemoteAuthenticationException(
                    'The accounting guard\'s public key is not a PEM block (esanj.auth_bridge.public_key).',
                    'unauthenticated',
                    0,
                    null,
                    ['guard' => $this->name],
                );
            }

            return $this->publicKeyMaterial = $inline;
        }

        $path = trim((string)$this->publicKeyPath);

        if ($path === '' || !is_readable($path)) {
            throw new RemoteAuthenticationException(
                sprintf(
                    'The "%s" guard has no key to verify the signed-in user\'s token with. Set '
                    . 'esanj.auth_bridge.public_key or public_key_path to the account service\'s public key, or give '
                    . 'the guard a jwks_url.%s',
                    $this->name,
                    $path === '' ? '' : sprintf(' The configured path (%s) is not readable.', $path),
                ),
                'unauthenticated',
                0,
                null,
                ['guard' => $this->name, 'public_key_path' => $path],
            );
        }

        $contents = file_get_contents($path);

        if ($contents === false || trim($contents) === '') {
            throw new RemoteAuthenticationException(
                sprintf('The accounting guard\'s public key file (%s) is empty or unreadable.', $path),
                'unauthenticated',
                0,
                null,
                ['guard' => $this->name, 'public_key_path' => $path],
            );
        }

        return $this->publicKeyMaterial = $contents;
    }

    private function fetchUser(): ?Authenticatable
    {
        $class = $this->provider->getModel();

        try {
            $response = $class::remoteTransport()
                ->withActor($this->claimsUser)
                ->me($this->meOptions + ['token' => $this->resolveToken()]);
        } catch (ModelNotFoundException) {
            return $this->reject('the token\'s subject no longer exists');
        } catch (RemoteAuthenticationException $exception) {
            if (!$exception->isUserTokenRejection()) {
                throw $exception;
            }

            return $this->forgetRejectedToken($exception);
        }

        $record = $response->record();

        if ($record === null) {
            return null;
        }

        $class::remoteIdentityMap()?->observe(
            $response->schemaVersion(),
            $response->permissionsVersion(),
        );

        $this->cacheUser($record);

        return $this->provider->hydrate($record);
    }

    private function forgetRejectedToken(RemoteAuthenticationException $exception): null
    {
        $this->claims = null;
        $this->claimsResolved = true;
        $this->token = null;

        if (strtolower($this->input) !== 'bearer') {
            $this->bridge?->clearToken();
        }

        return $this->reject('the account service rejected the signed-in user\'s token', [
            'reason' => $exception->context()['reason'] ?? null,
            'request_id' => $exception->requestId(),
        ]);
    }

    private function cachedUser(): ?Authenticatable
    {
        $token = $this->resolveToken();

        if ($this->userTtl <= 0 || $this->cache === null || $token === null) {
            return null;
        }

        $record = $this->cache->get($this->userCacheKey($token));

        return is_array($record) ? $this->provider->hydrate($record) : null;
    }

    private function cacheUser(array $record): void
    {
        $token = $this->resolveToken();

        if ($this->userTtl <= 0 || $this->cache === null || $token === null) {
            return;
        }

        $this->cache->put($this->userCacheKey($token), $record, $this->userTtl);
    }

    private function userCacheKey(#[SensitiveParameter] string $token): string
    {
        return $this->cachePrefix . 'guard:' . $this->name . ':user:' . hash('sha256', $token);
    }

    private function assertResolvedSubject(Authenticatable $user, $subject): void
    {
        $identifier = $user->getAuthIdentifier();

        if ((string)$identifier === (string)$subject) {
            return;
        }

        throw RemoteAuthenticationException::fromServer(
            'users/me answered with a different user than the signed-in token names',
            null,
            [
                'guard' => $this->name,
                'token_subject' => (string)$subject,
                'resolved' => (string)$identifier,
            ],
        );
    }

    private function credentialsAreNotOurs(string $method): LogicException
    {
        return new LogicException(sprintf(
            '%s::%s() cannot work: people sign in on the Accounting service, and the users resource publishes no '
            . 'password hash to compare against. Send them through the esanj/auth-bridge login route '
            . '(route "auth-bridge.redirect"); this guard reads the token that flow leaves in the session. '
            . 'In a test, use actingAs($user, "%s").',
            self::class,
            $method,
            $this->name,
        ));
    }
}
