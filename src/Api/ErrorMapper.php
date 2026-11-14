<?php

namespace Owise\Api;

use Owise\Exceptions\AuthenticationFailed;
use Owise\Exceptions\ConnectionFailed;
use Owise\Exceptions\PermissionDenied;
use Owise\Exceptions\RateLimited;
use Owise\Exceptions\StrongCustomerAuthenticationRequired;
use Owise\Exceptions\TransferCannotBeCancelled;
use Owise\Exceptions\WiseApiError;
use Owise\Exceptions\WiseException;
use Owise\Http\ResponseRecorder;
use Owise\Support\Payload;
use Theocode\Wise\Exceptions\ApiException;
use Theocode\Wise\Exceptions\RateLimitException;
use Theocode\Wise\Exceptions\TransportException;
use Theocode\Wise\Exceptions\WiseException as SdkWiseException;

/**
 * Translate SDK exceptions into the package exception hierarchy for the current account.
 */
final readonly class ErrorMapper
{
    /**
     * Create a new error mapper instance.
     *
     * @param  ResponseRecorder  $recorder  The response recorder.
     * @param  bool  $scaSigningEnabled  Whether SCA signing is enabled.
     */
    public function __construct(
        private ResponseRecorder $recorder,
        private bool $scaSigningEnabled = false,
    ) {}

    /**
     * Run a callback and map SDK exceptions to package exceptions.
     *
     * Enriches errors with Wise's `errors[]`, `x-trace-id` and correlation id. SDK exceptions are kept as `previous`.
     *
     * @template T
     *
     * @param  callable(): T  $callback  The callback to run.
     * @return T The callback result.
     *
     * @throws ConnectionFailed
     * @throws WiseApiError
     * @throws WiseException
     */
    public function wrap(callable $callback): mixed
    {
        $this->recorder->reset();

        try {
            return $callback();
        } catch (TransportException $exception) {
            throw new ConnectionFailed($exception->getMessage(), $this->recorder->correlationId(), $exception);
        } catch (ApiException $exception) {
            throw $this->mapApiException($exception);
        } catch (SdkWiseException $exception) {
            throw new WiseException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * Map an SDK API exception to a package exception.
     *
     * @param  ApiException  $exception  The SDK exception.
     * @return WiseApiError The mapped package exception.
     */
    private function mapApiException(ApiException $exception): WiseApiError
    {
        $status = $exception->statusCode;
        $raw = $exception->errorBody;
        $errors = $this->parseErrors($raw);
        $response = $this->recorder->response();
        $response = $response !== null && $response->getStatusCode() === $status ? $response : null;

        $message = $errors[0]['message'] ?? Payload::string($raw, 'message') ?? $exception->getMessage();
        $traceId = ($response?->getHeaderLine('x-trace-id') ?: null) ?? $exception->requestId;
        $correlationId = $this->recorder->correlationId() ?? $exception->correlationId;
        $oneTimeToken = $response?->getHeaderLine('x-2fa-approval') ?? '';

        if ($status === 403 && $oneTimeToken !== '') {
            return new StrongCustomerAuthenticationRequired(
                $this->scaSigningEnabled
                    ? 'Wise rejected the signed Strong Customer Authentication challenge. Check that the configured private key matches the public key uploaded to Wise.'
                    : 'Wise requires Strong Customer Authentication for this request. Configure wise.sca.private_key (or the account\'s sca_private_key) with the private key whose public key is uploaded to Wise.',
                $status,
                $oneTimeToken,
                $errors,
                $traceId,
                $correlationId,
                $raw,
                $exception,
            );
        }

        if ($status === 403) {
            return new PermissionDenied(
                $errors[0]['message']
                    ?? Payload::string($raw, 'message')
                    ?? 'Wise refused this request (403 Forbidden). The credentials may not be permitted to perform this operation (for example, personal API tokens cannot fund transfers or read statements for UK/EEA profiles without Strong Customer Authentication), the resource may not belong to this profile, or the token lacks a required role.',
                $status,
                $errors,
                $traceId,
                $correlationId,
                $raw,
                $exception,
            );
        }

        if ($status === 401) {
            return new AuthenticationFailed($message, $status, $errors, $traceId, $correlationId, $raw, $exception);
        }

        if ($exception instanceof RateLimitException || $status === 429) {
            $retryAfter = $exception instanceof RateLimitException ? $exception->retryAfter : null;

            return new RateLimited($message, $status, $retryAfter, $errors, $traceId, $correlationId, $raw, $exception);
        }

        $error = new WiseApiError($message, $status, $errors, $traceId, $correlationId, $raw, $exception);

        if ($status === 409 && $error->hasErrorCode('transfer.cancellation.not.allowed')) {
            return new TransferCannotBeCancelled($message, $status, $errors, $traceId, $correlationId, $raw, $exception);
        }

        return $error;
    }

    /**
     * Parse the Wise error list from a raw payload.
     *
     * @param  array<array-key, mixed>  $raw  The raw error payload.
     * @return list<array{code: ?string, message: ?string, path: ?string, arguments: array<array-key, mixed>}> The parsed errors.
     */
    private function parseErrors(array $raw): array
    {
        $errors = [];
        foreach (Payload::list($raw, 'errors') as $error) {
            $errors[] = [
                'code' => Payload::string($error, 'code'),
                'message' => Payload::string($error, 'message'),
                'path' => Payload::string($error, 'path'),
                'arguments' => Payload::array($error, 'arguments'),
            ];
        }

        if ($errors !== []) {
            return $errors;
        }

        $error = Payload::string($raw, 'error');
        $errorDescription = Payload::string($raw, 'error_description');
        $code = Payload::string($raw, 'code');
        $message = Payload::string($raw, 'message');

        // OAuth error responses carry `error` + `error_description`.
        if ($error !== null && $errorDescription !== null) {
            $errors[] = [
                'code' => $error,
                'message' => $errorDescription,
                'path' => null,
                'arguments' => [],
            ];

            return $errors;
        }

        // Some Wise endpoints return a flat `{code, message}` payload.
        if ($code !== null) {
            $errors[] = [
                'code' => $code,
                'message' => $message ?? $code,
                'path' => null,
                'arguments' => [],
            ];

            return $errors;
        }

        // A bare `error` (OAuth) or Spring-style `{error, message}` response.
        if ($error !== null) {
            $errors[] = [
                'code' => $error,
                'message' => $message ?? $error,
                'path' => null,
                'arguments' => [],
            ];
        }

        return $errors;
    }
}
