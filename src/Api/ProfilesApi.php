<?php

namespace Owise\Api;

use Owise\Data\Profile;
use Owise\Exceptions\WiseApiError;
use Owise\Support\Payload;

/**
 * List Wise profiles for the current account.
 */
final class ProfilesApi extends AccountApi
{
    /**
     * List the profiles for the account.
     *
     * @return list<Profile> The profiles.
     *
     * @throws WiseApiError
     */
    public function list(): array
    {
        return array_map(Profile::fromArray(...), Payload::arrays($this->client->requestOperation('profiles.list')));
    }

    /**
     * Get a profile by its ID.
     *
     * @param  int  $profileId  The profile ID.
     * @return Profile The profile.
     *
     * @throws WiseApiError
     */
    public function find(int $profileId): Profile
    {
        return Profile::fromArray($this->client->requestOperation('profiles.find', ['profileId' => $profileId]));
    }
}
