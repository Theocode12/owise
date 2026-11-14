<?php

namespace Owise\Api;

use InvalidArgumentException;
use Owise\Data\OAuthToken;
use Owise\Exceptions\WiseException;
use SensitiveParameter;

/**
 * Exchange credentials for Wise OAuth tokens.
 */
final readonly class OAuthApi
{
    /**
     * Create a new OAuth API instance.
     *
     * @param  ApiClient  $client  The unauthenticated HTTP gateway.
     */
    public function __construct(private ApiClient $client) {}

    /**
     * Get a token using the client credentials grant.
     *
     * Client credentials are sent with HTTP Basic auth, never in the form body.
     *
     * @param  string  $clientId  The OAuth client ID.
     * @param  string  $clientSecret  The OAuth client secret.
     * @return OAuthToken The OAuth token.
     *
     * @throws InvalidArgumentException
     * @throws WiseException
     */
    public function clientCredentials(string $clientId, #[SensitiveParameter] string $clientSecret): OAuthToken
    {
        return $this->token($clientId, $clientSecret, ['grant_type' => 'client_credentials']);
    }

    /**
     * Get a token using a refresh token.
     *
     * @param  string  $clientId  The OAuth client ID.
     * @param  string  $clientSecret  The OAuth client secret.
     * @param  string  $refreshToken  The refresh token.
     * @return OAuthToken The OAuth token.
     *
     * @throws InvalidArgumentException
     * @throws WiseException
     */
    public function refreshToken(
        string $clientId,
        #[SensitiveParameter] string $clientSecret,
        #[SensitiveParameter] string $refreshToken,
    ): OAuthToken {
        return $this->token($clientId, $clientSecret, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    /**
     * Get a token using a registration code.
     *
     * @param  string  $clientId  The OAuth client ID.
     * @param  string  $clientSecret  The OAuth client secret.
     * @param  string  $email  The Wise account email.
     * @param  string  $registrationCode  The registration code.
     * @return OAuthToken The OAuth token.
     *
     * @throws InvalidArgumentException
     * @throws WiseException
     */
    public function registrationCode(
        string $clientId,
        #[SensitiveParameter] string $clientSecret,
        string $email,
        #[SensitiveParameter] string $registrationCode,
    ): OAuthToken {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('A valid email is required for the registration_code grant.');
        }
        if (strlen($registrationCode) < 32) {
            throw new InvalidArgumentException('The Wise registration code must be at least 32 characters.');
        }

        return $this->token($clientId, $clientSecret, [
            'grant_type' => 'registration_code',
            'client_id' => $clientId,
            'email' => $email,
            'registration_code' => $registrationCode,
        ]);
    }

    /**
     * Request an OAuth token from Wise.
     *
     * @param  string  $clientId  The OAuth client ID.
     * @param  string  $clientSecret  The OAuth client secret.
     * @param  array<string, string>  $form  The grant form parameters.
     * @return OAuthToken The OAuth token.
     *
     * @throws InvalidArgumentException
     * @throws WiseException
     */
    private function token(string $clientId, #[SensitiveParameter] string $clientSecret, array $form): OAuthToken
    {
        foreach ([...$form, 'client_id' => $clientId, 'client_secret' => $clientSecret] as $key => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("The OAuth {$key} cannot be empty.");
            }
        }

        $payload = $this->client->formOperation('oauth.token', form: $form, headers: [
            'Authorization' => 'Basic '.base64_encode("{$clientId}:{$clientSecret}"),
        ]);

        $token = OAuthToken::fromArray($payload);
        if ($token->accessToken === '') {
            throw new WiseException('The Wise token response did not include an access_token.');
        }

        return $token;
    }
}
