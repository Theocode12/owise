<?php

arch('only the SDK adapter, transports and auth may depend on the Wise SDK')
    ->expect('Theocode\Wise')
    ->toOnlyBeUsedIn([
        'Owise\Api',
        'Owise\Http',
        'Owise\Auth',
        // Token provider contract carried by the account context.
        'Owise\Account\AccountContext',
        // Documented escape hatch: WiseProfile::sdk().
        'Owise\WiseProfile',
    ]);

arch('DTOs are readonly')
    ->expect('Owise\Data')
    ->classes()
    ->toBeReadonly()
    ->ignoring('Owise\Data\Concerns');

arch('DTOs are final')
    ->expect('Owise\Data')
    ->classes()
    ->toBeFinal()
    ->ignoring('Owise\Data\Concerns');
