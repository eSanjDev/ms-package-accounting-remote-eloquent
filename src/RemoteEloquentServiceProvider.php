<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent;

use Esanj\AuthBridge\Contracts\AuthBridgeServiceInterface;
use Esanj\RemoteEloquent\Auth\AccessTokenManager;
use Esanj\RemoteEloquent\Auth\AccountingGuard;
use Esanj\RemoteEloquent\Auth\ActorTokenManager;
use Esanj\RemoteEloquent\Auth\RemoteUserProvider;
use Esanj\RemoteEloquent\Cache\IdentityMap;
use Esanj\RemoteEloquent\Console\RemoteAccessCommand;
use Esanj\RemoteEloquent\Console\RemoteDoctorCommand;
use Esanj\RemoteEloquent\Console\RemoteModelCommand;
use Esanj\RemoteEloquent\Console\RemoteSchemaCommand;
use Esanj\RemoteEloquent\Contracts\AccessTokenProvider;
use Esanj\RemoteEloquent\Contracts\ActorTokenProvider;
use Esanj\RemoteEloquent\Contracts\ResourceTransport;
use Esanj\RemoteEloquent\Database\ApiConnection;
use Esanj\RemoteEloquent\Exceptions\UnsupportedQueryException;
use Esanj\RemoteEloquent\Idempotency\OperationContext;
use Esanj\RemoteEloquent\Models\ApiModel;
use Esanj\RemoteEloquent\Models\ApiUser;
use Esanj\RemoteEloquent\Observability\RemoteCallCollector;
use Esanj\RemoteEloquent\Schema\SchemaRepository;
use Esanj\RemoteEloquent\Schema\SchemaValidator;
use Esanj\RemoteEloquent\Testing\FakeResourceTransport;
use Esanj\RemoteEloquent\Validation\RemotePresenceVerifier;
use Illuminate\Auth\AuthManager;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Queue\Job as QueuedJob;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\PresenceVerifierInterface;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

final class RemoteEloquentServiceProvider extends ServiceProvider
{
    public const CONFIG_KEY = 'esanj.remote_eloquent';

    public const CONNECTION = 'remote-eloquent';

    private const TRANSPORTS = [
        'rest' => 'Esanj\\RemoteEloquent\\Transport\\RestResourceTransport',
        'fake' => FakeResourceTransport::class,
        'array' => FakeResourceTransport::class,
    ];

    private const REST_CLIENT = 'Esanj\\RemoteEloquent\\Client\\ResourceClient';

    private const REST_CLIENT_ALIASES = [
        'Esanj\\RemoteEloquent\\Client\\ResourceClient',
        'Esanj\\RemoteEloquent\\Transport\\ResourceClient',
    ];

    private const DEFAULT_ALGORITHM = 'RS256';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/config/remote_eloquent.php', self::CONFIG_KEY);

        $this->registerConnection();
        $this->registerRequestState();
        $this->registerTokenProviders();
        $this->registerTransport();
        $this->registerSchema();
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/config/remote_eloquent.php' => config_path('esanj/remote_eloquent.php'),
        ], 'esanj-remote-eloquent-config');

        $this->registerAuthDrivers();
        $this->registerPresenceVerifier();
        $this->registerCommands();
        $this->bindIdempotencyScopeToJobs();
        $this->preventLazyLoadingOutsideProduction();
    }

    private function registerConnection(): void
    {
        Connection::resolverFor(
            self::CONNECTION,
            static function ($connection, $database, $prefix, $config): ApiConnection {
                return new ApiConnection((string)($config['name'] ?? self::CONNECTION));
            }
        );

        $config = $this->app->make(ConfigRepository::class);
        $key = 'database.connections.' . self::CONNECTION;

        if ($config->get($key) === null) {
            $config->set($key, [
                'driver' => self::CONNECTION,
                'database' => null,
                'prefix' => '',
            ]);
        }
    }

    private function registerRequestState(): void
    {
        $this->app->scoped(IdentityMap::class, function (Container $app): IdentityMap {
            return new IdentityMap((bool)$this->setting($app, 'cache.identity_map', true));
        });

        $this->app->scoped(OperationContext::class, static function (): OperationContext {
            return new OperationContext();
        });

        $this->app->scoped(RemoteCallCollector::class, function (Container $app): RemoteCallCollector {
            return new RemoteCallCollector(
                $this->logger($app),
                (int)$this->setting($app, 'telemetry.call_warning_threshold', 20),
            );
        });
    }

    private function registerTokenProviders(): void
    {
        $this->app->singleton(AccessTokenProvider::class, function (Container $app): AccessTokenProvider {
            $tokenUrl = $this->text($this->setting($app, 'auth.token_url'));
            $this->assertSecureUrl($app, $tokenUrl, 'auth.token_url');

            return new AccessTokenManager(
                $app->make(HttpFactory::class),
                $this->cache($app),
                $tokenUrl,
                $this->text($this->setting($app, 'auth.client_id')),
                $this->text($this->setting($app, 'auth.client_secret')),
                (string)($this->setting($app, 'auth.scope') ?? ''),
                (string)($this->setting($app, 'auth.cache_key') ?? 'esanj:remote_eloquent:token') . ':' . self::cacheScope($app->make(ConfigRepository::class)),
                (int)$this->setting($app, 'auth.refresh_buffer_seconds', 60),
                (float)$this->setting($app, 'rest.timeout', 5.0),
                (float)$this->setting($app, 'rest.connect_timeout', 2.0),
            );
        });

        $this->app->alias(AccessTokenProvider::class, AccessTokenManager::class);

        $this->app->singleton(ActorTokenProvider::class, function (Container $app): ActorTokenProvider {
            $exchangeUrl = $this->text($this->setting($app, 'actor.exchange_url'));
            $this->assertSecureUrl($app, $exchangeUrl, 'actor.exchange_url');

            return new ActorTokenManager(
                $app->make(HttpFactory::class),
                fn () => $this->bridge($app),
                $exchangeUrl,
                $this->text($this->setting($app, 'auth.client_id')),
                $this->text($this->setting($app, 'auth.client_secret')),
                (string)($this->setting($app, 'actor.token_type') ?? 'Bearer'),
                (int)$this->setting($app, 'actor.ttl', 60),
                (string)($this->setting($app, 'actor.scope') ?? ''),
                (string)($this->setting($app, 'actor.audience') ?? ''),
                $this->cachePrefix($app),
                (float)$this->setting($app, 'rest.timeout', 5.0),
                (float)$this->setting($app, 'rest.connect_timeout', 2.0),
            );
        });

        $this->app->alias(ActorTokenProvider::class, ActorTokenManager::class);
    }

    private function registerTransport(): void
    {
        $this->app->singleton(self::REST_CLIENT, function (Container $app): object {
            return $this->makeRestClient($app);
        });

        $this->app->scoped(ResourceTransport::class, function (Container $app): ResourceTransport {
            return $this->makeTransport($app);
        });
    }

    private function registerSchema(): void
    {
        $this->app->scoped(SchemaRepository::class, function (Container $app): SchemaRepository {
            return new SchemaRepository(
                $app->make(ResourceTransport::class),
                $this->cache($app),
                (int)$this->setting($app, 'cache.schema_ttl', 3600),
                $this->cachePrefix($app),
                $this->logger($app),
            );
        });

        $this->app->scoped(SchemaValidator::class, static function (Container $app): SchemaValidator {
            return new SchemaValidator($app->make(SchemaRepository::class));
        });
    }

    private function makeTransport(Container $app): ResourceTransport
    {
        $driver = (string)($this->setting($app, 'driver', 'rest') ?? 'rest');
        $class = self::TRANSPORTS[$driver] ?? null;

        if ($class === null) {
            throw UnsupportedQueryException::method(
                sprintf('the "%s" remote-eloquent transport', $driver),
                sprintf(
                    'Set REMOTE_ELOQUENT_DRIVER to one of: %s. Version 1\'s "grpc" driver shipped SQL over the wire and no longer exists.',
                    implode(', ', array_keys(self::TRANSPORTS))
                ),
                ['driver' => $driver],
            );
        }

        if ($class === FakeResourceTransport::class) {
            return new FakeResourceTransport();
        }

        if (!class_exists($class)) {
            throw UnsupportedQueryException::method(
                sprintf('the "%s" remote-eloquent transport', $driver),
                sprintf(
                    '%s is not installed. Bind %s yourself, or use the "fake" driver.',
                    $class,
                    ResourceTransport::class
                ),
                ['driver' => $driver, 'class' => $class],
            );
        }

        /** @var ResourceTransport $transport */
        $transport = $app->make($class, [
            'client' => $app->make(self::REST_CLIENT),
            'calls' => $app->make(RemoteCallCollector::class),
            'collector' => $app->make(RemoteCallCollector::class),
            'logger' => $this->logger($app),
        ]);

        return $transport;
    }

    private function makeRestClient(Container $app): object
    {
        $class = null;

        foreach (self::REST_CLIENT_ALIASES as $candidate) {
            if (class_exists($candidate)) {
                $class = $candidate;

                break;
            }
        }

        if ($class === null) {
            throw UnsupportedQueryException::method(
                'the REST resource client',
                sprintf(
                    '%s is not installed. Bind %s yourself, or use the "fake" driver.',
                    self::REST_CLIENT,
                    ResourceTransport::class
                ),
                ['class' => self::REST_CLIENT],
            );
        }

        $base = $this->text($this->setting($app, 'rest.base_url'));

        if ($base === null) {
            throw UnsupportedQueryException::method(
                'the REST resource client',
                'Set REMOTE_ELOQUENT_BASE_URL (or ACCOUNTING_BRIDGE_BASE_URL) to the account service, e.g. https://auth.esanj.io.',
                ['setting' => self::CONFIG_KEY . '.rest.base_url'],
            );
        }

        $this->assertSecureUrl($app, $base, 'rest.base_url');

        $headers = $this->setting($app, 'rest.headers', []);

        $parameters = [
            'http' => $app->make(HttpFactory::class),
            'accessTokens' => $app->make(AccessTokenProvider::class),
            'actorTokens' => $app->make(ActorTokenProvider::class),
            'baseUrl' => $base,
            'prefix' => (string)($this->setting($app, 'rest.prefix') ?? '/api/remote/v1'),
            'timeout' => (float)$this->setting($app, 'rest.timeout', 5.0),
            'connectTimeout' => (float)$this->setting($app, 'rest.connect_timeout', 2.0),
            'clientVersion' => (string)($this->setting($app, 'rest.client_version') ?? '2.0'),
            'headers' => is_array($headers) ? $headers : [],
            'calls' => $app->make(RemoteCallCollector::class),
            'logger' => $this->logger($app),
            // Request-scoped, so resolved per use.
            'schemas' => static fn (): SchemaRepository => $app->make(SchemaRepository::class),
        ];

        return $class === self::REST_CLIENT
            ? new $class(...$parameters)
            : $app->make($class, $parameters);
    }

    private function registerAuthDrivers(): void
    {
        if (!$this->app->bound('auth')) {
            return;
        }

        /** @var AuthManager $auth */
        $auth = $this->app->make('auth');

        $auth->provider('remote', static function ($app, array $config): RemoteUserProvider {
            return new RemoteUserProvider((string)($config['model'] ?? ApiUser::class));
        });

        // A method, not a closure: Laravel 13 rebinds extend() closures to the AuthManager.
        $auth->extend('accounting', $this->makeAccountingGuard(...));
    }

    private function makeAccountingGuard(Container $app, string $name, array $config): AccountingGuard
    {
        $provider = $app->make('auth')->createUserProvider($config['provider'] ?? null);

        if (!$provider instanceof RemoteUserProvider) {
            throw new InvalidArgumentException(sprintf(
                'The "%s" guard needs a "remote" user provider, got %s. Set auth.guards.%s.provider to a provider with "driver" => "remote".',
                $name,
                $provider === null ? 'none' : $provider::class,
                $name,
            ));
        }

        $jwksUrl = $this->text($config['jwks_url'] ?? null);
        $this->assertSecureUrl($app, $jwksUrl, 'auth.guards.' . $name . '.jwks_url');

        $guard = new AccountingGuard(
            $name,
            $provider,
            $app->make(HttpFactory::class),
            null,
            $this->bridge($app),
            $this->cache($app),
            $this->logger($app),
            (string)($config['input'] ?? 'session'),
            (string)($config['algorithm'] ?? self::DEFAULT_ALGORITHM),
            $this->text($this->bridgeSetting($app, 'public_key')),
            $this->text($this->bridgeSetting($app, 'public_key_path')),
            $jwksUrl,
            (int)($config['jwks_ttl'] ?? 3600),
            $this->audiences($app, $config),
            $this->text($config['issuer'] ?? $this->bridgeSetting($app, 'expected_issuer')),
            $this->list($config['authorized_parties'] ?? []),
            $this->list($config['scopes'] ?? []),
            (int)($config['leeway'] ?? 30),
            (int)($config['user_ttl'] ?? 0),
            is_array($config['me'] ?? null) ? $config['me'] : [],
            $this->cachePrefix($app),
        );

        $request = $app->refresh('request', $guard, 'setRequest');

        if ($request instanceof Request) {
            $guard->setRequest($request);
        }

        return $guard;
    }

    private function registerPresenceVerifier(): void
    {
        if (!$this->app->bound('validation.presence')) {
            return;
        }

        $this->app->extend(
            'validation.presence',
            function (PresenceVerifierInterface $local, Container $app): PresenceVerifierInterface {
                if ($local instanceof RemotePresenceVerifier) {
                    return $local;
                }

                // The transport and the schemas are request-scoped, so both are resolved per use.
                return new RemotePresenceVerifier(
                    static fn (): ResourceTransport => $app->make(ResourceTransport::class),
                    $local,
                    [self::CONNECTION],
                    (int)$this->setting($app, 'limits.max_query_limit', 100),
                    (int)$this->setting($app, 'limits.in_chunk', 500),
                    schemas: static fn (): SchemaRepository => $app->make(SchemaRepository::class),
                );
            }
        );

        if ($this->app->resolved('validator')) {
            $this->app->make('validator')->setPresenceVerifier($this->app->make('validation.presence'));
        }
    }

    private function registerCommands(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            RemoteSchemaCommand::class,
            RemoteAccessCommand::class,
            RemoteModelCommand::class,
            RemoteDoctorCommand::class,
        ]);
    }

    private function bindIdempotencyScopeToJobs(): void
    {
        if (!$this->app->bound('events')) {
            return;
        }

        $this->app->make('events')->listen(
            JobProcessing::class,
            function (JobProcessing $event): void {
                // A sync job runs inside its caller and keeps the caller's scope and actor.
                if ($event->job instanceof SyncJob) {
                    return;
                }

                // A job that failed before forgetRemoteActor() must not hand its actor to the next one.
                ApiModel::forgetRemoteActor();

                $job = $event->job;

                if (!$job instanceof QueuedJob) {
                    return;
                }

                $uuid = $job->uuid();

                if (!is_string($uuid) || $uuid === '') {
                    return;
                }

                try {
                    $operations = $this->container()->make(OperationContext::class);
                } catch (Throwable) {
                    return;
                }

                if ($operations instanceof OperationContext) {
                    $operations->useScope($uuid);
                }
            }
        );
    }

    private function preventLazyLoadingOutsideProduction(): void
    {
        if ($this->app->environment('production')) {
            return;
        }

        if (!(bool)$this->setting($this->container(), 'telemetry.prevent_lazy_loading', true)) {
            return;
        }

        $this->app->booted(static function (): void {
            if (Model::preventsLazyLoading()) {
                return;
            }

            Model::preventLazyLoading();

            Model::handleLazyLoadingViolationUsing(static function (Model $model, string $relation): void {
                if (!$model instanceof ApiModel) {
                    return;
                }

                if (!$model->exists || $model->wasRecentlyCreated) {
                    return;
                }

                throw new LazyLoadingViolationException($model, $relation);
            });
        });
    }

    private function container(): Container
    {
        /** @var Container $app */
        $app = $this->app;

        return $app;
    }

    private function setting(Container $app, string $key, mixed $default = null): mixed
    {
        return $app->make(ConfigRepository::class)->get(self::CONFIG_KEY . '.' . $key, $default);
    }

    private function bridgeSetting(Container $app, string $key): mixed
    {
        return $app->make(ConfigRepository::class)->get('esanj.auth_bridge.' . $key);
    }

    private function cache(Container $app): CacheRepository
    {
        $store = $this->text($this->setting($app, 'cache.store'));

        return $app->make(CacheFactory::class)->store($store);
    }

    private function cachePrefix(Container $app): string
    {
        return (string)($this->setting($app, 'cache.prefix') ?? 'esanj:remote_eloquent:') . self::cacheScope($app->make(ConfigRepository::class)) . ':';
    }

    /**
     * The client secret and every token travel on these URLs: plain http is refused outside local and testing.
     */
    private function assertSecureUrl(Container $app, ?string $url, string $setting): void
    {
        if ($url === null || strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https') {
            return;
        }

        $environment = $app instanceof \Illuminate\Contracts\Foundation\Application ? $app->environment() : 'production';

        if (in_array($environment, ['local', 'testing'], true)) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'The remote-eloquent "%s" setting must be an https URL outside the local and testing environments; "%s" would send the client secret and tokens in clear text.',
            $setting,
            $url,
        ));
    }

    /**
     * Which application and which server a cached token, schema or snapshot belongs to: two services sharing a store never read each other's.
     */
    public static function cacheScope(ConfigRepository $config): string
    {
        $clientId = $config->get(self::CONFIG_KEY . '.auth.client_id');
        $baseUrl = $config->get(self::CONFIG_KEY . '.rest.base_url');

        return substr(hash('sha256', (is_scalar($clientId) ? (string) $clientId : '') . '|' . (is_scalar($baseUrl) ? (string) $baseUrl : '')), 0, 12);
    }

    private function logger(Container $app): ?LoggerInterface
    {
        if (!$app->bound('log')) {
            return null;
        }

        $logs = $app->make('log');
        $channel = $this->text($this->setting($app, 'telemetry.log_channel'));

        if ($channel !== null && method_exists($logs, 'channel')) {
            $logger = $logs->channel($channel);

            return $logger instanceof LoggerInterface ? $logger : null;
        }

        return $logs instanceof LoggerInterface ? $logs : null;
    }

    private function bridge(Container $app): ?AuthBridgeServiceInterface
    {
        if (!interface_exists(AuthBridgeServiceInterface::class) || !$app->bound(AuthBridgeServiceInterface::class)) {
            return null;
        }

        $bridge = $app->make(AuthBridgeServiceInterface::class);

        return $bridge instanceof AuthBridgeServiceInterface ? $bridge : null;
    }

    private function audiences(Container $app, array $config): array
    {
        $audiences = $this->list($config['audiences'] ?? $this->bridgeSetting($app, 'expected_audiences') ?? []);

        if ($audiences !== []) {
            return $audiences;
        }

        $clientId = (string)($config['input'] ?? 'session') === 'session'
            ? $this->text($this->bridgeSetting($app, 'client_id')) ?? $this->text($this->setting($app, 'auth.client_id'))
            : $this->text($this->setting($app, 'auth.client_id'));

        return $clientId === null ? [] : [$clientId];
    }

    private function list(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (!is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $item) {
            if (!is_scalar($item)) {
                continue;
            }

            $item = trim((string)$item);

            if ($item !== '') {
                $items[] = $item;
            }
        }

        return array_values(array_unique($items));
    }

    private function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
