<?php

namespace Owise;

use Composer\InstalledVersions;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Log\LogManager;
use Illuminate\Support\ServiceProvider;
use Owise\Account\Environment;
use Owise\Api\ApiClientFactory;
use Owise\Auth\AuthorizationTokenProvider;
use Owise\Auth\ClientCredentialsTokenProvider;
use Owise\Auth\TokenProviderFactory;
use Owise\Console\RefreshAuthorizationCommand;
use Owise\Console\TransfersSyncCommand;
use Owise\Console\WebhooksCommand;
use Owise\Enums\WiseAuthorizationStatus;
use Owise\Exceptions\ConnectionRevoked;
use Owise\Exceptions\InvalidConfiguration;
use Owise\Models\WiseAuthorization;
use Owise\OAuth\OAuthManager;
use Owise\Support\ApiVersion;
use Owise\Support\CacheKey;
use Psr\Log\LoggerInterface;

class WiseServiceProvider extends ServiceProvider
{
    /**
     * Register the Wise package bindings.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/wise.php', 'wise');

        $this->app->singleton(TokenProviderFactory::class);
        $this->app->singleton(ApiClientFactory::class, function ($app): ApiClientFactory {
            $config = $app['config'];
            $channel = $config->get('wise.logging.channel');
            $logger = is_string($channel) && $channel !== ''
                ? $app->make(LogManager::class)->channel($channel)
                : null;

            return new ApiClientFactory(
                $app->make(HttpFactory::class),
                $config->get('wise.http', []),
                $logger instanceof LoggerInterface ? $logger : null,
                $config->get('wise.urls', []),
                $config->get('wise.sca', []),
                $config->get('wise.oauth.mtls', []),
                ApiVersion::fromConfig($config->get('wise.api_version', '2026Q4')),
            );
        });
        $this->app->singleton(WiseManager::class);
        $this->app->alias(WiseManager::class, 'wise');
        $this->app->singleton(OAuthManager::class);
        $this->app->afterResolving(TokenProviderFactory::class, function (TokenProviderFactory $factory): void {
            $lockSeconds = AuthorizationTokenProvider::lockSecondsFor(config('wise.http', []));

            $factory->extend('client_credentials', function (array $config) use ($lockSeconds): ClientCredentialsTokenProvider {
                $oauth = config('wise.oauth', []);
                $environment = Environment::tryFrom(strtolower((string) config('wise.environment', 'sandbox')));
                if ($environment === null) {
                    throw new InvalidConfiguration('Invalid OAuth environment.');
                }
                self::assertMtlsConfigured($oauth);
                foreach (['client_id', 'client_secret'] as $key) {
                    if (! is_string($oauth[$key] ?? null) || trim($oauth[$key]) === '') {
                        throw new InvalidConfiguration("wise.oauth.{$key} must be configured.");
                    }
                }

                return new ClientCredentialsTokenProvider(
                    app('cache'),
                    CacheKey::clientCredentialsToken($environment->value, (string) $oauth['client_id']),
                    fn () => app(ApiClientFactory::class)->oauth($environment)->clientCredentials((string) ($oauth['client_id'] ?? ''), (string) ($oauth['client_secret'] ?? '')),
                    (int) ($oauth['client_credentials_refresh_before_seconds'] ?? 300),
                    $lockSeconds,
                );
            });
            $factory->extend('oauth', function (array $config, string $profileName) use ($lockSeconds): AuthorizationTokenProvider {
                $authorization = WiseAuthorization::current();
                if ($authorization === null) {
                    throw new InvalidConfiguration("No Wise authorization is stored for profile '{$profileName}'. Explicitly call Wise::oauth()->useRegistrationCode() first.");
                }
                if ($authorization->status === WiseAuthorizationStatus::Revoked) {
                    throw new ConnectionRevoked('The stored Wise authorization was revoked; call Wise::oauth()->recover() explicitly.');
                }
                if (! $authorization->isActive()) {
                    throw new InvalidConfiguration('The stored Wise authorization is not active. Explicitly import the authorization with useRegistrationCode() before using a profile.');
                }

                $oauth = config('wise.oauth', []);
                $environment = Environment::tryFrom(strtolower((string) config('wise.environment', 'sandbox')));
                if ($environment === null) {
                    throw new InvalidConfiguration('wise.environment must be sandbox or production.');
                }
                self::assertMtlsConfigured($oauth);
                foreach (['client_id', 'client_secret'] as $key) {
                    if (! is_string($oauth[$key] ?? null) || trim($oauth[$key]) === '') {
                        throw new InvalidConfiguration("wise.oauth.{$key} must be configured.");
                    }
                }

                return new AuthorizationTokenProvider(
                    $authorization,
                    app('cache'),
                    (int) ($oauth['refresh_before_seconds'] ?? 21600),
                    function (WiseAuthorization $current) use ($oauth, $environment): array {
                        $token = app(ApiClientFactory::class)->oauth($environment)->refreshToken(
                            (string) $oauth['client_id'],
                            (string) $oauth['client_secret'],
                            (string) $current->refresh_token,
                        );

                        return [
                            'access_token' => $token->accessToken,
                            'refresh_token' => $token->refreshToken ?? $current->refresh_token,
                            'access_token_expires_at' => $token->expiresAt ?? now()->addHours(12),
                            'refresh_token_expires_at' => $token->refreshTokenExpiresAt ?? $current->refresh_token_expires_at,
                            'scope' => $token->scope,
                            'status' => WiseAuthorizationStatus::Active,
                            'revoked_at' => null,
                        ];
                    },
                    $lockSeconds,
                );
            });
        });
    }

    /**
     * Bootstrap the Wise package services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->registerPublishing();
        }

        if ((bool) config('wise.routes.enabled', false)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([WebhooksCommand::class, RefreshAuthorizationCommand::class, TransfersSyncCommand::class]);
        }

        AboutCommand::add('Owise', fn (): array => [
            'Version' => InstalledVersions::getPrettyVersion('theocode12/owise') ?? 'unknown',
            'Default profile' => config('wise.default_profile'),
        ]);
    }

    /**
     * Register the publishable package assets.
     */
    private function registerPublishing(): void
    {
        $this->publishes([
            __DIR__.'/../config/wise.php' => $this->app->configPath('wise.php'),
        ], 'wise-config');

    }

    /**
     * Assert that the OAuth mTLS configuration is present.
     *
     *
     * @throws InvalidConfiguration
     */
    private static function assertMtlsConfigured(mixed $oauth): void
    {
        if (! is_array($oauth)) {
            throw new InvalidConfiguration('wise.oauth must be an array.');
        }
        $mtls = $oauth['mtls'] ?? [];
        if (! is_array($mtls)
            || ! ($mtls['enabled'] ?? false)
            || ! is_string($mtls['certificate'] ?? null)
            || trim($mtls['certificate']) === ''
            || ! is_string($mtls['private_key'] ?? null)
            || trim($mtls['private_key']) === '') {
            throw new InvalidConfiguration('Wise OAuth requires wise.oauth.mtls.enabled and configured certificate and private key paths.');
        }
    }
}
