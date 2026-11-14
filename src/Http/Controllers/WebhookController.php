<?php

namespace Owise\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use JsonException;
use Owise\Events\WebhookReceived;
use Owise\Jobs\ProcessWiseWebhook;
use Owise\Support\WiseEventDispatcher;
use Owise\Webhooks\Payload;

/**
 * Accept incoming Wise webhooks and queue them for processing.
 */
class WebhookController
{
    /**
     * Validate the payload and dispatch it for processing.
     */
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $body = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response()->json(['message' => 'Invalid JSON payload.'], 400);
        }

        if (! is_array($body)
            || ! is_string($body['event_type'] ?? null)
            || trim($body['event_type']) === ''
            || ! is_string($body['schema_version'] ?? null)
            || trim($body['schema_version']) === '') {
            return response()->json(['message' => 'The payload must include event_type and schema_version.'], 400);
        }

        $deliveryId = $request->header('X-Delivery-Id');
        if (is_string($deliveryId) && $deliveryId !== '' && ! Str::isUuid($deliveryId)) {
            return response()->json(['message' => 'X-Delivery-Id must be a UUID.'], 400);
        }
        $deliveryId = is_string($deliveryId) && $deliveryId !== '' ? $deliveryId : null;

        $isTest = strtolower((string) $request->header('X-Test-Notification')) === 'true';
        $payload = Payload::fromRequest($body, $deliveryId, $isTest);

        $dispatch = ProcessWiseWebhook::dispatch($body, $deliveryId, $isTest)->afterCommit();
        $queue = config('wise.webhooks.queue');
        $connection = config('wise.webhooks.connection');
        if (is_string($queue) && $queue !== '') {
            $dispatch->onQueue($queue);
        }
        if (is_string($connection) && $connection !== '') {
            $dispatch->onConnection($connection);
        }

        app(WiseEventDispatcher::class)->dispatchApplicationEvent(new WebhookReceived($payload));

        return response()->json(['received' => true]);
    }
}
