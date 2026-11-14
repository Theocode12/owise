<?php

namespace Owise\Api;

use Theocode\Wise\Resources\Webhook\WebhookSignatureVerifier as SdkVerifier;
use Throwable;

/**
 * Verify Wise webhook signatures for the current account.
 */
final class WebhookSignatureVerifier
{
    /**
     * Verify a webhook payload against the configured public keys.
     *
     * @param  string  $body  The raw request body.
     * @param  string  $signatureB64  The base64-encoded signature.
     * @param  array<array-key, mixed>  $publicKeysPem  The PEM public keys.
     * @return bool Whether the signature is valid.
     */
    public function verify(string $body, string $signatureB64, array $publicKeysPem): bool
    {
        $keys = array_values(array_filter($publicKeysPem, is_string(...)));
        if ($keys === []) {
            return false;
        }

        try {
            return (new SdkVerifier($keys))->verify($body, $signatureB64);
        } catch (Throwable) {
            return false;
        }
    }
}
