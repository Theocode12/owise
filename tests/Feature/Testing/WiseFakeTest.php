<?php

use Brick\Money\Money as BrickMoney;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Owise\Enums\TransferStatus;
use Owise\Exceptions\PermissionDenied;
use Owise\Exceptions\StrongCustomerAuthenticationRequired;
use Owise\Exceptions\WiseApiError;
use Owise\Facades\Wise;
use Owise\Jobs\ProcessWiseWebhook;
use Owise\Models\WiseRecipient;
use Owise\Testing\Endpoints;
use Owise\Testing\FakeResponses;
use Owise\Testing\InteractsWithWiseWebhooks;
use Owise\Testing\WebhookFactory;
use Owise\Tests\Fixtures\Models\Freelancer;
use Owise\Webhooks\WebhookProcessor;

uses(InteractsWithWiseWebhooks::class);

beforeEach(function () {
    wiseProfile(['funding' => 'manual']);

    if (! Schema::hasTable('freelancers')) {
        Schema::create('freelancers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamps();
        });
    }
});

function makeTestingFreelancer(string $email = 'alex@example.com'): Freelancer
{
    return Freelancer::query()->create(['name' => 'Alex Freelancer', 'email' => $email]);
}

function makeTestingRecipient(Freelancer $payable): WiseRecipient
{
    return WiseRecipient::factory()->create([
        'payable_type' => $payable->getMorphClass(),
        'payable_id' => $payable->getKey(),
        'profile_name' => 'default',
        'profile_id' => 101,
        'wise_recipient_id' => 40000000,
        'currency' => 'EUR',
        'type' => 'iban',
        'holder_name' => 'Jane Doe',
        'summary' => 'EUR IBAN •••• 1234',
        'is_default' => true,
        'active' => true,
    ]);
}

it('provides a default fake response for every public endpoint', function () {
    Wise::fake();

    foreach (Endpoints::public() as $operation) {
        expect(FakeResponses::for($operation))
            ->not->toBeNull("Missing a default fake response for [{$operation}].");
    }
});

it('records requests and asserts endpoint counts', function () {
    $profile = wiseProfile();
    $fake = Wise::fake();

    $profile->profiles()->list();
    $profile->profiles()->list();

    $fake->assertSentCount(Endpoints::LIST_PROFILES, 2);
});

it('asserts nothing was sent when no requests were made', function () {
    Wise::fake()->assertNothingSent();
});

it('runs a payout and queues its signed completion webhook with only Wise::fake()', function () {
    Queue::fake();
    Http::preventStrayRequests();

    $fake = Wise::fake();
    $payable = makeTestingFreelancer();
    $recipient = makeTestingRecipient($payable);

    $transfer = $payable->payout(BrickMoney::of('85.50', 'EUR'))
        ->from('GBP')
        ->to($recipient)
        ->idempotencyKey('invoice-42')
        ->send();

    expect($transfer->status)->toBe(TransferStatus::AwaitingFunding)
        ->and($transfer->wise_transfer_id)->toBe(1000000);

    $fake->assertQuoteCreated();
    $fake->assertTransferCreated(fn (?array $body): bool => $body['targetAccount'] === 40000000);

    $payload = WebhookFactory::transferStateChanged(
        $transfer->wise_transfer_id,
        from: 'processing',
        to: 'outgoing_payment_sent',
    );

    $this->postWiseWebhook($payload)->assertOk();
    Queue::assertPushed(ProcessWiseWebhook::class);

    $job = Queue::pushed(ProcessWiseWebhook::class)->sole();
    $job->handle(app(WebhookProcessor::class));

    expect($transfer->fresh()->wise_status)->toBe('outgoing_payment_sent')
        ->and($transfer->fresh()->isCompleted())->toBeTrue();
});

it('increments transfer ids and dedups by customerTransactionId', function () {
    $profile = wiseProfile();
    Wise::fake();

    $firstId = (string) Str::uuid();
    $secondId = (string) Str::uuid();

    $first = $profile->transfers()->create(40000000, 'quote-1', $firstId);
    $second = $profile->transfers()->create(40000000, 'quote-2', $secondId);
    $duplicate = $profile->transfers()->create(40000000, 'quote-3', $firstId);

    expect($first->id)->toBe(1000000)
        ->and($second->id)->toBe(1000001)
        ->and($duplicate->id)->toBe(1000000);
});

it('returns a correctly shaped transfer list and preserves the requested find id with transferStatus', function () {
    $profile = wiseProfile();
    $fake = Wise::fake()->transferStatus('processing');

    $found = $profile->transfers()->find(12345);
    $listed = $profile->transfers()->list();

    expect($found->id)->toBe(12345)
        ->and($found->status)->toBe('processing')
        ->and($listed)->toHaveCount(1)
        ->and($listed[0]->id)->toBe(16521632)
        ->and($listed[0]->status)->toBe('processing');

    $fake->assertSentCount(Endpoints::FIND_TRANSFER, 1)
        ->assertSentCount(Endpoints::LIST_TRANSFERS, 1);
});

it('supports insufficient-funds, SCA and permission-denied shortcuts', function () {
    $profile = wiseProfile();
    $fake = Wise::fake()->insufficientFunds();

    $result = $profile->transfers()->fund(123);
    expect($result->isRejected())->toBeTrue();

    $fake->requiresSca();
    expect(fn () => $profile->transfers()->fund(123))
        ->toThrow(StrongCustomerAuthenticationRequired::class);

    $fake->permissionDenied();
    expect(fn () => $profile->transfers()->fund(123))
        ->toThrow(PermissionDenied::class);
});

it('applies respond and fail overrides for a single endpoint', function () {
    $profile = wiseProfile();
    $fake = Wise::fake();

    $fake->respond(Endpoints::FIND_PROFILE, ['type' => 'BUSINESS', 'id' => 7, 'fullName' => 'Acme']);

    expect($profile->profiles()->find(7)->fullName)->toBe('Acme');

    $fake->fail(Endpoints::FIND_PROFILE, 422, [['code' => 'validation', 'message' => 'Bad profile']]);

    expect(fn () => $profile->profiles()->find(7))
        ->toThrow(WiseApiError::class);
});

it('does not stub non-Wise hosts', function () {
    Http::preventStrayRequests();
    Wise::fake();

    expect(fn () => Http::get('https://example.com/anything'))
        ->toThrow(StrayRequestException::class);
});

it('fails fast for an unrecognized path under the configured Wise API version', function () {
    Wise::fake();

    expect(fn () => Http::get(WISE_SANDBOX.'/2026Q4/unknown-operation'))
        ->toThrow(LogicException::class, 'unrecognized Wise API request');
});

it('fails closed for an unrecognized API version under the configured Wise host', function () {
    Wise::fake();

    expect(fn () => Http::get(WISE_SANDBOX.'/v1/profiles'))
        ->toThrow(LogicException::class, 'unrecognized Wise API request');
});

it('does not intercept a different port or path on a configured Wise hostname', function () {
    config()->set('wise.urls.sandbox.api_base_url', 'http://127.0.0.1:8080/wise-api');
    config()->set('wise.urls.sandbox.mtls_api_base_url', 'http://127.0.0.1:8080/wise-api-mtls');
    Http::preventStrayRequests();
    Wise::fake();

    expect(fn () => Http::get('http://127.0.0.1:9000/wise-api/2026Q4/profiles'))
        ->toThrow(StrayRequestException::class);

    expect(fn () => Http::get('http://127.0.0.1:8080/other-api/2026Q4/profiles'))
        ->toThrow(StrayRequestException::class);
});
