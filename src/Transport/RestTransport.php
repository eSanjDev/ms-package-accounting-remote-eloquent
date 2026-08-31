<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Transport;

use Esanj\RemoteEloquent\Contracts\AccessTokenProviderInterface;
use Esanj\RemoteEloquent\Contracts\TransportInterface;
use Esanj\RemoteEloquent\DTOs\QueryResult;
use Esanj\RemoteEloquent\Exceptions\InvalidQueryException;
use Esanj\RemoteEloquent\Exceptions\QueryAccessDeniedException;
use Esanj\RemoteEloquent\Exceptions\RemoteEloquentException;
use Esanj\RemoteEloquent\Exceptions\TransportException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class RestTransport implements TransportInterface
{
    public function __construct(
        private readonly AccessTokenProviderInterface $token,
        private readonly array                        $config,
    )
    {
    }

    public function runQuery(string $sql, array $bindings): QueryResult
    {
        $payload = ['sql' => $sql, 'bindings' => $this->normalizeBindings($bindings)];

        $response = $this->send($payload, forceFreshToken: false);

        // The token may have been revoked server-side before its local expiry.
        if ($response->status() === 401) {
            $response = $this->send($payload, forceFreshToken: true);
        }

        return $this->toResult($response);
    }

    private function send(array $payload, bool $forceFreshToken): Response
    {
        $attempts = max((int)($this->config['retries'] ?? 1), 1);
        $lastError = null;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            try {
                return $this->request($forceFreshToken)->post($this->url(), $payload);
            } catch (ConnectionException $e) {
                $lastError = $e;
            } catch (RemoteEloquentException $e) {
                throw $e;
            } catch (Throwable $e) {
                throw TransportException::requestFailed('REST', $e);
            }
        }

        throw TransportException::connectionFailed('REST', $lastError?->getMessage() ?? 'unknown error', $lastError);
    }

    private function request(bool $forceFreshToken): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->timeout((int)($this->config['timeout'] ?? 15))
            ->connectTimeout((int)($this->config['connect_timeout'] ?? 5))
            ->withToken($this->bearer($forceFreshToken));
    }

    private function toResult(Response $response): QueryResult
    {
        if ($response->status() === 401) {
            throw TransportException::unauthenticated('REST');
        }

        if ($response->status() === 403) {
            throw QueryAccessDeniedException::denied((string)$response->json('message', ''));
        }

        if ($response->status() === 422) {
            throw InvalidQueryException::rejected((string)$response->json('message', ''));
        }

        if ($response->failed()) {
            throw TransportException::unexpectedStatus('REST', $response->status(), $response->body());
        }

        /** @var array<string, mixed> $data */
        $data = (array)$response->json('data', []);

        return new QueryResult(
            rows: $this->normalizeRows($data['rows'] ?? []),
            affectedRows: (int)($data['affected_rows'] ?? 0),
            lastInsertId: isset($data['last_insert_id']) ? (string)$data['last_insert_id'] : null,
        );
    }

    private function normalizeRows(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_map(static function ($row): array {
            $fields = [];

            foreach ((array)$row as $key => $value) {
                $fields[(string)$key] = $value === null || is_scalar($value)
                    ? $value
                    : json_encode($value, JSON_UNESCAPED_UNICODE);
            }

            return $fields;
        }, $rows));
    }

    private function normalizeBindings(array $bindings): array
    {
        return array_values(array_map(static function ($binding) {
            if (is_string($binding) && !mb_check_encoding($binding, 'UTF-8')) {
                return ['__b64' => base64_encode($binding)];
            }

            return $binding;
        }, $bindings));
    }

    private function bearer(bool $forceFresh): string
    {
        $access = $this->token->getAccessToken($forceFresh);

        return $access;
    }

    private function url(): string
    {
        $base = rtrim((string)($this->config['base_url'] ?? ''), '/');
        $path = '/' . ltrim((string)($this->config['query_path'] ?? '/api/application/query'), '/');

        return $base . $path;
    }
}
