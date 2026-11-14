<?php

namespace Owise\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Owise\Database\Factories\WiseAuthorizationFactory;
use Owise\Enums\WiseAuthorizationStatus;

/**
 * One Wise user authorization shared by all of the integration's profiles.
 *
 * @property string $email
 * @property string $registration_code
 * @property string $language
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property WiseAuthorizationStatus $status
 * @property \DateTimeInterface|null $access_token_expires_at
 * @property \DateTimeInterface|null $refresh_token_expires_at
 * @property \DateTimeInterface|null $revoked_at
 */
class WiseAuthorization extends Model
{
    /** @use HasFactory<WiseAuthorizationFactory> */
    use HasFactory;

    public const ID = 1;

    protected $table = 'wise_authorizations';

    protected $guarded = [];

    protected $hidden = ['email', 'registration_code', 'access_token', 'refresh_token'];

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): WiseAuthorizationFactory
    {
        return WiseAuthorizationFactory::new();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email' => 'encrypted',
            'registration_code' => 'encrypted',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'language' => 'string',
            'wise_user_id' => 'integer',
            'status' => WiseAuthorizationStatus::class,
            'access_token_expires_at' => 'immutable_datetime',
            'refresh_token_expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /**
     * Get the singleton authorization record.
     *
     * @return static|null
     */
    public static function current(): ?self
    {
        return static::query()->find(self::ID);
    }

    /**
     * Determine if the authorization is active.
     */
    public function isActive(): bool
    {
        return $this->status === WiseAuthorizationStatus::Active && $this->revoked_at === null;
    }
}
