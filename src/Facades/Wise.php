<?php

namespace Owise\Facades;

use Illuminate\Support\Facades\Facade;

class Wise extends Facade
{
    /**
     * Get the registered facade accessor.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'wise';
    }
}
