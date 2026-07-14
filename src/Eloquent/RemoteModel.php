<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Eloquent;

use Illuminate\Database\Eloquent\Model;

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
     * Resolve the package's remote connection by default. A subclass may still
     * override $connection to target a differently-named remote connection.
     */
    public function getConnectionName(): ?string
    {
        return $this->connection ?? config('esanj.remote_eloquent.connection', 'remote');
    }
}
