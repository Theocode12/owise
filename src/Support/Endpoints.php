<?php

namespace Owise\Support;

use InvalidArgumentException;

/**
 * Explicit method-aware route catalogue for Owise's Wise API operations.
 *
 * Requests resolve only to verified CalVer routes; no legacy paths are kept
 * or inferred. Dynamic path segments use named placeholders.
 */
final class Endpoints
{
    /** @var array<string, array{method: string, calver: string}> */
    private const MAP = [
        'profiles.list' => ['method' => 'GET', 'calver' => '/profiles'],
        'profiles.find' => ['method' => 'GET', 'calver' => '/profiles/{profileId}'],
        'quotes.create' => ['method' => 'POST', 'calver' => '/profiles/{profileId}/quotes'],
        'quotes.find' => ['method' => 'GET', 'calver' => '/profiles/{profileId}/quotes/{quoteId}'],
        'quotes.update' => ['method' => 'PATCH', 'calver' => '/profiles/{profileId}/quotes/{quoteId}'],
        'recipients.requirements.get' => ['method' => 'GET', 'calver' => '/quotes/{quoteId}/account-requirements'],
        'recipients.requirements.refresh' => ['method' => 'POST', 'calver' => '/quotes/{quoteId}/account-requirements'],
        'recipients.create' => ['method' => 'POST', 'calver' => '/accounts'],
        'recipients.find' => ['method' => 'GET', 'calver' => '/accounts/{recipientId}'],
        'recipients.list' => ['method' => 'GET', 'calver' => '/accounts'],
        'recipients.delete' => ['method' => 'DELETE', 'calver' => '/accounts/{recipientId}'],
        'transfers.requirements' => ['method' => 'POST', 'calver' => '/transfer-requirements'],
        'transfers.create' => ['method' => 'POST', 'calver' => '/transfers'],
        'transfers.find' => ['method' => 'GET', 'calver' => '/transfers/{transferId}'],
        'transfers.list' => ['method' => 'GET', 'calver' => '/transfers'],
        'transfers.cancel' => ['method' => 'PUT', 'calver' => '/transfers/{transferId}/cancel'],
        'transfers.fund' => ['method' => 'POST', 'calver' => '/profiles/{profileId}/transfers/{transferId}/payments'],
        'transfers.payments' => ['method' => 'GET', 'calver' => '/transfers/{transferId}/payments'],
        'balances.list' => ['method' => 'GET', 'calver' => '/profiles/{profileId}/balances'],
        'balances.find' => ['method' => 'GET', 'calver' => '/profiles/{profileId}/balances/{balanceId}'],
        'balances.create' => ['method' => 'POST', 'calver' => '/profiles/{profileId}/balances'],
        'balances.close' => ['method' => 'DELETE', 'calver' => '/profiles/{profileId}/balances/{balanceId}'],
        'balances.capacity' => ['method' => 'GET', 'calver' => '/profiles/{profileId}/balance-capacity'],
        'balances.total-funds' => ['method' => 'GET', 'calver' => '/profiles/{profileId}/total-funds/{currency}'],
        'balances.movement' => ['method' => 'POST', 'calver' => '/profiles/{profileId}/balance-movements'],
        'balances.statement' => ['method' => 'GET', 'calver' => '/profiles/{profileId}/balance-statements/{balanceId}/{statementPath}'],
        'simulation.transfer-state' => ['method' => 'GET', 'calver' => '/simulation/transfers/{transferId}/{status}'],
        'simulation.balance-top-up' => ['method' => 'POST', 'calver' => '/simulation/balance/topup'],
        'webhooks.profile.create' => ['method' => 'POST', 'calver' => '/profiles/{profileId}/subscriptions'],
        'webhooks.profile.list' => ['method' => 'GET', 'calver' => '/profiles/{profileId}/subscriptions'],
        'webhooks.profile.find' => ['method' => 'GET', 'calver' => '/profiles/{profileId}/subscriptions/{subscriptionId}'],
        'webhooks.profile.delete' => ['method' => 'DELETE', 'calver' => '/profiles/{profileId}/subscriptions/{subscriptionId}'],
        'webhooks.application.create' => ['method' => 'POST', 'calver' => '/applications/{clientKey}/subscriptions'],
        'webhooks.application.list' => ['method' => 'GET', 'calver' => '/applications/{clientKey}/subscriptions'],
        'webhooks.application.find' => ['method' => 'GET', 'calver' => '/applications/{clientKey}/subscriptions/{subscriptionId}'],
        'webhooks.application.delete' => ['method' => 'DELETE', 'calver' => '/applications/{clientKey}/subscriptions/{subscriptionId}'],
        'webhooks.application.test' => ['method' => 'POST', 'calver' => '/applications/{clientKey}/subscriptions/{subscriptionId}/test-notifications'],
        'oauth.token' => ['method' => 'POST', 'calver' => '/oauth/token'],
        'oauth.registration' => ['method' => 'POST', 'calver' => '/user/signup/registration_code'],
    ];

    /**
     * Return the complete catalogue for contract tests and documentation.
     *
     * @return array<string, array{method: string, calver: string}>
     */
    public static function all(): array
    {
        return self::MAP;
    }

    /**
     * Resolve an operation's HTTP method and full URL path for the selected API version.
     *
     * @param  array<string, int|string>  $parameters  Values for route placeholders.
     * @return array{method: string, path: string}
     *
     * @throws InvalidArgumentException
     */
    public static function resolve(string $operation, ApiVersion $version, array $parameters = []): array
    {
        $entry = self::MAP[$operation] ?? throw new InvalidArgumentException("Unknown Wise API operation '{$operation}'.");
        $template = $entry['calver'];
        $path = preg_replace_callback('/\{([A-Za-z][A-Za-z0-9]*)\}/', static function (array $matches) use ($parameters, $operation): string {
            $name = $matches[1];
            if (! array_key_exists($name, $parameters)) {
                throw new InvalidArgumentException("Missing '{$name}' parameter for Wise API operation '{$operation}'.");
            }

            return rawurlencode((string) $parameters[$name]);
        }, $template);

        return [
            'method' => $entry['method'],
            'path' => '/'.$version->value.$path,
        ];
    }
}
