<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Owise\Api\ApiClientFactory;
use Owise\Exceptions\InvalidConfiguration;
use Owise\Exceptions\StrongCustomerAuthenticationRequired;

function scaTestKeyPath(string $name): string
{
    return dirname(__DIR__, 2)."/Fixtures/keys/sca-test-{$name}.pem";
}

it('signs the one-time token and retries the request once', function (string $keySetting) {
    Http::fake(['*' => Http::sequence()
        ->push('', 403, ['x-2fa-approval' => 'ott-123', 'x-2fa-approval-result' => 'REJECTED'])
        ->push(wiseFixture('funding'), 201)]);

    $key = $keySetting === 'path' ? scaTestKeyPath('private') : (string) file_get_contents(scaTestKeyPath('private'));
    $result = wiseProfile(['sca_private_key' => $key])->transfers()->fund(16521632);

    expect($result->isCompleted())->toBeTrue();

    $requests = Http::recorded()->map(fn (array $pair): Request => $pair[0])->values();
    $retry = $requests[1];
    $signature = base64_decode($retry->header('X-Signature')[0] ?? '', true);

    expect($requests)->toHaveCount(2)
        ->and($requests[0]->hasHeader('X-Signature'))->toBeFalse()
        ->and($retry->hasHeader('x-2fa-approval', 'ott-123'))->toBeTrue()
        ->and(openssl_verify('ott-123', (string) $signature, (string) file_get_contents(scaTestKeyPath('public')), OPENSSL_ALGO_SHA256))->toBe(1)
        ->and($retry->body())->toBe($requests[0]->body())
        ->and($retry->header('X-External-Correlation-Id'))->toBe($requests[0]->header('X-External-Correlation-Id'));
})->with(['path', 'pem']);

it('uses the global SCA key when the account does not override it', function () {
    config()->set('wise.sca.private_key', scaTestKeyPath('private'));
    app()->forgetInstance(ApiClientFactory::class);
    Http::fake(['*' => Http::sequence()
        ->push('', 403, ['x-2fa-approval' => 'ott-456'])
        ->push(wiseFixture('funding'), 201)]);

    wiseProfile()->transfers()->fund(1);

    Http::assertSentCount(2);
});

it('does not loop when Wise rejects the signed challenge', function () {
    Http::fake(['*' => Http::response('', 403, ['x-2fa-approval' => 'ott-789'])]);

    try {
        wiseProfile(['sca_private_key' => scaTestKeyPath('private')])->transfers()->fund(1);
        test()->fail('Expected the SCA challenge to fail.');
    } catch (StrongCustomerAuthenticationRequired $exception) {
        expect($exception->getMessage())->toContain('rejected the signed');
    }

    Http::assertSentCount(2);
});

it('passes through responses that are not SCA challenges', function () {
    Http::fake(['*' => Http::response(wiseFixture('funding'), 201)]);

    wiseProfile(['sca_private_key' => scaTestKeyPath('private')])->transfers()->fund(1);

    Http::assertSentCount(1);
});

it('rejects an unreadable SCA key with a clear configuration error', function () {
    expect(fn () => wiseProfile(['sca_private_key' => '/missing/key.pem']))
        ->toThrow(InvalidConfiguration::class, 'does not exist or is not readable')
        ->and(fn () => wiseProfile(['sca_private_key' => "-----BEGIN PRIVATE KEY-----\ninvalid\n-----END PRIVATE KEY-----"]))
        ->toThrow(InvalidConfiguration::class, 'could not be loaded');
});
