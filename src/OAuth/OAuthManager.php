<?php

namespace Owise\OAuth;

use Illuminate\Contracts\Config\Repository;
use Owise\Account\AccountContext;
use Owise\Account\Environment;
use Owise\Api\ApiClientFactory;
use Owise\Auth\TokenProviderFactory;
use Owise\Data\OAuthToken;
use Owise\Enums\FundingMode;
use Owise\Enums\WiseAuthorizationStatus;
use Owise\Exceptions\InvalidConfiguration;
use Owise\Exceptions\InvalidRegistrationCode;
use Owise\Exceptions\WiseApiError;
use Owise\Exceptions\WiseException;
use Owise\Models\WiseAuthorization;
use SensitiveParameter;

/** Explicit Wise user token management via the registration-code grant. */
final class OAuthManager
{
    /**
     * Create a new OAuth manager instance.
     */
    public function __construct(
        private readonly Repository $config,
        private readonly ApiClientFactory $clients,
        private readonly TokenProviderFactory $tokenProviders,
    ) {}

    /**
     * Explicitly exchange the stored registration code for a new token pair after revocation.
     *
     *
     * @throws InvalidConfiguration
     * @throws InvalidRegistrationCode
     * @throws WiseApiError
     */
    public function recover(): WiseAuthorization
    {
        $settings = $this->settings();
        $authorization = WiseAuthorization::current();
        if ($authorization === null || $authorization->status !== WiseAuthorizationStatus::Revoked) {
            throw new InvalidConfiguration('There is no revoked Wise authorization to recover.');
        }

        try {
            return $this->exchangeAndStore($authorization, $settings);
        } catch (WiseApiError $e) {
            if ($e->hasErrorCode('invalid_grant')) {
                throw new InvalidRegistrationCode('Wise rejected the stored registration code during recovery.', previous: $e);
            }

            throw $e;
        }
    }

    /**
     * Import an API-created Wise user when the caller has its original
     * registration code. This does not create a Wise user.
     *
     *
     * @throws InvalidConfiguration
     * @throws InvalidRegistrationCode
     * @throws WiseApiError
     */
    public function useRegistrationCode(#[SensitiveParameter] string $email, #[SensitiveParameter] string $registrationCode): WiseAuthorization
    {
        $settings = $this->settings();
        $this->validateEmailAndLanguage($email, 'EN');
        if (strlen($registrationCode) < 32) {
            throw new InvalidConfiguration('The Wise registration code must be at least 32 characters.');
        }
        if (WiseAuthorization::current() !== null) {
            throw new InvalidConfiguration('A Wise authorization already exists; it must be explicitly replaced before importing another.');
        }

        $authorization = new WiseAuthorization([
            'id' => WiseAuthorization::ID,
            'email' => $email,
            'registration_code' => $registrationCode,
            'language' => 'EN',
            'status' => WiseAuthorizationStatus::UserCreated,
        ]);
        $authorization->save();

        try {
            return $this->exchangeAndStore($authorization, $settings);
        } catch (WiseApiError $e) {
            if ($e->hasErrorCode('invalid_grant')) {
                $authorization->delete();
                throw new InvalidRegistrationCode('Wise rejected the email and registration code.', previous: $e);
            }

            throw $e;
        }
    }

    /**
     * Create a Wise user with a registration code.
     *
     * Kept dormant for future user provisioning: Wise documents `POST /user/signup/registration_code`
     * but does not yet serve it on the `2026Q4` API (it currently returns 404). Re-enable by
     * restoring the public `registerUser()` / `resumeRegistration()` entry points.
     *
     * @param  array{environment: Environment, client_id: string, client_secret: string}  $settings
     * @return array<array-key, mixed>
     */
    protected function createWiseUser(#[SensitiveParameter] string $email, #[SensitiveParameter] string $registrationCode, string $language, array $settings): array
    {
        $tokens = $this->tokenProviders->make('client_credentials', [], 'Wise user registration');
        $context = new AccountContext(
            name: 'wise-user-registration',
            environment: $settings['environment'],
            profileId: null,
            tokens: $tokens,
            funding: FundingMode::Manual,
            authType: 'client_credentials',
        );

        $client = $this->clients->make($context);

        return $client->requestOperation(
            'oauth.registration',
            body: [
                'email' => $email,
                'registrationCode' => $registrationCode,
                'language' => strtoupper($language),
            ],
        );
    }

    /**
     * Exchange the stored registration code and persist the token pair.
     *
     * @param  array{environment: Environment, client_id: string, client_secret: string}  $settings
     *
     * @throws WiseApiError
     * @throws WiseException
     */
    private function exchangeAndStore(WiseAuthorization $authorization, array $settings): WiseAuthorization
    {
        $token = $this->clients->oauth($settings['environment'])->registrationCode(
            $settings['client_id'],
            $settings['client_secret'],
            $authorization->email,
            $authorization->registration_code,
        );
        if ($token->refreshToken === null || $token->refreshToken === '') {
            throw new WiseException('The Wise registration response did not include a refresh_token.');
        }

        $authorization->forceFill($this->tokenFields($token) + ['status' => WiseAuthorizationStatus::Active, 'revoked_at' => null])->save();

        return $authorization->refresh();
    }

    /**
     * Map an OAuth token to authorization attributes.
     *
     * @return array{access_token: string, refresh_token: string|null, access_token_expires_at: \DateTimeInterface|null, refresh_token_expires_at: \DateTimeInterface|null, scope: string|null}
     */
    private function tokenFields(OAuthToken $token): array
    {
        return [
            'access_token' => $token->accessToken,
            'refresh_token' => $token->refreshToken,
            'access_token_expires_at' => $token->expiresAt,
            'refresh_token_expires_at' => $token->refreshTokenExpiresAt,
            'scope' => $token->scope,
        ];
    }

    /**
     * Resolve the OAuth settings from configuration.
     *
     * @return array{environment: Environment, client_id: string, client_secret: string}
     *
     * @throws InvalidConfiguration
     */
    private function settings(): array
    {
        $oauth = $this->config->get('wise.oauth', []);
        if (! is_array($oauth)) {
            throw new InvalidConfiguration('wise.oauth must be an array.');
        }
        foreach (['client_id', 'client_secret'] as $key) {
            if (! is_string($oauth[$key] ?? null) || trim($oauth[$key]) === '') {
                throw new InvalidConfiguration("wise.oauth.{$key} must be configured.");
            }
        }

        $environmentValue = $this->config->get('wise.environment', 'sandbox');
        $environment = is_string($environmentValue) ? Environment::tryFrom(strtolower($environmentValue)) : null;
        if ($environment === null) {
            throw new InvalidConfiguration('wise.environment must be sandbox or production.');
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

        return [
            'environment' => $environment,
            'client_id' => trim($oauth['client_id']),
            'client_secret' => $oauth['client_secret'],
        ];
    }

    /**
     * Validate the user email and language.
     *
     *
     * @throws InvalidConfiguration
     */
    private function validateEmailAndLanguage(#[SensitiveParameter] string $email, string $language): void
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidConfiguration('A valid Wise user email is required.');
        }

        $languages = ['EN', 'US', 'PT', 'ES', 'FR', 'DE', 'IT', 'JA', 'RU', 'PL', 'HU', 'TR', 'RO', 'NL', 'HK'];
        if (! in_array(strtoupper($language), $languages, true)) {
            throw new InvalidConfiguration('Wise user language must be one of: '.implode(', ', $languages).'.');
        }
    }
}
