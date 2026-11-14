<?php

namespace Owise\Enums;

/**
 * Represents a Wise OAuth authorization status.
 */
enum WiseAuthorizationStatus: string
{
    /**
     * The authorization is awaiting user approval.
     */
    case Pending = 'pending';

    /**
     * The authorization created a new user.
     */
    case UserCreated = 'user_created';

    /**
     * The authorization is active.
     */
    case Active = 'active';

    /**
     * The authorization was revoked.
     */
    case Revoked = 'revoked';
}
