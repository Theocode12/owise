<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Owise\Data\Recipient;

const QUOTE_ID = '11144c35-9fe8-4c32-b7fd-d05c2a7734bf';

it('fetches account requirements with the v1.1 minor version and flattens fields', function () {
    Http::fake([WISE_SANDBOX.'/2026Q4/quotes/*' => Http::response(wiseFixture('account_requirements'))]);

    $form = wiseProfile()->recipients()->requirements(QUOTE_ID, originatorLegalEntityType: 'BUSINESS', addressRequired: true);
    $type = $form->types[0];

    expect($type->type)->toBe('south_korean_paygate')
        ->and($type->field('email')?->required)->toBeTrue()
        ->and($type->field('email')?->group)->toBe('E-mail')
        ->and($type->field('legalType')?->valuesAllowed)->toBe([['key' => 'PRIVATE', 'name' => 'Person']])
        ->and($type->field('accountHolderName')?->maxLength)->toBe(140);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === WISE_SANDBOX.'/2026Q4/quotes/'.QUOTE_ID.'/account-requirements?originatorLegalEntityType=BUSINESS&addressRequired=true'
        && $request->hasHeader('Accept-Minor-Version', '1'));
});

it('refreshes requirements with the recipient payload and the account profile', function () {
    Http::fake([WISE_SANDBOX.'/2026Q4/quotes/*' => Http::response(wiseFixture('account_requirements'))]);

    wiseProfile()->recipients()->refreshRequirements(QUOTE_ID, ['currency' => 'GBP', 'type' => 'sort_code', 'details' => ['legalType' => 'PRIVATE']]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === WISE_SANDBOX.'/2026Q4/quotes/'.QUOTE_ID.'/account-requirements'
        && $request->hasHeader('Accept-Minor-Version', '1')
        && $request->data() === ['currency' => 'GBP', 'type' => 'sort_code', 'details' => ['legalType' => 'PRIVATE'], 'profile' => 101]);
});

it('creates, finds, lists and deactivates recipients', function () {
    Http::fake([
        WISE_SANDBOX.'/2026Q4/accounts' => Http::response(wiseFixture('recipient_v1')),
        WISE_SANDBOX.'/2026Q4/accounts/40000000' => Http::response(wiseFixture('recipient_v2')),
        WISE_SANDBOX.'/2026Q4/accounts?*' => Http::response(wiseFixture('recipient_v2_list')),
    ]);
    $recipients = wiseProfile()->recipients();

    $created = $recipients->create(['currency' => 'GBP', 'type' => 'sort_code', 'accountHolderName' => 'John Doe']);
    $found = $recipients->find(40000000);
    $page = $recipients->list(currency: 'gbp', size: 20);
    $recipients->delete(40000000);

    expect($created)->toBeInstanceOf(Recipient::class)
        ->and($created->accountHolderName)->toBe('John Doe')
        ->and($created->summary)->toBe('GBP account ending in 8842')
        ->and($found->profileId)->toBe(30000000)
        ->and($page->recipients)->toHaveCount(1)
        ->and($page->nextSeekPosition)->toBe(40000001)
        ->and($page->hasMore())->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === WISE_SANDBOX.'/2026Q4/accounts'
        && $request['profile'] === 101);
    Http::assertSent(fn (Request $request): bool => $request->url() === WISE_SANDBOX.'/2026Q4/accounts?profileId=101&currency=GBP&active=true&size=20');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === WISE_SANDBOX.'/2026Q4/accounts/40000000');
});
