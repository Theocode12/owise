<?php

namespace Owise\Data;

use DateTimeImmutable;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Owise\Concerns\SerializesData;
use Owise\Support\Payload;
use SensitiveParameter;

/**
 * Represents a Wise OAuth token response.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class OAuthToken implements Arrayable, JsonSerializable
{
    use SerializesData {
        toArray as private serializedArray;
    }

    /**
     * The secret keys redacted from stored payloads.
     *
     * @var list<string>
     */
    private const SECRET_KEYS = ['access_token', 'refresh_token'];

    /**
     * Create a new OAuth token instance.
     *
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        /**
         * The access token issued by Wise.
         *
         * @var string
         */
        #[SensitiveParameter] public string $accessToken,
        /**
         * The token type issued by Wise.
         *
         * @var string|null
         */
        public ?string $tokenType,
        /**
         * The refresh token issued by Wise.
         *
         * @var string|null
         */
        #[SensitiveParameter] public ?string $refreshToken,
        /**
         * The lifetime of the access token in seconds.
         *
         * @var int|null
         */
        public ?int $expiresIn,
        /**
         * The time the access token expires.
         *
         * @var DateTimeImmutable|null
         */
        public ?DateTimeImmutable $expiresAt,
        /**
         * The time the refresh token expires.
         *
         * @var DateTimeImmutable|null
         */
        public ?DateTimeImmutable $refreshTokenExpiresAt,
        /**
         * The scopes granted to the token.
         *
         * @var string|null
         */
        public ?string $scope,
        /**
         * The time the token was created.
         *
         * @var DateTimeImmutable|null
         */
        public ?DateTimeImmutable $createdAt,
        /**
         * The raw token payload with secrets redacted.
         *
         * Token values are redacted.
         *
         * @var array<array-key, mixed>
         */
        public array $raw = [],
    ) {}

    /**
     * Create an OAuth token instance from a Wise API payload.
     *
     * @param  array<array-key, mixed>  $raw
     * @return static
     */
    public static function fromArray(array $raw, ?DateTimeImmutable $now = null): self
    {
        $now ??= new DateTimeImmutable;
        $expiresIn = Payload::int($raw, 'expires_in');
        $refreshExpiresIn = Payload::int($raw, 'refresh_token_expires_in');

        return new self(
            accessToken: Payload::string($raw, 'access_token') ?? '',
            tokenType: Payload::string($raw, 'token_type'),
            refreshToken: Payload::string($raw, 'refresh_token'),
            expiresIn: $expiresIn,
            expiresAt: Payload::date($raw, 'expires_at') ?? ($expiresIn !== null ? $now->modify("+{$expiresIn} seconds") : null),
            refreshTokenExpiresAt: Payload::date($raw, 'refresh_token_expires_at')
                ?? ($refreshExpiresIn !== null ? $now->modify("+{$refreshExpiresIn} seconds") : null),
            scope: Payload::string($raw, 'scope'),
            createdAt: Payload::date($raw, 'created_at'),
            raw: self::redact($raw),
        );
    }

    /**
     * Get the serializable representation without secrets.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = $this->serializedArray();
        unset($data['accessToken'], $data['refreshToken']);

        return $data;
    }

    /**
     * Redact secret values from a raw token payload.
     *
     * @param  array<array-key, mixed>  $raw
     * @return array<array-key, mixed>
     */
    private static function redact(array $raw): array
    {
        foreach (self::SECRET_KEYS as $key) {
            if (array_key_exists($key, $raw)) {
                $raw[$key] = '[REDACTED]';
            }
        }

        return $raw;
    }
}
