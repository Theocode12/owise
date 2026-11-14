<?php

namespace Owise;

use Owise\Account\AccountContext;
use Owise\Api\ApiClient;
use Owise\Api\BalancesApi;
use Owise\Api\ProfilesApi;
use Owise\Api\QuotesApi;
use Owise\Api\RecipientsApi;
use Owise\Api\SimulationApi;
use Owise\Api\StatementsApi;
use Owise\Api\TransfersApi;
use Owise\Api\WebhookSubscriptionsApi;
use Owise\Exceptions\MissingProfile;
use Theocode\Wise\WiseClient;

/** Provide scoped access to the Wise APIs for a single account context. */
final readonly class WiseProfile
{
    /**
     * Create a new Wise profile instance.
     */
    public function __construct(
        private AccountContext $accountContext,
        private ApiClient $client,
    ) {}

    /**
     * Get the underlying account context.
     */
    public function context(): AccountContext
    {
        return $this->accountContext;
    }

    /**
     * Escape hatch to the underlying SDK client. Calls made through it bypass the package's DTOs and error mapping.
     */
    public function sdk(): WiseClient
    {
        return $this->client->sdk;
    }

    /**
     * Get the configured Wise profile ID.
     *
     *
     * @throws MissingProfile
     */
    public function profileId(): int
    {
        return $this->accountContext->profileId
            ?? throw new MissingProfile(
                "Wise profile '{$this->accountContext->name}' has no profile_id configured. Set WISE_PROFILE_ID or this profile's profile_id.",
            );
    }

    /**
     * Get the profiles API for the current profile.
     */
    public function profiles(): ProfilesApi
    {
        return new ProfilesApi($this->client, $this->accountContext);
    }

    /**
     * Get the quotes API for the current profile.
     */
    public function quotes(): QuotesApi
    {
        return new QuotesApi($this->client, $this->accountContext);
    }

    /**
     * Get the recipients API for the current profile.
     */
    public function recipients(): RecipientsApi
    {
        return new RecipientsApi($this->client, $this->accountContext);
    }

    /**
     * Get the transfers API for the current profile.
     */
    public function transfers(): TransfersApi
    {
        return new TransfersApi($this->client, $this->accountContext);
    }

    /**
     * Get the balances API for the current profile.
     */
    public function balances(): BalancesApi
    {
        return new BalancesApi($this->client, $this->accountContext);
    }

    /**
     * Get the statements API for the current profile.
     */
    public function statements(): StatementsApi
    {
        return new StatementsApi($this->client, $this->accountContext);
    }

    /**
     * Get the webhooks API for the current profile.
     */
    public function webhooks(): WebhookSubscriptionsApi
    {
        return new WebhookSubscriptionsApi($this->client, $this->accountContext);
    }

    /**
     * Get the simulation API for the current profile.
     */
    public function simulate(): SimulationApi
    {
        return new SimulationApi($this->client, $this->accountContext);
    }
}
