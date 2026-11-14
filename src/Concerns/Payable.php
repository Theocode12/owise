<?php

namespace Owise\Concerns;

/**
 * Recipient ownership and payout workflows for an Eloquent model.
 */
trait Payable
{
    use ManagesRecipients;
    use SendsPayouts;
}
