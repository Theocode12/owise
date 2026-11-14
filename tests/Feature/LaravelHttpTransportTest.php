<?php

use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Owise\Http\LaravelHttpTransport;
use Theocode\Wise\Exceptions\TransportException;

it('forwards the PSR request body and headers and returns error responses without throwing', function () {
    Http::fake([
        'api.wise-sandbox.com/v1/transfers' => Http::response(['errors' => [['message' => 'invalid']]], 422),
    ]);

    $factory = new HttpFactory;
    $request = $factory->createRequest('POST', 'https://api.wise-sandbox.com/v1/transfers')
        ->withHeader('Authorization', 'Bearer token')
        ->withHeader('Content-Type', 'application/json')
        ->withBody($factory->createStream('{"amount":10}'));

    $response = (new LaravelHttpTransport(app(Factory::class)))->send($request);

    expect($response->getStatusCode())->toBe(422);

    Http::assertSent(fn (HttpRequest $sent): bool => $sent->method() === 'POST'
        && $sent->url() === 'https://api.wise-sandbox.com/v1/transfers'
        && $sent->body() === '{"amount":10}'
        && $sent->hasHeader('Authorization', 'Bearer token')
        && $sent->hasHeader('Content-Type', 'application/json')
        && $sent->hasHeader('X-External-Correlation-Id')
    );
});

it('preserves a supplied correlation id and wraps connection failures', function () {
    $capturedRequest = null;
    Http::fake([
        '*' => function (HttpRequest $request) use (&$capturedRequest) {
            $capturedRequest = $request;

            throw new ConnectionException('connection refused');
        },
    ]);

    $request = (new HttpFactory)->createRequest('GET', 'https://api.wise-sandbox.com/v2/profiles')
        ->withHeader('X-External-Correlation-Id', 'known-correlation-id');

    expect(fn () => (new LaravelHttpTransport(app(Factory::class)))->send($request))
        ->toThrow(TransportException::class, 'Wise API connection failed');

    expect($capturedRequest)->toBeInstanceOf(HttpRequest::class)
        ->and($capturedRequest->hasHeader('X-External-Correlation-Id', 'known-correlation-id'))->toBeTrue();
});
