<?php

namespace Owise\Support;

use Illuminate\Support\Facades\Event;

/**
 * Dispatches application-facing Owise events when the integration enables them.
 *
 * Internal webhook processing does not use this dispatcher and is unaffected by the setting.
 */
final class WiseEventDispatcher
{
    public function dispatchApplicationEvent(object $event): void
    {
        if (! (bool) config('wise.events.application.enabled', true)) {
            return;
        }

        Event::dispatch($event);
    }
}
