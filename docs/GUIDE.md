# Remote Eloquent — Complete Guide

This guide assumes you have never used the package. It explains how to make a Laravel service read and write
accounts in the **Esanj Accounting** service through ordinary Eloquent, and — just as importantly — what it will
refuse to do and what to write instead.

---

## Table of contents

1. [The big picture](#1-the-big-picture)
2. [Requirements](#2-requirements)
3. [Installation](#3-installation)
4. [Configuration and `.env`](#4-configuration-and-env)
5. [Your first remote model](#5-your-first-remote-model)
6. [The QuerySpec: what your builder becomes](#6-the-queryspec-what-your-builder-becomes)
7. [Reading data](#7-reading-data)
8. [Pagination in one request](#8-pagination-in-one-request)
9. [Walking a large result set](#9-walking-a-large-result-set)
10. [Relations across the boundary](#10-relations-across-the-boundary)
11. [Writes, idempotency and stale records](#11-writes-idempotency-and-stale-records)
12. [Soft deletes](#12-soft-deletes)
13. [Domain actions](#13-domain-actions)
14. [Validation](#14-validation)
15. [The auth guard](#15-the-auth-guard)
16. [Acting for a user](#16-acting-for-a-user)
17. [Permissions and quota](#17-permissions-and-quota)
18. [Error handling](#18-error-handling)
19. [Rate limits in queued jobs](#19-rate-limits-in-queued-jobs)
20. [Testing](#20-testing)
21. [Artisan commands](#21-artisan-commands)
22. [Performance](#22-performance)
23. [Everything it refuses](#23-everything-it-refuses)
24. [Troubleshooting](#24-troubleshooting)
25. [Cheat sheet](#25-cheat-sheet)

---

## 1. The big picture

A normal Eloquent model talks to your database through PDO. A remote model has **no database and no PDO**. Its
builder is translated into a **QuerySpec** — a structured description of fields, conditions, ordering and paging
— and posted to a resource API that Accounting owns.

```
Your service                       Remote Eloquent                       Accounting
  User::query()
      ->where('is_active', true)
      ->limit(20)->get()   ----->  translate to a QuerySpec
                                   attach the app token (+ the user token)
                                   attach X-Request-Id, Accept-Language
                                       POST users/query {spec}  -------->  check the permission
                                                                           apply row-level tenancy
                                                                           validate every field + operator
                                                                           run it (MySQL or PostgreSQL)
                                       <----- {data, meta, schema_version}
  Collection<User>         <-----  hydrate, cast, fire events
```

### The two rules

**1. The server decides what is legal.** The client never builds SQL. It cannot widen what Accounting allows, and
the fields a resource publishes are its whole surface — a column that exists in the table but is not in the
contract cannot be named, filtered on, or returned.

**2. Nothing is ever dropped silently.** If the package cannot express something, it throws *before* the request
is made and the message names the supported alternative. A dropped `where` returns the wrong rows and looks like
a success, which is the worst failure a data layer has.

### What version 2 replaced

Version 1 compiled Eloquent to a MySQL SQL string and sent it over. Accounting vetted the string with regular
expressions. That made its table layout a public API, bypassed every domain rule that was not expressible as a
regex, broke on PostgreSQL, and — through a "fallback" transport — replayed failed writes and rate-limited calls
on a second channel. All of that is gone. The connection class in 2.0 throws on `select()`, so there is no code
path left that can run a compiled string.

---

## 2. Requirements

- PHP 8.2+
- Laravel 12 or 13
- Credentials for the Accounting service (an OAuth client id and secret)
- `firebase/php-jwt` ^7.0 **only if** you use the `accounting` auth guard
- `esanj/auth-bridge` if the signed-in user's own token should travel with writes

---

## 3. Installation

```bash
composer require esanj/remote-eloquent
```

The service provider is discovered automatically. It registers:

- the `remote-eloquent` connection (so you do **not** edit `config/database.php`),
- `ResourceTransport` and its OAuth token providers,
- the schema repository and validator,
- the `accounting` auth guard and the `remote` user provider,
- the presence verifier behind `Rule::exists()` / `Rule::unique()`,
- four artisan commands,
- `Model::preventLazyLoading()` outside production.

Publish the config only to change it:

```bash
php artisan vendor:publish --tag=esanj-remote-eloquent-config
# -> config/esanj/remote_eloquent.php, read as config('esanj.remote_eloquent.*')
```

---

## 4. Configuration and `.env`

Everything defaults to the `ACCOUNTING_BRIDGE_*` variables a service talking to Accounting already has.

```dotenv
REMOTE_ELOQUENT_DRIVER=rest
REMOTE_ELOQUENT_PREFIX=/api/remote/v1
REMOTE_ELOQUENT_TIMEOUT=5
REMOTE_ELOQUENT_CONNECT_TIMEOUT=2
REMOTE_ELOQUENT_CLIENT_VERSION=2.0
REMOTE_ELOQUENT_MAX_LIMIT=100
REMOTE_ELOQUENT_IN_CHUNK=500
REMOTE_ELOQUENT_IDENTITY_MAP=true
REMOTE_ELOQUENT_SCHEMA_TTL=3600
REMOTE_ELOQUENT_ACCESS_TTL=600
REMOTE_ELOQUENT_CALL_WARNING_THRESHOLD=20
REMOTE_ELOQUENT_RENDER_ERRORS=true

# Optional, and off by default:
# REMOTE_ELOQUENT_BASE_URL=https://auth.esanj.io        # else ACCOUNTING_BRIDGE_BASE_URL
# REMOTE_ELOQUENT_CLIENT_ID=                            # else ACCOUNTING_BRIDGE_CLIENT_ID
# REMOTE_ELOQUENT_CLIENT_SECRET=                        # else ACCOUNTING_BRIDGE_CLIENT_SECRET
# REMOTE_ELOQUENT_TOKEN_URL=                             # else {base_url}/oauth/token
# REMOTE_ELOQUENT_SCOPE=
# REMOTE_ELOQUENT_TOKEN_CACHE_KEY=esanj:remote_eloquent:token
# REMOTE_ELOQUENT_REFRESH_BUFFER=60
# REMOTE_ELOQUENT_ACTOR_EXCHANGE_URL=
# REMOTE_ELOQUENT_ACTOR_TOKEN_TYPE=Bearer
# REMOTE_ELOQUENT_ACTOR_TTL=60
# REMOTE_ELOQUENT_CACHE_STORE=
# REMOTE_ELOQUENT_CACHE_PREFIX=esanj:remote_eloquent:
# REMOTE_ELOQUENT_FIND_TTL=0
# REMOTE_ELOQUENT_LOG_CHANNEL=
```

Base and token URLs must be `https` outside the `local` and `testing` environments; the provider refuses to build
the client otherwise. Cached tokens, schemas and access snapshots are keyed by client id and base URL, so two
services sharing a cache store never read each other's.

| Key | Default | What it is |
| --- | --- | --- |
| `driver` | `rest` | The transport. `fake` is the in-memory one used by tests. |
| `rest.base_url` | `ACCOUNTING_BRIDGE_BASE_URL` | The account service. |
| `rest.prefix` | `/api/remote/v1` | Every endpoint lives under it. |
| `rest.timeout` / `rest.connect_timeout` | 5 / 2 s | Deliberately short. A page already waiting on a hop should fail fast. |
| `rest.client_version` | `2.0` | Sent as `X-Client-Version`, so the server can flag a client too old for a schema. |
| `rest.headers` | `[]` | Extra headers on every call. `Authorization`, `Actor-Authorization` and `Idempotency-Key` are built by the transport and cannot be overridden. |
| `auth.*` | `ACCOUNTING_BRIDGE_*` | The application's own OAuth client-credentials identity. |
| `auth.refresh_buffer_seconds` | 60 | The token is dropped this long before it expires, so a call never leaves with one that expires in flight. |
| `actor.exchange_url` | `null` | Unset means actor tokens are off — the documented opt-out. |
| `actor.ttl` | 60 s | An actor token only has to survive the request that produced it. |
| `limits.max_query_limit` | 100 | The largest page. |
| `limits.in_chunk` | 500 | The longest `in` list one request may carry. A longer `whereIn()` is split and merged; a list that cannot be split is refused with the reason. |
| `cache.identity_map` | `true` | Per-request memo of records fetched by id. |
| `cache.find_ttl` | 0 | Records are **not** cached across requests, on purpose. |
| `cache.schema_ttl` / `cache.access_ttl` | 3600 / 600 s | The descriptive endpoints. |
| `errors.render` | `true` | Let the package's exceptions answer the browser. |
| `telemetry.call_warning_threshold` | 20 | Warn once a request passes this many remote calls. |
| `fallback` | `false` | Not configurable. See §1. |

Then:

```bash
php artisan remote:doctor
```

---

## 5. Your first remote model

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Esanj\RemoteEloquent\Models\ApiModel;

class Wallet extends ApiModel
{
    protected string $resource = 'wallets';
    protected $connection = 'remote-eloquent';

    protected $fillable = ['label'];

    protected function casts(): array
    {
        return ['balance' => 'decimal:2', 'created_at' => 'datetime'];
    }
}
```

- **`$resource`** is the only required piece. It is what the API calls the collection, and `getTable()` answers
  it too, so a local model's `belongsTo(Wallet::class)` still derives `wallet_id` the usual way.
- **`$connection`** is not used to open anything — `getConnection()` always returns the hollow `ApiConnection` —
  but `Rule::exists(Wallet::class)` reads it to tell a remote resource from a local table. Set it.
- **`$fillable`** should be exactly the writable fields. Anything else becomes `400 field_not_writable`.
- **`$timestamps` is off** and `created_at` / `updated_at` are stripped from every write. The server stamps its
  own rows; sending your clock writes a time from a different machine than the rest of the data.

Extend **`ApiUser`** instead for the resource behind the auth guard — it adds Laravel's `Authenticatable` and
`Authorizable` contracts.

### Let the schema write it

```bash
php artisan remote:model wallets
php artisan remote:model users --auth
```

and keep it honest in CI:

```bash
php artisan remote:schema users --diff      # non-zero exit when the model has drifted
```

Casts, accessors, mutators, `$appends`, `$hidden`, `$fillable`/`$guarded`, model events and local relations all
behave exactly as on a normal model.

---

## 6. The QuerySpec: what your builder becomes

Useful to know, because every error message speaks in these terms.

```json
{
  "fields": ["id", "first_name"],
  "where": [
    {"boolean": "and", "not": false, "field": "is_active", "op": "eq", "value": true},
    {"boolean": "and", "not": false, "group": [
      {"boolean": "or", "not": false, "field": "first_name", "op": "starts_with", "value": "A"}
    ]}
  ],
  "order": [{"field": "created_at", "direction": "desc"}],
  "limit": 20,
  "offset": 0,
  "with_total": true,
  "include": ["roles"],
  "counts": ["wallets"],
  "trashed": null,
  "distinct": false
}
```

Operators: `eq` `ne` `gt` `gte` `lt` `lte` `in` `not_in` `between` `not_between` `null` `not_null`
`starts_with` `contains` `ends_with`.

Rules the client checks before sending, so you get a named exception instead of a 400:

- `null` / `not_null` carry no value;
- `in` / `not_in` take a list, capped at 500 per request;
- `between` / `not_between` take exactly two bounds;
- `eq` / `ne` are never sent with a null value — that is what `whereNull()` is for;
- `fields: []` means "the resource's default projection", not "no fields".

Each field publishes the operators *it* allows. `is_active` may allow only `eq`; `created_at` only the range
operators. `remote:schema users` prints the table.

---

## 7. Reading data

### One record

```php
User::find(7);              // GET users/7        -> null when absent
User::findOrFail(7);        // ModelNotFoundException
User::findMany([1, 2, 3]);  // one query with an "in"
User::query()->where('email', 'ada@example.com')->first();
User::query()->where('email', 'ada@example.com')->sole();
```

`find()` uses the record endpoint only when the builder is otherwise empty. Add a condition and it becomes a
`query` with `limit 1`, because a `GET {id}` cannot express one.

### Many records — always bounded

```php
User::query()->where('is_active', true)->limit(20)->get();
```

```php
User::all();                           // UnboundedQueryException
User::query()->where(...)->get();      // UnboundedQueryException
```

There is no "all" over a network. The exception names the limit and the alternatives.

### Filters

```php
User::query()
    ->where('is_active', true)
    ->where('created_at', '>=', now()->subMonth())
    ->whereIn('id', [1, 2, 3])
    ->whereNotNull('email_verified_at')
    ->whereBetween('created_at', [$from, $to])
    ->where('first_name', 'like', 'Ada%')        // starts_with
    ->where(fn ($q) => $q->where('gender', 'female')->orWhere('gender', 'other'))
    ->limit(20)
    ->get();
```

`=`, `!=`, `<>`, `>`, `>=`, `<`, `<=` translate directly. `like` translates only in the three published shapes:
`'x%'`, `'%x%'`, `'%x'`. A wildcard anywhere else — `'a%b'` — has no operator and is refused rather than
approximated. Nested closures go three levels deep; deeper is `query_too_complex`.

`whereDate()` and `whereYear()` translate with `=`. `whereMonth()`, `whereDay()` and `whereTime()` do not — use
`whereBetween()` on the timestamp.

A `whereIn()` longer than one request may carry -- `min(in_chunk, 500)` -- is **split into several requests**
and the results merged; it is never truncated. A `get()` that named no limit of its own splits at
`max_query_limit` instead, because there the key list is what bounds the request.

Three shapes cannot be split, and are refused by name rather than answered wrongly:

- `whereNotIn()`. The pieces intersect instead of unioning: "not in the first half" and "not in the second
  half" are both true of very nearly every record, so merging them would answer with very nearly everything.
- A list with an `orWhere()` beside it. The other branch matches rows the list never named, so each of them
  would come back once per piece.
- A negated clause. `NOT (a or b)` is not the question `NOT a or NOT b` asks.

### Projection

```php
User::query()->select('id', 'first_name')->limit(50)->get();
User::query()->limit(50)->pluck('first_name', 'id');
User::query()->where('id', 7)->value('email');
```

Selecting nothing means the resource's default fields, which is usually a small set — not every column.

### Ordering

```php
User::query()->orderBy('created_at', 'desc')->limit(20)->get();
User::query()->latest()->limit(20)->get();
$builder->reorder()->orderBy('id')->limit(20)->get();
```

Only fields the schema marks sortable. NULLs sort last in both directions — neither driver's default, and the
only answer that is stable across both.

### Aggregates

```php
User::query()->where('is_active', true)->count();
User::query()->where('is_active', true)->exists();      // one limit-1 query on the key, not a count
User::query()->doesntExist();
User::query()->max('created_at');
Wallet::query()->where('user_id', 7)->sum('balance');
```

Aggregates follow SQL: `count` over no rows is `0`; `min`/`max`/`sum`/`avg` over no rows is `null` (Laravel's own
`sum()` turns that into 0). A field must publish the aggregate for it to be allowed.

```php
User::query()->distinct()->count('gender');             // count(distinct gender), NULL not counted
```

The one distinct aggregate is a count over one named field the schema marks `distinct`. It leaves NULL out, as SQL
and Eloquent do locally. `distinct()` before any other aggregate, before a `count()` with no field, or over an
`in` list long enough to be split is refused — the flag would be lost, or the pieces would count a value once per
piece, and the answer would be wrong while looking right.

---

## 8. Pagination in one request

```php
$users = User::query()->active()->orderBy('created_at', 'desc')->paginate(20);
```

One request. The spec carries `with_total: true` and the response carries both the rows and the count, so this is
**not** the two queries a local paginator runs.

```php
$users->total();          // meta.total
$users->currentPage();
$users->hasMorePages();   // meta.has_more
{{ $users->links() }}
```

`simplePaginate()` skips the total and is one request as well. `cursorPaginate()` is refused: it needs a keyset
cursor the API does not expose.

Asking for more than `max_query_limit` throws before the call.

---

## 9. Walking a large result set

```php
User::query()->where('is_active', true)->chunkById(100, function ($users) {
    foreach ($users as $user) { /* ... */ }
});

foreach (User::query()->lazyById(100) as $user) { /* ... */ }

User::query()->eachById(function (User $user) { /* ... */ }, 100);
```

`chunk()`, `lazy()`, `cursor()` and `each()` are refused. They page by **offset**, and against a table that is
being written to concurrently an offset page skips rows and repeats others. The `ById` forms page by the key,
which is stable. It is the same reason Laravel recommends them locally, except here the window between pages is a
network round trip wide.

---

## 10. Relations across the boundary

### Local → remote

The foreign key is a plain column on your table, so this is ordinary Eloquent:

```php
class Order extends Model            // a LOCAL table
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

$order->user;                        // GET users/{id}, memoized by the identity map
Order::with('user')->get();          // eager load -> one query with an "in"
```

### Remote → remote

Asking the server to include it, because only the server can join:

```php
User::query()->with('roles')->limit(20)->get();        // spec "include"
User::query()->withCount('wallets')->limit(20)->get(); // spec "counts"
User::query()->has('wallets', '>=', 1)->limit(20)->get();
User::query()->whereHas('wallets', fn ($q) => $q->where('balance', '>', 0))->limit(20)->get();
```

Only what the schema publishes under `includes` and `relations` can be asked for.

### Remote → local: refused

```php
User::whereHas('orders', ...)        // "orders" is a LOCAL table
```

Two databases cannot be joined by either side. The exception says so, and says what to write:

```php
$ids = Order::where('total', '>', 100)->distinct()->pluck('user_id');
$users = User::query()->whereIn('id', $ids)->limit(100)->get();
```

### Lazy loading is loud

Outside production the provider turns on `Model::preventLazyLoading()`. A lazy load here is a network call per
row; a `LazyLoadingViolationException` in development is the N+1 you would otherwise meet in production. Opt out
with `esanj.remote_eloquent.telemetry.prevent_lazy_loading => false` in the published config.

---

## 11. Writes, idempotency and stale records

```php
$user = User::create([
    'first_name' => 'Ada',
    'last_name' => 'Lovelace',
    'email' => 'ada@example.com',
]);                                   // POST users        -> 201, the model is refilled from the response

$user->first_name = 'Ada B.';
$user->save();                        // PATCH users/{id} with ONLY the dirty attributes

$user->delete();                      // DELETE users/{id}
User::destroy([1, 2]);
```

Model events (`creating`, `created`, `updating`, `saved`, `deleting`, …) fire exactly as usual.

### Idempotency

Every mutating call carries an `Idempotency-Key`, and the key identifies an **operation**, not a request:

- a retry — internal, a queued job's second attempt, the same code path running again — **reuses** the key, so
  the server recognises the replay instead of applying the write twice;
- the key is released once the outcome is known, so the next write is a new operation with a new key.

A write that throws keeps its key. That is deliberate: the retry must be recognised as the same charge.

A queued job retried in a **fresh process** would otherwise mint a different key and apply the write again. Bind
the scope to something stable and the keys are derived instead of random:

```php
use Esanj\RemoteEloquent\Idempotency\OperationContext;

app(OperationContext::class)->useScope($this->job->uuid());
```

Read-only POSTs — `query`, `aggregate`, `validate` — carry no key.

### Stale records

If the model carries a `version` attribute it is sent as `if_match`. When someone else wrote first the server
answers `409 stale_record`:

```php
try {
    $user->save();
} catch (ConflictException $e) {
    $user->refresh();          // re-read, re-apply, re-submit
}
```

### Mass writes are refused

```php
User::where('is_active', false)->update(['is_active' => true]);   // UnsupportedQueryException
User::where('is_active', false)->delete();                        // UnsupportedQueryException
$user->increment('login_count');                                  // UnsupportedQueryException
```

There is no endpoint for a write by filter, and doing it row by row is a *different* operation with different
failure modes. Load a page and write each model, or ask for a domain action that owns the rule — which is what
`increment` really wants.

---

## 12. Soft deletes

```php
use Esanj\RemoteEloquent\Concerns\RemoteSoftDeletes;

class User extends ApiUser
{
    use RemoteSoftDeletes;
}
```

```php
$user->delete();          // closes THIS application's membership; the account lives on
$user->restore();         // POST users/{id}/restore — reopens the membership
$user->forceDelete();     // DELETE users/{id}?force=1 — removes the account itself
$user->trashed();         // reads the deleted_at the server published

User::query()->withTrashed()->limit(20)->get();   // trashed: "with" — members and closed memberships
User::query()->onlyTrashed()->limit(20)->get();   // trashed: "only" — closed memberships only
```

For `users`, "deleted" means **deleted for this application**: the account is shared by every application that
hosts it, so `delete()` closes the calling application's membership and `deleted_at` is when that happened.
`forceDelete()` removes the account for everyone and needs `users.force_delete`; the server refuses it with
`409` while another application still hosts the user.

This trait is **not** Laravel's. There is no global scope and no `deleted_at` filter, because the filtering is the
server's job: the spec carries a `trashed` mode and Accounting decides what that means for the resource. The
default mode already excludes trashed rows, so `withoutTrashed()` sends nothing extra. `fresh()` and `refresh()`
on a model you just deleted ask with `trashed: "with"`, so they still find it.

`restoreOrCreate()` and `createOrRestore()` are refused for the same reason as `firstOrCreate()` — there is no
atomic endpoint behind them.

---

## 13. Domain actions

Anything with a rule attached to it is an action, not a column:

```php
$user->remoteAction('suspend');
$user->remoteAction('unsuspend');
$user->remoteAction('change-password', ['password' => $new]);
$user->remoteAction('change-email', ['email' => 'ada@example.com']);
$user->remoteAction('sync-roles', ['roles' => ['editor', 'support']]);
$user->remoteAction('notify', ['title' => 'Welcome', 'message' => 'Your account is ready.', 'channels' => ['database']]);
```

The payload travels as `{"payload": {...}}`; that wrapping is the transport's job, pass the bare array.
`email` and `phone_number` are writable on create only: change an existing address with `change-email`, which
needs its own permission and signs the user out everywhere. `sync-roles` never grants the protected roles
(`admin`, `application_admin`, `user`) however they are spelled, and leaves a protected role the membership
already holds in place. `users.update.all` lets `save()` reach users outside this application's members; without
it only members can be edited.

This is where the logic that used to be an `UPDATE` lives now. `is_active` is not writable — suspending an
account emails the person, writes an audit row and ends their sessions, and none of that happens when a column
changes. The action is the operation; the column is a consequence.

`php artisan remote:schema users` lists the actions a resource publishes; an unknown one throws
`UnsupportedResourceException` naming the ones that exist. Actions are mutating, so they carry an idempotency
key.

---

## 14. Validation

### `exists` and `unique`

```php
use Illuminate\Validation\Rule;

$request->validate([
    'user_id' => ['required', Rule::exists(User::class, 'id')],
    'email'   => ['required', 'email', Rule::unique(User::class, 'email')->ignore($user)],
]);
```

What goes over the wire:

- `unique`, and `exists` on one value, become a single `POST users/aggregate` with `{function: count, ...}`;
- `exists` over a list counts the distinct matches, because Laravel compares the answer with
  `count(array_unique($values))`. On the key that is one `count` per page, on a field the schema publishes as
  `distinct` one distinct `count` per page, and on any other field one limit-1 query per value, so that repeats of
  one value cannot hide another.

Use the **class-string** form. `'exists:users,id'` is indistinguishable from a local `users` table, so it goes to
Laravel's own verifier and silently queries nothing. `remote:doctor` lists every string-form rule it finds.

`where()` closures inside the rule are translated too — `whereIn`, `whereNotIn`, equality, null checks. A closure
doing something the spec cannot express throws `UnsupportedValidationRuleException` with zero network calls.

### Asking the server to validate

```php
$user->fill($request->validated());
$user->validateRemote();                    // POST users/validate, mode=update

User::validateRemoteInto($attributes);      // mode=create, no record in hand
```

A failure throws `RemoteValidationException`, which **extends** `Illuminate\Validation\ValidationException`. So
a controller needs no `try`/`catch`: the request bounces back to the form with Accounting's own messages on the
right fields, in the right language (`Accept-Language` is sent from `app()->getLocale()`).

---

## 15. The auth guard

The signed-in person is a record in Accounting. The guard verifies their token **locally** — no network — and
then reads `GET users/me` with that token, once per request.

```php
// config/auth.php
'defaults' => ['guard' => 'accounting'],

'guards' => [
    'accounting' => [
        'driver' => 'accounting',
        'provider' => 'remote-users',
        'input' => 'session',            // or 'bearer' for a token API
        // 'audiences' => ['<client-id>'],   default: esanj.auth_bridge.expected_audiences, else the client id; required
        // 'issuer' => 'https://auth.esanj.io',
        // 'authorized_parties' => [],
        // 'scopes' => ['users.read'],
        // 'algorithm' => 'RS256',
        // 'leeway' => 30,                   clock skew, capped at 300
        // 'jwks_url' => null, 'jwks_ttl' => 3600,
        // 'user_ttl' => 0,                  0 = per-request only
        // 'me' => ['fields' => ['id','first_name'], 'include' => ['roles']],
    ],
],

'providers' => [
    'remote-users' => ['driver' => 'remote', 'model' => App\Models\User::class],
],
```

```php
auth()->user();            // your User model
auth()->id();              // the token's "sub"
$this->authorize('update', $user);
@can('update', $user)
```

The signing key comes from `esanj/auth-bridge` (`esanj.auth_bridge.public_key` or `public_key_path`). A JWKS URL
is supported and optional; Passport issues tokens with no `kid`, so the pinned PEM is the normal path. `iss`,
`azp` and scope checks are opt-in and enforced only when configured.

### Fail loud vs. fail guest

| Situation | Answer |
| --- | --- |
| Expired, wrong signature, wrong `aud`/`iss`/`azp`, missing scope, not a JWT, algorithm swap | warning logged, **guest** |
| No public key and no JWKS, session mode with no auth-bridge, unreachable JWKS, `firebase/php-jwt` missing | `RemoteAuthenticationException` |
| `users/me` answers 401 — the user's token was revoked, or they signed out elsewhere | warning logged, **guest**; the session token is dropped |
| `users/me` answers 401 blaming this application's client (revoked or inactive) | `RemoteAuthenticationException` |
| `users/me` answers 503 / 429 / 403 | the exception propagates |
| `users/me` answers 404 for the subject | `null` |

Answering "guest" when the question could not be *asked* would log the whole application out and read as a login
bug, so a configuration fault is loud.

### What it cannot do

```php
Auth::attempt(['email' => ..., 'password' => ...]);    // throws
```

The password hash is not a published field and never will be. A login form that says "wrong password" when the
check could not be performed sends people to reset a password that was correct. Sign-in is the OAuth flow; this
guard resolves who the resulting token belongs to. `remember me` is disabled for the same kind of reason — there
is nowhere to store the token, and one that silently never remembers is worse than one never offered.

---

## 16. Acting for a user

A write that originated in a person's request should carry that person's identity, so Accounting can apply their
permissions on top of the application's. With `REMOTE_ELOQUENT_ACTOR_EXCHANGE_URL` set, the user signed in on the
default guard is picked up on every write (and on `validateRemote()`, which presents the actor without spending
it), their token is exchanged for a short-lived actor token, and it is sent as `Actor-Authorization`
automatically. An actor token is good for exactly one write, so it is exchanged again for the next one.

`GET users/me` (`ApiUser::me()` and the `accounting` guard) is different: it is answered with the user's **own**
access token in `Authorization`, not with an actor token.

In a job or a command, where there is no request, pin the actor:

```php
User::actingAsRemote($user);
try {
    $user->remoteAction('suspend');
} finally {
    User::forgetRemoteActor();
}
```

Refusals, in order:

- no user → no actor header;
- no `exchange_url` → no actor header (the documented opt-out); a write the server marks actor-required comes
  back as `403 actor_denied` rather than being performed anonymously;
- a user **with** `exchange_url` set and no token available → `RemoteAuthenticationException`, so a write that
  speaks for a person is never quietly sent as the application;
- the subject token's `sub` not matching the actor → refused. That claim is only ever used to refuse, never to
  grant.

---

## 17. Permissions and quota

```php
use Esanj\RemoteEloquent\Facades\RemoteAccess;

RemoteAccess::can('users.update');
RemoteAccess::allows('users', 'delete');
RemoteAccess::permissions();
RemoteAccess::quota();            // the last RateLimit-* headers seen; sends nothing
RemoteAccess::refresh();          // force a re-read
```

```blade
@if (RemoteAccess::allows('users', 'update'))
    <a href="{{ route('users.edit', $user) }}">Edit</a>
@endif
```

`GET access` is read once per process and kept until some other response reports a new `X-Permissions-Version`,
so a menu asking twelve questions costs nothing.

**It is advisory.** An unreadable access endpoint answers `true`. This is for hiding a button, never for the
check itself — Accounting authorizes every call whether or not this ran, and a client-side "no" caused by a cold
cache would be a worse bug than a button that turns out to 403. In a test, `RemoteAccess::fake([...])` makes it
strict.

---

## 18. Error handling

Every failure is a typed exception. With `errors.render` on, each one answers the browser itself.

| Server says | You get | Rendered |
| --- | --- | --- |
| `400 invalid_query` / `unknown_field` / `operator_not_allowed` / `field_not_writable` / `query_too_complex`, `413 payload_too_large` | `InvalidQueryException` | 500 |
| refused before sending | `UnsupportedQueryException`, `UnboundedQueryException`, `UnsupportedValidationRuleException` | 500 |
| `404 unknown_resource` | `UnsupportedResourceException` | 500 |
| `401` (after one automatic refresh) | `RemoteAuthenticationException` | 500 |
| `403 permission_denied` / `privileged_account` / `actor_denied` | `AccessDeniedException` — `requiredPermission()` | 403 |
| `404 not_found` | `ModelNotFoundException`, or `null` from `find()` | 404 |
| `404 user_merged` | reads follow it **once** with a warning, then `UserMergedException` — `mergedInto()`; writes never follow it | 409 |
| `409 stale_record` / `idempotency_mismatch` / `idempotency_in_progress` | `ConflictException` | 409 |
| `422 validation_failed` / `field_locked` | `RemoteValidationException` | 422 / back to the form |
| `429 rate_limited` | `RateLimitedException` — `retryAfter()`, `bucket()` | 429 + `Retry-After` |
| `503`/`504 query_timeout` | `RemoteTimeoutException` | 503 |
| `503 unavailable`, `500 internal_error`, any other 5xx, a 3xx redirect, connect or TLS failure | `TransportException` | 503 |
| `405 method_not_allowed` / `415 unsupported_media_type` | `InvalidQueryException` | 500 |

Why a 400 renders 500: a malformed query is a bug in **your** code, not in the visitor's request. Showing them a
400 would blame the wrong party and hide the bug from your error tracker.

Every exception carries `errorCode()`, `status()`, `requestId()` and `context()`. The request id is the one in
Accounting's logs — quote it in a ticket.

```php
try {
    $user->remoteAction('suspend');
} catch (AccessDeniedException $e) {
    report($e);
    return back()->withErrors(__('You need :permission.', ['permission' => $e->requiredPermission()]));
} catch (RateLimitedException $e) {
    return back()->withErrors(__('Try again in :n seconds.', ['n' => $e->retryAfter()]));
}
```

With `REMOTE_ELOQUENT_RENDER_ERRORS=false`, every `render()` returns null and your own handler takes over.

**A 429 is never retried, never replayed on another transport and never silently re-sent.** That was version 1's
headline bug: a rate-limited write that had already been accepted got sent again.

---

## 19. Rate limits in queued jobs

```php
use Esanj\RemoteEloquent\Queue\RemoteRateLimited;

class SyncAccount implements ShouldQueue
{
    public function middleware(): array
    {
        return [new RemoteRateLimited()];        // fallback 60s, clamped at 3600
    }
}
```

It catches **only** `RateLimitedException` and releases the job for exactly `Retry-After` seconds. A timeout or an
outage is a different decision and is left to the job's own `$tries` / `retryUntil()`. There is no jitter and no
backoff of its own.

---

## 20. Testing

No HTTP, no fixtures, no mock expectations. The fake **executes** the QuerySpec against rows you hand it, so a
green test proves the query says what you meant — not merely that some method was called.

```php
use Esanj\RemoteEloquent\Testing\FakeResourceTransport;

protected function tearDown(): void
{
    FakeResourceTransport::reset();
    parent::tearDown();
}

public function test_it_lists_active_users(): void
{
    User::fake([
        ['id' => 1, 'first_name' => 'Ada',   'is_active' => true],
        ['id' => 2, 'first_name' => 'Grace', 'is_active' => false],
    ]);

    $users = User::query()->active()->limit(10)->get();

    $this->assertCount(1, $users);
    FakeResourceTransport::assertQueried('users', fn (array $spec) => $spec['limit'] === 10);
    FakeResourceTransport::assertRequestCount(1);
}
```

Arranging it:

```php
FakeResourceTransport::seed('wallets', [...]);
FakeResourceTransport::identifyBy('invoices', 'uuid');     // a resource whose key is not "id"
FakeResourceTransport::schemaFor('users', [...]);          // else one is inferred from the rows
FakeResourceTransport::failWith('users', 429, retryAfter: 30);
FakeResourceTransport::failValidation('users', ['email' => ['Taken.']]);
FakeResourceTransport::handleAction('users', 'suspend', fn (array $payload, array $row) => true);
FakeResourceTransport::actingAs(['id' => 1]);              // what users/me answers
FakeResourceTransport::grant(['users.read', 'users.update']);
```

Asserting:

```php
FakeResourceTransport::assertCreated('users', fn (array $attributes) => $attributes['email'] === 'ada@example.com');
FakeResourceTransport::assertUpdated('users');
FakeResourceTransport::assertDeleted('users');
FakeResourceTransport::assertNothingDeleted();
FakeResourceTransport::assertActioned('users', 'suspend');
FakeResourceTransport::assertRequestCount(1);              // the N+1 guard
```

Or fluently, through the facade:

```php
RemoteResource::fake()
    ->seed('users', [['id' => 1, 'first_name' => 'Ada']])
    ->grant(['users.read'])
    ->assertRequestCount(0);
```

### Semantics the fake pins

They are stricter than either driver's defaults on purpose — a test that is green here is green on MySQL **and**
PostgreSQL:

- `between` / `not_between` are **half-open**: lower inclusive, upper exclusive;
- `in []` matches nothing; `not_in []` matches everything, including null rows;
- three-valued logic: a comparison with null is unknown, and `NOT unknown` is unknown — so neither
  `where('x', 1)` nor `whereNot('x', 1)` returns a null row. Only `null` / `not_null` are definite;
- LIKE wildcards inside a value are literal;
- text matching is **case sensitive**;
- NULLs order last in both directions;
- pipeline order: trashed → where → distinct → order → total → offset/limit → projection, counts, includes.

A repeated idempotency key replays the stored response instead of writing twice, so retries are testable.
`delete()` soft-deletes when the row has a `deleted_at` or the schema says so, otherwise it removes the row.

The fake does **not** enforce the schema on writes — use `failWith()` / `failValidation()` to drive those paths.
Enforcing it would make the fake a second implementation of the server's rules, and the two would drift.

---

## 21. Artisan commands

```bash
php artisan remote:schema users                 # the published contract
php artisan remote:schema users --diff          # ...against your model; non-zero on drift
php artisan remote:schema users --diff --model="App\Models\Account"

php artisan remote:access                       # permissions + quota
php artisan remote:access --json

php artisan remote:model users --auth           # generate a model from the schema
php artisan remote:model wallets --namespace="App\Models\Accounting"

php artisan remote:doctor                       # everything at once
php artisan remote:doctor --offline             # config only, no network
```

`remote:doctor` checks the configuration, the base URL's scheme, reachability, the token, the permissions
granted, your models against the published schemas, and scans `app/` for `exists:` / `unique:` rules in string
form. It exits non-zero on a problem; warnings do not fail CI.

`remote:model` has no `--force`. It will not overwrite a file you have edited.

---

## 22. Performance

### The identity map

Resolving the same record twice in one request is one call, not two. The key includes the application, the
actor, the resource, the id, the projection, the includes, the trashed mode, and both version headers — because
every one of those changes what the server would return. A record fetched with five fields is never served to a
caller that asked for all of them.

It dies with the request. `cache.find_ttl` is 0: records are **not** cached across requests, because Accounting
is the system of record for balances and permissions and serving a stale one by default would be a correctness
bug wearing a speedup's clothes.

### The N+1 warning

Code that used to be a join now reads exactly the same and costs one round trip per row. Past
`telemetry.call_warning_threshold` calls in a single request, a warning names the resource and operation that
repeated most:

```
Remote Eloquent made 43 calls to the account service in one request (threshold 20).
hotspots: {"users.find": 41}
```

That line is usually the whole diagnosis. The fix is nearly always the same shape:

```php
// 41 calls
foreach ($orders as $order) { $names[] = $order->user->first_name; }

// 1 call
$users = User::query()->whereIn('id', $orders->pluck('user_id')->unique())->limit(100)->get()->keyBy('id');
```

### Schema and access caching

Both are cached and both carry a version header. A response reporting a version other than the cached one
discards the entry immediately — invalidation with no invalidation step. A schema that cannot be fetched is not an
error: the client-side check is skipped and the request goes out exactly as it would have, because Accounting
validates it anyway.

---

## 23. Everything it refuses

Each throws before any network call, naming the alternative.

| Refused | Instead |
| --- | --- |
| `all()`, unbounded `get()` | `->limit(n)->get()`, `chunkById()` |
| unbounded `pluck()` | `->limit(n)->pluck(...)` |
| `cursorPaginate()` | `paginate()`, `simplePaginate()` |
| `chunk()`, `lazy()`, `cursor()`, `each()` | `chunkById()`, `lazyById()`, `eachById()` |
| `count('column')` | `->whereNotNull('column')->count()` |
| `whereHas()` with `withTrashed()`/`onlyTrashed()`, or on a relation whose definition adds a `where()` | the `where()` in the closure; for trashed, the related ids through `whereIn()` |
| `firstOrCreate()`, `createOrFirst()`, `updateOrCreate()`, `incrementOrCreate()`, `upsert()` | `first()` then `create()`, or a domain action |
| `restoreOrCreate()`, `createOrRestore()` | the same |
| builder `update()` / `delete()` / `forceDelete()` / `touch()` | load the page, write each model |
| `increment()`, `decrement()`, `incrementEach()`, `decrementEach()` | a domain action |
| `whereColumn()` | compare in PHP, or ask for a computed field |
| `whereRaw`, `selectRaw`, `orderByRaw`, `havingRaw`, `DB::raw`, `toSql()` | a published field |
| `whereExists()`, subqueries | `pluck()` the ids, then `whereIn()` |
| `whereJson*`, `whereFullText` | ask for the field to be published |
| `whereMonth`, `whereDay`, `whereTime` | `whereBetween()` on the timestamp |
| `inRandomOrder()`, `groupBy()`, `having()`, `join()` | an aggregate, or an endpoint |
| `withAggregate()`, `hasMorph()` | `withCount()`, or a domain query |
| `whereHas` / `has` / `withCount` against a **local** relation | `pluck()` the ids, then `whereIn()` |
| `DB::transaction()` on this connection | one idempotent call, or a domain action |
| `insert()`, `insertGetId()`, `insertUsing()`, `truncate()` | `create()` per record |
| `Auth::attempt()` | the OAuth flow |

---

## 24. Troubleshooting

**`UnsupportedResourceException: ... has no transport`** — the provider is not registered, or the transport could
not be built. Run `php artisan remote:doctor`. In a test, call `Model::fake()` first.

**`Database connection [remote-eloquent] not configured`** — something asked for a real connection. The provider
registers it; if you cleared the config cache in a half-deployed state, run `php artisan config:clear`.

**`400 unknown_field`** — your model names something the contract does not publish. `remote:schema {resource} --diff`.

**`400 operator_not_allowed`** — the field exists but not with that operator. The schema table prints which ones
it takes; `is_active` commonly allows only `eq`.

**`403 permission_denied`** — `remote:access` shows what this application holds. `requiredPermission()` on the
exception names what was missing.

**`403 actor_denied`** — the write needs the end user's identity. Set `REMOTE_ELOQUENT_ACTOR_EXCHANGE_URL`, or
pin the actor with `actingAsRemote()`.

**`409 idempotency_in_progress`** — the same operation is still running. Retry later with the same key; do not
mint a new one.

**A page got slow** — look for the N+1 warning in the log, then `FakeResourceTransport::assertRequestCount()` in
a test to hold the line.

**`Rule::exists()` seems to hit the local database** — you used the string form, or the model has no
`$connection = 'remote-eloquent'`. `remote:doctor` lists the string-form rules it finds.

**`LazyLoadingViolationException` in development** — that is the point. Eager load it, or `pluck()`/`whereIn()`.

---

## 25. Cheat sheet

```php
// read
User::find(7);
User::query()->where('email', $email)->first();
User::query()->active()->orderBy('created_at', 'desc')->limit(20)->get();
User::query()->active()->paginate(20);                    // rows + total, ONE request
User::query()->where('is_active', true)->count();
User::query()->whereIn('id', $ids)->limit(100)->pluck('first_name', 'id');
User::query()->chunkById(100, fn ($users) => ...);

// remote relations
User::query()->with('roles')->withCount('wallets')->limit(20)->get();
User::query()->has('wallets')->limit(20)->get();

// write
$user = User::create([...]);
$user->update(['first_name' => 'Ada']);
$user->delete(); $user->restore(); $user->forceDelete();
$user->remoteAction('suspend');

// validate
Rule::exists(User::class, 'id');
Rule::unique(User::class, 'email')->ignore($user);
$user->validateRemote();

// auth
auth()->user();  User::actingAsRemote($user);  User::forgetRemoteActor();

// access
RemoteAccess::allows('users', 'update');  RemoteAccess::quota();

// test
User::fake([...]);  FakeResourceTransport::assertRequestCount(1);  FakeResourceTransport::reset();

// diagnose
php artisan remote:doctor
php artisan remote:schema users --diff
php artisan remote:access
```
