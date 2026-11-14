<?php

namespace Owise\Account;

use Owise\Auth\TokenProviderFactory;
use Owise\Enums\FundingMode;
use Owise\Exceptions\InvalidConfiguration;
use SensitiveParameter;
use Theocode\Wise\Contracts\AccessTokenProviderInterface;

/**
 * Hold the resolved configuration for a single Wise profile.
 */
final readonly class AccountContext
{
    /**
     * Create a new account context instance.
     *
     * @param  string  $name  The profile name the context belongs to.
     */
    public function __construct(
        public string $name,
        public Environment $environment,
        public ?int $profileId,
        public AccessTokenProviderInterface $tokens,
        public FundingMode $funding,
        public string $authType = 'personal_token',
        #[SensitiveParameter] public ?string $scaPrivateKey = null,
        #[SensitiveParameter] public ?string $scaPassphrase = null,
    ) {}

    /**
     * Build an account context from the profile configuration.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $authConfig
     *
     * @throws InvalidConfiguration
     */
    public static function fromConfig(
        string $name,
        array $config,
        string $environmentValue,
        string $authType,
        array $authConfig,
        TokenProviderFactory $tokenProviders,
    ): self {
        if (($environment = Environment::tryFrom(strtolower($environmentValue))) === null) {
            throw new InvalidConfiguration(
                "The library-wide Wise environment is invalid; expected 'sandbox' or 'production'.",
            );
        }

        $fundingValue = $config['funding'] ?? 'manual';
        if (! is_string($fundingValue) || ($funding = FundingMode::tryFrom(strtolower($fundingValue))) === null) {
            throw new InvalidConfiguration("Wise profile '{$name}' has invalid funding mode; expected 'api' or 'manual'.");
        }

        if (trim($authType) === '') {
            throw new InvalidConfiguration('wise.auth must be a non-empty authentication type.');
        }

        $profileId = self::profileIdFromConfig($name, $config['profile_id'] ?? null);
        $profileProviderConfig = array_diff_key($config, array_flip([
            'environment',
            'auth',
            'token',
            'oauth',
            'client_id',
            'client_secret',
            'client_key',
        ]));
        $providerConfig = array_replace($authConfig, $profileProviderConfig);

        return new self(
            name: $name,
            environment: $environment,
            profileId: $profileId,
            tokens: $tokenProviders->make($authType, $providerConfig, $name),
            funding: $funding,
            authType: $authType,
            scaPrivateKey: self::optionalString($name, $config, 'sca_private_key'),
            scaPassphrase: self::optionalString($name, $config, 'sca_passphrase'),
        );
    }

    /**
     * Get an optional string value from the profile configuration.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws InvalidConfiguration
     */
    private static function optionalString(string $name, array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidConfiguration("Wise profile '{$name}' has an invalid {$key}; expected a string.");
        }

        return $value;
    }

    /**
     * Normalize a configured profile id to an integer.
     *
     *
     * @throws InvalidConfiguration
     */
    private static function profileIdFromConfig(string $name, mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && filter_var($value, FILTER_VALIDATE_INT) !== false) {
            return (int) $value;
        }

        throw new InvalidConfiguration("Wise profile '{$name}' has an invalid profile_id; expected an integer.");
    }
}
