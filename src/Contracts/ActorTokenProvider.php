<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

interface ActorTokenProvider
{
    public function actorTokenFor(?Authenticatable $user): ?string;
}
