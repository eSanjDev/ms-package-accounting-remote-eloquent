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

    public function __construct(
        private readonly Application $app,
    ) {}

    /**
     * Resolve the transport for the given driver, or the configured default
     * when $driver is null/empty.
     */
    public function driver(?string $driver = null): TransportInterface
    {
        $driver = ($driver !== null && $driver !== '')
            ? $driver
            : (string) config('esanj.remote_eloquent.driver', 'rest');

        return $this->resolved[$driver] ??= $this->build($driver);
    }

    /**
     * Whether the given driver name is a transport this package can build.
     */
    public static function supports(string $driver): bool
    {
        return in_array($driver, self::DRIVERS, true);
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