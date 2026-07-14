<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Transport;

use Esanj\RemoteEloquent\Contracts\AccessTokenProviderInterface;
use Esanj\RemoteEloquent\Contracts\TransportInterface;
use Esanj\RemoteEloquent\DTOs\QueryResult;
use Esanj\RemoteEloquent\Exceptions\InvalidQueryException;
use Esanj\RemoteEloquent\Exceptions\QueryAccessDeniedException;
use Esanj\RemoteEloquent\Exceptions\TransportException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Delivers compiled SQL to the Accounting service over REST:
 *
 *   POST {base_url}{query_path}
 *   Authorization: Bearer <client-credentials token>
 *   { "sql": "...", "bindings": [ ... ] }
 *   -> { "data": { "rows": [ {col: "val"} ], "affected_rows": N } }
 */
final class RestTransport implements TransportInterface
{
    /**
     * @param  array<string, mixed>  $config  config('esanj.remote_eloquent.rest')
     */
    public function __construct(
        private readonly AccessTokenProviderInterface $token,
        private readonly array $config,
    ) {}

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

    /**
     * Send the request, retrying only on genuine connection failures (never on
     * a 4xx/5xx — those carry meaning and are handled in toResult()).
     *
     * @param  array{sql: string, bindings: array<int, scalar|null>}  $payload
     */
    private function send(array $payload, bool $forceFreshToken): Response
    {
        $attempts = max((int) ($this->config['retries'] ?? 1), 1);
        $lastError = null;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            try {
                return $this->request($forceFreshToken)->post($this->url(), $payload);
            } catch (ConnectionException $e) {
                $lastError = $e;
            }
        }

        throw TransportException::connectionFailed('REST', $lastError?->getMessage() ?? 'unknown error', $lastError);
    }

    private function request(bool $forceFreshToken): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->timeout((int) ($this->config['timeout'] ?? 15))
            ->connectTimeout((int) ($this->config['connect_timeout'] ?? 5))
            ->withToken($this->bearer($forceFreshToken));
    }

    private function toResult(Response $response): QueryResult
    {
        if ($response->status() === 401) {
            throw TransportException::unauthenticated('REST');
        }

        if ($response->status() === 403) {
            throw QueryAccessDeniedException::denied((string) $response->json('message', ''));
        }

        if ($response->status() === 422) {
            throw InvalidQueryException::rejected((string) $response->json('message', ''));
        }

        if ($response->failed()) {
            throw TransportException::unexpectedStatus('REST', $response->status(), $response->body());
        }

        /** @var array<string, mixed> $data */
        $data = (array) $response->json('data', []);

        return new QueryResult(
            rows: $this->normalizeRows($data['rows'] ?? []),
            affectedRows: (int) ($data['affected_rows'] ?? 0),
            lastInsertId: isset($data['last_insert_id']) ? (string) $data['last_insert_id'] : null,
        );
    }

    /**
     * @param  mixed  $rows
     * @return list<array<string, string>>
     */
    private function normalizeRows(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_map(static function ($row): array {
            $fields = [];

            foreach ((array) $row as $key => $value) {
                $fields[(string) $key] = $value === null ? '' : (string) $value;
            }

            return $fields;
        }, $rows));
    }

    /**
     * @param  array<int, scalar|null>  $bindings
     * @return array<int, scalar|null>
     */
    private function normalizeBindings(array $bindings): array
    {
        return array_values($bindings);
    }

    private function bearer(bool $forceFresh): string
    {
        $access = $this->token->getAccessToken($forceFresh);

        return $access;
    }

    private function url(): string
    {
        $base = rtrim((string) ($this->config['base_url'] ?? ''), '/');
        $path = '/'.ltrim((string) ($this->config['query_path'] ?? '/api/application/query'), '/');

        return $base.$path;
    }
}