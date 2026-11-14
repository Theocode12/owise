<?php

namespace Owise\Api;

use Owise\Account\AccountContext;
use Owise\Exceptions\MissingProfile;

/**
 * Base class for profile-scoped Wise API wrappers.
 */
abstract class AccountApi
{
    /**
     * Create a new profile-scoped API instance.
     *
     * @param  ApiClient  $client  The HTTP gateway used for Wise requests.
     * @param  AccountContext  $context  The account holding the profile and environment.
     */
    public function __construct(
        protected readonly ApiClient $client,
        protected readonly AccountContext $context,
    ) {}

    /**
     * Resolve the Wise profile ID for the request.
     *
     * Falls back to the account context when no explicit ID is given.
     *
     * @param  int|null  $profileId  The explicit profile ID, if any.
     * @return int The resolved profile ID.
     *
     * @throws MissingProfile
     */
    protected function profileId(?int $profileId = null): int
    {
        return $profileId
            ?? $this->context->profileId
            ?? throw new MissingProfile(
                "Wise profile '{$this->context->name}' has no profile_id configured. Set WISE_PROFILE_ID or this profile's profile_id, or pass profileId explicitly.",
            );
    }
}
