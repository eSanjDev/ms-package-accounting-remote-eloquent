# 📚 Remote Eloquent — Complete Guide

This guide assumes you have **never used this package**. It explains, step by step, how to make a Laravel service
read and write the **Accounting** database through plain Eloquent — without a local copy of those tables.

> 💡 **What is this?** Normally an Eloquent model talks to *your* database through PDO. Remote Eloquent swaps that
> PDO for a network transport: your query is compiled to SQL and sent to the Accounting service (over gRPC or
> REST), which runs it and sends the rows back. Your models hydrate from those rows exactly as usual.

---

## Table of contents

1. [The big picture](#1-the-big-picture)
2. [Requirements](#2-requirements)
3. [Installation](#3-installation)
4. [Configuration & `.env`](#4-configuration--env)
5. [Your first remote model](#5-your-first-remote-model)
6. [Reading data](#6-reading-data)
7. [Writes & the insert-id caveat](#7-writes--the-insert-id-caveat)
8. [Choosing a transport: REST vs gRPC](#8-choosing-a-transport-rest-vs-grpc)
   - [Automatic fallback between transports](#automatic-fallback-between-transports)
9. [How token caching works](#9-how-token-caching-works)
10. [Raw queries with the `RemoteQuery` facade](#10-raw-queries-with-the-remotequery-facade)
11. [What you can and can't query](#11-what-you-can-and-cant-query)
12. [How authorization works on the server](#12-how-authorization-works-on-the-server)
13. [Error handling](#13-error-handling)
14. [Troubleshooting](#14-troubleshooting)
15. [Cheat sheet](#15-cheat-sheet)

---

## 1. The big picture

```
Your service                       Remote Eloquent                     Accounting
   User::find(7)  ------------->  compile to SQL + bindings
                                  attach cached Bearer token
                                  send {sql, bindings}  ------------->  check the app owns USER_LIST
                                                                        run: select * from `users` where id = 7
                                  hydrate model  <-------------------   return rows (as strings)
```

Your job is small: **extend `RemoteModel`, set the table, declare casts.** Everything else is normal Eloquent.

---

## 2. Requirements

- PHP 8.2+, Laravel 11–13.
- The Accounting service reachable over REST (a base URL) and/or gRPC (`host:port`).
- An OAuth **client id + secret** issued by Accounting (the same pair `esanj/auth-bridge` uses is fine).
- **Only if you choose gRPC:** `ext-grpc`, plus the `grpc/grpc` and `google/protobuf` composer packages (the
  message classes ship with this package — section 8). REST needs none of these.

---

## 3. Installation

Add a path repository to the consuming service and require the package:

```bash
composer require esanj/remote-eloquent
php artisan vendor:publish --tag="esanj-remote-eloquent-config"   # optional
php artisan config:clear
```

The provider (`Esanj\RemoteEloquent\RemoteEloquentServiceProvider`) and the `RemoteQuery` facade auto-discover. The
package registers a database connection named `remote` for you.

---

## 4. Configuration & `.env`

The only things you *must* provide are where Accounting is and how to authenticate. Both default to the
`esanj/auth-bridge` variables, so if this service already logs into Accounting you may only need the driver line.

```env
REMOTE_ELOQUENT_BASE_URL=https://accounting.example.com      # or leave unset to reuse ACCOUNTING_BRIDGE_BASE_URL
REMOTE_ELOQUENT_CLIENT_ID=your-client-id                     # or reuse ACCOUNTING_BRIDGE_CLIENT_ID
REMOTE_ELOQUENT_CLIENT_SECRET=your-client-secret             # or reuse ACCOUNTING_BRIDGE_CLIENT_SECRET
REMOTE_ELOQUENT_DRIVER=rest                                  # rest (default) | grpc
REMOTE_ELOQUENT_FALLBACK=true                                # replay on the other transport when this one is down
```

> ⚠️ Run `php artisan config:clear` after editing `.env`.

Every option (timeouts, token cache store & buffer, connection name, table prefix, gRPC host & message classes)
is documented in [`src/config/remote_eloquent.php`](../src/config/remote_eloquent.php).

---

## 5. Your first remote model

Create a model that extends `RemoteModel` and points at a **remote** table name:

```php
<?php

namespace App\Models\Remote;

use Esanj\RemoteEloquent\Eloquent\RemoteModel;

class User extends RemoteModel
{
    protected $table = 'users';     // the table name ON the Accounting database
    protected $guarded = [];

    // Remote values arrive as strings. Casts turn them back into real types.
    protected $casts = [
        'id' => 'int',
        'is_admin' => 'bool',
        'email_verified_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
```

That's the whole setup. There is **no migration** — the table lives in Accounting, not here.

> 💡 **Always declare `$casts`.** Without them, `id` would be the string `"7"`, `is_admin` the string `"1"`, and
> timestamps plain strings. With them, Eloquent hydrates `int`, `bool` and `Carbon` as you'd expect.

---

## 6. Reading data

Everything that compiles to a single-table `SELECT` works — which is almost the entire read API:

```php
User::find(7);
User::findOrFail(7);
User::where('email', 'ada@example.com')->first();
User::where('is_admin', true)->orderByDesc('id')->limit(20)->get();
User::whereIn('id', [1, 2, 3])->pluck('email');
User::where('email', 'like', '%@example.com')->exists();
User::count();                       // aggregate
User::query()->paginate(15);         // runs a count() then a limited select
```

Each call becomes one SQL statement and one round-trip. Bindings are sent separately (parameterized), so values are
never string-interpolated into SQL.

---

## 7. Writes & the insert-id caveat

Updates and deletes are fully supported and return the affected row count:

```php
User::where('id', 7)->update(['name' => 'Ada L.']);   // => 1
User::where('id', 7)->delete();                        // => 1
$user->update(['name' => 'Ada L.']);
$user->delete();
```

Inserts run too — the row **is** created:

```php
User::create(['name' => 'Grace', 'email' => 'grace@example.com']);
```

`create()` on an auto-increment model returns the real key: Accounting reports the id as `data.last_insert_id`
over REST and `QueryResponse.last_insert_id` over gRPC, and `RemoteConnection::getLastInsertId()` hands it to
Eloquent exactly as a local PDO would.

```php
$user = User::create(['name' => 'Grace']);
$user->id;        // 4242
$user->update(['status' => 'active']);   // update ... where `id` = ?   ✔
```

**Against an Accounting deployment that predates this contract**, no id comes back. The package then **throws**
`RemoteConnectionException` from `getLastInsertId()` rather than handing you a model with a null key:

```
The insert succeeded but Accounting returned no last_insert_id, so the model has no key. …
```

That is deliberate. A null key is not a missing convenience — the row *is* created, but every later `save()` and
`delete()` on that model compiles to ``where `id` is null``, matches nothing, and reports success. A loud failure at
the insert beats a silent no-op three lines later.

So when you talk to such a server, **client-generated keys are required, not merely recommended**, for any model
you create remotely:

```php
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Token extends RemoteModel
{
    use HasUuids;
    protected $table = 'tokens';
}

$token = Token::create([...]);   // $token->id is the UUID your app generated — no server round trip
```

A UUID/ULID model never asks for an insert id, so it is unaffected either way. Bulk inserts
(`Model::insert([...])`, `DB::table()->insert()`) never ask for one either and keep working.

---

## 8. Choosing a transport: REST vs gRPC

Both speak the **same contract** — `{sql, bindings}` → `{rows, affected_rows}` — so your models behave identically
either way. Pick per environment with `REMOTE_ELOQUENT_DRIVER`.

### REST (default, recommended to start)

Nothing to install. It POSTs to `{REMOTE_ELOQUENT_BASE_URL}/api/application/query` with a Bearer token. Done.

### gRPC

Faster and lighter on the wire. The protobuf message classes **ship with the package**, so there is no `protoc`
step:

1. **Install the stack** in the consuming service:
   ```bash
   pecl install grpc          # or enable ext-grpc in php.ini
   composer require grpc/grpc google/protobuf
   ```
2. **Configure** — the host is all you need; `request_class`/`response_class` already default to the shipped
   `Esanj\RemoteEloquent\Grpc\QueryRequest` / `QueryResponse`:
   ```env
   REMOTE_ELOQUENT_DRIVER=grpc
   REMOTE_ELOQUENT_GRPC_HOST=accounting.example.com:50051
   ```
   Only set `REMOTE_ELOQUENT_GRPC_REQUEST` / `REMOTE_ELOQUENT_GRPC_RESPONSE` if you want to point at your own
   generated classes instead of the shipped ones.

If gRPC isn't fully wired, the transport fails **catchably** with a message telling you exactly what's missing
(`ext-grpc`, `grpc/grpc`, or a message class). With fallback enabled (the default) the statement then goes over
REST and a warning is logged — you keep serving traffic, and the log tells you what to install.

### Per-model transport override

`REMOTE_ELOQUENT_DRIVER` is the default for **every** model. To make one model use a different transport, set
`$transport` on it:

```php
class Ledger extends RemoteModel
{
    protected $table = 'ledgers';
    protected $transport = 'grpc';   // always gRPC, even when the default is rest
}
```

- Accepted values: `'rest'` and `'grpc'`. Any other value throws `InvalidArgumentException` (with the offending
  model and value) as soon as the model resolves its connection.
- Each transport gets its own auto-registered connection — `remote` (default), `remote_rest`, `remote_grpc` — so a
  gRPC model and a REST model never share a connection, cached token pipe, or transport instance.
- Need to decide at runtime (e.g. per environment or feature flag)? Override the method form instead:

  ```php
  public function getTransportName(): ?string
  {
      return app()->environment('production') ? 'grpc' : 'rest';
  }
  ```

  Return `null` to fall back to the configured default.

An explicit `$connection` on the model still takes precedence over `$transport` (for advanced multi-endpoint
setups where you register your own connections).

### Automatic fallback between transports

Picking a transport does not mean betting the service on it. When the transport a statement runs on turns out to be
**unreachable, misconfigured, or answering with an unexpected status**, the statement is replayed on the other one:

```
User::find(7)  --> rest    ✗  connection refused
                            ↓  (warning logged)
               --> grpc    ✓  rows come back — your code never noticed
```

It is **on by default**. One env var turns it off everywhere:

```env
REMOTE_ELOQUENT_FALLBACK=false
```

The chain is symmetric out of the box (`rest → grpc`, `grpc → rest`) and lives in
[`src/config/remote_eloquent.php`](../src/config/remote_eloquent.php) under `fallback.chain`.

#### What falls back, and what does not

The fallback replaces a **broken pipe**, never a valid answer. So:

| Failure | Falls back? | Why |
|---|---|---|
| Connection refused / timed out | ✅ | The transport is down; the other one may be up. |
| Unexpected status (5xx, 404, …) | ✅ | Often a misconfigured endpoint. |
| gRPC stack missing (`ext-grpc`, message classes) | ✅ | Nothing was ever sent. |
| `InvalidQueryException` (422 — rejected SQL) | ❌ | A server verdict. gRPC rejects the same statement. |
| `QueryAccessDeniedException` (403 — missing feature) | ❌ | Authorization is transport-agnostic. |
| `TokenRequestException` | ❌ | Both transports use the same token issuer. |

#### Writes are treated more strictly

A REST `INSERT` that **timed out** may well have been applied on the server — the response just never arrived.
Replaying it over gRPC would insert the row twice. So `INSERT`/`UPDATE`/`DELETE` do **not** fall back by default.
They still do when the failure provably happened *before* anything left your process (a missing gRPC stack, for
example), because there is nothing to double-apply.

If your writes are idempotent and you would rather have availability, opt in:

```env
REMOTE_ELOQUENT_FALLBACK_RETRY_WRITES=true
```

> ⚠️ On an accounting database, think twice. A duplicated `UPDATE ... SET balance = balance + ?` is worse than a
> failed request.

#### Turning fallback off for one model

Some tables should never move quietly between transports — you want the failure, not a detour. Set
`$transportFallback` on that model:

```php
class User extends RemoteModel
{
    protected $table = 'users';
    protected $transportFallback = false;   // always this model's own transport, or an error
}
```

- `false` — never fall back, whatever the global setting says.
- `true` — always fall back, even when `REMOTE_ELOQUENT_FALLBACK=false`.
- `null` (the default) — follow the package setting.
- Combine it with `$transport` freely: `$transport = 'grpc'` + `$transportFallback = false` means *gRPC or nothing*.
- Dynamic decisions: override `getTransportFallback(): ?bool` instead.

Each combination has its own auto-registered connection (`remote_nofallback`, `remote_grpc_fallback`, …), so a
pinned model and a failing-over model never share a transport instance.

#### Observability

Every handover logs a warning — a fallback that hides an outage is worse than the outage:

```
[warning] remote-eloquent: the [rest] transport failed, retrying on [grpc].
          {"transport":"rest","fallback":"grpc","reason":"Could not reach the Accounting service over REST: ..."}
```

Send it somewhere you watch with `REMOTE_ELOQUENT_FALLBACK_LOG_CHANNEL=slack`, or silence it with
`REMOTE_ELOQUENT_FALLBACK_LOG=false`.

When **every** transport in the chain fails, you get one `TransportException` naming each attempt, with the primary
failure kept as `getPrevious()` and the per-transport reasons in `getContext()['attempts']`:

```
Every transport failed for this statement — rest: Could not reach the Accounting service over REST: cURL error 7…;
grpc: The gRPC transport is unavailable: the ext-grpc PHP extension is not installed.
```

> ⏱️ **A note on latency.** A fallback costs the primary transport's full timeout before the second attempt starts.
> While REST is down, every query pays `REMOTE_ELOQUENT_REST_TIMEOUT` (15s by default) on top of the gRPC call.
> Keep that timeout tight if you rely on fallback under load.

---

## 9. How token caching works

You configure a client id/secret once; the package handles tokens for you:

- On the first query it requests an access token with the **client-credentials** grant and **caches** it (keyed by
  client id + scope). Subsequent queries reuse the cached token — no token round-trip per query.
- When the cached token is within `cache_buffer_seconds` (default 60) of expiring, it is refreshed automatically:
  via the **refresh-token** grant if the server issued a refresh token, otherwise by requesting a fresh
  client-credentials token. Your queries never fail because a 15-minute token lapsed.
- If Accounting rejects a token mid-flight (HTTP 401 / gRPC `UNAUTHENTICATED`), the package drops the cached token
  and retries once with a fresh one.

Force a re-auth yourself with `RemoteQuery::forgetToken()`.

---

## 10. Raw queries with the `RemoteQuery` facade

When you want the remote pipe without a model:

```php
use Esanj\RemoteEloquent\Facades\RemoteQuery;

// Returns list<array<string,string>>
$rows = RemoteQuery::select('select * from `users` where `id` = ?', [7]);

// Returns the affected row count
RemoteQuery::affectingStatement('update `users` set `name` = ? where `id` = ?', ['Ada', 7]);

// Full result object ({rows, affectedRows, lastInsertId})
$result = RemoteQuery::run('select count(*) as c from `users`');

// The underlying connection / cached token
RemoteQuery::connection();     // Illuminate\Database\ConnectionInterface
RemoteQuery::forgetToken();
```

The same single-table + feature-gate rules below apply to raw queries.

---

## 11. What you can and can't query

The Accounting server only accepts **one single-table statement at a time**. Remote Eloquent passes these limits
straight through:

| ✅ Works | ❌ Rejected (`InvalidQueryException`) |
|---|---|
| `select`, `insert`, `update`, `delete` on one table | `JOIN`, `UNION` |
| `where`, `whereIn`, `orderBy`, `groupBy`, `having`, `limit`, `offset` | Multiple tables in one statement |
| aggregates, `paginate`, `pluck`, `exists` | Stacked statements (`;`) or SQL comments |

To combine data across tables, run separate queries and join in PHP (e.g. fetch users, then fetch their wallets by
id). Eager-loading a relation that lives in the **same** remote database works when each relation query is itself
single-table.

Two data-fidelity notes:

- **Strings in, casts out.** Over gRPC a row is a `map<string, string>`, so every non-NULL column comes back as a
  string — always declare `$casts` (section 5). Over REST the JSON types (`int`, `float`, `bool`) survive intact.
- **`NULL` stays `NULL`, both directions.** A `NULL` column reads back as `null`, so `is_null()`, `?? $default`,
  `SoftDeletes` and nullable casts all work normally — and writing `null` stores a real `NULL`. REST carries `null`
  natively. gRPC cannot put a `NULL` in a string map or a string list, so it sends the positions alongside:
  `DataRow.null_fields` names the columns that are really `NULL` on the way back, and `QueryRequest.null_bindings`
  carries the indexes of the null bindings on the way out; each side restores them. **Both need an Accounting
  deployment that speaks these fields**; against an older server a `NULL` read still arrives as `''` and a `NULL`
  write still stores `''` (the previous behaviour) rather than erroring.

---

## 12. How authorization works on the server

You cannot query anything you like — Accounting authorizes **every** statement against your application's
capability features, per `table.operation`. On the server this lives in `config/query.php`, e.g.:

```
users.select  => USER_LIST
users.insert  => USER_CREATE
users.update  => USER_UPDATE
users.delete  => USER_DELETE
clients.select => CLIENT_LIST
```

If your application owns `USER_LIST` it may `SELECT` from `users` and nothing else; a `DELETE` would throw
`QueryAccessDeniedException` (403 / `PERMISSION_DENIED`). Ask an Accounting admin to grant the features your
service needs. Anything not mapped is denied by default.

---

## 13. Error handling

```php
use Esanj\RemoteEloquent\Exceptions\InvalidQueryException;
use Esanj\RemoteEloquent\Exceptions\QueryAccessDeniedException;
use Esanj\RemoteEloquent\Exceptions\TokenRequestException;
use Esanj\RemoteEloquent\Exceptions\TransportException;

try {
    $users = User::where('is_admin', true)->get();
} catch (QueryAccessDeniedException $e) {   // 403 — missing capability feature
} catch (InvalidQueryException $e) {        // 422 — statement rejected (JOIN, multi-table, comment…)
} catch (TokenRequestException $e) {        // could not obtain/refresh the access token
} catch (TransportException $e) {           // connection/auth/unexpected status
}
```

All extend `RemoteEloquentException` (with `getContext()`), and are thrown **unwrapped** — you catch the specific type
directly, not a generic `QueryException`.

---

## 14. Troubleshooting

**`QueryAccessDeniedException: Running a select on "users" is not exposed` / `...not allowed to...`.**
Your application doesn't own the required feature (or the `table.operation` isn't mapped on the server). Grant the
feature in the Accounting panel.

**`InvalidQueryException: JOIN and UNION queries are not supported`.**
Your Eloquent query compiled to a multi-table statement (often an eager-load across tables or a `whereHas`). Split
it into single-table queries.

**Everything is a string / dates aren't `Carbon`.**
Declare `$casts` on the model.

**A `NULL` column reads as `''`, `0` or "now" — or writing `null` stores `''`.**
The Accounting deployment you are talking to predates the `null_fields` / `null_bindings` contract, so it cannot
signal `NULL` over gRPC in either direction. Upgrade Accounting; until then a `datetime` cast on a nullable column
produces `Carbon::now()` and `trashed()` returns `true` for live rows, and a write of `null` lands as `''` — a 1292
under MySQL strict mode, a `0000-00-00 00:00:00` without it. Against such a server, use
`REMOTE_ELOQUENT_DRIVER=rest`, which has always carried `null` correctly.

**`TransportException: The gRPC transport is unavailable ...`.**
Install `ext-grpc` + `grpc/grpc` + `google/protobuf` (the message classes ship with the package, so no `protoc`
step is needed). Or switch back to `REMOTE_ELOQUENT_DRIVER=rest`.

**`TransportException: Every transport failed for this statement — rest: …; grpc: …`.**
Fallback did its job and both transports were unreachable. The message names what each one hit — usually a wrong
`REMOTE_ELOQUENT_BASE_URL` / `REMOTE_ELOQUENT_GRPC_HOST`, or Accounting genuinely being down. `getContext()['attempts']`
has the same breakdown as an array.

**A model keeps switching transport and I don't want it to.**
Set `protected $transportFallback = false;` on it (section 8), or turn fallback off service-wide with
`REMOTE_ELOQUENT_FALLBACK=false`.

**My write failed instead of falling back.**
That is deliberate: a write that may already have reached the server is never replayed elsewhere, or it could apply
twice. Enable `REMOTE_ELOQUENT_FALLBACK_RETRY_WRITES=true` only if your writes are idempotent (section 8).

**`TokenRequestException: client credentials are not configured`.**
Set `REMOTE_ELOQUENT_CLIENT_ID` / `REMOTE_ELOQUENT_CLIENT_SECRET` (or the `ACCOUNTING_BRIDGE_*` equivalents), then
`php artisan config:clear`.

**`RemoteConnectionException: The insert succeeded but Accounting returned no last_insert_id`.**
The row was created, but the Accounting deployment predates the `last_insert_id` contract so the model has no key.
Upgrade Accounting, or give the model `HasUuids`/`HasUlids` (section 7). The package throws here on purpose: the
alternative is a model whose every later `save()`/`delete()` silently matches no rows.

**Config changes ignored.** `php artisan config:clear` (and re-cache in production).

---

## 15. Cheat sheet

```bash
composer require esanj/remote-eloquent
php artisan vendor:publish --tag="esanj-remote-eloquent-config"
php artisan config:clear
```

```php
// Model
class User extends \Esanj\RemoteEloquent\Eloquent\RemoteModel {
    protected $table = 'users';
    protected $casts = ['id' => 'int', 'created_at' => 'datetime'];
}

// Read
User::where('email', $email)->first();
User::query()->orderByDesc('id')->paginate();

// Write (prefer UUID keys for create())
User::where('id', 7)->update(['name' => 'Ada']);
User::where('id', 7)->delete();

// Raw + token
\Esanj\RemoteEloquent\Facades\RemoteQuery::select('select * from `users` where id = ?', [7]);
\Esanj\RemoteEloquent\Facades\RemoteQuery::forgetToken();
```

| I want to… | Do this |
|---|---|
| Make a table remote | `class X extends RemoteModel { protected $table = '...'; }` |
| Fix string/date types | declare `$casts` |
| Use gRPC | `REMOTE_ELOQUENT_DRIVER=grpc` + install ext-grpc/grpc/protobuf (classes ship with the package) |
| Pin one model to a transport | `protected $transport = 'grpc';` (or `'rest'`) on that model |
| Survive one transport going down | nothing — it's on by default (`REMOTE_ELOQUENT_FALLBACK=false` to stop it) |
| Stop one model from falling back | `protected $transportFallback = false;` on that model |
| Let writes fall back too | `REMOTE_ELOQUENT_FALLBACK_RETRY_WRITES=true` (idempotent writes only) |
| Run raw SQL | `RemoteQuery::select()` / `::affectingStatement()` |
| Force re-auth | `RemoteQuery::forgetToken()` |
| Combine tables | run separate single-table queries, join in PHP |
