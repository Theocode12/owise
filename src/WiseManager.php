<?php

namespace Owise;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Owise\Account\AccountContext;
use Owise\Account\Environment;
use Owise\Api\ApiClientFactory;
use Owise\Api\WebhookSubscriptionsApi;
use Owise\Auth\TokenProviderFactory;
use Owise\Enums\FundingMode;
use Owise\Exceptions\InvalidConfiguration;
use Owise\OAuth\OAuthManager;
use Owise\Testing\Simulation;
use Owise\Testing\WiseFake;

/** Manage configured Wise profiles and application-level services. */
final class WiseManager
{
    /**
     * The resolved Wise profiles keyed by name.
     *
     * @var array<string, WiseProfile>
     */
    private array $profiles = [];

    /**
     * Create a new Wise manager instance.
     */
    public function __construct(
        private readonly Repository $config,
        private readonly ApiClientFactory $clients,
        private readonly TokenProviderFactory $tokenProviders,
    ) {}

    /**
     * Resolve a configured Wise profile by name.
     *
     * Resolved profiles are cached for the lifetime of the manager.
     *
     *
     * @throws InvalidConfiguration
     */
    public function profile(?string $name = null): WiseProfile
    {
        $name ??= $this->config->get('wise.default_profile', 'default');
        if (! is_string($name) || trim($name) === '') {
            throw new InvalidConfiguration('The default Wise profile name must be a non-empty string.');
        }

        if (isset($this->profiles[$name])) {
            return $this->profiles[$name];
        }

        $profiles = $this->config->get('wise.profiles', []);
        if (! is_array($profiles) || ! isset($profiles[$name]) || ! is_array($profiles[$name])) {
            throw new InvalidConfiguration("Wise profile '{$name}' is not configured in config/wise.php.");
        }

        $environment = $this->config->get('wise.environment', 'sandbox');
        $authType = $this->config->get('wise.auth', 'personal_token');
        if (! is_string($environment) || ! is_string($authType)) {
            throw new InvalidConfiguration('wise.environment and wise.auth must be strings.');
        }
        $authConfig = ['token' => $this->config->get('wise.token')];
        $context = AccountContext::fromConfig($name, $profiles[$name], $environment, $authType, $authConfig, $this->tokenProviders);

        return $this->profiles[$name] = $this->forContext($context);
    }

    /**
     * Create a Wise profile for the given account context.
     */
    public function forContext(AccountContext $context): WiseProfile
    {
        return new WiseProfile($context, $this->clients->make($context));
    }

    /**
     * Resolve the application-level webhook API client.
     *
     * It authenticates with client credentials rather than a profile token.
     *
     *
     * @throws InvalidConfiguration
     */
    public function applicationWebhooks(): WebhookSubscriptionsApi
    {
        $environmentValue = $this->config->get('wise.environment', 'sandbox');
        $environment = is_string($environmentValue) ? Environment::tryFrom(strtolower($environmentValue)) : null;
        if ($environment === null) {
            throw new InvalidConfiguration('wise.environment must be sandbox or production.');
        }

        $context = new AccountContext(
            name: 'wise-application-webhooks',
            environment: $environment,
            profileId: null,
            tokens: $this->tokenProviders->make('client_credentials', [], 'application webhooks'),
            funding: FundingMode::Manual,
            authType: 'client_credentials',
        );

        return new WebhookSubscriptionsApi($this->clients->make($context), $context);
    }

    /**
     * Register a custom authentication type.
     */
    public function extendAuth(string $type, Closure $factory): void
    {
        $this->tokenProviders->extend($type, $factory);
    }

    /**
     * Get the OAuth manager.
     */
    public function oauth(): OAuthManager
    {
        return app(OAuthManager::class);
    }

    /**
     * Fake the Wise API for testing, scoped to the configured Wise base URLs.
     *
     * @param  array<string, array<array-key, mixed>|Closure>  $responses  Operation key to payload (or closure).
     */
    public function fake(array $responses = []): WiseFake
    {
        return new WiseFake($responses);
    }

    /**
     * Drive real sandbox transfers through their states (sandbox only).
     */
    public function simulate(?string $profile = null): Simulation
    {
        return new Simulation($this->profile($profile)->simulate());
    }

    /**
     * Forward missing calls to the default Wise profile.
     *
     * @param  array<array-key, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->profile()->{$method}(...$arguments);
    }
}
