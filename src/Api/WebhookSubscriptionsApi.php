<?php

namespace Owise\Api;

use InvalidArgumentException;
use Owise\Data\WebhookSubscription;
use Owise\Exceptions\MissingProfile;
use Owise\Exceptions\WiseApiError;
use Owise\Support\Payload;

/**
 * Manage Wise webhook subscriptions for the current profile.
 */
final class WebhookSubscriptionsApi extends AccountApi
{
    /** The default webhook delivery version. @var string */
    public const DEFAULT_VERSION = '4.0.0';

    /**
     * Create a profile webhook subscription.
     *
     * Uses raw requests: the SDK's subscription body (`url`, `triggers`) and model (integer id) do not match Wise's schema.
     *
     * @param  string  $name  The subscription name.
     * @param  string  $triggerOn  The trigger event.
     * @param  string  $url  The delivery URL.
     * @param  string  $version  The delivery version.
     * @param  int|null  $profileId  The profile ID, falling back to the account context.
     * @param  bool|null  $mtlsEnabled  Whether mTLS is enabled, if any.
     * @return WebhookSubscription The created subscription.
     *
     * @throws InvalidArgumentException
     * @throws WiseApiError
     */
    public function create(string $name, string $triggerOn, string $url, string $version = self::DEFAULT_VERSION, ?int $profileId = null, ?bool $mtlsEnabled = null): WebhookSubscription
    {
        return WebhookSubscription::fromArray($this->client->requestOperation(
            'webhooks.profile.create',
            $this->profileParameters($profileId),
            body: $this->body($name, $triggerOn, $url, $version, $mtlsEnabled),
        ));
    }

    /**
     * List the profile webhook subscriptions.
     *
     * @param  int|null  $profileId  The profile ID, falling back to the account context.
     * @return list<WebhookSubscription> The subscriptions.
     *
     * @throws WiseApiError
     */
    public function list(?int $profileId = null): array
    {
        return $this->mapList($this->client->requestOperation('webhooks.profile.list', $this->profileParameters($profileId)));
    }

    /**
     * Get a profile webhook subscription by its ID.
     *
     * @param  string  $subscriptionId  The subscription ID.
     * @param  int|null  $profileId  The profile ID, falling back to the account context.
     * @return WebhookSubscription The subscription.
     *
     * @throws WiseApiError
     */
    public function find(string $subscriptionId, ?int $profileId = null): WebhookSubscription
    {
        return WebhookSubscription::fromArray(
            $this->client->requestOperation('webhooks.profile.find', $this->profileParameters($profileId, $subscriptionId)),
        );
    }

    /**
     * Delete a profile webhook subscription.
     *
     * @param  string  $subscriptionId  The subscription ID.
     * @param  int|null  $profileId  The profile ID, falling back to the account context.
     *
     * @throws WiseApiError
     */
    public function delete(string $subscriptionId, ?int $profileId = null): void
    {
        $this->client->requestOperation('webhooks.profile.delete', $this->profileParameters($profileId, $subscriptionId));
    }

    /**
     * Create an application webhook subscription.
     *
     * Creates an application-level subscription using the configured client-credentials token.
     *
     * @param  string  $clientKey  The OAuth client key.
     * @param  string  $name  The subscription name.
     * @param  string  $triggerOn  The trigger event.
     * @param  string  $url  The delivery URL.
     * @param  string  $version  The delivery version.
     * @param  bool|null  $mtlsEnabled  Whether mTLS is enabled, if any.
     * @return WebhookSubscription The created subscription.
     *
     * @throws InvalidArgumentException
     * @throws WiseApiError
     */
    public function createForApplication(string $clientKey, string $name, string $triggerOn, string $url, string $version = self::DEFAULT_VERSION, ?bool $mtlsEnabled = null): WebhookSubscription
    {
        return WebhookSubscription::fromArray($this->client->requestOperation(
            'webhooks.application.create',
            $this->applicationParameters($clientKey),
            body: $this->body($name, $triggerOn, $url, $version, $mtlsEnabled),
        ));
    }

    /**
     * List the application webhook subscriptions.
     *
     * @param  string  $clientKey  The OAuth client key.
     * @return list<WebhookSubscription> The subscriptions.
     *
     * @throws WiseApiError
     */
    public function listForApplication(string $clientKey): array
    {
        return $this->mapList($this->client->requestOperation('webhooks.application.list', $this->applicationParameters($clientKey)));
    }

    /**
     * Get an application webhook subscription by its ID.
     *
     * @param  string  $clientKey  The OAuth client key.
     * @param  string  $subscriptionId  The subscription ID.
     * @return WebhookSubscription The subscription.
     *
     * @throws WiseApiError
     */
    public function findForApplication(string $clientKey, string $subscriptionId): WebhookSubscription
    {
        return WebhookSubscription::fromArray(
            $this->client->requestOperation('webhooks.application.find', $this->applicationParameters($clientKey, $subscriptionId)),
        );
    }

    /**
     * Delete an application webhook subscription.
     *
     * @param  string  $clientKey  The OAuth client key.
     * @param  string  $subscriptionId  The subscription ID.
     *
     * @throws WiseApiError
     */
    public function deleteForApplication(string $clientKey, string $subscriptionId): void
    {
        $this->client->requestOperation('webhooks.application.delete', $this->applicationParameters($clientKey, $subscriptionId));
    }

    /**
     * Send a test notification for an application subscription.
     *
     * @param  string  $clientKey  The OAuth client key.
     * @param  string  $subscriptionId  The subscription ID.
     *
     * @throws WiseApiError
     */
    public function testForApplication(string $clientKey, string $subscriptionId): void
    {
        $this->client->requestOperation('webhooks.application.test', $this->applicationParameters($clientKey, $subscriptionId));
    }

    /**
     * Build the subscription request body.
     *
     * @param  string  $name  The subscription name.
     * @param  string  $triggerOn  The trigger event.
     * @param  string  $url  The delivery URL.
     * @param  string  $version  The delivery version.
     * @param  bool|null  $mtlsEnabled  Whether mTLS is enabled, if any.
     * @return array<string, mixed> The request body.
     *
     * @throws InvalidArgumentException
     */
    private function body(string $name, string $triggerOn, string $url, string $version, ?bool $mtlsEnabled = null): array
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false || ! str_starts_with(strtolower($url), 'https://')) {
            throw new InvalidArgumentException('Wise webhook delivery URLs must be absolute HTTPS URLs.');
        }

        /** @var array{version: string, url: string, mtls_enabled?: bool} $delivery */
        $delivery = ['version' => $version, 'url' => $url];
        if ($mtlsEnabled !== null) {
            $delivery['mtls_enabled'] = $mtlsEnabled;
        }

        return [
            'name' => $name,
            'trigger_on' => $triggerOn,
            'delivery' => $delivery,
        ];
    }

    /**
     * Map a raw payload to webhook subscriptions.
     *
     * @param  array<array-key, mixed>  $payload  The raw response payload.
     * @return list<WebhookSubscription> The subscriptions.
     */
    private function mapList(array $payload): array
    {
        return array_map(WebhookSubscription::fromArray(...), Payload::arrays($payload));
    }

    /**
     * Build route parameters for a profile subscription operation.
     *
     * @param  int|null  $profileId  The profile ID, falling back to the account context.
     * @param  string|null  $subscriptionId  The subscription ID.
     * @return array{profileId: int, subscriptionId?: string}
     *
     * @throws MissingProfile
     */
    private function profileParameters(?int $profileId, ?string $subscriptionId = null): array
    {
        $parameters = ['profileId' => $this->profileId($profileId)];
        if ($subscriptionId !== null) {
            $parameters['subscriptionId'] = $subscriptionId;
        }

        return $parameters;
    }

    /**
     * Build route parameters for an application subscription operation.
     *
     * @param  string  $clientKey  The OAuth client key.
     * @param  string|null  $subscriptionId  The subscription ID.
     * @return array{clientKey: string, subscriptionId?: string}
     */
    private function applicationParameters(string $clientKey, ?string $subscriptionId = null): array
    {
        $parameters = ['clientKey' => $clientKey];
        if ($subscriptionId !== null) {
            $parameters['subscriptionId'] = $subscriptionId;
        }

        return $parameters;
    }
}
