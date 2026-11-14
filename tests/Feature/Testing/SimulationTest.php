<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Owise\Exceptions\SimulationNotAllowed;
use Owise\Facades\Wise;
use Owise\Models\WiseTransfer;
use Owise\WiseManager;

beforeEach(function () {
    wiseProfile();
});

it('drives a transfer through sandbox states fluently', function () {
    Http::fake([
        WISE_SANDBOX.'/2026Q4/simulation/transfers/123/processing' => Http::response(wiseFixture('simulation_transfer')),
        WISE_SANDBOX.'/2026Q4/simulation/transfers/123/funds_converted' => Http::response(wiseFixture('simulation_transfer')),
        WISE_SANDBOX.'/2026Q4/simulation/transfers/123/outgoing_payment_sent' => Http::response(wiseFixture('simulation_transfer')),
    ]);

    Wise::simulate()->transfer(123)->processing()->fundsConverted()->outgoingPaymentSent();

    Http::assertSentCount(3);
});

it('resolves the transfer id from a WiseTransfer model', function () {
    $transfer = WiseTransfer::factory()->completed()->create(['wise_transfer_id' => 555]);

    Http::fake(['*' => Http::response(wiseFixture('simulation_transfer'))]);

    Wise::simulate()->transfer($transfer)->processing();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/simulation/transfers/555/processing'));
});

it('rejects a local transfer without a Wise id before making an HTTP request', function () {
    $transfer = WiseTransfer::factory()->pending()->create();
    $fake = Wise::fake();

    expect(fn () => Wise::simulate()->transfer($transfer)->processing())
        ->toThrow(InvalidArgumentException::class, 'positive wise_transfer_id');

    $fake->assertNothingSent();
});

it('rejects non-positive transfer IDs before making an HTTP request', function () {
    $fake = Wise::fake();

    expect(fn () => Wise::simulate()->transfer(0)->processing())
        ->toThrow(InvalidArgumentException::class, 'positive Wise transfer ID');

    $fake->assertNothingSent();
});

it('refuses simulation outside the sandbox', function () {
    wiseProfile();
    config()->set('wise.environment', 'production');
    app()->forgetInstance(WiseManager::class);
    Http::preventStrayRequests();

    expect(fn () => Wise::simulate()->transfer(123)->processing())
        ->toThrow(SimulationNotAllowed::class);
});
