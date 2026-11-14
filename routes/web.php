<?php

use Illuminate\Support\Facades\Route;
use Owise\Http\Controllers\WebhookController;
use Owise\Http\Middleware\VerifyWiseSignature;

$prefix = trim((string) config('wise.routes.prefix', 'wise'), '/');

Route::prefix($prefix)->name('wise.')->middleware(VerifyWiseSignature::class)->group(function (): void {
    Route::post('webhook', WebhookController::class)->name('webhook');
});
