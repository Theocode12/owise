<?php

namespace Owise\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Owise\Data\Recipient;
use Owise\Database\Factories\WiseRecipientFactory;
use Owise\Exceptions\MissingProfile;
use Owise\WiseManager;
use Owise\WiseProfile;

/**
 * @property int $id
 * @property string $payable_type
 * @property int|string $payable_id
 * @property string $profile_name
 * @property int $profile_id
 * @property int $wise_recipient_id
 * @property string $currency
 * @property string $type
 * @property string|null $holder_name
 * @property string|null $summary
 * @property string|null $country
 * @property bool $is_default
 * @property bool $active
 * @property array<string, mixed>|null $meta
 * @property-read Model $payable
 */
class WiseRecipient extends Model
{
    /** @use HasFactory<WiseRecipientFactory> */
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     */
    protected $table = 'wise_recipients';

    /**
     * The attributes that aren't mass assignable.
     */
    protected $guarded = [];

    /**
     * Get the parent payable model.
     *
     * @return MorphTo<Model, $this>
     */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Filter query by active recipients.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * Filter query by currency.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForCurrency(Builder $query, string $currency): Builder
    {
        return $query->where('currency', strtoupper($currency));
    }

    /**
     * Get the Wise profile for the recipient.
     *
     *
     * @throws MissingProfile
     */
    public function profile(): WiseProfile
    {
        $profileName = $this->getAttribute('profile_name');
        if (! is_string($profileName) || $profileName === '') {
            throw new MissingProfile('The Wise recipient does not have a configured profile name.');
        }

        return app(WiseManager::class)->profile($profileName);
    }

    /**
     * Get the recipient as a Wise API resource.
     *
     *
     * @throws MissingProfile
     */
    public function asWiseRecipient(): Recipient
    {
        return $this->profile()->recipients()->find((int) $this->getAttribute('wise_recipient_id'));
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): WiseRecipientFactory
    {
        return WiseRecipientFactory::new();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'profile_id' => 'integer',
            'wise_recipient_id' => 'integer',
            'is_default' => 'boolean',
            'active' => 'boolean',
            'meta' => 'array',
            'deleted_at' => 'immutable_datetime',
        ];
    }
}
