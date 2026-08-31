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
- **Automatic transport fallback** — when REST is unreachable the statement is replayed over gRPC (and the other
  way round), logged, and never at the cost of correctness: server verdicts are not re-asked and writes are not
  replayed blindly. Opt a model out with `protected $transportFallback = false;`.
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

# Replay a statement on the other transport when this one is unreachable (default true)
REMOTE_ELOQUENT_FALLBACK=true
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

> ⚠️ **Auto-increment ids on insert.** `create()` returns the real `id` — Accounting reports it as
> `last_insert_id` on both transports. Against an older Accounting that does not, the package **throws**
> `RemoteConnectionException` instead of returning a model with a null key, because such a model's later
> `save()`/`delete()` would compile to ``where `id` is null`` and silently match nothing. For those servers,
> client-generated keys (UUID/ULID via `HasUuids`) are **required** for models you create remotely. Reads, updates
> and deletes are unaffected. See [docs/GUIDE.md](docs/GUIDE.md#writes--the-insert-id-caveat).

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

### Transport fallback

If the transport a statement is running on turns out to be unreachable, misconfigured or answering with an
unexpected status, the statement is replayed on the other one — REST covers for gRPC and gRPC covers for REST —
and a warning is logged for every handover. It is on by default; turn it off globally with
`REMOTE_ELOQUENT_FALLBACK=false`.

Two things are deliberately **not** retried, because the fallback replaces a broken pipe, not a valid answer:

- **Server verdicts.** A rejected statement (422) or a denied table (403) is thrown straight through — the other
  transport would give the same verdict, and hiding it behind a second round-trip only delays the real error.
- **Writes that may already have landed.** A REST `INSERT` that timed out might have been applied server-side, so
  replaying it over gRPC could apply it twice. Writes therefore stay put unless the failure provably happened
  before anything was sent (a missing gRPC stack, say), or you accept the risk with
  `REMOTE_ELOQUENT_FALLBACK_RETRY_WRITES=true`.

To keep a single model on its own transport regardless of the global setting:

```php
class User extends RemoteModel
{
    protected $table = 'users';
    protected $transportFallback = false;   // never silently switch transport
}
```

`true` forces fallback on for that model even when the package default is off, and `null` (the default) follows the
package setting. Each combination has its own auto-registered connection (`remote_grpc_nofallback`, …). For dynamic
decisions, override `getTransportFallback(): ?bool`. When **every** transport in the chain fails you get a single
`TransportException` naming each attempt, with the primary failure as `getPrevious()`.

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
| **Values are strings over gRPC** | The gRPC row map is `map<string, string>`, so every non-NULL column arrives as a string — **declare `$casts`** and timestamps, ints and bools hydrate correctly. Over REST the JSON types survive as they are. |
| **`NULL` stays `NULL`** | A `NULL` column reads back as `null` and writing `null` stores a real `NULL`, on both transports — so `SoftDeletes`, `?? $default` and nullable casts all behave normally. Needs Accounting deployed with the `null_fields` / `null_bindings` contract; against an older server a `NULL` still degrades to `''` in both directions. |
| **No transactions** | The remote connection cannot open a real DB transaction, so `DB::transaction()`, `beginTransaction()`, `commit()` and `rollBack()` **throw** `RemoteConnectionException` rather than pretend. A block that reads as transactional but silently is not leaves half-applied writes on the first failure — on an accounting database, a half-finished transfer. For atomicity put the whole operation behind one Accounting endpoint that opens a local transaction, or write a compensating action. See [docs/GUIDE.md](docs/GUIDE.md#7b-transactions-and-atomicity). |

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
