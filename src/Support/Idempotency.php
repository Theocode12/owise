<?php

namespace Owise\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

/**
 * Resolves idempotency keys for Wise requests.
 */
final class Idempotency
{
    /**
     * Generate a random idempotency UUID.
     */
    public static function uuid(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Resolve a caller-supplied key to a UUID for the `X-idempotence-uuid` header.
     *
     * - `null` → random v4 (callers must pass a key for safe retries).
     * - valid UUID → returned as-is (explicit UUID passthrough, BC).
     * - otherwise → deterministic v5 scoped by `$namespace`
     *   (e.g. `accountName:profileId`) so retries reuse the same header.
     */
    public static function forKey(?string $key, string $namespace = ''): string
    {
        if ($key === null || trim($key) === '') {
            return self::uuid();
        }

        $key = trim($key);

        if (Str::isUuid($key)) {
            return strtolower($key);
        }

        return Uuid::uuid5(Uuid::NAMESPACE_URL, $namespace."\0".$key)->toString();
    }

    /**
     * Validate that the given key is a UUID.
     *
     *
     * @throws InvalidArgumentException
     */
    public static function assertValid(string $key, string $name = 'idempotency key'): string
    {
        if (! Str::isUuid($key)) {
            throw new InvalidArgumentException("The {$name} must be a UUID; persist it before calling Wise and reuse it on retry.");
        }

        return $key;
    }
}
