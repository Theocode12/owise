<?php

namespace Owise\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Owise\Webhooks\SignatureVerifier;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guard Wise webhook routes by verifying the request signature.
 */
class VerifyWiseSignature
{
    /**
     * Create a new middleware instance.
     */
    public function __construct(private readonly SignatureVerifier $verifier) {}

    /**
     * Handle an incoming webhook request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('wise.webhooks.verify', true)) {
            if (app()->isProduction()) {
                Log::error('Wise webhook signature verification cannot be disabled in production.');
            } else {
                Log::critical('Wise webhook signature verification is DISABLED. Requests are being accepted without verification.');

                return $next($request);
            }
        }

        $signature = $request->header('X-Signature-SHA256');
        $valid = is_string($signature)
            && $this->verifier->verify($request->getContent(), $signature, $this->publicKeys());

        if (! $valid) {
            Log::warning('Rejected Wise webhook with a missing or invalid signature.', [
                'delivery_id' => $request->header('X-Delivery-Id'),
                'ip' => $request->ip(),
            ]);

            return response('Invalid Wise webhook signature.', 400);
        }

        return $next($request);
    }

    /**
     * Get the public keys used to verify webhook signatures.
     *
     * Falls back to the bundled keys for the configured environment.
     *
     * @return array<array-key, mixed>
     */
    private function publicKeys(): array
    {
        $configured = config('wise.webhooks.public_keys');
        if (is_array($configured)) {
            return $configured;
        }

        $environment = config('wise.environment', 'sandbox');

        if (! in_array($environment, ['sandbox', 'production'], true)) {
            return [];
        }

        $path = dirname(__DIR__, 3)."/resources/keys/wise-{$environment}.pem";
        $key = @file_get_contents($path);

        return is_string($key) ? [$key] : [];
    }
}
