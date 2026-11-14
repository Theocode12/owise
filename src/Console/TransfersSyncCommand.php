<?php

namespace Owise\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Owise\Enums\FundingMode;
use Owise\Enums\FundingStatus;
use Owise\Enums\TransferStatus;
use Owise\Exceptions\InvalidConfiguration;
use Owise\Exceptions\WiseException;
use Owise\Models\WiseTransfer;

/**
 * Reconcile local transfer mirrors with Wise. A safety net for missed or out-of-order webhooks;
 * schedule it every ~15 minutes.
 */
class TransfersSyncCommand extends Command
{
    protected $signature = 'wise:transfers:sync
        {--since= : Only reconcile sent transfers whose status changed at or after this ISO datetime (default: wise.payouts.sent_reconciliation_days)}
        {--profile= : Configured Wise profile name}
        {--no-resume : Skip resuming stuck drafts, quotes and pending funding}';

    protected $description = 'Reconcile local transfer mirrors with Wise (safety net for missed webhooks).';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $since = $this->resolveSince();
        $profileName = $this->profileName();
        $synced = 0;
        $failed = 0;

        if (! $this->option('no-resume')) {
            [$resumed, $resumeFailed] = $this->resumeStuck($profileName);
            $synced += $resumed;
            $failed += $resumeFailed;
        }

        // Non-terminal transfers: pending quotes/creations/funding.
        foreach ($this->query($profileName)->pending()->whereNotNull('wise_transfer_id')->lazyById(100) as $transfer) {
            [$ok] = $this->reconcile($transfer);
            $ok ? $synced++ : $failed++;
        }

        // Recently sent transfers: recover late bounce-backs/chargebacks.
        foreach ($this->query($profileName)->sent()->where('wise_status_occurred_at', '>=', $since)->lazyById(100) as $transfer) {
            [$ok] = $this->reconcile($transfer);
            $ok ? $synced++ : $failed++;
        }

        $unknown = $this->query($profileName)
            ->where('status', TransferStatus::Created)
            ->whereIn('funding_status', [FundingStatus::Unknown, FundingStatus::Rejected])
            ->count();
        if ($unknown > 0) {
            $this->warn("{$unknown} created transfer(s) have UNKNOWN/REJECTED funding; confirm with Wise, then call ->retryFunding().");
        }

        $this->info("Reconciled {$synced} transfers".($failed > 0 ? "; {$failed} failed." : '.'));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Resume stuck drafts, quotes and pending API funding older than the configured threshold.
     *
     * @return array{0: int, 1: int} Resumed and failed counts.
     */
    private function resumeStuck(?string $profileName): array
    {
        $resumed = 0;
        $failed = 0;
        $cutoff = now()->subMinutes((int) config('wise.payouts.resume_after_minutes', 10));

        $query = $this->query($profileName)
            ->where('updated_at', '<=', $cutoff)
            ->where(function (Builder $query): void {
                $query->whereIn('status', [TransferStatus::Draft, TransferStatus::Quoted])
                    ->orWhere(function (Builder $query): void {
                        $query->where('status', TransferStatus::Created)
                            ->where('funding_mode', FundingMode::Api)
                            ->where(function (Builder $query): void {
                                $query->whereNull('funding_status')
                                    ->orWhere('funding_status', FundingStatus::Pending);
                            });
                    });
            });

        foreach ($query->lazyById(100) as $transfer) {
            try {
                $transfer->resume();
                $resumed++;
            } catch (WiseException|InvalidArgumentException|LockTimeoutException $e) {
                $this->warn("Could not resume transfer {$transfer->getKey()}: {$e->getMessage()}");
                $failed++;
            }
        }

        return [$resumed, $failed];
    }

    /**
     * @return array{0: bool, 1: WiseTransfer}
     */
    private function reconcile(WiseTransfer $transfer): array
    {
        try {
            $transfer->refreshFromWise();

            return [true, $transfer];
        } catch (WiseException) {
            $this->warn("Could not reconcile transfer {$transfer->wise_transfer_id}.");

            return [false, $transfer];
        }
    }

    private function resolveSince(): CarbonImmutable
    {
        $since = $this->option('since');
        if (is_string($since) && $since !== '') {
            return CarbonImmutable::parse($since);
        }

        $days = (int) config('wise.payouts.sent_reconciliation_days', 60);

        return CarbonImmutable::now()->subDays($days);
    }

    private function profileName(): ?string
    {
        $name = $this->option('profile');
        if (! is_string($name) || $name === '') {
            return null;
        }

        $profiles = config('wise.profiles', []);
        if (! is_array($profiles) || ! array_key_exists($name, $profiles)) {
            throw new InvalidConfiguration("Wise profile '{$name}' is not configured in config/wise.php.");
        }

        return $name;
    }

    /** @return Builder<WiseTransfer> */
    private function query(?string $profileName): Builder
    {
        $query = $this->transferModel()::query();
        if ($profileName !== null) {
            $query->where('account', $profileName);
        }

        return $query;
    }

    /** @return class-string<WiseTransfer> */
    private function transferModel(): string
    {
        $class = config('wise.models.transfer', WiseTransfer::class);
        if (! is_string($class) || ! is_a($class, WiseTransfer::class, true)) {
            throw new InvalidConfiguration('wise.models.transfer must be a WiseTransfer model class.');
        }

        return $class;
    }
}
