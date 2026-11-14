<?php

namespace Owise\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Owise\Api\WebhookSubscriptionsApi;
use Owise\Data\WebhookSubscription;
use Owise\WiseManager;

/**
 * Manage Wise webhook subscriptions from the console.
 */
class WebhooksCommand extends Command
{
    protected $signature = 'wise:webhooks
        {--list : List webhook subscriptions}
        {--create : Create subscriptions for configured event types}
        {--delete= : Delete a subscription by ID}
        {--test= : Send an application test notification by subscription ID}
        {--url= : HTTPS delivery URL (defaults to the wise.webhook route)}
        {--profile= : Configured Wise profile name}
        {--application : Use application-level subscriptions}
        {--mtls : Request mTLS delivery for created subscriptions}
        {--no-mtls : Explicitly disable mTLS delivery for created subscriptions}';

    protected $description = 'Manage Wise webhook subscriptions.';

    /**
     * Execute the console command.
     */
    public function handle(WiseManager $wise): int
    {
        $application = (bool) $this->option('application');
        $clientKey = $application ? $this->applicationClientKey() : '';
        if ($clientKey === null) {
            return self::FAILURE;
        }

        if ($application) {
            $api = $wise->applicationWebhooks();
        } else {
            $profileName = $this->option('profile');
            $profileName = is_string($profileName) && $profileName !== '' ? $profileName : null;
            $api = $wise->profile($profileName)->webhooks();
        }

        $id = $this->option('delete');
        if (is_string($id) && $id !== '') {
            return $this->deleteSubscription($api, $id, $application, $clientKey);
        }

        $id = $this->option('test');
        if (is_string($id) && $id !== '') {
            return $this->sendTestNotification($api, $id, $application, $clientKey);
        }

        if ((bool) $this->option('create')) {
            return $this->createSubscriptions($api, $application, $clientKey);
        }

        return $this->listSubscriptions($api, $application, $clientKey);
    }

    /**
     * Get the client key required for application subscriptions.
     */
    private function applicationClientKey(): ?string
    {
        $clientKey = config('wise.client_key');
        if (! is_string($clientKey) || trim($clientKey) === '') {
            $this->error('Application subscriptions require a client key. Configure wise.client_key.');

            return null;
        }

        return trim($clientKey);
    }

    /**
     * Delete a webhook subscription.
     */
    private function deleteSubscription(WebhookSubscriptionsApi $api, string $id, bool $application, string $clientKey): int
    {
        if ($application) {
            $api->deleteForApplication($clientKey, $id);
        } else {
            $api->delete($id);
        }
        $this->info("Deleted webhook subscription {$id}.");

        return self::SUCCESS;
    }

    /**
     * Send a test notification for an application subscription.
     */
    private function sendTestNotification(WebhookSubscriptionsApi $api, string $id, bool $application, string $clientKey): int
    {
        if (! $application) {
            $this->error('Wise test notifications are available only for application-level subscriptions; pass --application.');

            return self::FAILURE;
        }

        $api->testForApplication($clientKey, $id);
        $this->info("Sent a test notification for subscription {$id}.");

        return self::SUCCESS;
    }

    /**
     * Create subscriptions for the configured event types.
     */
    private function createSubscriptions(WebhookSubscriptionsApi $api, bool $application, string $clientKey): int
    {
        $url = $this->deliveryUrl();
        if ($url === null) {
            return self::FAILURE;
        }

        $events = config('wise.webhooks.events', []);
        if (! is_array($events) || $events === []) {
            $this->error('Configure one or more event types in wise.webhooks.events.');

            return self::FAILURE;
        }

        $mtlsEnabled = $this->resolveMtlsEnabled();

        foreach ($events as $eventType) {
            if (! is_string($eventType) || $eventType === '') {
                continue;
            }
            $name = (string) config('wise.webhooks.name', 'Owise').' '.str_replace('#', ' ', $eventType);
            $version = (string) config('wise.webhooks.schema_version', WebhookSubscriptionsApi::DEFAULT_VERSION);
            $subscription = $application
                ? $api->createForApplication(clientKey: $clientKey, name: $name, triggerOn: $eventType, url: $url, version: $version, mtlsEnabled: $mtlsEnabled)
                : $api->create(name: $name, triggerOn: $eventType, url: $url, version: $version, mtlsEnabled: $mtlsEnabled);
            $this->line("Created {$eventType} subscription {$subscription->id}.");
        }

        return self::SUCCESS;
    }

    /**
     * Resolve whether mTLS delivery should be requested.
     */
    private function resolveMtlsEnabled(): ?bool
    {
        if ($this->option('mtls')) {
            return true;
        }
        if ($this->option('no-mtls')) {
            return false;
        }

        $configured = config('wise.webhooks.mtls_enabled');

        return is_bool($configured) ? $configured : null;
    }

    /**
     * Resolve the webhook delivery URL.
     */
    private function deliveryUrl(): ?string
    {
        $url = $this->option('url');
        if (is_string($url) && $url !== '') {
            return $url;
        }

        if (! Route::has('wise.webhook')) {
            $this->error('No webhook route is registered. Enable wise.routes.enabled or pass --url.');

            return null;
        }

        return route('wise.webhook');
    }

    /**
     * List the webhook subscriptions in a table.
     */
    private function listSubscriptions(WebhookSubscriptionsApi $api, bool $application, string $clientKey): int
    {
        $subscriptions = $application
            ? $api->listForApplication($clientKey)
            : $api->list();

        if ($subscriptions === []) {
            $this->info('No webhook subscriptions found.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Event', 'Version', 'URL', 'mTLS'],
            array_map(static fn (WebhookSubscription $subscription): array => [
                $subscription->id,
                $subscription->triggerOn,
                $subscription->deliveryVersion ?? '',
                $subscription->deliveryUrl ?? '',
                $subscription->deliveryMtlsEnabled === true ? 'yes' : ($subscription->deliveryMtlsEnabled === false ? 'no' : '—'),
            ], $subscriptions),
        );

        return self::SUCCESS;
    }
}
