<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Database;

use Closure;
use Esanj\RemoteEloquent\Contracts\TransportInterface;
use Esanj\RemoteEloquent\Exceptions\RemoteConnectionException;
use Esanj\RemoteEloquent\Exceptions\RemoteEloquentException;
use Esanj\RemoteEloquent\Transport\TransportManager;
use Illuminate\Database\MySqlConnection;
use Throwable;

/**
 * An Eloquent database connection with no PDO.
 *
 * It reuses Laravel's MySQL query grammar and processor to compile Eloquent
 * queries down to SQL + bindings, then hands that SQL to a {@see TransportInterface}
 * (gRPC or REST) instead of a local PDO. Result rows come back as string maps
 * and are returned as stdClass objects, exactly as a real FETCH_OBJ select would.
 *
 * Every standard Eloquent read/write flows through here: where/orderBy/limit,
 * aggregates, pagination, insert, update and delete all compile to a single
 * MySQL statement and travel over the wire.
 */
class RemoteConnection extends MySqlConnection
{
    private ?TransportInterface $transport = null;

    /**
     * Override the transport (used by the service container / tests).
     */
    public function setTransport(TransportInterface $transport): void
    {
        $this->transport = $transport;
    }

    protected function transport(): TransportInterface
    {
        if ($this->transport !== null) {
            return $this->transport;
        }

        $driver = $this->getConfig('transport');
        $driver = is_string($driver) && $driver !== '' ? $driver : null;

        // null leaves the fallback decision to the package default.
        $fallback = $this->getConfig('transport_fallback');
        $fallback = is_bool($fallback) ? $fallback : null;

        return $this->transport = app(TransportManager::class)->resolve($driver, $fallback);
    }

    /**
     * {@inheritDoc}
     */
    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return [];
            }

            $result = $this->transport()->runQuery($query, $this->prepareBindings($bindings));

            // Match the default PDO::FETCH_OBJ shape Eloquent hydrates from.
            return array_map(static fn (array $row): object => (object) $row, $result->rows);
        });
    }

    /**
     * {@inheritDoc}
     */
    public function cursor($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        // The contract has no server-side streaming; fetch then yield lazily.
        foreach ($this->select($query, $bindings, $useReadPdo, $fetchUsing) as $row) {
            yield $row;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function statement($query, $bindings = [])
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return true;
            }

            $result = $this->transport()->runQuery($query, $this->prepareBindings($bindings));

            $this->recordsHaveBeenModified();

            if ($result->lastInsertId !== null) {
                $this->lastInsertId = $result->lastInsertId;
            }

            return true;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function affectingStatement($query, $bindings = [])
    {
        return $this->run($query, $bindings, function ($query, $bindings): int {
            if ($this->pretending()) {
                return 0;
            }

            $result = $this->transport()->runQuery($query, $this->prepareBindings($bindings));

            $this->recordsHaveBeenModified($result->affectedRows > 0);

            return $result->affectedRows;
        });
    }

    public function getLastInsertId()
    {
        if ($this->lastInsertId === null) {
            throw RemoteConnectionException::insertIdUnavailable();
        }

        return $this->lastInsertId;
    }

    /**
     * {@inheritDoc}
     *
     * Assigned unconditionally so a server that returns no id clears the previous
     * statement's value instead of leaving a stale one behind.
     */
    public function insert($query, $bindings = [], $sequence = null)
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return true;
            }

            $result = $this->transport()->runQuery($query, $this->prepareBindings($bindings));

            $this->recordsHaveBeenModified();
            $this->lastInsertId = $result->lastInsertId;

            return true;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function unprepared($query)
    {
        return $this->run($query, [], function ($query): bool {
            if ($this->pretending()) {
                return true;
            }

            $this->transport()->runQuery($query, []);
            $this->recordsHaveBeenModified();

            return true;
        });
    }

    /**
     * Surface the package's own exceptions unchanged; let anything unexpected
     * fall through to Laravel's rich QueryException formatting.
     *
     * {@inheritDoc}
     */
    protected function runQueryCallback($query, $bindings, Closure $callback)
    {
        try {
            return $callback($query, $bindings);
        } catch (RemoteEloquentException $e) {
            throw $e;
        } catch (Throwable $e) {
            return parent::runQueryCallback($query, $bindings, static function () use ($e) {
                throw $e;
            });
        }
    }

    /**
     * There is no PDO to reconnect; never attempt it.
     *
     * {@inheritDoc}
     */
    public function reconnectIfMissingConnection()
    {
        // no-op
    }

    /**
     * {@inheritDoc}
     */
    public function reconnect()
    {
        // no-op: the transport is stateless.
        return $this;
    }

    public function getPdo()
    {
        throw RemoteConnectionException::pdoUnavailable();
    }

    public function getReadPdo()
    {
        throw RemoteConnectionException::pdoUnavailable();
    }

    /**
     * Reported without touching PDO (used for grammar/version decisions).
     */
    public function getServerVersion(): string
    {
        return (string) ($this->getConfig('server_version') ?? '8.0.0');
    }

    public function isMaria(): bool
    {
        return false;
    }

    /**
     * There is no transaction to open: each statement is its own call and nothing can
     * be rolled back. Running the callback anyway would make a block that reads as
     * transactional leave half-applied writes behind on the first failure, so it fails
     * up front instead — unless the application has opted into the old behaviour.
     *
     * {@inheritDoc}
     */
    public function transaction(Closure $callback, $attempts = 1)
    {
        $this->guardTransactions();

        return $callback($this);
    }

    public function beginTransaction(): void
    {
        $this->guardTransactions();
    }

    public function commit(): void
    {
        $this->guardTransactions();
    }

    public function rollBack($toLevel = null): void
    {
        $this->guardTransactions();
    }

    private function guardTransactions(): void
    {
        if ((bool) config('esanj.remote_eloquent.allow_unsafe_transactions', false)) {
            return;
        }

        throw RemoteConnectionException::transactionsUnsupported();
    }

    /**
     * Always zero, which is what keeps Eloquent's withSavepointIfNeeded() from routing
     * ordinary reads and writes through transaction().
     */
    public function transactionLevel(): int
    {
        return 0;
    }
}
