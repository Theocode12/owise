<?php

use Owise\Api\ApiClientFactory;
use Owise\Exceptions\InvalidConfiguration;
use Owise\Support\ApiVersion;
use Owise\Support\Endpoints;

it('resolves every catalogued Wise operation to an explicit 2026Q4 route', function () {
    foreach (Endpoints::all() as $operation => $endpoint) {
        expect($endpoint['calver'])->toBeString()
            ->and($endpoint['method'])->toBeIn(['GET', 'POST', 'PUT', 'PATCH', 'DELETE']);

        preg_match_all('/\{([A-Za-z][A-Za-z0-9]*)\}/', $endpoint['calver'], $matches);
        $parameters = array_fill_keys($matches[1], 'segment value');
        $expectedRoute = preg_replace_callback(
            '/\{([A-Za-z][A-Za-z0-9]*)\}/',
            static fn (array $match): string => rawurlencode($parameters[$match[1]]),
            $endpoint['calver'],
        );

        $resolved = Endpoints::resolve($operation, ApiVersion::V2026Q4, $parameters);

        expect($resolved['method'])->toBe($endpoint['method'])
            ->and($resolved['path'])->toBe('/2026Q4'.$expectedRoute)
            ->not->toMatch('~/v[0-9]+(?:/|$)~');
    }
});

it('keeps same-path transfer and recipient-requirements operations method-specific', function () {
    $endpoints = Endpoints::all();

    expect($endpoints['transfers.create']['calver'])->toBe('/transfers')
        ->and($endpoints['transfers.create']['method'])->toBe('POST')
        ->and($endpoints['transfers.list']['calver'])->toBe('/transfers')
        ->and($endpoints['transfers.list']['method'])->toBe('GET')
        ->and($endpoints['recipients.requirements.get']['calver'])->toBe('/quotes/{quoteId}/account-requirements')
        ->and($endpoints['recipients.requirements.get']['method'])->toBe('GET')
        ->and($endpoints['recipients.requirements.refresh']['calver'])->toBe('/quotes/{quoteId}/account-requirements')
        ->and($endpoints['recipients.requirements.refresh']['method'])->toBe('POST');
});

it('has no catalogue entry for operations without a 2026Q4 route', function () {
    $endpoints = Endpoints::all();

    expect($endpoints)->not->toHaveKey('recipients.requirements.for');
});

it('resolves the JSON statement route through the statement path placeholder', function () {
    $resolved = Endpoints::resolve('balances.statement', ApiVersion::V2026Q4, [
        'profileId' => 1,
        'balanceId' => 2,
        'statementPath' => 'statement.json',
    ]);

    expect($resolved['path'])->toBe('/2026Q4/profiles/1/balance-statements/2/statement.json');
});

it('rejects Wise API versions that this package has not verified', function () {
    expect(fn () => ApiVersion::fromConfig('2026Q3'))
        ->toThrow(InvalidConfiguration::class, 'currently 2026Q4');

    config()->set('wise.api_version', 'Latest');
    app()->forgetInstance(ApiClientFactory::class);

    expect(fn () => app(ApiClientFactory::class))
        ->toThrow(InvalidConfiguration::class, 'currently 2026Q4');
});
