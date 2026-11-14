<?php

namespace Owise\Testing;

use Owise\Support\ApiVersion;
use Owise\Support\Endpoints as Catalogue;

/**
 * Public Wise operation keys used to target {@see WiseFake} overrides and assertions.
 *
 * These mirror the verified CalVer catalogue in {@see Catalogue}, minus operations that have no
 * public method in this release. Pass the constants to {@see WiseFake::respond()},
 * {@see WiseFake::fail()} and {@see WiseFake::assertSentCount()}.
 */
final class Endpoints
{
    public const LIST_PROFILES = 'profiles.list';

    public const FIND_PROFILE = 'profiles.find';

    public const CREATE_QUOTE = 'quotes.create';

    public const FIND_QUOTE = 'quotes.find';

    public const UPDATE_QUOTE = 'quotes.update';

    public const GET_RECIPIENT_REQUIREMENTS = 'recipients.requirements.get';

    public const REFRESH_RECIPIENT_REQUIREMENTS = 'recipients.requirements.refresh';

    public const CREATE_RECIPIENT = 'recipients.create';

    public const FIND_RECIPIENT = 'recipients.find';

    public const LIST_RECIPIENTS = 'recipients.list';

    public const DELETE_RECIPIENT = 'recipients.delete';

    public const GET_TRANSFER_REQUIREMENTS = 'transfers.requirements';

    public const CREATE_TRANSFER = 'transfers.create';

    public const FIND_TRANSFER = 'transfers.find';

    public const LIST_TRANSFERS = 'transfers.list';

    public const CANCEL_TRANSFER = 'transfers.cancel';

    public const FUND_TRANSFER = 'transfers.fund';

    public const LIST_TRANSFER_PAYMENTS = 'transfers.payments';

    public const LIST_BALANCES = 'balances.list';

    public const FIND_BALANCE = 'balances.find';

    public const CREATE_BALANCE = 'balances.create';

    public const CLOSE_BALANCE = 'balances.close';

    public const BALANCE_CAPACITY = 'balances.capacity';

    public const TOTAL_FUNDS = 'balances.total-funds';

    public const MOVE_BALANCE = 'balances.movement';

    public const GET_STATEMENT = 'balances.statement';

    public const SIMULATE_TRANSFER_STATE = 'simulation.transfer-state';

    public const SIMULATE_BALANCE_TOP_UP = 'simulation.balance-top-up';

    public const CREATE_PROFILE_WEBHOOK = 'webhooks.profile.create';

    public const LIST_PROFILE_WEBHOOKS = 'webhooks.profile.list';

    public const FIND_PROFILE_WEBHOOK = 'webhooks.profile.find';

    public const DELETE_PROFILE_WEBHOOK = 'webhooks.profile.delete';

    public const CREATE_APPLICATION_WEBHOOK = 'webhooks.application.create';

    public const LIST_APPLICATION_WEBHOOKS = 'webhooks.application.list';

    public const FIND_APPLICATION_WEBHOOK = 'webhooks.application.find';

    public const DELETE_APPLICATION_WEBHOOK = 'webhooks.application.delete';

    public const TEST_APPLICATION_WEBHOOK = 'webhooks.application.test';

    public const OAUTH_TOKEN = 'oauth.token';

    /**
     * Operations kept in the catalogue but not exposed by any public method yet.
     *
     * @var list<string>
     */
    private const DORMANT = ['oauth.registration'];

    /**
     * Return every public operation key, in catalogue order.
     *
     * @return list<string>
     */
    public static function public(): array
    {
        return array_values(array_filter(
            array_keys(Catalogue::all()),
            static fn (string $operation): bool => ! in_array($operation, self::DORMANT, true),
        ));
    }

    /**
     * Resolve an operation to its HTTP method and CalVer path for the configured API version.
     *
     * @param  array<string, int|string>  $parameters  Values for route placeholders.
     * @return array{method: string, path: string}
     */
    public static function resolve(string $operation, array $parameters = []): array
    {
        return Catalogue::resolve($operation, ApiVersion::fromConfig(config('wise.api_version', '2026Q4')), $parameters);
    }
}
