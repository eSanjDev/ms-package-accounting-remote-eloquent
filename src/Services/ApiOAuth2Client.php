<?php

namespace Esanj\RemoteEloquent\Services;

use Esanj\RemoteEloquent\Contracts\RestClientInterface;
use Esanj\RemoteEloquent\Exceptions\ApiClientException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Cache;

/**
 * OAuth2 REST API client with automatic token management
 */
class ApiOAuth2Client implements RestClientInterface
{
    protected string $baseUrl;
    protected string $clientId;
    protected string $clientSecret;
    protected string $scope;
    protected RestClient $restClient;

    public function __construct()
    {
        $this->baseUrl = config('services.accounting.base_url');
        $this->clientId = config('services.accounting.client_id');
        $this->clientSecret = config('services.accounting.client_secret');
        $this->scope = config('services.accounting.scope', '*');

        $this->restClient = new RestClient($this->baseUrl);
    }

    /**
     * Get access token with caching
     */
    protected function getAccessToken(): string
    {
        return Cache::remember('oauth-access-token', 3500, function () {
            return $this->fetchAccessToken();
        });
    }

    /**
     * Fetch new access token from OAuth server
     */
    protected function fetchAccessToken(): string
    {
        try {
            $response = (new \GuzzleHttp\Client())->post($this->baseUrl . '/oauth/token', [
                'form_params' => [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'scope' => $this->scope,
                ],
            ]);

            $data = json_decode($response->getBody(), true);

            if (!isset($data['access_token'])) {
                throw new ApiClientException('Invalid OAuth response: access_token not found');
            }

            return $data['access_token'];
        } catch (RequestException $e) {
            throw new ApiClientException('Failed to fetch OAuth token: ' . $e->getMessage());
        }
    }

    /**
     * Ensure request has valid authentication
     */
    protected function ensureAuthenticated(): void
    {
        $token = $this->getAccessToken();
        $this->restClient->setAuthToken($token);
    }

    public function get(string $url, array $query = []): array
    {
        $this->ensureAuthenticated();
        return $this->restClient->get($url, $query);
    }

    public function post(string $url, array $data = []): array
    {
        $this->ensureAuthenticated();
        return $this->restClient->post($url, $data);
    }

    public function put(string $url, array $data = []): array
    {
        $this->ensureAuthenticated();
        return $this->restClient->put($url, $data);
    }

    public function delete(string $url): void
    {
        $this->ensureAuthenticated();
        $this->restClient->delete($url);
    }

    public function setBaseUrl(string $baseUrl): void
    {
        $this->baseUrl = $baseUrl;
        $this->restClient->setBaseUrl($baseUrl);
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }
}
