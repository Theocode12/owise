<?php

namespace Owise\Webhooks;

use Illuminate\Contracts\Container\Container;
use Owise\Contracts\WebhookHandler;
use Owise\Events\WebhookHandled;
use Owise\Support\WiseEventDispatcher;

/**
 * Route incoming webhook payloads to their configured handlers.
 */
class WebhookProcessor
{
    /**
     * The default event type to handler mapping.
     *
     * @var array<string, class-string<WebhookHandler>>
     */
    private const DEFAULT_HANDLERS = [
        'transfers#state-change' => Handlers\TransferStateChanged::class,
        'transfers#active-cases' => Handlers\TransferActiveCases::class,
        'transfers#payout-failure' => Handlers\TransferPayoutFailure::class,
        'transfers#refund' => Handlers\TransferRefund::class,
        'balances#credit' => Handlers\BalanceCredit::class,
        'balances#update' => Handlers\BalanceUpdate::class,
        'profiles#verification-state-change' => Handlers\ProfileVerificationStateChanged::class,
    ];

    /**
     * Create a new processor instance.
     */
    public function __construct(private readonly Container $container) {}

    /**
     * Process the given webhook payload.
     *
     * Dispatches the payload to its configured handler. Test and unhandled events only dispatch handled.
     *
     *
     * @throws \UnexpectedValueException
     */
    public function process(Payload $payload): void
    {
        if ($payload->isTest) {
            $this->finish($payload);

            return;
        }

        $configured = config('wise.webhooks.handlers', []);
        $handlers = is_array($configured) ? array_replace(self::DEFAULT_HANDLERS, $configured) : self::DEFAULT_HANDLERS;
        if (! array_key_exists($payload->eventType, $handlers)) {
            $this->finish($payload);

            return;
        }

        $handlerClass = $handlers[$payload->eventType];
        if (! is_string($handlerClass) || ! class_exists($handlerClass)) {
            throw new \UnexpectedValueException("Configured webhook handler for {$payload->eventType} does not exist.");
        }

        $handler = $this->container->make($handlerClass);
        if (! $handler instanceof WebhookHandler) {
            throw new \UnexpectedValueException("Webhook handler {$handlerClass} must implement ".WebhookHandler::class.'.');
        }

        $handler->handle($payload);
        $this->finish($payload);
    }

    /**
     * Dispatch the handled event for the given payload.
     */
    private function finish(Payload $payload): void
    {
        $this->container->make(WiseEventDispatcher::class)->dispatchApplicationEvent(new WebhookHandled($payload));
    }
}
