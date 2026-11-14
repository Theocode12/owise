<?php

namespace Owise\Api;

use GuzzleHttp\Psr7\HttpFactory;
use Owise\Account\Environment;
use Owise\Exceptions\WiseApiError;
use Owise\Exceptions\WiseException;
use Owise\Support\ApiVersion;
use Owise\Support\Endpoints;
use Theocode\Wise\Auth\TokenAuthenticator;
use Theocode\Wise\Contracts\AccessTokenProviderInterface;
use Theocode\Wise\Contracts\TransportInterface;
use Theocode\Wise\Exceptions\ApiException;
use Theocode\Wise\Exceptions\AuthException;
use Theocode\Wise\Exceptions\RateLimitException;
use Theocode\Wise\Support\Json;
use Theocode\Wise\WiseClient;

/**
 * The adapter's single gateway to the SDK for the current environment.
 */
final readonly class ApiClient
{
    /**
     * Create a new API client instance.
     *
     * @param  WiseClient  $sdk  The underlying SDK client.
     * @param  Environment  $environment  The Wise environment being targeted.
     * @param  ErrorMapper  $errors  The mapper for SDK exceptions.
     * @param  TransportInterface|null  $transport  The HTTP transport, if any.
     * @param  AccessTokenProviderInterface|null  $tokens  The token provider, if any.
     * @param  string|null  $baseUrl  The API base URL, if any.
     */
    public function __construct(
        public WiseClient $sdk,
        public Environment $environment,
        private ErrorMapper $errors,
        private ?TransportInterface $transport = null,
        private ?AccessTokenProviderInterface $tokens = null,
        private ?string $baseUrl = null,
        private ApiVersion $apiVersion = ApiVersion::V2026Q4,
    ) {}

    /**
     * Send a catalogued JSON operation to the Wise API.
     *
     * @param  string  $operation  The catalogued operation name.
     * @param  array<string, int|string>  $parameters  Values for route placeholders.
     * @param  array<string, mixed>  $query  The query parameters.
     * @param  array<string, mixed>  $body  The request body.
     * @param  array<string, string>  $headers  The request headers.
     * @param  bool  $authenticated  Whether to authenticate the request.
     * @return array<array-key, mixed> The decoded response payload.
     *
     * @throws WiseApiError
     */
    public function requestOperation(
        string $operation,
        array $parameters = [],
        array $query = [],
        array $body = [],
        array $headers = [],
        bool $authenticated = true,
    ): array {
        $endpoint = Endpoints::resolve($operation, $this->apiVersion, $parameters);

        return $this->request($endpoint['method'], $endpoint['path'], $query, $body, $headers, $authenticated);
    }

    /**
     * Send a catalogued form operation to the Wise API.
     *
     * @param  string  $operation  The catalogued operation name.
     * @param  array<string, int|string>  $parameters  Values for route placeholders.
     * @param  array<string, mixed>  $form  The form parameters.
     * @param  array<string, string>  $headers  The request headers.
     * @param  bool  $authenticated  Whether to authenticate the request.
     * @return array<array-key, mixed> The decoded response payload.
     *
     * @throws WiseApiError
     */
    public function formOperation(
        string $operation,
        array $parameters = [],
        array $form = [],
        array $headers = [],
        bool $authenticated = false,
    ): array {
        $endpoint = Endpoints::resolve($operation, $this->apiVersion, $parameters);

        return $this->form($endpoint['method'], $endpoint['path'], $form, $headers, $authenticated);
    }

    /**
     * Send a catalogued raw operation and return the binary response body.
     *
     * Kept in place for binary statement downloads, which Wise documents but does not yet serve
     * on the `2026Q4` API (they currently return 404). Re-enable by exposing it from
     * `StatementsApi` once the routes are live.
     *
     * The SDK's `request()` forces `Accept: application/json` and strictly decodes JSON, so binary
     * bodies go through the same transport (correlation + SCA signing) here.
     *
     * @param  string  $operation  The catalogued operation name.
     * @param  array<string, int|string>  $parameters  Values for route placeholders.
     * @param  array<string, mixed>  $query  The query parameters.
     * @param  array<string, string>  $headers  The request headers.
     * @param  bool  $authenticated  Whether to authenticate the request.
     * @return array{content: string, contentType: string} The raw content and content type.
     *
     * @throws \LogicException
     * @throws WiseApiError
     */
    public function rawOperation(
        string $operation,
        array $parameters = [],
        array $query = [],
        array $headers = [],
        bool $authenticated = true,
    ): array {
        $endpoint = Endpoints::resolve($operation, $this->apiVersion, $parameters);

        return $this->raw($endpoint['method'], $endpoint['path'], $query, $headers, $authenticated);
    }

    /**
     * Send a JSON request to a resolved Wise endpoint.
     *
     * @param  string  $method  The HTTP method from the operation catalogue.
     * @param  string  $path  The resolved API path.
     * @param  array<string, mixed>  $query  The query parameters.
     * @param  array<string, mixed>  $body  The request body.
     * @param  array<string, string>  $headers  The request headers.
     * @param  bool  $authenticated  Whether to authenticate the request.
     * @return array<array-key, mixed> The decoded response payload.
     *
     * @throws WiseApiError
     */
    private function request(
        string $method,
        string $path,
        array $query = [],
        array $body = [],
        array $headers = [],
        bool $authenticated = true,
    ): array {
        return $this->errors->wrap(fn (): array => $this->sdk->request($method, $path, $query, $body, $headers, $authenticated));
    }

    /**
     * Send a form request to a resolved Wise endpoint.
     *
     * @param  string  $method  The HTTP method from the operation catalogue.
     * @param  string  $path  The resolved API path.
     * @param  array<string, mixed>  $form  The form parameters.
     * @param  array<string, string>  $headers  The request headers.
     * @param  bool  $authenticated  Whether to authenticate the request.
     * @return array<array-key, mixed> The decoded response payload.
     *
     * @throws WiseApiError
     */
    private function form(string $method, string $path, array $form, array $headers = [], bool $authenticated = false): array
    {
        return $this->errors->wrap(fn (): array => $this->sdk->requestForm($method, $path, $form, $headers, $authenticated));
    }

    /**
     * Send a raw request to a resolved Wise endpoint and return the binary response body.
     *
     * @param  string  $method  The HTTP method from the operation catalogue.
     * @param  string  $path  The resolved API path.
     * @param  array<string, mixed>  $query  The query parameters.
     * @param  array<string, string>  $headers  The request headers.
     * @param  bool  $authenticated  Whether to authenticate the request.
     * @return array{content: string, contentType: string} The raw content and content type.
     *
     * @throws \LogicException
     * @throws WiseApiError
     */
    private function raw(
        string $method,
        string $path,
        array $query = [],
        array $headers = [],
        bool $authenticated = true,
    ): array {
        if ($this->transport === null || $this->tokens === null || $this->baseUrl === null) {
            throw new \LogicException('ApiClient::raw() requires transport, token provider and base URL (wired by ApiClientFactory).');
        }

        $transport = $this->transport;
        $tokens = $this->tokens;
        $baseUrl = $this->baseUrl;

        return $this->errors->wrap(function () use ($method, $path, $query, $headers, $authenticated, $transport, $tokens, $baseUrl): array {
            $url = rtrim($baseUrl, '/').'/'.ltrim($path, '/');
            $query = array_filter($query, fn (mixed $v): bool => $v !== null);
            if ($query !== []) {
                $url .= '?'.http_build_query($query);
            }

            $factory = new HttpFactory;
            $request = $factory->createRequest($method, $url)
                ->withHeader('User-Agent', 'owise/raw');

            foreach ($headers as $name => $value) {
                $request = $request->withHeader($name, $value);
            }

            if ($authenticated) {
                $request = (new TokenAuthenticator($tokens))->authenticate($request);
            }

            $response = $transport->send($request);
            $status = $response->getStatusCode();

            if ($status >= 400) {
                $payload = Json::decodeObjectSafe((string) $response->getBody());
                $message = (string) ($payload['message'] ?? $payload['error'] ?? 'Wise API request failed');
                $requestId = $response->getHeaderLine('x-request-id') ?: null;
                $correlationId = $response->getHeaderLine('x-correlation-id') ?: null;

                if ($status === 401 || $status === 403) {
                    throw new AuthException($message, $status, $payload, $requestId, $correlationId);
                }

                if ($status === 429) {
                    $retryAfter = $response->getHeaderLine('Retry-After');
                    throw new RateLimitException(
                        $message,
                        $status,
                        $payload,
                        $requestId,
                        $correlationId,
                        trim($retryAfter) !== '' && is_numeric($retryAfter) ? (int) $retryAfter : null,
                    );
                }

                throw new ApiException($message, $status, $payload, $requestId, $correlationId);
            }

            return [
                'content' => (string) $response->getBody(),
                'contentType' => $response->getHeaderLine('Content-Type'),
            ];
        });
    }

    /**
     * Run a callback against the SDK client with error mapping.
     *
     * @template T
     *
     * @param  callable(WiseClient): T  $callback  The callback receiving the SDK client.
     * @return T The callback result.
     *
     * @throws WiseException
     */
    public function call(callable $callback): mixed
    {
        return $this->errors->wrap(fn (): mixed => $callback($this->sdk));
    }
}
