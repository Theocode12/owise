<?php

namespace Owise\Testing;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use LogicException;
use Owise\Support\ApiVersion;
use Owise\Support\Endpoints as Catalogue;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * Fakes the Wise API at the HTTP layer, scoped to the configured Wise base URLs only.
 *
 * ```php
 * $fake = Wise::fake();
 * $fake->transferStatus('processing')->insufficientFunds();
 *
 * $transfer = $freelancer->payout($money)->to($recipient)->idempotencyKey('inv-1')->send();
 *
 * $fake->assertTransferCreated(fn (array $body) => $body['targetAccount'] === $recipient->wise_recipient_id);
 * ```
 */
final class WiseFake
{
    /**
     * Overrides keyed by operation. Each callable receives the request and returns a response.
     *
     * @var array<string, callable(Request): PromiseInterface>
     */
    private array $overrides = [];

    /**
     * Recorded requests in send order.
     *
     * @var list<array{operation: ?string, method: string, url: string, body: array<array-key, mixed>|null}>
     */
    private array $requests = [];

    /**
     * The next Wise transfer id to hand out for `transfers.create`.
     */
    private int $nextTransferId = 1000000;

    /**
     * Wise transfer ids keyed by `customerTransactionId`, mirroring Wise's dedup.
     *
     * @var array<string, int>
     */
    private array $transferIds = [];

    /**
     * The configured API URL origins and path prefixes this fake intercepts.
     *
     * @var list<array{scheme: string, host: string, port: int|null, path: string}>
     */
    private array $baseUrls;

    /**
     * Register the fake and apply any initial responses.
     *
     * @param  array<string, array<array-key, mixed>|Closure>  $responses  Operation key to payload (or closure).
     */
    public function __construct(array $responses = [])
    {
        $this->baseUrls = $this->resolveBaseUrls();

        foreach ($responses as $operation => $response) {
            $this->respond($operation, $response);
        }

        Http::fake(fn (Request $request): ?PromiseInterface => $this->handle($request));
    }

    /**
     * Override the response for a single endpoint.
     *
     * @param  array<array-key, mixed>|Closure(Request): array<array-key, mixed>  $payload
     */
    public function respond(string $endpointKey, array|Closure $payload, int $status = 200): self
    {
        $this->overrides[$endpointKey] = function (Request $request) use ($payload, $status): PromiseInterface {
            $body = $payload instanceof Closure ? $payload($request) : $payload;

            return Http::response($body, $status);
        };

        return $this;
    }

    /**
     * Fail a single endpoint with a Wise error response.
     *
     * @param  array<array-key, mixed>  $errors  One or more `{code, message, path, arguments}` entries.
     */
    public function fail(string $endpointKey, int $status, array $errors): self
    {
        return $this->respond($endpointKey, ['errors' => $errors], $status);
    }

    /**
     * Make `transfers.find` and `transfers.list` report the given Wise status.
     */
    public function transferStatus(string $status): self
    {
        $this->overrides[Endpoints::FIND_TRANSFER] = function (Request $request) use ($status): PromiseInterface {
            $payload = FakeResponses::transfer($this->transferIdFromRequest($request));
            $payload['status'] = $status;

            return Http::response($payload, 200);
        };

        $this->overrides[Endpoints::LIST_TRANSFERS] = function () use ($status): PromiseInterface {
            $payload = FakeResponses::transfer();
            $payload['status'] = $status;

            return Http::response([$payload], 200);
        };

        return $this;
    }

    /**
     * Simulate an insufficient-balance funding rejection (Wise returns 200 with status REJECTED).
     */
    public function insufficientFunds(): self
    {
        return $this->fundingRejected(
            'balance.payment-option-unavailable',
            'You do not have enough money in your balance to fund this transfer.',
        );
    }

    /**
     * Make funding return a REJECTED result, as Wise reports e.g. insufficient funds.
     */
    public function fundingRejected(?string $errorCode = 'balance.payment-option-unavailable', ?string $errorMessage = 'Wise rejected the transfer funding.'): self
    {
        $this->overrides[Endpoints::FUND_TRANSFER] = function () use ($errorCode, $errorMessage): PromiseInterface {
            return Http::response([
                'type' => 'BALANCE',
                'status' => 'REJECTED',
                'errorCode' => $errorCode,
                'errorMessage' => $errorMessage,
            ], 200);
        };

        return $this;
    }

    /**
     * Make funding return a 403 Strong Customer Authentication challenge.
     */
    public function requiresSca(): self
    {
        $this->overrides[Endpoints::FUND_TRANSFER] = function (): PromiseInterface {
            return Http::response([
                'errors' => [['code' => 'validation.not.allowed', 'message' => 'Strong customer authentication is required.']],
            ], 403, ['x-2fa-approval' => 'ott-123456', 'x-2fa-approval-result' => 'REJECTED']);
        };

        return $this;
    }

    /**
     * Make funding return a 403 without a Strong Customer Authentication challenge.
     */
    public function permissionDenied(): self
    {
        $this->overrides[Endpoints::FUND_TRANSFER] = function (): PromiseInterface {
            return Http::response([
                'errors' => [['code' => 'forbidden', 'message' => 'You are not permitted to perform this operation.']],
            ], 403);
        };

        return $this;
    }

    /**
     * Assert the number of requests sent for an endpoint.
     */
    public function assertSentCount(string $endpointKey, int $count): self
    {
        PHPUnit::assertSame($count, $this->count($endpointKey), "Expected {$count} Wise request(s) for [{$endpointKey}], found {$this->count($endpointKey)}.");

        return $this;
    }

    /**
     * Assert that no Wise requests were sent at all.
     */
    public function assertNothingSent(): self
    {
        PHPUnit::assertCount(0, $this->requests, 'Expected no Wise requests to be sent, found '.count($this->requests).'.');

        return $this;
    }

    /**
     * Assert a transfer was created. The optional callback receives the create request body.
     *
     * @param  (Closure(array<array-key, mixed>|null): bool)|null  $callback
     */
    public function assertTransferCreated(?Closure $callback = null): self
    {
        return $this->assertSent(Endpoints::CREATE_TRANSFER, $callback);
    }

    /**
     * Assert a transfer was funded. The optional callback receives the fund request body.
     *
     * @param  (Closure(array<array-key, mixed>|null): bool)|null  $callback
     */
    public function assertTransferFunded(?Closure $callback = null): self
    {
        return $this->assertSent(Endpoints::FUND_TRANSFER, $callback);
    }

    /**
     * Assert a quote was created. The optional callback receives the quote request body.
     *
     * @param  (Closure(array<array-key, mixed>|null): bool)|null  $callback
     */
    public function assertQuoteCreated(?Closure $callback = null): self
    {
        return $this->assertSent(Endpoints::CREATE_QUOTE, $callback);
    }

    /**
     * Assert a recipient was created. The optional callback receives the create request body.
     *
     * @param  (Closure(array<array-key, mixed>|null): bool)|null  $callback
     */
    public function assertRecipientCreated(?Closure $callback = null): self
    {
        return $this->assertSent(Endpoints::CREATE_RECIPIENT, $callback);
    }

    /**
     * Assert a balance conversion/movement was requested.
     *
     * @param  (Closure(array<array-key, mixed>|null): bool)|null  $callback
     */
    public function assertBalanceConverted(?Closure $callback = null): self
    {
        return $this->assertSent(Endpoints::MOVE_BALANCE, $callback);
    }

    /**
     * Assert at least one request was sent for an endpoint, optionally matching a callback.
     *
     * @param  (Closure(array<array-key, mixed>|null): bool)|null  $callback
     */
    public function assertSent(string $endpointKey, ?Closure $callback = null): self
    {
        $records = $this->sent($endpointKey);
        PHPUnit::assertNotEmpty($records, "Expected at least one Wise request for [{$endpointKey}], found none.");

        if ($callback !== null) {
            PHPUnit::assertTrue(
                $this->any($records, $callback),
                "The given callback did not match any Wise request for [{$endpointKey}].",
            );
        }

        return $this;
    }

    /**
     * Handle an intercepted request, recording it and returning the matching response.
     */
    private function handle(Request $request): ?PromiseInterface
    {
        $url = $request->url();

        $path = $this->apiPath($url);
        if ($path === null) {
            return null;
        }

        $operation = $this->match($request->method(), $path);

        $this->requests[] = [
            'operation' => $operation,
            'method' => $request->method(),
            'url' => $url,
            'body' => $this->requestData($request),
        ];

        if ($operation === null) {
            throw new LogicException("WiseFake intercepted an unrecognized Wise API request: {$request->method()} {$url}");
        }

        if (isset($this->overrides[$operation])) {
            return ($this->overrides[$operation])($request);
        }

        if ($operation === 'transfers.create') {
            return $this->transferCreateResponse($request);
        }

        $default = FakeResponses::for($operation);
        if ($default === null) {
            throw new LogicException("WiseFake has no default response for public operation [{$operation}].");
        }

        return Http::response($default['body'], $default['status']);
    }

    /**
     * Return a stateful transfer-create response: ids increment, duplicates reuse the same id.
     */
    private function transferCreateResponse(Request $request): PromiseInterface
    {
        $data = $this->requestData($request);
        $customerTransactionId = is_array($data) && is_string($data['customerTransactionId'] ?? null)
            ? $data['customerTransactionId']
            : null;

        if ($customerTransactionId !== null && isset($this->transferIds[$customerTransactionId])) {
            $id = $this->transferIds[$customerTransactionId];
        } else {
            $id = $this->nextTransferId++;
            if ($customerTransactionId !== null) {
                $this->transferIds[$customerTransactionId] = $id;
            }
        }

        return Http::response(FakeResponses::transfer($id, $customerTransactionId), 200);
    }

    private function transferIdFromRequest(Request $request): ?int
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        if (preg_match('~/transfers/([^/]+)$~', $path, $matches) !== 1) {
            return null;
        }

        $id = rawurldecode($matches[1]);

        return ctype_digit($id) ? (int) $id : null;
    }

    /**
     * Match a request method and path to an operation key, or null when unknown.
     */
    private function match(string $method, string $path): ?string
    {
        $version = '/'.ApiVersion::fromConfig(config('wise.api_version', '2026Q4'))->value;

        foreach (Endpoints::public() as $operation) {
            $entry = Catalogue::all()[$operation];

            if ($entry['method'] !== $method) {
                continue;
            }

            if ($this->pathMatches($version.$entry['calver'], $path)) {
                return $operation;
            }
        }

        return null;
    }

    private function pathMatches(string $template, string $path): bool
    {
        $regex = preg_replace_callback('/\{[^}]+\}/', static fn (): string => '([^/]+)', $template);
        if ($regex === null) {
            return false;
        }

        $regex = '#^'.str_replace('/', '\/', $regex).'$#';

        return preg_match($regex, $path) === 1;
    }

    /**
     * Return the parsed JSON/form body for a request, or null.
     *
     * @return array<array-key, mixed>|null
     */
    private function requestData(Request $request): ?array
    {
        $data = $request->data();
        if ($data !== []) {
            return $data;
        }

        $decoded = json_decode($request->body(), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return list<array{scheme: string, host: string, port: int|null, path: string}>
     */
    private function resolveBaseUrls(): array
    {
        $urls = config('wise.urls', []);
        $baseUrls = [];

        foreach (['sandbox', 'production'] as $environment) {
            foreach (['api_base_url', 'mtls_api_base_url'] as $key) {
                $url = $urls[$environment][$key] ?? null;
                if (! is_string($url) || $url === '') {
                    continue;
                }

                $parts = parse_url($url);
                if (! is_array($parts) || ! is_string($parts['scheme'] ?? null) || ! is_string($parts['host'] ?? null)) {
                    continue;
                }

                $scheme = strtolower($parts['scheme']);
                $defaultPort = $scheme === 'https' ? 443 : ($scheme === 'http' ? 80 : null);
                $baseUrls[] = [
                    'scheme' => $scheme,
                    'host' => strtolower($parts['host']),
                    'port' => $parts['port'] ?? $defaultPort,
                    'path' => rtrim($parts['path'] ?? '', '/'),
                ];
            }
        }

        return $baseUrls;
    }

    private function apiPath(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! is_string($parts['scheme'] ?? null) || ! is_string($parts['host'] ?? null)) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $defaultPort = $scheme === 'https' ? 443 : ($scheme === 'http' ? 80 : null);
        $port = $parts['port'] ?? $defaultPort;
        $path = $parts['path'] ?? '/';

        foreach ($this->baseUrls as $baseUrl) {
            if ($scheme !== $baseUrl['scheme']
                || strtolower($parts['host']) !== $baseUrl['host']
                || $port !== $baseUrl['port']) {
                continue;
            }

            $prefix = $baseUrl['path'];
            if ($prefix !== '' && $path !== $prefix && ! str_starts_with($path, $prefix.'/')) {
                continue;
            }

            $relativePath = $prefix === '' ? $path : substr($path, strlen($prefix));

            // Intercept every path under the configured API base URL, not only the
            // currently configured version. Unknown versions and routes must fail
            // closed in handle() rather than falling through to a real Wise request.
            return $relativePath;
        }

        return null;
    }

    /**
     * @return list<array{operation: ?string, method: string, url: string, body: array<array-key, mixed>|null}>
     */
    private function sent(string $endpointKey): array
    {
        return array_values(array_filter(
            $this->requests,
            static fn (array $record): bool => $record['operation'] === $endpointKey,
        ));
    }

    private function count(string $endpointKey): int
    {
        return count($this->sent($endpointKey));
    }

    /**
     * @param  list<array{operation: ?string, method: string, url: string, body: array<array-key, mixed>|null}>  $records
     */
    private function any(array $records, Closure $callback): bool
    {
        foreach ($records as $record) {
            if ($callback($record['body']) === true) {
                return true;
            }
        }

        return false;
    }
}
