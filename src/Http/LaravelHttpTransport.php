<?php

namespace Owise\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Theocode\Wise\Contracts\TransportInterface;
use Theocode\Wise\Exceptions\TransportException;

/**
 * Send Wise API requests over Laravel's HTTP client.
 *
 * Attaches a correlation id, records responses for error mapping, and applies mTLS when enabled.
 */
final readonly class LaravelHttpTransport implements TransportInterface
{
    public const CORRELATION_HEADER = 'X-External-Correlation-Id';

    /**
     * Create a new Laravel HTTP transport.
     *
     * @param  array<string, mixed>  $options  Timeouts and other HTTP options.
     * @param  ResponseRecorder|null  $recorder  The recorder used to expose Wise response headers.
     * @param  array<string, mixed>  $mtls  The mTLS certificate configuration.
     */
    public function __construct(
        private Factory $http,
        private array $options = [],
        private ?LoggerInterface $logger = null,
        private ?ResponseRecorder $recorder = null,
        private array $mtls = [],
    ) {}

    /**
     * Send the given request and return the response.
     *
     *
     * @throws \InvalidArgumentException
     * @throws TransportException
     */
    public function send(RequestInterface $request): ResponseInterface
    {
        $correlationId = $request->getHeaderLine(self::CORRELATION_HEADER);
        if ($correlationId === '') {
            $correlationId = (string) Str::uuid();
            $request = $request->withHeader(self::CORRELATION_HEADER, $correlationId);
        }

        $this->recorder?->recordRequest($correlationId);

        try {
            $pendingRequest = $this->http->withHeaders($request->getHeaders())
                ->timeout((float) ($this->options['timeout'] ?? 30))
                ->connectTimeout((float) ($this->options['connect_timeout'] ?? 10));

            if ((bool) ($this->mtls['enabled'] ?? false)) {
                $certificate = (string) ($this->mtls['certificate'] ?? '');
                $privateKey = (string) ($this->mtls['private_key'] ?? '');
                if ($certificate === '' || $privateKey === '') {
                    throw new \InvalidArgumentException('mTLS requires certificate and private key file paths.');
                }

                $passphrase = $this->mtls['passphrase'] ?? null;
                $pendingRequest = $pendingRequest->withOptions([
                    'cert' => is_string($passphrase) && $passphrase !== '' ? [$certificate, $passphrase] : $certificate,
                    'ssl_key' => is_string($passphrase) && $passphrase !== '' ? [$privateKey, $passphrase] : $privateKey,
                ]);
            }

            $body = (string) $request->getBody();
            if ($body !== '') {
                $pendingRequest = $pendingRequest->withBody(
                    $body,
                    $request->getHeaderLine('Content-Type') ?: 'application/octet-stream',
                );
            }

            $response = $pendingRequest->send($request->getMethod(), (string) $request->getUri())->toPsrResponse();
        } catch (ConnectionException $exception) {
            $this->logger?->warning('wise.transport.connection_failed', [
                'method' => $request->getMethod(),
                'host' => $request->getUri()->getHost(),
                'correlation_id' => $correlationId,
            ]);

            throw new TransportException('Wise API connection failed: '.$exception->getMessage(), 0, $exception);
        }

        $this->recorder?->recordResponse($response);

        if ($response->getStatusCode() >= 400) {
            $this->logger?->warning('wise.response.failed', [
                'method' => $request->getMethod(),
                'path' => $request->getUri()->getPath(),
                'status' => $response->getStatusCode(),
                'correlation_id' => $correlationId,
                'trace_id' => $response->getHeaderLine('x-trace-id') ?: null,
            ]);
        }

        return $response;
    }
}
