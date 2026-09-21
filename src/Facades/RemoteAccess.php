<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Facades;

use Esanj\RemoteEloquent\Access\AccessSnapshot;
use Esanj\RemoteEloquent\Access\RemoteAccess as Access;

/**
 * What the account service lets this application do, asked from a Blade view, a policy or a menu builder.
 */
final class RemoteAccess
{
    /**
     * Does this application hold a permission?
     */
    public static function can(string $permission): bool
    {
        return Access::can($permission);
    }

    public static function cannot(string $permission): bool
    {
        return Access::cannot($permission);
    }

    /**
     * @return list<string>
     */
    public static function permissions(): array
    {
        return Access::permissions();
    }

    /**
     * Is an operation — read, create, update, delete, restore, an action name — published for a resource?
     */
    public static function allows(string $resource, string $operation): bool
    {
        return Access::allows($resource, $operation);
    }

    /**
     * The RateLimit-* headers the last answer carried, plus whatever quota the access payload published.
     *
     * @return array<string, mixed>
     */
    public static function quota(): array
    {
        return Access::quota();
    }

    /**
     * Drop the snapshot everywhere and read it again now.
     */
    public static function refresh(): void
    {
        Access::refresh();
    }

    /**
     * Answer from this list of permissions and never call the server.
     *
     * @param  list<string>  $permissions
     */
    public static function fake(array $permissions): void
    {
        Access::fake($permissions);
    }

    /**
     * Forget what was read, without reading again.
     */
    public static function forget(): void
    {
        Access::forget();
    }

    /**
     * The whole payload: application, permissions, resources and quota.
     */
    public static function snapshot(): AccessSnapshot
    {
        return Access::snapshot();
    }
}
