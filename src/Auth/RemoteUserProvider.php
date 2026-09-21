<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Auth;

use Esanj\RemoteEloquent\Exceptions\UnsupportedQueryException;
use Esanj\RemoteEloquent\Models\ApiUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * The "remote" auth provider: it hands Laravel's auth stack an ApiUser.
 */
final class RemoteUserProvider implements UserProvider
{
    /**
     * @param  class-string<ApiUser>  $model
     */
    public function __construct(
        private readonly string $model = ApiUser::class,
    ) {
        if (! is_a($this->model, ApiUser::class, true)) {
            throw new InvalidArgumentException(sprintf(
                'The "remote" auth provider needs a model extending %s, got "%s". '
                .'Point auth.providers.<name>.model at your own subclass of it.',
                ApiUser::class,
                $this->model,
            ));
        }
    }

    /**
     * @return class-string<ApiUser>
     */
    public function getModel(): string
    {
        return $this->model;
    }

    public function createModel(): ApiUser
    {
        $class = $this->model;

        return new $class;
    }

    /**
     * Build a user from a record the server already sent.
     *
     * @param  array<string, mixed>  $record
     */
    public function hydrate(array $record): ApiUser
    {
        /** @var ApiUser $user */
        $user = $this->createModel()->newFromBuilder($record);

        return $user;
    }

    /**
     * GET {resource}/{id} on the APPLICATION's token.
     *
     * @param  mixed  $identifier
     */
    public function retrieveById($identifier): ?Authenticatable
    {
        if ($identifier === null || $identifier === '') {
            return null;
        }

        /** @var ApiUser|null $user */
        $user = $this->createModel()->newQuery()->find($identifier);

        return $user;
    }

    /**
     * Always null: the resource has no remember-token field.
     *
     * @param  mixed  $identifier
     * @param  string  $token
     */
    public function retrieveByToken($identifier, #[SensitiveParameter] $token): ?Authenticatable
    {
        return null;
    }

    /**
     * @param  string  $token
     */
    public function updateRememberToken(Authenticatable $user, #[SensitiveParameter] $token): void
    {
        // Nothing to write to, and nothing to report: see the class docblock.
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function retrieveByCredentials(#[SensitiveParameter] array $credentials): never
    {
        throw UnsupportedQueryException::authAttempt([
            'provider' => self::class,
            'model' => $this->model,
            'builder_method' => 'retrieveByCredentials',
        ]);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function validateCredentials(Authenticatable $user, #[SensitiveParameter] array $credentials): never
    {
        throw UnsupportedQueryException::authAttempt([
            'provider' => self::class,
            'model' => $this->model,
            'builder_method' => 'validateCredentials',
        ]);
    }

    /**
     * No hash is ever held here, so there is never one to rehash.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function rehashPasswordIfRequired(
        Authenticatable $user,
        #[SensitiveParameter] array $credentials,
        bool $force = false,
    ): void {
        //
    }
}
