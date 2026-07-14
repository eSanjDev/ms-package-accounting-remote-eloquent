# Remote Eloquent

**Remote Eloquent** lets a Laravel service talk to the **Esanj Accounting** database as if the tables were local.
You extend `RemoteModel` instead of Laravel's `Model`, and every Eloquent query you write is compiled to SQL and
executed on the Accounting service over **gRPC or REST** — you get rows back and hydrate real models. No local
tables, no schema copies, no hand-written API client.

```php
use Esanj\RemoteEloquent\Eloquent\RemoteModel;

class User extends RemoteModel
{
    protected $table = 'users';
    protected $casts = ['id' => 'int', 'email_verified_at' => 'datetime'];
}

User::where('email', 'ada@example.com')->first();   // SELECT ... over the wire
User::query()->orderByDesc('id')->paginate();       // count + page, remotely
$user->update(['name' => 'Ada L.']);                // UPDATE ... over the wire
```

> Built to pair with [`esanj/auth-bridge`](../ms-package-accounting-bridge). It reuses the same OAuth
> client-credentials model to authenticate, and defaults its credentials to the same `ACCOUNTING_BRIDGE_*` env vars.

## How it works

```
Your service                         Remote Eloquent (this package)        Accounting service
   |  User::where(...)->get()              |                                     |
   |------------------------------------->| Eloquent builds the query           |
   |                                       | MySQL grammar compiles -> SQL + ?   |
   |                                       | attach cached Bearer token          |
   |                                       |----- {sql, bindings} (gRPC/REST) -->| authorize by feature
   |                                       |                                     | run single-table statement
   |                                       |<---- {rows, affected_rows} ---------| return string rows
   |<-- hydrated Collection<User> ---------| rows -> stdClass -> models (casts)  |
```

The heavy lifting is a custom database **connection with no PDO**. It reuses Laravel's MySQL query grammar and
processor to turn any Eloquent query into a single SQL statement, then hands that statement to a **transport**
(gRPC or REST) instead of a local driver. Because the whole query builder is reused, the **entire Eloquent
read/write surface keeps working**: `where`, `whereIn`, `orderBy`, `limit`/`offset`, aggregates (`count`, `sum`),
`paginate`, `find`, `first`, `pluck`, `exists`, `create`, `update`, `delete`, `updateOrCreate`, and so on.

## Features

- **Drop-in Eloquent** — extend `RemoteModel`; keep writing normal Eloquent.
- **Two transports, one contract** — `rest` (turnkey, zero extra deps) or `grpc`. Switch the default with one env
  var, or pin an individual model to a transport with `protected $transport = 'grpc';`.
- **Automatic token caching** — an OAuth client-credentials token is fetched once, cached, and transparently
  refreshed shortly before it expires (via the refresh-token grant when available, otherwise re-requested).
- **Server-enforced authorization** — every statement is gated on the Accounting side by the calling
  application's capability features (per `table.operation`). This package never bypasses that.
- **Typed exceptions** — `InvalidQueryException` (422), `QueryAccessDeniedException` (403), `TransportException`,
  `TokenRequestException`.
- **`RemoteQuery` facade** — for raw one-off statements and token control without a model.

## Requirements

- PHP **8.2+**, Laravel **11 – 13** (tested on 13).
- Reachable Accounting service (REST base URL and/or gRPC endpoint) plus an OAuth **client id/secret** issued by it.
- **gRPC only:** the `ext-grpc` PHP extension plus the `grpc/grpc` and `google/protobuf` composer packages. The
  protobuf message classes ship with this package — no code generation needed. REST needs none of this.

## Installation

This package lives in the Esanj monorepo.

```bash
composer require esanj/remote-eloquent
```

```bash
php artisan vendor:publish --tag="esanj-remote-eloquent-config"   # optional
```

The service provider and the `RemoteQuery` facade are auto-discovered. The package registers its own `remote`
database connection — **you do not touch `config/database.php`.**

## Configuration

Minimum `.env` (credentials fall back to the `esanj/auth-bridge` variables, so a service already wired for
Accounting needs almost nothing new):

```env
# Where the Accounting service lives (REST). Defaults to ACCOUNTING_BRIDGE_BASE_URL.
REMOTE_ELOQUENT_BASE_URL=https://accounting.example.com

# OAuth client-credentials. Default to ACCOUNTING_BRIDGE_CLIENT_ID / _SECRET.
REMOTE_ELOQUENT_CLIENT_ID=your-client-id
REMOTE_ELOQUENT_CLIENT_SECRET=your-client-secret

# Transport: rest (default) or grpc
REMOTE_ELOQUENT_DRIVER=rest
```

For gRPC, install the stack (`ext-grpc`, `grpc/grpc`, `google/protobuf`) and set:

```env
REMOTE_ELOQUENT_DRIVER=grpc
REMOTE_ELOQUENT_GRPC_HOST=accounting.example.com:50051
```

The `QueryRequest`/`QueryResponse` protobuf classes **ship with the package** (namespace
`Esanj\RemoteEloquent\Grpc`) and are wired as the defaults — you do **not** need to run `protoc`. Only set
`REMOTE_ELOQUENT_GRPC_REQUEST` / `REMOTE_ELOQUENT_GRPC_RESPONSE` if you want to point at your own generated classes.

See [`src/config/remote_eloquent.php`](src/config/remote_eloquent.php) for every option (timeouts, token cache store &
buffer, connection name, table prefix, …).

## Usage

### 1. Write a model

```php
use Esanj\RemoteEloquent\Eloquent\RemoteModel;

class Client extends RemoteModel
{
    protected $table = 'clients';           // must match the remote table name exactly
    protected $guarded = [];

    // Remote values arrive as strings; casts restore real types on the way in.
    protected $casts = [
        'id' => 'int',
        'revoked' => 'bool',
        'created_at' => 'datetime',
    ];
}
```

### 2. Use Eloquent normally

```php
Client::find($id);
Client::where('revoked', false)->orderByDesc('id')->limit(20)->get();
Client::query()->paginate(15);
Client::count();
```

### 3. Writes

```php
$user = User::create(['name' => 'Grace', 'email' => 'grace@example.com']);
$user->update(['name' => 'Grace H.']);
$user->delete();
```

> ⚠️ **Auto-increment ids on insert.** The current server contract returns *affected rows* for writes, not the new
> id, so `create()` cannot echo back a database-generated `id` unless the server is extended to return one. Prefer
> **client-generated keys** (UUID/ULID via `HasUuids`) for models you create remotely. Reads, updates and deletes
> are unaffected. See [docs/GUIDE.md](docs/GUIDE.md#writes--the-insert-id-caveat).

### Per-model transport

`REMOTE_ELOQUENT_DRIVER` sets the default transport for every model. To pin a **single** model to a specific
transport — regardless of that default — declare `$transport`:

```php
class Ledger extends RemoteModel
{
    protected $table = 'ledgers';
    protected $transport = 'grpc';   // this model always talks gRPC; others use the default
}
```

Accepted values are `'rest'` and `'grpc'` (an unknown value throws `InvalidArgumentException`). Under the hood each
transport has its own auto-registered remote connection (`remote`, `remote_rest`, `remote_grpc`), so models using
different transports stay fully isolated. For dynamic decisions, override `getTransportName(): ?string` instead.

### Raw queries (no model)

```php
use Esanj\RemoteEloquent\Facades\RemoteQuery;

$rows = RemoteQuery::select('select * from `users` where `id` = ?', [7]);   // list<array<string,string>>
$affected = RemoteQuery::affectingStatement('update `users` set `name` = ? where `id` = ?', ['Ada', 7]);
RemoteQuery::forgetToken();   // drop the cached access token
```

## Important constraints

These come from the Accounting server contract — Remote Eloquent surfaces them, it does not impose them:

| Constraint | What it means for you |
|---|---|
| **Single table per query** | No `JOIN`/`UNION`, no cross-table `whereHas`. Load related data with separate queries. Violations throw `InvalidQueryException`. |
| **Feature-gated** | Each `table.operation` must be permitted for your application on the Accounting side (e.g. `USER_LIST` → `SELECT users`). Otherwise `QueryAccessDeniedException`. |
| **Values are strings** | Every column comes back as a string, so **declare `$casts`**. Timestamps, ints and bools then hydrate correctly. |
| **`NULL` reads as `""`** | The contract stringifies `NULL` to an empty string. A `nullable` column that is `NULL` arrives as `''`; a nullable-int cast becomes `0`. Keep this in mind for nullable columns. |
| **No transactions** | The remote connection cannot open a real DB transaction; `DB::transaction()` on it runs the callback without atomicity. |

## Error handling

```php
use Esanj\RemoteEloquent\Exceptions\QueryAccessDeniedException;
use Esanj\RemoteEloquent\Exceptions\InvalidQueryException;
use Esanj\RemoteEloquent\Exceptions\TransportException;

try {
    User::create([...]);
} catch (QueryAccessDeniedException $e) {   // 403 — your app lacks the capability feature
    report($e);
} catch (InvalidQueryException $e) {        // 422 — the statement was rejected (e.g. a JOIN)
    report($e);
} catch (TransportException $e) {           // connectivity / auth / unexpected status
    report($e);
}
```

All extend `Esanj\RemoteEloquent\Exceptions\RemoteEloquentException` (which carries `getContext()`). They are surfaced
**unwrapped** through Eloquent, so you catch them directly.

## Documentation

For a step-by-step walkthrough — installation, a first model, both transports, generating the gRPC stubs, the write
caveats and troubleshooting — see **[docs/GUIDE.md](docs/GUIDE.md)**.

## Credits

Developed and maintained by the **Esanj Tech Team**.
