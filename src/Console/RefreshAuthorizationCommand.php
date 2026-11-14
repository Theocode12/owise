<?php

namespace Owise\Console;

use Illuminate\Console\Command;
use Owise\Exceptions\InvalidConfiguration;
use Owise\Models\WiseAuthorization;
use Owise\WiseManager;

/**
 * Refresh the stored Wise OAuth authorization when it is close to expiry.
 */
class RefreshAuthorizationCommand extends Command
{
    protected $signature = 'wise:oauth:refresh {--before= : Refresh window in seconds}';

    protected $description = 'Refresh the stored Wise user authorization when it is close to expiry.';

    /**
     * Execute the console command.
     *
     *
     * @throws InvalidConfiguration
     */
    public function handle(WiseManager $wise): int
    {
        if (config('wise.auth') !== 'oauth') {
            throw new InvalidConfiguration('wise:oauth:refresh requires wise.auth=oauth.');
        }

        $authorization = WiseAuthorization::current();
        if ($authorization === null || ! $authorization->isActive()) {
            throw new InvalidConfiguration('No active Wise authorization is available to refresh.');
        }

        $before = (int) ($this->option('before') ?: config('wise.oauth.refresh_before_seconds', 21600));
        $limit = now()->addSeconds(max(0, $before));
        if ($authorization->access_token_expires_at !== null
            && $authorization->access_token_expires_at->getTimestamp() > $limit->getTimestamp()) {
            $this->info('The Wise authorization does not need refreshing yet.');

            return self::SUCCESS;
        }

        $wise->profile()->context()->tokens->getAccessToken();

        $this->info('Refreshed the Wise authorization.');

        return self::SUCCESS;
    }
}
