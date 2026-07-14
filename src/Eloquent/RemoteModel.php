<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Eloquent;

use Esanj\RemoteEloquent\Transport\TransportManager;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Base model for data that lives in the Accounting service.
 *
 * Extend this instead of Illuminate\Database\Eloquent\Model. Every query the
 * model builds is compiled to a single MySQL statement and executed remotely
 * over gRPC or REST — the full Eloquent read/write surface keeps working:
 *
 *   RemoteUser::where('email', $email)->first();
 *   RemoteUser::query()->orderByDesc('id')->paginate();
 *   RemoteUser::create([...]);            // see the write notes in docs/GUIDE.md
 *   $user->update(['name' => 'New']);
 *   $user->delete();
 *
 * Notes for subclasses:
 *  - Set $table to the exact remote table name (e.g. "users"). The server
 *    authorizes each call by table + operation, so this must line up.
 *  - Declare $casts. Remote values arrive as strings; casts restore int/bool/
 *    datetime types on the way in.
 *  - Cross-table queries (JOIN/UNION, whereHas across tables) are rejected by
 *    the server. Keep queries single-table.
 */
abstract class RemoteModel extends Model
{
    /**
     * Per-model transport override: "rest" or "grpc". Leave null to use the
     * package default (config esanj.remote_eloquent.driver). Set this to pin a
     * model to a specific transport regardless of the global default:
     *
     *   class Ledger extends RemoteModel
     *   {
     *       protected $table = 'ledgers';
     *       protected $transport = 'grpc';   // this model always talks gRPC
     *   }
     *
     * For dynamic decisions, override getTransportName() instead.
     *
     * @var string|null
     */
    protected $transport = null;

    /**
     * Resolve the connection this model runs on. When a transport override is
     * set, the model targets that transport's dedicated remote connection (e.g.
     * "remote_grpc"); otherwise it uses the package's default remote connection.
     * An explicit $connection still wins for advanced, multi-endpoint setups.
     */
    public function getConnectionName(): ?string
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        $base = (string) config('esanj.remote_eloquent.connection', 'remote');
        $transport = $this->getTransportName();

        return $transport === null ? $base : $base.'_'.$transport;
    }

    /**
     * The transport this model is pinned to, or null for the package default.
     * Override for dynamic decisions (e.g. per environment).
     *
     * @throws InvalidArgumentException When $transport is set to an unknown driver.
     */
    public function getTransportName(): ?string
    {
        if ($this->transport === null) {
            return null;
        }

        if (! TransportManager::supports($this->transport)) {
            throw new InvalidArgumentException(sprintf(
                'Model [%s] declares an unknown $transport [%s]. Use one of: %s.',
                static::class,
                $this->transport,
                implode(', ', TransportManager::DRIVERS),
            ));
        }

        return $this->transport;
    }
}
