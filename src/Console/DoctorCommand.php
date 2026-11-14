<?php

namespace Owise\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Owise\Account\Environment;
use Owise\Exceptions\MissingProfile;
use Owise\WiseManager;
use Throwable;

/**
 * Diagnose the Owise configuration and Wise connectivity.
 */
class DoctorCommand extends Command
{
    protected $signature = 'wise:doctor';

    protected $description = 'Check Owise configuration and Wise connectivity.';

    /** @var list<array{0: string, 1: string, 2: string}> */
    private array $results = [];

    private bool $hasFailures = false;

    /**
     * Execute the diagnostics.
     */
    public function handle(WiseManager $wise): int
    {
        $this->results = [];
        $this->hasFailures = false;

        $environmentValue = config('wise.environment', 'sandbox');
        $environment = is_string($environmentValue)
            ? Environment::tryFrom(strtolower($environmentValue))
            : null;

        if ($environment === null) {
            $this->record('Environment', 'FAIL', 'Set wise.environment to sandbox or production.');
        } else {
            $urls = config('wise.urls', []);
            try {
                if (! is_array($urls)) {
                    throw new \InvalidArgumentException('Wise URLs must be an array.');
                }

                $environment->apiBaseUrl($urls, (bool) config('wise.oauth.mtls.enabled', false));
                $this->record('Environment and API URL', 'PASS', "Using the {$environment->value} environment with a valid API URL.");
            } catch (Throwable) {
                $this->record('Environment and API URL', 'FAIL', "Set a valid wise.urls.{$environment->value}.api_base_url.");
            }
        }

        $profileConfigs = config('wise.profiles', []);
        if (! is_array($profileConfigs) || $profileConfigs === []) {
            $this->record('Profiles configuration', 'FAIL', 'Configure at least one profile in wise.profiles.');
            $profileConfigs = [];
        } else {
            $defaultProfile = config('wise.default_profile', 'default');
            if (! is_string($defaultProfile) || ! array_key_exists($defaultProfile, $profileConfigs)) {
                $this->record('Default profile', 'FAIL', 'Set wise.default_profile to a key in wise.profiles.');
            } else {
                $this->record('Default profile', 'PASS', "Configured default profile: {$defaultProfile}.");
            }
        }

        $authType = config('wise.auth', 'personal_token');
        foreach ($profileConfigs as $name => $profileConfig) {
            if (! is_string($name) || trim($name) === '' || ! is_array($profileConfig)) {
                $this->record('Profile configuration', 'FAIL', 'Each wise.profiles entry must have a non-empty name and array configuration.');

                continue;
            }

            $this->checkProfile($wise, $name, $profileConfig, is_string($authType) ? $authType : '');
        }

        $this->checkScaKeys($profileConfigs);
        $this->checkWebhookKeys($environment?->value);
        $this->checkWebhookRoute();
        $this->checkQueue();
        $this->checkCacheLocks(is_string($authType) ? $authType : '');

        $this->table(['Check', 'Status', 'Details'], $this->results);

        if ($this->hasFailures) {
            $this->error('One or more Wise checks failed. Fix the failures above and run wise:doctor again.');

            return self::FAILURE;
        }

        $this->info('Wise checks completed. Review any warnings above.');

        return self::SUCCESS;
    }

    /**
     * Check one configured profile and verify that Wise can reach it.
     *
     * @param  array<string, mixed>  $config
     */
    private function checkProfile(WiseManager $wise, string $name, array $config, string $authType): void
    {
        if (! in_array($authType, ['personal_token', 'oauth', 'client_credentials'], true)) {
            $this->record("{$name}: authentication", 'WARN', 'A custom authentication provider is configured; credential checks are provider-specific.');
        } elseif ($authType === 'personal_token' && (! is_string(config('wise.token')) || trim((string) config('wise.token')) === '')) {
            $this->record("{$name}: credentials", 'FAIL', 'Set WISE_API_TOKEN (wise.token) for personal-token authentication.');

            return;
        } elseif (in_array($authType, ['oauth', 'client_credentials'], true)
            && (! is_string(config('wise.oauth.client_id')) || trim((string) config('wise.oauth.client_id')) === ''
                || ! is_string(config('wise.oauth.client_secret')) || trim((string) config('wise.oauth.client_secret')) === '')) {
            $this->record("{$name}: credentials", 'FAIL', 'Configure wise.oauth.client_id and wise.oauth.client_secret.');

            return;
        } elseif (in_array($authType, ['oauth', 'client_credentials'], true)
            && (! (bool) config('wise.oauth.mtls.enabled')
                || ! is_string(config('wise.oauth.mtls.certificate')) || trim((string) config('wise.oauth.mtls.certificate')) === ''
                || ! is_string(config('wise.oauth.mtls.private_key')) || trim((string) config('wise.oauth.mtls.private_key')) === '')) {
            $this->record("{$name}: credentials", 'FAIL', 'Configure enabled OAuth mTLS with certificate and private key paths.');

            return;
        } else {
            $this->record("{$name}: credentials", 'PASS', "Authentication is configured ({$authType}).");
        }

        try {
            $profile = $wise->profile($name);
        } catch (Throwable $exception) {
            $this->record(
                "{$name}: profile configuration",
                'FAIL',
                'Could not construct the profile client ('.$exception::class.'); check authentication and profile configuration. Details are suppressed.',
            );

            return;
        }

        try {
            $profileId = $profile->profileId();
        } catch (MissingProfile) {
            $this->record("{$name}: profile ID", 'FAIL', 'Set a valid profile_id for this configured Wise profile.');

            return;
        }

        if ($profileId <= 0) {
            $this->record("{$name}: profile ID", 'FAIL', 'The configured profile_id must be greater than zero.');

            return;
        }

        $this->record("{$name}: profile ID", 'PASS', 'A positive Wise profile ID is configured.');

        try {
            $remoteProfile = $profile->profiles()->find($profileId);
            if ($remoteProfile->id !== $profileId) {
                $this->record("{$name}: Wise reachability", 'FAIL', 'Wise returned a different profile ID than configured.');

                return;
            }

            $this->record("{$name}: Wise reachability", 'PASS', 'Wise accepted the credentials and returned the configured profile.');
        } catch (Throwable $exception) {
            $this->record(
                "{$name}: Wise reachability",
                'FAIL',
                'The Wise profile request failed ('.$exception::class.'); check network access, credential permissions, and profile ID. Response details are suppressed.',
            );
        }
    }

    /**
     * Check configured SCA private keys without displaying key material.
     *
     * @param  array<array-key, mixed>  $profileConfigs
     */
    private function checkScaKeys(array $profileConfigs): void
    {
        $checked = false;
        foreach ($profileConfigs as $name => $profileConfig) {
            if (! is_array($profileConfig)) {
                continue;
            }

            $key = $profileConfig['sca_private_key'] ?? config('wise.sca.private_key');
            if ($key === null || $key === '') {
                continue;
            }

            $checked = true;
            $passphrase = $profileConfig['sca_passphrase'] ?? config('wise.sca.passphrase');
            if (! is_string($key) || ($passphrase !== null && ! is_string($passphrase))) {
                $this->record("{$name}: SCA key", 'FAIL', 'The configured SCA key and passphrase must be strings.');

                continue;
            }

            $pem = str_contains($key, '-----BEGIN')
                ? $key
                : (is_file($key) && is_readable($key) ? @file_get_contents($key) : false);

            if (! is_string($pem) || openssl_pkey_get_private($pem, is_string($passphrase) ? $passphrase : '') === false) {
                $this->record("{$name}: SCA key", 'FAIL', 'The configured SCA key is missing, unreadable, or cannot be loaded with its passphrase.');

                continue;
            }

            $this->record("{$name}: SCA key", 'PASS', 'The configured SCA private key is readable and loadable.');
        }

        if (! $checked) {
            $this->record('SCA keys', 'SKIP', 'No SCA private key is configured.');
        }
    }

    /**
     * Validate the webhook public keys used by the signature middleware.
     */
    private function checkWebhookKeys(?string $environment): void
    {
        $keys = config('wise.webhooks.public_keys');
        if ($keys === null) {
            $keys = $environment === null
                ? []
                : [@file_get_contents(dirname(__DIR__, 2)."/resources/keys/wise-{$environment}.pem")];
        }

        if (! is_array($keys) || $keys === []) {
            $this->record('Webhook public keys', 'FAIL', 'Configure valid PEM keys in wise.webhooks.public_keys or select a valid environment for the bundled key.');

            return;
        }

        foreach ($keys as $key) {
            $publicKey = is_string($key) ? openssl_pkey_get_public($key) : false;
            $details = $publicKey === false ? false : openssl_pkey_get_details($publicKey);
            if ($details === false || $details['type'] !== OPENSSL_KEYTYPE_RSA) {
                $this->record('Webhook public keys', 'FAIL', 'One or more configured webhook public keys are not valid PEM public keys.');

                return;
            }
        }

        $this->record('Webhook public keys', 'PASS', 'All webhook verification keys are valid PEM public keys.');
    }

    /**
     * Report whether the package webhook endpoint is registered.
     */
    private function checkWebhookRoute(): void
    {
        if (Route::has('wise.webhook')) {
            $this->record('Webhook route', 'PASS', 'The wise.webhook route is registered.');

            return;
        }

        $this->record('Webhook route', 'WARN', 'No wise.webhook route is registered; enable wise.routes.enabled if this app receives webhooks.');
    }

    /**
     * Check that production webhook processing is not configured for sync.
     */
    private function checkQueue(): void
    {
        $connection = config('wise.webhooks.connection') ?: config('queue.default');
        if (! is_string($connection) || $connection === '') {
            $this->record('Queue connection', 'WARN', 'No queue connection is configured.');

            return;
        }

        if (app()->environment('production') && $connection === 'sync') {
            $this->record('Queue connection', 'WARN', 'The sync queue is configured in production; use a persistent queue worker for webhook processing.');

            return;
        }

        $this->record('Queue connection', 'PASS', "Using the {$connection} queue connection.");
    }

    /**
     * Check lock support for authentication flows that coordinate token refresh.
     */
    private function checkCacheLocks(string $authType): void
    {
        if (! in_array($authType, ['oauth', 'client_credentials'], true)) {
            $this->record('OAuth cache locks', 'SKIP', 'The configured authentication does not use OAuth token locks.');

            return;
        }

        try {
            $store = Cache::store()->getStore();
            if ($store instanceof LockProvider) {
                $this->record('OAuth cache locks', 'PASS', 'The configured cache store supports atomic locks.');

                return;
            }
        } catch (Throwable) {
            // Report a consistent actionable diagnostic below.
        }

        $this->record('OAuth cache locks', 'FAIL', 'Choose a cache store that supports atomic locks for OAuth token refresh.');
    }

    /**
     * Add a diagnostic result and remember whether it is a failure.
     */
    private function record(string $check, string $status, string $details): void
    {
        $this->results[] = [$check, $status, $details];
        $this->hasFailures = $this->hasFailures || $status === 'FAIL';
    }
}
