<?php

namespace Owise\Api;

use Composer\InstalledVersions;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Http\Client\Factory;
use Owise\Account\AccountContext;
use Owise\Account\Environment;
use Owise\Auth\PrivateKeyScaSigner;
use Owise\Http\LaravelHttpTransport;
use Owise\Http\ResponseRecorder;
use Owise\Http\ScaSigningTransport;
use Owise\Support\ApiVersion;
use Psr\Log\LoggerInterface;
use Theocode\Wise\Auth\AuthMode;
use Theocode\Wise\Config\ClientConfig;
use Theocode\Wise\Contracts\AccessTokenProviderInterface;
use Theocode\Wise\Contracts\TransportInterface;
use Theocode\Wise\Wise;
use Theocode\Wise\WiseClient;

/**
 * Build profile-scoped API clients for the current environment.
 */
final readonly class ApiClientFactory
{
    /**
     * Create a new API client factory instance.
     *
     * @param  Factory  $http  The Laravel HTTP factory.
     * @param  array<string, mixed>  $options  The client options.
     * @param  LoggerInterface|null  $logger  The logger, if any.
     * @param  array<string, mixed>  $urls  The URL overrides.
     * @param  array<string, mixed>  $sca  The SCA configuration.
     * @param  array<string, mixed>  $mtls  The mTLS configuration.
     */
    public function __construct(
        private Factory $http,
        private array $options = [],
        private ?LoggerInterface $logger = null,
        private array $urls = [],
        private array $sca = [],
        private array $mtls = [],
        private ApiVersion $apiVersion = ApiVersion::V2026Q4,
    ) {}

    /**
     * Make an authenticated client for the given account.
     *
     * @param  AccountContext  $context  The account to build the client for.
     * @return ApiClient The authenticated API client.
     */
    public function make(AccountContext $context): ApiClient
    {
        $signer = $this->scaSigner($context);

        return $this->build(
            $context->environment,
            $context->authType === 'personal_token' ? AuthMode::ApiToken : AuthMode::OAuth2,
            $context->tokens,
            $signer,
        );
    }

    /**
     * Make an unauthenticated client for the given environment.
     *
     * Used for unauthenticated calls such as `POST /oauth/token`.
     *
     * @param  Environment  $environment  The Wise environment.
     * @return ApiClient The unauthenticated API client.
     */
    public function makeUnauthenticated(Environment $environment): ApiClient
    {
        return $this->build($environment, AuthMode::OAuth2, null, null);
    }

    /**
     * Make an OAuth API instance for the given environment.
     *
     * @param  Environment  $environment  The Wise environment.
     * @return OAuthApi The OAuth API instance.
     */
    public function oauth(Environment $environment): OAuthApi
    {
        return new OAuthApi($this->makeUnauthenticated($environment));
    }

    /**
     * Build an API client for the given environment and credentials.
     *
     * @param  Environment  $environment  The Wise environment.
     * @param  AuthMode  $authMode  The authentication mode.
     * @param  AccessTokenProviderInterface|null  $tokens  The token provider, if any.
     * @param  PrivateKeyScaSigner|null  $signer  The SCA signer, if any.
     * @return ApiClient The built API client.
     */
    private function build(
        Environment $environment,
        AuthMode $authMode,
        ?AccessTokenProviderInterface $tokens,
        ?PrivateKeyScaSigner $signer,
    ): ApiClient {
        $retry = is_array($this->options['retry'] ?? null) ? $this->options['retry'] : [];
        $retryMethods = $retry['methods'] ?? ['GET'];
        if (! is_array($retryMethods)) {
            $retryMethods = ['GET'];
        }

        $clientConfig = new ClientConfig(
            authMode: $authMode,
            accessTokenProvider: $tokens,
            baseUrl: $environment->apiBaseUrl($this->urls, (bool) ($this->mtls['enabled'] ?? false)),
            timeoutSeconds: (float) ($this->options['timeout'] ?? 30),
            userAgent: 'owise/'.(InstalledVersions::getPrettyVersion('theocode12/owise') ?? 'unknown').' theocode12/wise-php-sdk',
            retryEnabled: (bool) ($retry['enabled'] ?? false),
            retryMaxAttempts: (int) ($retry['times'] ?? 3),
            retryMethods: array_values(array_map(
                'strtoupper',
                array_filter($retryMethods, 'is_string'),
            )),
            logger: $this->logger,
        );

        $recorder = new ResponseRecorder;
        $transport = new LaravelHttpTransport($this->http, $this->options, $this->logger, $recorder, $this->mtls);
        if ($signer !== null) {
            $transport = new ScaSigningTransport($transport, $signer);
        }

        return new ApiClient(
            $this->client($clientConfig, $transport),
            $environment,
            new ErrorMapper($recorder, $signer !== null),
            $transport,
            $tokens,
            $clientConfig->baseUrl,
            $this->apiVersion,
        );
    }

    /**
     * Create the underlying SDK client.
     *
     * @param  ClientConfig  $config  The SDK client configuration.
     * @param  TransportInterface  $transport  The HTTP transport.
     * @return WiseClient The SDK client.
     */
    private function client(ClientConfig $config, TransportInterface $transport): WiseClient
    {
        return Wise::client($config, $transport, new HttpFactory, new HttpFactory);
    }

    /**
     * Resolve the SCA signer for the given account.
     *
     * @param  AccountContext  $context  The account to resolve the signer for.
     * @return PrivateKeyScaSigner|null The signer, if configured.
     */
    private function scaSigner(AccountContext $context): ?PrivateKeyScaSigner
    {
        $key = $context->scaPrivateKey ?? $this->sca['private_key'] ?? null;
        if (! is_string($key) || $key === '') {
            return null;
        }

        $passphrase = $context->scaPrivateKey !== null ? $context->scaPassphrase : ($this->sca['passphrase'] ?? null);

        return new PrivateKeyScaSigner($key, is_string($passphrase) && $passphrase !== '' ? $passphrase : null);
    }
}
