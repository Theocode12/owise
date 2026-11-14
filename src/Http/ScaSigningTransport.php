<?php

namespace Owise\Http;

use Illuminate\Support\Str;
use Owise\Auth\PrivateKeyScaSigner;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Theocode\Wise\Contracts\TransportInterface;

/**
 * Completes Wise's personal-token SCA challenge: a 403 carrying an `x-2fa-approval` one-time token is retried
 * once with the token and its RSA-SHA256 signature in `X-Signature`.
 */
final readonly class ScaSigningTransport implements TransportInterface
{
    /**
     * Create a new SCA signing transport.
     *
     * @param  TransportInterface  $inner  The transport used to send the requests.
     */
    public function __construct(
        private TransportInterface $inner,
        private PrivateKeyScaSigner $signer,
    ) {}

    /**
     * Send the request, retrying once with an SCA signature on challenge.
     */
    public function send(RequestInterface $request): ResponseInterface
    {
        if (! $request->hasHeader(LaravelHttpTransport::CORRELATION_HEADER)) {
            $request = $request->withHeader(LaravelHttpTransport::CORRELATION_HEADER, (string) Str::uuid());
        }

        $response = $this->inner->send($request);

        $oneTimeToken = $response->getHeaderLine('x-2fa-approval');
        if ($response->getStatusCode() !== 403 || $oneTimeToken === '' || $request->hasHeader('X-Signature')) {
            return $response;
        }

        $body = $request->getBody();
        if (! $body->isSeekable()) {
            return $response;
        }

        $body->rewind();

        return $this->inner->send($request
            ->withHeader('x-2fa-approval', $oneTimeToken)
            ->withHeader('X-Signature', $this->signer->sign($oneTimeToken)));
    }
}
