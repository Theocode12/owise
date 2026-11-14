<?php

namespace Owise\Account;

use Owise\Exceptions\InvalidConfiguration;

enum Environment: string
{
    case Sandbox = 'sandbox';
    case Production = 'production';

    /**
     * Get the API base URL for the environment.
     *
     * @param  array<string, mixed>  $urls
     *
     * @throws InvalidConfiguration
     */
    public function apiBaseUrl(array $urls, bool $mtls = false): string
    {
        return $this->configuredUrl($urls, $mtls ? 'mtls_api_base_url' : 'api_base_url');
    }

    /**
     * Get the web base URL for the environment.
     *
     * @param  array<string, mixed>  $urls
     *
     * @throws InvalidConfiguration
     */
    public function webBaseUrl(array $urls): string
    {
        return $this->configuredUrl($urls, 'web_base_url');
    }

    /**
     * Resolve and validate a configured URL for the environment.
     *
     * @param  array<string, mixed>  $urls
     *
     * @throws InvalidConfiguration
     */
    private function configuredUrl(array $urls, string $key): string
    {
        $setting = "wise.urls.{$this->value}.{$key}";
        $environmentUrls = $urls[$this->value] ?? null;
        if (! is_array($environmentUrls) || ! array_key_exists($key, $environmentUrls)) {
            throw new InvalidConfiguration("Missing Wise URL configuration at '{$setting}'.");
        }

        $url = $environmentUrls[$key];
        $scheme = is_string($url) ? parse_url($url, PHP_URL_SCHEME) : null;
        if (! is_string($url)
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || ! in_array(strtolower((string) $scheme), ['http', 'https'], true)) {
            throw new InvalidConfiguration(
                "Invalid Wise URL configured at '{$setting}'; expected an absolute HTTP or HTTPS URL.",
            );
        }

        return rtrim($url, '/');
    }
}
