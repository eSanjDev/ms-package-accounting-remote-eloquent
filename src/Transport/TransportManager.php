<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Transport;

use Esanj\RemoteEloquent\Contracts\AccessTokenProviderInterface;
use Esanj\RemoteEloquent\Contracts\TransportInterface;
use Esanj\RemoteEloquent\Grpc\GrpcClientFactory;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;

/**
 * Resolves (and memoizes) a {@see TransportInterface} per driver name.
 *
 * The package has one configured default transport (esanj.remote_eloquent.driver),
 * but a {@see \Esanj\RemoteEloquent\Eloquent\RemoteModel} may pin itself to a
 * specific transport with `protected $transport = 'grpc'`. Each distinct driver
 * is built at most once and reused.
 *
 * Use {@see resolve()} to get the transport a query should actually run on: it
 * layers the configured fallback chain on top, so a broken REST endpoint can be
 * taken over by gRPC (and the other way round). {@see driver()} always returns
 * the bare transport, with no fallback.
 */
final class TransportManager
{
    /**
     * The transport drivers this package ships. A model's $transport override
     * must be one of these.
     *
     * @var list<string>
     */
    public const DRIVERS = ['rest', 'grpc'];

    /** @var array<string, TransportInterface> */
    private array $resolved = [];

    /** @var array<string, TransportInterface> */
    private array $chains = [];

    public function __construct(
        private readonly Application $app,
    ) {}

    /**
     * Resolve the bare transport for the given driver, or the configured default
     * when $driver is null/empty. No fallback is applied.
     */
    public function driver(?string $driver = null): TransportInterface
    {
        $driver = $this->normalize($driver);

        return $this->resolved[$driver] ??= $this->build($driver);
    }

    /**
     * Resolve the transport a query should run on, wrapped in a
     * {@see FallbackTransport} when transport fallback applies.
     *
     * @param  bool|null  $fallback  Force fallback on/off for this caller; null
     *                               follows config esanj.remote_eloquent.fallback.enabled.
     */
    public function resolve(?string $driver = null, ?bool $fallback = null): TransportInterface
    {
        $driver = $this->normalize($driver);
        $config = (array) config('esanj.remote_eloquent.fallback', []);

        if (! ($fallback ?? (bool) ($config['enabled'] ?? false))) {
            return $this->driver($driver);
        }

        $chain = self::chainFor($driver, $config);

        if ($chain === []) {
            return $this->driver($driver);
        }

        return $this->chains[$driver] ??= new FallbackTransport(
            $driver,
            $chain,
            fn (string $name): TransportInterface => $this->driver($name),
            new FallbackPolicy((bool) ($config['retry_writes'] ?? false)),
            [
                'enabled' => (bool) ($config['log'] ?? true),
                'channel' => $config['log_channel'] ?? null,
            ],
        );
    }

    /**
     * Whether the given driver name is a transport this package can build.
     */
    public static function supports(string $driver): bool
    {
        return in_array($driver, self::DRIVERS, true);
    }

    /**
     * The drivers a statement failing on $driver is replayed on, in order:
     * shipped drivers only, never the primary itself, never twice.
     *
     * @param  array<string, mixed>  $config  config('esanj.remote_eloquent.fallback')
     * @return list<string>
     */
    private static function chainFor(string $driver, array $config): array
    {
        $chain = array_map(
            static fn ($name): string => (string) $name,
            (array) ($config['chain'][$driver] ?? []),
        );

        return array_values(array_unique(array_filter(
            $chain,
            static fn (string $name): bool => $name !== $driver && self::supports($name),
        )));
    }

    private function normalize(?string $driver): string
    {
        return ($driver !== null && $driver !== '')
            ? $driver
            : (string) config('esanj.remote_eloquent.driver', 'rest');
    }

    private function build(string $driver): TransportInterface
    {
        $token = $this->app->make(AccessTokenProviderInterface::class);

        return match ($driver) {
            'rest' => new RestTransport($token, (array) config('esanj.remote_eloquent.rest', [])),
            'grpc' => new GrpcTransport(
                $token,
                $this->app->make(GrpcClientFactory::class),
                (array) config('esanj.remote_eloquent.grpc', []),
            ),
            default => throw new InvalidArgumentException(
                "Unsupported remote_eloquent transport driver [{$driver}]. Use \"rest\" or \"grpc\"."
            ),
        };
    }
}