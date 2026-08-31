<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Transport;

use Esanj\RemoteEloquent\Contracts\AccessTokenProviderInterface;
use Esanj\RemoteEloquent\Contracts\TransportInterface;
use Esanj\RemoteEloquent\DTOs\QueryResult;
use Esanj\RemoteEloquent\Exceptions\InvalidQueryException;
use Esanj\RemoteEloquent\Exceptions\QueryAccessDeniedException;
use Esanj\RemoteEloquent\Exceptions\TransportException;
use Esanj\RemoteEloquent\Grpc\GrpcClientFactory;
use Google\Protobuf\Internal\Message;

final class GrpcTransport implements TransportInterface
{
    private const STATUS_OK = 0;
    private const STATUS_INVALID_ARGUMENT = 3;
    private const STATUS_PERMISSION_DENIED = 7;
    private const STATUS_UNAUTHENTICATED = 16;

    /**
     * @param  array<string, mixed>  $config  config('esanj.remote_eloquent.grpc')
     */
    public function __construct(
        private readonly AccessTokenProviderInterface $token,
        private readonly GrpcClientFactory $factory,
        private readonly array $config,
    ) {}

    public function runQuery(string $sql, array $bindings): QueryResult
    {
        $request = $this->buildRequest($sql, $bindings);

        [$response, $status] = $this->call($request, forceFreshToken: false);

        if (($status->code ?? self::STATUS_OK) === self::STATUS_UNAUTHENTICATED) {
            [$response, $status] = $this->call($request, forceFreshToken: true);
        }

        $this->guardStatus($status);

        return $this->toResult($response);
    }

    private function buildRequest(string $sql, array $bindings): Message
    {
        $class = $this->factory->requestClass();

        /** @var Message $request */
        $request = new $class;
        $request->setSql($sql);
        $request->setBindings($this->stringifyBindings($bindings));

        return $request;
    }

    /**
     * @return array{0: mixed, 1: object}
     */
    private function call(Message $request, bool $forceFreshToken): array
    {
        $metadata = [
            (string) ($this->config['metadata_key'] ?? 'authorization') => [
                $this->token->getAccessToken($forceFreshToken),
            ],
        ];

        $options = [];
        $timeout = (int) ($this->config['timeout'] ?? 0);
        if ($timeout > 0) {
            $options['timeout'] = $timeout * 1_000_000; // microseconds
        }

        $call = $this->factory->client()->RunQuery($request, $metadata, $options);

        /** @var array{0: mixed, 1: object} $result */
        $result = $call->wait();

        return $result;
    }

    private function guardStatus(object $status): void
    {
        $code = $status->code ?? self::STATUS_OK;

        if ($code === self::STATUS_OK) {
            return;
        }

        $details = (string) ($status->details ?? '');

        throw match ($code) {
            self::STATUS_PERMISSION_DENIED => QueryAccessDeniedException::denied($details),
            self::STATUS_INVALID_ARGUMENT => InvalidQueryException::rejected($details),
            self::STATUS_UNAUTHENTICATED => TransportException::unauthenticated('gRPC'),
            default => TransportException::unexpectedStatus('gRPC', (int) $code, $details),
        };
    }

    private function toResult(mixed $response): QueryResult
    {
        if (! $response instanceof Message) {
            throw TransportException::unexpectedStatus('gRPC', 0, 'The server returned an empty response.');
        }

        $rows = [];

        foreach ($response->getRows() as $row) {
            $fields = [];

            foreach ($row->getFields() as $key => $value) {
                $fields[(string) $key] = (string) $value;
            }

            foreach ($row->getNullFields() as $column) {
                $fields[(string) $column] = null;
            }

            $rows[] = $fields;
        }

        return new QueryResult(
            rows: $rows,
            affectedRows: (int) $response->getAffectedRows(),
        );
    }

    /**
     * @param  array<int, scalar|null>  $bindings
     * @return list<string>
     */
    private function stringifyBindings(array $bindings): array
    {
        return array_values(array_map(static function ($binding): string {
            if ($binding === null) {
                return '';
            }

            if (is_bool($binding)) {
                return $binding ? '1' : '0';
            }

            return (string) $binding;
        }, $bindings));
    }
}
