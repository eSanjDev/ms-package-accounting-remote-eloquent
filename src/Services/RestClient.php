<?php

namespace Esanj\RemoteEloquent\Services;

use Esanj\RemoteEloquent\Contracts\RestClientInterface;
use Esanj\RemoteEloquent\Exceptions\RestClientException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * REST API client implementation for RemoteEloquent
 */
class RestClient implements RestClientInterface
{
    protected string $baseUrl;
    protected Client $httpClient;
    protected array $headers;
    protected array $defaultOptions;

    public function __construct(string $baseUrl = '', array $headers = [])
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->headers = array_merge([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], $headers);

        $this->httpClient = new Client();
        $this->defaultOptions = [
            'headers' => $this->headers,
            'timeout' => 30,
        ];
    }

    public function get(string $url, array $query = []): array
    {
        try {
            $options = array_merge($this->defaultOptions, [
                'query' => $query,
            ]);

            $response = $this->httpClient->get($this->buildUrl($url), $options);

            return json_decode($response->getBody()->getContents(), true) ?? [];
        } catch (RequestException $e) {
            throw new RestClientException("REST GET request failed: " . $e->getMessage());
        }
    }

    public function post(string $url, array $data = []): array
    {
        try {
            $options = array_merge($this->defaultOptions, [
                'json' => $data,
            ]);

            $response = $this->httpClient->post($this->buildUrl($url), $options);

            return json_decode($response->getBody()->getContents(), true) ?? [];
        } catch (RequestException $e) {
            throw new RestClientException("REST POST request failed: " . $e->getMessage());
        }
    }

    public function put(string $url, array $data = []): array
    {
        try {
            $options = array_merge($this->defaultOptions, [
                'json' => $data,
            ]);

            $response = $this->httpClient->put($this->buildUrl($url), $options);

            return json_decode($response->getBody()->getContents(), true) ?? [];
        } catch (RequestException $e) {
            throw new RestClientException("REST PUT request failed: " . $e->getMessage());
        }
    }

    public function delete(string $url): void
    {
        try {
            $response = $this->httpClient->delete($this->buildUrl($url), $this->defaultOptions);

            if ($response->getStatusCode() >= 400) {
                throw new RestClientException("REST DELETE request failed with status: " . $response->getStatusCode());
            }
        } catch (RequestException $e) {
            throw new RestClientException("REST DELETE request failed: " . $e->getMessage());
        }
    }

    /**
     * Build full URL from base URL and endpoint
     */
    protected function buildUrl(string $endpoint): string
    {
        $endpoint = ltrim($endpoint, '/');
        return $this->baseUrl . '/' . $endpoint;
    }

    public function setBaseUrl(string $baseUrl): void
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Set custom headers
     */
    public function setHeaders(array $headers): void
    {
        $this->headers = array_merge($this->headers, $headers);
        $this->defaultOptions['headers'] = $this->headers;
    }

    /**
     * Set authentication token
     */
    public function setAuthToken(string $token): void
    {
        $this->setHeaders(['Authorization' => 'Bearer ' . $token]);
    }
} 