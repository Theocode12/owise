<?php

namespace Owise\Enums;

/**
 * Represents the transfer funding mode.
 */
enum FundingMode: string
{
    /**
     * The transfer is funded via the API.
     */
    case Api = 'api';

    /**
     * The transfer is funded manually.
     */
    case Manual = 'manual';
}
