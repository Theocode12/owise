<?php

namespace Owise\Testing;

use Illuminate\Foundation\Testing\Concerns\MakesHttpRequests;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sign and POST Wise webhook deliveries so tests exercise the real verification middleware.
 *
 * Add this trait to a Pest or PHPUnit test class. The trait loads a throwaway RSA keypair,
 * configures its public key for verification, signs the raw body and posts it to the `wise.webhook`
 * route with the `X-Signature-SHA256` and `X-Delivery-Id` headers.
 *
 * @mixin MakesHttpRequests
 */
trait InteractsWithWiseWebhooks
{
    /**
     * The bundled test keypair, cached for the process.
     *
     * @var array{private: string, public: string}|null
     */
    private static ?array $wiseWebhookKeypair = null;

    /**
     * Sign and POST a webhook payload to the package's webhook route.
     *
     * @param  array<string, mixed>  $payload  A payload from {@see WebhookFactory}.
     * @param  string|null  $deliveryId  The `X-Delivery-Id` header (defaults to a random UUID).
     * @return TestResponse<Response>
     */
    public function postWiseWebhook(array $payload, ?string $deliveryId = null): TestResponse
    {
        $keys = self::wiseWebhookKeypair();
        config()->set('wise.webhooks.public_keys', [$keys['public']]);

        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $signature = '';
        openssl_sign($body, $signature, self::privateKey(), OPENSSL_ALGO_SHA256);

        return $this->call('POST', route('wise.webhook'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SIGNATURE_SHA256' => base64_encode($signature),
            'HTTP_X_DELIVERY_ID' => $deliveryId ?? (string) Str::uuid(),
        ], $body);
    }

    /**
     * Sign a raw webhook body and return the Base64 `X-Signature-SHA256` value.
     */
    public function wiseWebhookSignature(string $body): string
    {
        $signature = '';
        openssl_sign($body, $signature, self::privateKey(), OPENSSL_ALGO_SHA256);

        return base64_encode($signature);
    }

    /**
     * Load the bundled throwaway RSA test private key.
     */
    private static function privateKey(): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_get_private(self::wiseWebhookKeypair()['private']);

        if ($key === false) {
            throw new \RuntimeException('Unable to load the bundled Wise webhook test private key.');
        }

        return $key;
    }

    /**
     * Load the bundled throwaway RSA test keypair (committed so the kit works on platforms
     * without an `openssl.cnf`, e.g. Windows).
     *
     * @return array{private: string, public: string}
     */
    private static function wiseWebhookKeypair(): array
    {
        if (self::$wiseWebhookKeypair !== null) {
            return self::$wiseWebhookKeypair;
        }

        $directory = dirname(__DIR__, 2).'/resources/keys/testing';

        return self::$wiseWebhookKeypair = [
            'private' => (string) file_get_contents($directory.'/owise-test-private.pem'),
            'public' => (string) file_get_contents($directory.'/owise-test-public.pem'),
        ];
    }
}
