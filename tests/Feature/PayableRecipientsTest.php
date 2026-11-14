<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Owise\Data\RequirementsForm;
use Owise\Events\RecipientCreated;
use Owise\Events\RecipientRemoved;
use Owise\Exceptions\InvalidRecipientDetails;
use Owise\Exceptions\RecipientOwnershipMismatch;
use Owise\Exceptions\WiseApiError;
use Owise\Models\WiseRecipient;
use Owise\Tests\Fixtures\Models\Freelancer;
use Workbench\App\Models\User;

beforeEach(function () {
    wiseProfile();

    if (! Schema::hasTable('freelancers')) {
        Schema::create('freelancers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamps();
        });
    }
});

function makePayableUser(string $name = 'Jane Doe', string $email = 'jane@example.com'): User
{
    return User::factory()->create(['name' => $name, 'email' => $email]);
}

function makeFreelancer(string $name = 'Alex Freelancer', string $email = 'alex@example.com'): Freelancer
{
    return Freelancer::query()->create(['name' => $name, 'email' => $email]);
}

function recipientRequirements(): RequirementsForm
{
    return RequirementsForm::fromArray(wiseFixture('account_requirements'));
}

function recipientResponse(array $overrides = []): array
{
    $recipient = wiseFixture('recipient_v1');
    $recipient['details'] = [
        'sortCode' => '040075',
        'accountNumber' => '37778842',
        'hashedByLooseHashAlgorithm' => 'ad245621b974efa3ef870895c3wer419a3f01af18a8a5790b47645dba6304194',
    ];

    return array_replace_recursive($recipient, $overrides);
}

it('adds form helpers and derives validation rules from Wise field metadata', function () {
    $form = recipientRequirements();

    expect($form->types())->toBe(['south_korean_paygate'])
        ->and($form->fields('south_korean_paygate'))->toHaveCount(6)
        ->and($form->needsRefresh('email'))->toBeFalse()
        ->and($form->toArray())->not->toHaveKey('raw');

    $validator = Validator::make([
        'accountHolderName' => str_repeat('A', 141),
        'details' => [
            'email' => 'not-an-email',
            'legalType' => 'BUSINESS',
            'dateOfBirth' => '1990-01-01',
            'bankCode' => '004',
            'accountNumber' => '1234567890',
        ],
    ], $form->rules('south_korean_paygate'));

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('accountHolderName'))->toBeTrue()
        ->and($validator->errors()->has('details.email'))->toBeTrue()
        ->and($validator->errors()->has('details.legalType'))->toBeTrue();
});

it('exposes refreshable requirement fields documented by Wise', function () {
    $form = RequirementsForm::fromArray(wiseFixture('account_requirements_refresh_example'));

    expect($form->needsRefresh('address.country'))->toBeTrue()
        ->and($form->fields('address_example')[0]->key)->toBe('address.country');
});

it('fetches and refreshes quote-based recipient requirements through the payable profile', function () {
    Http::fake([
        WISE_SANDBOX.'/2026Q4/quotes/*/account-requirements' => Http::response(wiseFixture('account_requirements_refresh_example')),
    ]);
    $payable = makePayableUser();

    $requirements = $payable->wiseRecipientRequirements('quote-123');
    $payload = [
        'profile' => 999,
        'currency' => 'GBP',
        'type' => 'iban',
        'accountHolderName' => 'Jane Doe',
        'ownedByCustomer' => false,
        'details' => ['address' => ['country' => 'US']],
    ];
    $payable->refreshWiseRecipientRequirements('quote-123', $payload);

    expect($requirements->needsRefresh('address.country'))->toBeTrue();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_contains($request->url(), '/2026Q4/quotes/quote-123/account-requirements'));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_contains($request->url(), '/2026Q4/quotes/quote-123/account-requirements')
        && $request->data() === [...$payload, 'profile' => 101]);
});

it('rejects invalid recipient details locally before making a Wise request', function () {
    Http::preventStrayRequests();
    $payable = makePayableUser();

    expect(fn () => $payable->addWiseRecipient(
        currency: 'KRW',
        type: 'south_korean_paygate',
        details: ['email' => 'bad-email'],
        requirements: recipientRequirements(),
    ))->toThrow(ValidationException::class);
});

it('requires the payable model to be persisted before managing remote recipients', function () {
    Http::preventStrayRequests();
    $payable = new User(['name' => 'Jane Doe', 'email' => 'jane@example.com']);

    expect(fn () => $payable->addWiseRecipient('EUR', 'iban', ['iban' => 'GB00']))
        ->toThrow(InvalidArgumentException::class, 'Persist the payable model');
});

it('uses payable hooks for business recipient defaults and custom name and email values', function () {
    $requirements = RequirementsForm::fromArray([[
        'type' => 'sort_code',
        'fields' => [
            ['group' => [[
                'key' => 'accountHolderName',
                'type' => 'text',
                'required' => true,
            ]]],
            ['group' => [[
                'key' => 'email',
                'type' => 'text',
                'required' => true,
            ]]],
            ['group' => [[
                'key' => 'legalType',
                'type' => 'select',
                'required' => true,
                'valuesAllowed' => [['key' => 'BUSINESS', 'name' => 'Business']],
            ]]],
        ],
    ]]);

    $payable = new class extends Freelancer
    {
        public function wiseRecipientName(): ?string
        {
            return 'Acme Payroll Ltd';
        }

        public function wiseRecipientEmail(): ?string
        {
            return 'finance@acme.example';
        }

        public function wiseRecipientLegalEntityType(): ?string
        {
            return 'BUSINESS';
        }
    };
    $payable->setAttribute('name', 'Acme');
    $payable->setAttribute('email', 'admin@acme.example');
    $payable->save();

    Http::fake([WISE_SANDBOX.'/2026Q4/accounts' => Http::response(recipientResponse(['profileId' => 101]))]);

    $payable->addWiseRecipient('GBP', 'sort_code', [], requirements: $requirements);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->data()['accountHolderName'] === 'Acme Payroll Ltd'
        && $request->data()['details'] === [
            'email' => 'finance@acme.example',
            'legalType' => 'BUSINESS',
        ]);
});

it('creates and stores a recipient without persisting bank details', function () {
    $response = recipientResponse([
        'profileId' => 101,
        'currency' => 'KRW',
        'type' => 'south_korean_paygate',
        'name' => ['fullName' => 'Jane Doe'],
        'details' => ['accountNumber' => '1234567890123', 'bankCode' => '004'],
        'longAccountSummary' => 'KRW account ending in 0123',
    ]);
    Http::fake([WISE_SANDBOX.'/2026Q4/accounts' => Http::response($response)]);
    $payable = makePayableUser();

    $recipient = $payable->addWiseRecipient(
        currency: 'KRW',
        type: 'south_korean_paygate',
        details: [
            'dateOfBirth' => '1990-01-01',
            'bankCode' => '004',
            'accountNumber' => '1234567890123',
        ],
        default: true,
        ownedByCustomer: true,
        requirements: recipientRequirements(),
    );

    expect($recipient)->toBeInstanceOf(WiseRecipient::class)
        ->and($recipient->payable->is($payable))->toBeTrue()
        ->and($recipient->profile_name)->toBe('default')
        ->and($recipient->profile_id)->toBe(101)
        ->and($recipient->profile()->profileId())->toBe(101)
        ->and($recipient->currency)->toBe('KRW')
        ->and($recipient->holder_name)->toBe('Jane Doe')
        ->and($recipient->summary)->toBe('KRW account ending in 0123')
        ->and($recipient->is_default)->toBeTrue()
        ->and($payable->defaultWiseRecipient('krw')?->is($recipient))->toBeTrue()
        ->and($payable->hasWiseRecipient('KRW'))->toBeTrue()
        ->and($recipient->getAttributes())->not->toHaveKey('details')
        ->and($recipient->meta)->toBe(['legal_entity_type' => 'PERSON']);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === WISE_SANDBOX.'/2026Q4/accounts'
        && $request->data() === [
            'profile' => 101,
            'currency' => 'KRW',
            'type' => 'south_korean_paygate',
            'accountHolderName' => 'Jane Doe',
            'ownedByCustomer' => true,
            'details' => [
                'dateOfBirth' => '1990-01-01',
                'bankCode' => '004',
                'accountNumber' => '1234567890123',
                'email' => 'jane@example.com',
                'legalType' => 'PRIVATE',
            ],
        ]);
});

it('redacts an unmasked provider summary before persistence', function () {
    $response = recipientResponse([
        'profileId' => 101,
        'longAccountSummary' => 'Account 37778842 at bank 040075',
        'accountSummary' => 'Account 37778842 at bank 040075',
    ]);
    $response['details'] = ['sortCode' => '', 'accountNumber' => ''];
    Http::fake([WISE_SANDBOX.'/2026Q4/accounts' => Http::response($response)]);
    $payable = makePayableUser();

    $recipient = $payable->addWiseRecipient('GBP', 'sort_code', ['sortCode' => '040075', 'accountNumber' => '37778842']);

    expect($recipient->summary)->toBe('GBP SORTCODE •••• 8842')
        ->and($recipient->summary)->not->toContain('37778842')
        ->and($recipient->summary)->not->toContain('040075');
});

it('never uses the potentially unmasked accountSummary fallback', function () {
    $response = recipientResponse([
        'profileId' => 101,
        'longAccountSummary' => null,
        'accountSummary' => 'Account ending 37778842',
    ]);
    Http::fake([WISE_SANDBOX.'/2026Q4/accounts' => Http::response($response)]);
    $payable = makePayableUser();

    $recipient = $payable->addWiseRecipient('GBP', 'sort_code', ['accountNumber' => '37778842']);

    expect($recipient->summary)->toBe('GBP SORTCODE •••• 8842')
        ->and($recipient->summary)->not->toContain('37778842');
});

it('does not use a name or email as the fallback account last four', function () {
    $response = recipientResponse([
        'profileId' => 101,
        'currency' => 'EUR',
        'type' => 'email',
        'longAccountSummary' => null,
    ]);
    $response['details'] = ['email' => 'recipient@example.com'];
    $response['displayFields'] = [];
    Http::fake([WISE_SANDBOX.'/2026Q4/accounts' => Http::response($response)]);
    $payable = makePayableUser();

    $recipient = $payable->addWiseRecipient('EUR', 'email', ['email' => 'recipient@example.com']);

    expect($recipient->summary)->toBe('EUR EMAIL recipient')
        ->and($recipient->summary)->not->toContain('••••');
});

it('keeps one default recipient per payable and currency', function () {
    Http::fakeSequence(WISE_SANDBOX.'/2026Q4/accounts')
        ->push(recipientResponse(['id' => 40000000, 'profileId' => 101]))
        ->push(recipientResponse(['id' => 40000001, 'profileId' => 101]));
    $payable = makePayableUser();

    $first = $payable->addWiseRecipient('GBP', 'sort_code', ['accountNumber' => '11112222'], default: true);
    $second = $payable->addWiseRecipient('GBP', 'sort_code', ['accountNumber' => '33334444'], default: true);

    expect($first->fresh()->is_default)->toBeFalse()
        ->and($second->fresh()->is_default)->toBeTrue()
        ->and($payable->defaultWiseRecipient('GBP')?->is($second))->toBeTrue();
});

it('maps Wise 422 paths to field errors', function () {
    Http::fake([WISE_SANDBOX.'/2026Q4/accounts' => Http::response([
        'errors' => [[
            'code' => 'error.recipient.invalid',
            'message' => 'Invalid IBAN',
            'path' => 'details.iban',
            'arguments' => [],
        ]],
    ], 422)]);
    $payable = makePayableUser();

    try {
        $payable->addWiseRecipient('EUR', 'iban', ['iban' => 'not-an-iban']);
        test()->fail('Expected InvalidRecipientDetails.');
    } catch (InvalidRecipientDetails $exception) {
        expect($exception->fieldErrors)->toBe(['details.iban' => ['Invalid IBAN']])
            ->and($exception->getPrevious())->toBeInstanceOf(WiseApiError::class);
    }
});

it('maps a bare Wise detail error path under details', function () {
    Http::fake([WISE_SANDBOX.'/2026Q4/accounts' => Http::response([
        'errors' => [[
            'code' => 'NOT_VALID',
            'message' => 'Invalid IBAN',
            'path' => 'IBAN',
            'arguments' => [],
        ]],
    ], 422)]);
    $payable = makePayableUser();

    try {
        $payable->addWiseRecipient('EUR', 'iban', ['IBAN' => 'invalid']);
        test()->fail('Expected InvalidRecipientDetails.');
    } catch (InvalidRecipientDetails $exception) {
        expect($exception->fieldErrors)->toBe(['details.IBAN' => ['Invalid IBAN']]);
    }
});

it('attaches recipients per payable, restores its own soft-deleted row, and rejects cross-payable ownership', function () {
    Http::fake([WISE_SANDBOX.'/2026Q4/accounts/40000000' => Http::response(recipientResponse(['profileId' => 101]))]);
    $payable = makePayableUser();
    $other = makeFreelancer();

    $recipient = $payable->attachWiseRecipient(40000000);
    $recipient->delete();
    $restored = $payable->attachWiseRecipient(40000000);

    expect($restored->exists)->toBeTrue()
        ->and($restored->trashed())->toBeFalse()
        ->and($restored->type)->toBe('sort_code')
        ->and(fn () => $other->attachWiseRecipient(40000000))->toThrow(RecipientOwnershipMismatch::class)
        ->and(fn () => $other->removeWiseRecipient($restored))->toThrow(RecipientOwnershipMismatch::class);
});

it('supports recipient ownership on two different model classes', function () {
    Http::fake([
        WISE_SANDBOX.'/2026Q4/accounts/40000000' => Http::response(recipientResponse(['profileId' => 101])),
        WISE_SANDBOX.'/2026Q4/accounts/40000001' => Http::response(recipientResponse(['id' => 40000001, 'profileId' => 101])),
    ]);

    $user = makePayableUser();
    $freelancer = makeFreelancer();
    $userRecipient = $user->attachWiseRecipient(40000000);
    $freelancerRecipient = $freelancer->attachWiseRecipient(40000001);

    expect($userRecipient->payable->is($user))->toBeTrue()
        ->and($freelancerRecipient->payable->is($freelancer))->toBeTrue()
        ->and($user->wiseRecipients()->count())->toBe(1)
        ->and($freelancer->wiseRecipients()->count())->toBe(1);
});

it('deactivates and soft-deletes only a recipient owned by the payable', function () {
    Http::fake([
        WISE_SANDBOX.'/2026Q4/accounts/40000000' => function (Request $request) {
            return $request->method() === 'DELETE'
                ? Http::response(recipientResponse(['profileId' => 101]))
                : Http::response(recipientResponse(['profileId' => 101]));
        },
    ]);
    $payable = makePayableUser();
    $recipient = $payable->attachWiseRecipient(40000000);

    $removed = $payable->removeWiseRecipient($recipient);

    expect($removed->active)->toBeFalse()
        ->and($removed->trashed())->toBeTrue()
        ->and($payable->wiseRecipients()->count())->toBe(0);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === WISE_SANDBOX.'/2026Q4/accounts/40000000');
});

it('syncs all recipient pages and marks missing local recipients inactive', function () {
    $listSequence = Http::sequence()
        ->push(['content' => [recipientResponse(['id' => 50000000, 'profileId' => 101])], 'seekPositionForNext' => 20, 'size' => 20])
        ->push(['content' => [recipientResponse(['id' => 60000000, 'profileId' => 101])], 'seekPositionForNext' => null, 'size' => 20]);
    Http::fake([
        WISE_SANDBOX.'/2026Q4/accounts/40000000' => Http::response(recipientResponse(['profileId' => 101])),
        WISE_SANDBOX.'/2026Q4/accounts?*' => $listSequence,
    ]);
    $payable = makePayableUser();
    $recipient = $payable->attachWiseRecipient(40000000);

    expect($payable->syncWiseRecipients())->toBe(1)
        ->and($recipient->fresh()->active)->toBeFalse()
        ->and($recipient->fresh()->is_default)->toBeFalse()
        ->and($payable->wiseRecipients()->pluck('wise_recipient_id')->all())->toBe([40000000]);

    Http::assertSentCount(3);
});

it('fires recipient lifecycle events only after persistence', function () {
    Event::fake([RecipientCreated::class, RecipientRemoved::class]);
    Http::fake([
        WISE_SANDBOX.'/2026Q4/accounts' => Http::response(recipientResponse(['profileId' => 101])),
        WISE_SANDBOX.'/2026Q4/accounts/40000000' => function (Request $request) {
            return Http::response(recipientResponse(['profileId' => 101]));
        },
    ]);
    $payable = makePayableUser();
    $recipient = $payable->addWiseRecipient('GBP', 'sort_code', ['sortCode' => '040075', 'accountNumber' => '37778842']);
    $payable->removeWiseRecipient($recipient);

    Event::assertDispatched(RecipientCreated::class, fn (RecipientCreated $event): bool => $event->recipient->exists);
    Event::assertDispatched(RecipientRemoved::class, fn (RecipientRemoved $event): bool => $event->recipient->trashed());
});
