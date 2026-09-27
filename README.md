# Remote Eloquent

Eloquent models whose rows live in the **Esanj Accounting** service.

```php
use App\Models\User;

$user = User::find(7);                                  // GET  users/7
$page = User::query()->active()->paginate(20);          // POST users/query  (rows + total, one request)
$user->update(['first_name' => 'Ada']);                 // PATCH users/7
$user->remoteAction('suspend');                         // POST users/7/actions/suspend
```

No local `users` table, no schema copy, no hand-written API client. What leaves this process is a **QuerySpec** —
a structured description of the query — which Accounting validates against the contract it publishes before it
runs anything.

---

## What changed in 2.0, and why

Version 1 compiled Eloquent to a MySQL **SQL string** and shipped it to Accounting, which vetted it with regular
expressions. Three things were wrong with that, and all three were load-bearing:

| v1 | Why it had to go |
| --- | --- |
| The client built SQL | Accounting's table layout became a public API. Renaming a column broke consumers. |
| The server vetted SQL with regexes | Every domain rule — tenancy, field-level permissions, soft deletes — was bypassed by anything that parsed as a legal `SELECT`. |
| The SQL was MySQL's | It did not run on PostgreSQL at all. |
| A failed call was replayed on the other transport | Writes and rate-limited calls were silently re-sent. |

Version 2 sends a **QuerySpec** to a resource API. The server owns the decision; the client never builds SQL.
The connection under these models has **no PDO at all** — if some path ever reaches a grammar and compiles a
string, it throws instead of running.

The second rule, and it is the one to remember: **this client never silently drops a condition.** Anything it
cannot express refuses *before* the request is made, and the exception names the supported alternative. A query
that quietly returns the wrong rows is worse than one that fails.

---

## Installation

```bash
composer require esanj/remote-eloquent
```

Laravel discovers the service provider. It registers the `remote-eloquent` connection, the transport, the
`accounting` auth guard, the presence verifier behind `Rule::exists()`, and four artisan commands — nothing to
wire by hand.

Publish the config only if you need to change it:

```bash
php artisan vendor:publish --tag=esanj-remote-eloquent-config   # -> config/esanj/remote_eloquent.php
```

### Environment

The base URL and the client credentials default to the `ACCOUNTING_BRIDGE_*` variables, so a service already
talking to Accounting needs almost nothing:

```dotenv
REMOTE_ELOQUENT_DRIVER=rest
# REMOTE_ELOQUENT_BASE_URL=https://auth.esanj.io    # defaults to ACCOUNTING_BRIDGE_BASE_URL
REMOTE_ELOQUENT_PREFIX=/api/remote/v1
REMOTE_ELOQUENT_TIMEOUT=5
REMOTE_ELOQUENT_CONNECT_TIMEOUT=2
REMOTE_ELOQUENT_MAX_LIMIT=100
REMOTE_ELOQUENT_IN_CHUNK=500

# Only when a write must speak for the signed-in user, not just the application:
# REMOTE_ELOQUENT_ACTOR_EXCHANGE_URL=https://auth.esanj.io/oauth/token
```

Run `php artisan remote:doctor` afterwards. It checks the configuration, the reachability of the service, the
permissions it grants you, and your models against the published schemas.

---

## Defining a model

```php
<?php

namespace App\Models;

use Esanj\RemoteEloquent\Concerns\RemoteSoftDeletes;
use Esanj\RemoteEloquent\Models\ApiUser;

class User extends ApiUser
{
    use RemoteSoftDeletes;

    protected string $resource = 'users';
    protected $connection = 'remote-eloquent';       // makes Rule::exists(User::class) remote

    protected $fillable = ['first_name', 'last_name', 'email', 'phone_number', 'gender'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'global_ban' => 'boolean',
            'email_verified_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
```

Extend `ApiModel` for an ordinary resource, `ApiUser` for the one behind the auth guard. `$fillable` should be
exactly the fields the contract marks writable — everything else comes back as `400 field_not_writable`.

Or let the schema write it for you:

```bash
php artisan remote:model users --auth        # app/Models/User.php from the published schema
php artisan remote:schema users --diff       # what drifted since
```

Casts, accessors, `$appends`, `$hidden`, model events, `$fillable`/`$guarded` and local relations all behave
exactly as they do on a normal model. `$timestamps` is off and `created_at`/`updated_at` are stripped from every
write: the server stamps its own rows.

---

## The supported surface

Everything here works, and works the way it reads:

| | |
| --- | --- |
| **Fetch one** | `find`, `findOrFail`, `findMany`, `whereKey`, `first`, `firstOrFail`, `sole`, `firstOrNew` |
| **Fetch many** | `get()` **with an explicit `limit()`**, `pluck()` with a limit, `value` |
| **Project** | `select`, `addSelect` |
| **Filter** | `where` with `=` `!=` `<>` `>` `>=` `<` `<=`, `orWhere`, nested closures (depth ≤ 3), `whereNot`, `whereIn`, `whereNotIn`, `whereIntegerInRaw`, `whereNull`, `whereNotNull`, `whereBetween`, `whereNotBetween`, `whereDate`/`whereYear` with `=` |
| **LIKE** | `'x%'`, `'%x%'`, `'%x'` — the three the contract publishes as `starts_with`, `contains`, `ends_with` |
| **Order** | `orderBy`, `latest`, `oldest`, `reorder` |
| **Page** | `limit`/`take`, `offset`/`skip`, `paginate`, `simplePaginate` |
| **Aggregate** | `count`, `exists`, `doesntExist`, `min`, `max`, `sum`, `avg` |
| **Walk** | `chunkById`, `lazyById`, `eachById` — pages are capped at `limits.max_query_limit` |
| **Write** | `create`, `save`, `update` on a model, `delete`, `destroy` |
| **Soft delete** | `RemoteSoftDeletes`: `withTrashed`, `onlyTrashed`, `restore`, `forceDelete`, `trashed` |
| **Relations** | a **local** model's `belongsTo` to a remote one; its `hasMany` through a bounded query (see *Relations*); server-side `with()`/`withCount()` |
| **Compose** | `when`, `tap`, scopes |
| **Domain** | `remoteAction()`, `validateRemote()`, `remoteCan()` |

The operators a field accepts are per-field and published by the server: `is_active` may allow only `eq`,
`created_at` only the range operators. A disallowed one is `400 operator_not_allowed`, and `remote:schema` prints
the table.

---

## What it refuses, and what to write instead

Each of these throws **before any network call**, and the message names the alternative.

| You wrote | Why it cannot work | Write instead |
| --- | --- | --- |
| `User::all()` / unbounded `get()` | There is no "all" over a network. It would page the whole account service into memory. | `->limit(100)->get()`, or `chunkById()` |
| `cursorPaginate()` | Needs a keyset cursor the API does not expose. | `paginate()` or `simplePaginate()` |
| `chunk()`, `lazy()`, `cursor()`, `each()` | Offset paging over a table that is being written to skips and repeats rows. | `chunkById()`, `lazyById()`, `eachById()` |
| `firstOrCreate()`, `updateOrCreate()`, `upsert()` | There is no atomic upsert endpoint; a read-then-write would race. | `first()` then `create()`, or a domain action |
| `User::where(...)->update([...])` / `->delete()` | A mass write by filter has no endpoint, and doing it row by row is not the same operation. | Load the page, then write each model |
| `increment()`, `decrement()`, `touch()` | Not atomic across the network. | A domain action that owns the rule |
| `whereColumn`, `whereRaw`, `selectRaw`, `orderByRaw`, `DB::raw` | Raw SQL is exactly what 2.0 removed. | A published field, or an action |
| `whereExists`, subqueries | The server evaluates no client SQL. | `pluck()` the ids, then `whereIn()` |
| `whereJson*`, `whereFullText` | Not in the contract. | Ask for the field to be published |
| `whereMonth`, `whereDay`, `whereTime` | Only `whereDate`/`whereYear` translate. | `whereBetween` on the timestamp |
| `inRandomOrder`, `groupBy`, `having`, `join` | Set operations belong to the owner of the data. | An aggregate, or an endpoint |
| `whereHas()` against a **local** table | The two sides are in different databases. | `pluck()` the ids locally, then `whereIn()` |
| `whereHas()` with `withTrashed()`/`onlyTrashed()`, or on a relation whose definition adds a `where()` | A has clause carries only the closure's field conditions. | Move the `where()` into the closure; for a trashed state, query the related resource and `whereIn()` its ids |
| `count('column')` | SQL skips NULLs there, the API counts rows. | `->whereNotNull('column')->count()` |
| `DB::transaction()` on this connection | There is no distributed transaction. | One idempotent call, or a domain action |
| `Auth::attempt()` | The password hash is not a published field, and never will be. | The OAuth flow, and the `accounting` guard |

```php
// Not this:
$orders = Order::whereHas('user', fn ($q) => $q->where('is_active', true))->get();

// This:
$ids = User::query()->active()->limit(100)->pluck('id');
$orders = Order::whereIn('user_id', $ids)->get();
```

---

## Relations

**Local → remote** works as usual, because the foreign key is a plain column on your table:

```php
class Order extends Model                 // a LOCAL table
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);   // one GET users/{id}, through the identity map
    }
}
```

A local model's `hasMany` to a remote one cannot be eager-loaded — `with()` would be an unbounded `whereIn()`.
Ask through the relation with a limit instead:

```php
$team->members()->limit(50)->get();
```

**Remote → remote** is done by the server, through `include`:

```php
User::query()->with('roles')->limit(20)->get();     // one request, roles in the payload
User::query()->withCount('wallets')->limit(20)->get();
```

Only the relations the schema publishes under `includes` and `relations` can be asked for. `has()` and
`whereHas()` translate too — as long as the relation is **remote**:

```php
User::query()->has('wallets', '>=', 1)->limit(20)->get();      // server-side "has" clause
```

Lazy loading is a network call here, so the provider turns on `Model::preventLazyLoading()` outside production.
A `LazyLoadingViolationException` in development is the N+1 you would otherwise have found in production.

---

## Pagination in one request

`paginate()` sets `with_total` on the spec, so the rows **and** the count come back in a single response — not
the two queries a local paginator runs:

```php
$users = User::query()->active()->orderBy('created_at', 'desc')->paginate(20);

$users->total();        // from meta.total
$users->hasMorePages(); // from meta.has_more
```

`simplePaginate()` skips the total and is one request as well.

`max_query_limit` (100 by default) caps a page. Asking for more throws before the call rather than coming back as
a 400.

---

## Writes and idempotency

```php
$user = User::create(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.com']);

$user->first_name = 'Ada B.';
$user->save();          // PATCH with ONLY the dirty attributes
$user->delete();        // users: closes this application's membership
$user->forceDelete();   // DELETE ?force=1 — the account itself; 409 while another application hosts it
$user->restore();       // reopens the membership
```

`email` and `phone_number` are sent on create only; an existing address changes through the `change-email`
action. Editing users who are not this application's members needs `users.update.all`.

Every mutating call carries an **Idempotency-Key**. The key identifies an *operation*, not a request: a retry —
an internal one, a queued job's second attempt, the same code path running again — reuses it, so the server
recognises the replay instead of applying the write twice. The key is released once the outcome is known, so the
next write is a new operation and gets a new key.

A queued job that may be retried in a fresh process should bind the scope to something stable, and the keys are
then derived rather than random:

```php
app(OperationContext::class)->useScope($job->uuid());
```

Read-only POSTs — `query`, `aggregate`, `validate` — carry no key.

If the record changed under you, the `PATCH` answers `409 stale_record` as a `ConflictException`. Models carrying
a `version` attribute send it as `if_match`.

---

## Domain actions

Anything with a rule attached to it is an action, not a column. Suspending an account, changing an email,
syncing roles — Accounting owns what each one means:

```php
$user->remoteAction('suspend');
$user->remoteAction('change-email', ['email' => 'ada@example.com']);
$user->remoteAction('sync-roles', ['roles' => ['editor']]);   // protected roles are always refused
```

`php artisan remote:schema users` lists the actions a resource publishes. An unknown one throws
`UnsupportedResourceException` naming the ones that exist.

---

## Validation

`Rule::exists()` and `Rule::unique()` work against a remote resource. One value is one `aggregate` request; a list
is one count per page on the key or on a field the schema publishes as `distinct`, and one request per value on
any other field — up to 25 values; a longer list there throws, so give such a rule a `max:25`:

```php
$request->validate([
    'user_id' => ['required', Rule::exists(User::class, 'id')],
    'email'   => ['required', 'email', Rule::unique(User::class, 'email')->ignore($user)],
]);
```

Use the **class-string** form. The string form (`'exists:users,id'`) is indistinguishable from a local table and
goes to Laravel's own verifier; `remote:doctor` reports every one it finds.

To ask the server to run its own rules before you commit to a write:

```php
$user->fill($request->validated());

if (! $user->validateRemote()) {
    // RemoteValidationException was thrown for a failure; false means it was refused softly
}

User::validateRemoteInto($attributes);      // create mode, no record in hand
```

`RemoteValidationException` **extends** `Illuminate\Validation\ValidationException`, so a failed remote validation
lands back on the form with the server's messages attached to the right fields, with no `try`/`catch` anywhere.

---

## The auth guard

The signed-in user is a record in Accounting, so the guard verifies the end user's token locally and then reads
`GET users/me` with that token — once per request.

```php
// config/auth.php
'defaults' => ['guard' => 'accounting', 'passwords' => 'users'],

'guards' => [
    'accounting' => [
        'driver' => 'accounting',
        'provider' => 'remote-users',
        'input' => 'session',          // or 'bearer' for an API
        // 'scopes' => ['users.read'], 'issuer' => ..., 'leeway' => 30,
        // 'me' => ['include' => ['roles']],
    ],
],

'providers' => [
    'remote-users' => ['driver' => 'remote', 'model' => App\Models\User::class],
],
```

`auth()->user()` returns your `User` model, `$this->authorize()` and every policy keep working, and `@can` reads
the same as always.

Two behaviours worth knowing:

- A **bad token** — expired, wrong signature, wrong audience, missing scope — logs a warning and answers *guest*.
- A **configuration fault** that makes the check impossible — no public key, unreachable JWKS, no audience to
  compare against (no `auth.client_id` and no `audiences`) — throws
  `RemoteAuthenticationException`. Answering "guest" when the question could not be asked would log the whole
  application out and read as a login bug.

`Auth::attempt()` throws. Sign-in belongs to the OAuth flow (`esanj/auth-bridge`); this guard resolves who the
token belongs to.

### Acting for a user

A write that originated in a person's request should travel with that person's identity, so Accounting can apply
their permissions on top of the application's. Set `REMOTE_ELOQUENT_ACTOR_EXCHANGE_URL` and it happens by itself:
the user signed in on the default guard is exchanged for a one-write actor token on every write and on
`validateRemote()`. In a job that has no request, pin the actor:

```php
User::actingAsRemote($user);
// ...
User::forgetRemoteActor();
```

With no exchange URL configured, writes travel as the application alone and anything the server marks
actor-required comes back as `403 actor_denied` — never performed anonymously.

---

## Errors

Every failure is a typed exception. With `errors.render` on (the default) they answer the browser themselves.

| Status / code | Exception | Renders |
| --- | --- | --- |
| `400 invalid_query`, `unknown_field`, `operator_not_allowed`, `field_not_writable`, `query_too_complex`, `413` | `InvalidQueryException` | 500 — it is a bug in the query, not in the request |
| pre-flight refusals | `UnsupportedQueryException`, `UnboundedQueryException` | 500 |
| `404 unknown_resource` | `UnsupportedResourceException` | 500 |
| `401` after one refresh | `RemoteAuthenticationException` | 500 — the *application's* identity, not the user's |
| `403 permission_denied`, `privileged_account`, `actor_denied` | `AccessDeniedException` (`requiredPermission()`) | 403 |
| `404 not_found` | `ModelNotFoundException`, or `null` from `find()` | 404 |
| `404 user_merged` | followed once automatically, then `UserMergedException` (`mergedInto()`) | 409 |
| `409 stale_record`, `idempotency_mismatch`, `idempotency_in_progress` | `ConflictException` | 409 |
| `422 validation_failed`, `field_locked` | `RemoteValidationException` | 422 / back to the form |
| `429 rate_limited` | `RateLimitedException` (`retryAfter()`) | 429 + `Retry-After` |
| `503`/`504 query_timeout` | `RemoteTimeoutException` | 503 |
| `503 unavailable`, connect or TLS failure | `TransportException` | 503 |

**A 429 is never retried and never re-sent.** That was the headline bug of version 1. In a queued job, release it
instead:

```php
public function middleware(): array
{
    return [new RemoteRateLimited()];      // release()s the job for exactly Retry-After seconds
}
```

Turn rendering off with `REMOTE_ELOQUENT_RENDER_ERRORS=false` and every `render()` returns null, leaving your own
handler in control.

---

## Testing

No HTTP, no fixtures, no mock expectations — rows in, rows out. The fake **executes** the QuerySpec, so a test
that passes proves the query says what you meant:

```php
use Esanj\RemoteEloquent\Testing\FakeResourceTransport;

$fake = User::fake([
    ['id' => 1, 'first_name' => 'Ada', 'is_active' => true],
    ['id' => 2, 'first_name' => 'Grace', 'is_active' => false],
]);

$this->assertCount(1, User::query()->active()->limit(10)->get());

FakeResourceTransport::assertQueried('users', fn (array $spec) => $spec['limit'] === 10);
FakeResourceTransport::assertRequestCount(1);          // the N+1 guard
```

Or through the facade, which returns a fluent arrangement object:

```php
RemoteResource::fake()
    ->seed('users', [['id' => 1, 'first_name' => 'Ada']])
    ->grant(['users.read', 'users.update'])
    ->failWith('wallets', 429, retryAfter: 30);
```

`FakeResourceTransport::reset()` (or `RemoteResourceFake::stop()`) in `tearDown` — `stopFaking()` does not clear
seeded rows.

The fake pins semantics the server has to match, and they are stricter than one driver's defaults on purpose:
`between` is half-open, text matching is **case sensitive**, NULLs order last in both directions, and a
comparison against null is unknown — so neither `where('x', 1)` nor `whereNot('x', 1)` returns a null row. A test
that needed case folding fails loudly here instead of passing on MySQL and failing on PostgreSQL.

---

## Artisan commands

| Command | What it does |
| --- | --- |
| `remote:schema {resource} [--diff] [--model=]` | Print the published contract, or diff it against your model. Exits non-zero on drift, so CI can hold the line. |
| `remote:access [--json]` | The permissions and quota Accounting grants this application. |
| `remote:model {resource} [--auth] [--model=] [--namespace=] [--path=]` | Generate a model from the schema. No `--force`: it will not overwrite. |
| `remote:doctor [--offline]` | Configuration, reachability, TLS, permissions, models vs. schemas, and `exists:`/`unique:` rules in string form. Non-zero on any problem. |

---

## Limits

`config/esanj/remote_eloquent.php`, all under `esanj.remote_eloquent`:

| Setting | Default | Meaning |
| --- | --- | --- |
| `limits.max_query_limit` | 100 | The largest page. The server caps it too; this turns a certain 400 into an exception naming the builder call. |
| `limits.in_chunk` | 500 | The longest `in` list one request may carry, capped at the server's own 500. A longer `whereIn()` is split across calls and merged. A `not_in`, a list with an `orWhere()` beside it, and a negated clause cannot be split and are refused by name — see *Reading* for why. |
| `rest.timeout` / `rest.connect_timeout` | 5 / 2 s | Short on purpose. Waiting 30 seconds for an answer that is not coming turns a fast failure into a slow one. |
| `cache.identity_map` | true | Per-request memo, so resolving the same id twice is one call. Never shared between requests. |
| `cache.find_ttl` | 0 | Records are **not** cached across requests. Accounting is the system of record for balances and permissions. |
| `cache.schema_ttl` / `cache.access_ttl` | 3600 / 600 s | The two descriptive endpoints. Both are dropped early when a response reports a new `X-Schema-Version` or `X-Permissions-Version`. A schema that cannot be refreshed is served stale for up to 24 × `schema_ttl`. |
| `telemetry.call_warning_threshold` | 20 | Log a warning once one request has made this many remote calls — that is the N+1 that used to be a join. |
| `fallback` | false | Not configurable. v1 replayed failed calls on a second transport and re-sent accepted writes. |

---

## Upgrading to 2.0.2

- **Laravel 12 or 13 is required.** 11 is past its security support, and Composer 2.9+ refuses to install any of
  its releases.
- **Laravel 13 and the latest 12.x load**: 2.0.1 stopped with a fatal signature error on the first remote query.
- **A revoked sign-in token makes the user a guest** (with a logged warning) instead of a 500.
- **A job run with `dispatchSync()` keeps its caller's actor and Idempotency-Keys**, so a retried outer job no
  longer re-applies the writes it made after the inner one.
- **`http://` is refused outside `local`/`testing`** for `actor.exchange_url` and a guard's `jwks_url` too, and
  for `auth.token_url` however the token provider is resolved.
- **`count('column')` throws** instead of silently counting every row; write `->whereNotNull('column')->count()`.

## Further reading

[`docs/GUIDE.md`](docs/GUIDE.md) — the long version: the QuerySpec itself, every refusal with a worked
alternative, relations across the boundary, the identity map, actor tokens, and how to debug a slow page.
