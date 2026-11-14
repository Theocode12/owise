<?php

namespace Owise\Enums;

/**
 * The package's local funding-attempt state.
 */
enum FundingStatus: string
{
    case Pending = 'PENDING';
    case Unknown = 'UNKNOWN';
    case Created = 'CREATED';
    case Completed = 'COMPLETED';
    case Rejected = 'REJECTED';
}
