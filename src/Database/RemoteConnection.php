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

        return $this->transport = app(TransportManager::class)->driver($driver);
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

    /**
     * {@inheritDoc}
     *
     * The last insert id is captured only when the server returns one; the
     * current contract returns affected rows for writes, so auto-increment ids
     * are not echoed back (see docs/GUIDE.md — prefer client-generated keys).
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
     * The remote contract has no transaction support; run the callback directly.
     *
     * {@inheritDoc}
     */
    public function transaction(Closure $callback, $attempts = 1)
    {
        return $callback($this);
    }

    public function beginTransaction(): void
    {
        // no-op
    }

    public function commit(): void
    {
        // no-op
    }

    public function rollBack($toLevel = null): void
    {
        // no-op
    }

    public function transactionLevel(): int
    {
        return 0;
    }
}