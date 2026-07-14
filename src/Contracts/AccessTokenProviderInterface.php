<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Contracts;

use Esanj\RemoteEloquent\Exceptions\TokenRequestException;

interface AccessTokenProviderInterface
{
    /**
     * A currently-valid access token, obtained from cache when possible.
     *
     * @param  bool  $forceFresh  Bypass the cache and request a brand-new token.
     *
     * @throws TokenRequestException
     */
    public function getAccessToken(bool $forceFresh = false): string;

    /**
     * The ready-to-send "Bearer xxx" header value.
     *
     * @throws TokenRequestException
     */
    public function getAuthorizationHeader(bool $forceFresh = false): string;

    /**
     * Drop the cached token so the next call re-authenticates.
     */
    public function forget(): void;
}