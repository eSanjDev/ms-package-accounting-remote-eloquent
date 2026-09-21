<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Contracts;

interface AccessTokenProvider
{
    public function getAccessToken(bool $forceFresh = false): string;
}
