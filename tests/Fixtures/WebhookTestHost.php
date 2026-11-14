<?php

namespace Owise\Tests\Fixtures;

use Owise\Testing\InteractsWithWiseWebhooks;

/**
 * Concrete host that applies {@see InteractsWithWiseWebhooks} so PHPStan analyses the trait.
 *
 * The trait is attached to consumer test cases through Pest's `uses()` or a `use` statement; this
 * fixture is not part of the test suite.
 */
final class WebhookTestHost
{
    use InteractsWithWiseWebhooks;
}
