<?php

use Owise\Enums\FundingStatus;
use Owise\Enums\TransferStatus;
use Owise\Models\WiseAuthorization;
use Owise\Models\WiseRecipient;
use Owise\Models\WiseTransfer;

beforeEach(function () {
    wiseProfile();
});

it('creates recipients with a default state', function () {
    $recipient = WiseRecipient::factory()->asDefault()->create();

    expect($recipient->active)->toBeTrue()
        ->and($recipient->is_default)->toBeTrue()
        ->and($recipient->currency)->toBe('EUR');
});

it('creates transfers in each workflow state', function () {
    $pending = WiseTransfer::factory()->pending()->create();
    $quoted = WiseTransfer::factory()->quoted()->create();
    $created = WiseTransfer::factory()->created()->create();
    $failedToCreate = WiseTransfer::factory()->failedToCreate()->create();
    $awaiting = WiseTransfer::factory()->awaitingFunding()->create();
    $completed = WiseTransfer::factory()->completed()->create();
    $failed = WiseTransfer::factory()->failed()->create();
    $rejected = WiseTransfer::factory()->fundingRejected()->create();
    $unknown = WiseTransfer::factory()->fundingUnknown()->create();

    expect($pending->status)->toBe(TransferStatus::Draft)
        ->and($pending->wise_status)->toBeNull()
        ->and($quoted->status)->toBe(TransferStatus::Quoted)
        ->and($quoted->wise_quote_id)->not->toBeNull()
        ->and($created->status)->toBe(TransferStatus::Created)
        ->and($created->wise_transfer_id)->not->toBeNull()
        ->and($failedToCreate->status)->toBe(TransferStatus::FailedToCreate)
        ->and($failedToCreate->wise_transfer_id)->toBeNull()
        ->and($awaiting->status)->toBe(TransferStatus::AwaitingFunding)
        ->and($completed->wise_status)->toBe('outgoing_payment_sent')
        ->and($completed->funding_status)->toBe(FundingStatus::Completed)
        ->and($failed->wise_status)->toBe('cancelled')
        ->and($rejected->funding_status)->toBe(FundingStatus::Rejected)
        ->and($unknown->funding_status)->toBe(FundingStatus::Unknown);
});

it('creates an active authorization', function () {
    $authorization = WiseAuthorization::factory()->create();

    expect($authorization->isActive())->toBeTrue();
});
