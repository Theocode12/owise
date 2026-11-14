<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Monolog\Handler\TestHandler;
use Owise\Api\ApiClientFactory;
use Owise\Exceptions\AuthenticationFailed;
use Owise\Exceptions\ConnectionFailed;
use Owise\Exceptions\PermissionDenied;
use Owise\Exceptions\RateLimited;
use Owise\Exceptions\StrongCustomerAuthenticationRequired;
use Owise\Exceptions\TransferCannotBeCancelled;
use Owise\Exceptions\WiseApiError;
use Owise\Exceptions\WiseException;
use Theocode\Wise\Exceptions\ApiException;

function captureWiseError(Closure $callback): Throwable
{
    try {
        $callback();
    } catch (Throwable $exception) {
        return $exception;
    }

    test()->fail('Expected the Wise call to throw.');
}

it('maps validation errors with errors[], trace id and correlation id', function () {
    Http::fake(['*' => Http::response(wiseFixture('error_validation_422'), 422, ['x-trace-id' => 'trace-422'])]);

    $error = captureWiseError(fn () => wiseProfile()->quotes()->create('GBP', 'USD', 100));

    expect($error)->toBeInstanceOf(WiseApiError::class)
        ->and($error->status)->toBe(422)
        ->and($error->getMessage())->toBe("We couldn't find an account with that ID (111,111,111).")
        ->and($error->errors)->toBe([[
            'code' => 'error.account.notFound',
            'message' => "We couldn't find an account with that ID (111,111,111).",
            'path' => null,
            'arguments' => [111111111],
        ]])
        ->and($error->hasErrorCode('error.account.notFound'))->toBeTrue()
        ->and($error->traceId)->toBe('trace-422')
        ->and($error->correlationId)->toBeString()->not->toBeEmpty()
        ->and($error->raw)->toBe(wiseFixture('error_validation_422'))
        ->and($error->getPrevious())->toBeInstanceOf(ApiException::class);

    Http::assertSent(fn ($request): bool => $request->hasHeader('X-External-Correlation-Id', $error->correlationId));
});

it('maps 401 to AuthenticationFailed', function () {
    Http::fake(['*' => Http::response(['errors' => [['code' => 'unauthorized', 'message' => 'Invalid token']]], 401)]);

    $error = captureWiseError(fn () => wiseProfile()->profiles()->list());

    expect($error)->toBeInstanceOf(AuthenticationFailed::class)->and($error->getMessage())->toBe('Invalid token');
});

it('maps an SCA challenge without a configured key to StrongCustomerAuthenticationRequired', function () {
    Http::fake(['*' => Http::response('', 403, ['x-2fa-approval' => 'ott-123', 'x-2fa-approval-result' => 'REJECTED'])]);

    $error = captureWiseError(fn () => wiseProfile()->transfers()->fund(1));

    expect($error)->toBeInstanceOf(StrongCustomerAuthenticationRequired::class)
        ->and($error->oneTimeToken)->toBe('ott-123')
        ->and($error->getMessage())->toContain('wise.sca.private_key');

    Http::assertSentCount(1);
});

it('maps a 403 without an SCA challenge to PermissionDenied with a fallback message', function () {
    Http::fake(['*' => Http::response('', 403)]);

    $error = captureWiseError(fn () => wiseProfile()->recipients()->delete(1));

    expect($error)->toBeInstanceOf(PermissionDenied::class)
        ->and($error)->not->toBeInstanceOf(AuthenticationFailed::class)
        ->and($error->status)->toBe(403)
        ->and($error->getMessage())->toContain('Wise refused this request (403 Forbidden)');
});

it('parses a flat top-level code and message error body', function () {
    Http::fake(['*' => Http::response(['code' => 'INVALID_CALLBACK_URL', 'message' => 'Callback endpoint did not respond successfully'], 422)]);

    $error = captureWiseError(fn () => wiseProfile()->profiles()->list());

    expect($error->hasErrorCode('INVALID_CALLBACK_URL'))->toBeTrue()
        ->and($error->getMessage())->toBe('Callback endpoint did not respond successfully');
});

it('parses a Spring-style error response', function () {
    Http::fake(['*' => Http::response(['error' => 'Not Found', 'message' => 'Resource not found', 'status' => '404'], 404)]);

    $error = captureWiseError(fn () => wiseProfile()->profiles()->find(1));

    expect($error->getMessage())->toBe('Resource not found')
        ->and($error->errors[0]['code'])->toBe('Not Found');
});

it('parses an OAuth error response', function () {
    Http::fake(['*' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Invalid user credentials.'], 401)]);

    $error = captureWiseError(fn () => wiseProfile()->profiles()->list());

    expect($error->hasErrorCode('invalid_grant'))->toBeTrue();
});

it('maps a refused cancellation to TransferCannotBeCancelled', function () {
    Http::fake(['*' => Http::response(wiseFixture('error_cancel_409'), 409)]);

    $error = captureWiseError(fn () => wiseProfile()->transfers()->cancel(1));

    expect($error)->toBeInstanceOf(TransferCannotBeCancelled::class)
        ->and($error->getMessage())->toBe('Transfer cannot be cancelled');
});

it('maps 429 to RateLimited with Retry-After', function () {
    Http::fake(['*' => Http::response(['errors' => [['code' => 'rate.limited', 'message' => 'Slow down']]], 429, ['Retry-After' => '7'])]);

    $error = captureWiseError(fn () => wiseProfile()->profiles()->list());

    expect($error)->toBeInstanceOf(RateLimited::class)->and($error->retryAfter)->toBe(7);
});

it('maps connection failures to ConnectionFailed with the correlation id', function () {
    Http::fake(['*' => fn () => throw new ConnectionException('Connection refused')]);

    $error = captureWiseError(fn () => wiseProfile()->profiles()->list());

    expect($error)->toBeInstanceOf(ConnectionFailed::class)
        ->and($error->correlationId)->toBeString()->not->toBeEmpty()
        ->and($error->getMessage())->toContain('Connection refused');
});

it('wraps malformed success responses in the package exception', function () {
    Http::fake(['*' => Http::response('not json', 200)]);

    $error = captureWiseError(fn () => wiseProfile()->profiles()->list());

    expect($error)->toBeInstanceOf(WiseException::class)
        ->and($error->getPrevious())->toBeInstanceOf(Theocode\Wise\Exceptions\WiseException::class);
});

it('logs failed responses with correlation and trace ids to the configured channel', function () {
    config()->set('logging.channels.wise-test', ['driver' => 'monolog', 'handler' => TestHandler::class]);
    config()->set('wise.logging.channel', 'wise-test');
    app()->forgetInstance(ApiClientFactory::class);
    Http::fake(['*' => Http::response(wiseFixture('error_validation_422'), 422, ['x-trace-id' => 'trace-log'])]);

    captureWiseError(fn () => wiseProfile()->profiles()->list());

    $handler = collect(app('log')->channel('wise-test')->getLogger()->getHandlers())->first();
    $record = collect($handler->getRecords())->firstWhere('message', 'wise.response.failed');

    expect($record?->context)->toMatchArray(['status' => 422, 'trace_id' => 'trace-log', 'path' => '/2026Q4/profiles'])
        ->and($record?->context['correlation_id'])->toBeString();
});
