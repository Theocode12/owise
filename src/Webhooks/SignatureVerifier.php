<?php

namespace Owise\Webhooks;

use Owise\Api\WebhookSignatureVerifier as SdkSignatureVerifier;

final class SignatureVerifier
{
    /**
     * Create a new verifier instance.
     */
    public function __construct(private readonly SdkSignatureVerifier $sdkVerifier) {}

    /**
     * Verify the webhook signature against the given public keys.
     *
     * @param  array<array-key, mixed>  $publicKeysPem
     */
    public function verify(string $body, string $signatureB64, array $publicKeysPem): bool
    {
        return $this->sdkVerifier->verify($body, $signatureB64, $publicKeysPem);
    }
}
