<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Models;

use Esanj\RemoteEloquent\Exceptions\UnsupportedQueryException;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Foundation\Auth\Access\Authorizable as AuthorizableTrait;

/**
 * The account service's user, usable as the application's own user model.
 */
class ApiUser extends ApiModel implements AuthenticatableContract, AuthorizableContract
{
    use AuthenticatableTrait;
    use AuthorizableTrait;

    protected string $resource = 'users';

    public function getRememberTokenName(): string
    {
        return '';
    }

    /**
     * GET users/me — resolved with the END USER's token.
     *
     * @param  array<string, mixed>  $options  fields, include
     */
    public static function me(array $options = []): ?static
    {
        $model = new static;

        $response = static::remoteTransport()
            ->withActor($model->remoteActor(), $model->remoteSubjectToken())
            ->me($options);

        $record = $response->record();

        if ($record === null) {
            return null;
        }

        static::remoteIdentityMap()?->observe(
            $response->schemaVersion(),
            $response->permissionsVersion(),
        );

        /** @var static $user */
        $user = $model->newFromBuilder($record);

        return $user;
    }

    /**
     * Never available: see the class docblock.
     */
    public function getAuthPassword(): never
    {
        throw UnsupportedQueryException::authAttempt([
            'model' => static::class,
            'resource' => $this->resource,
        ]);
    }

    public function getAuthPasswordName(): never
    {
        throw UnsupportedQueryException::authAttempt([
            'model' => static::class,
            'resource' => $this->resource,
        ]);
    }

    /**
     * @param  string  $value
     */
    public function setRememberToken($value): void
    {
        throw UnsupportedQueryException::method(
            sprintf('%s::setRememberToken()', static::class),
            'The account service issues and stores its own tokens; there is no remember-token field on this resource to write to. Sign in without "remember me", or keep the remember token on a local model that references the remote user by id.',
            ['model' => static::class, 'builder_method' => 'setRememberToken'],
        );
    }
}
