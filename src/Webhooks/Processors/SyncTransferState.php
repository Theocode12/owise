<?php

namespace Owise\Webhooks\Processors;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Owise\Enums\FundingMode;
use Owise\Enums\TransferStatus;
use Owise\Events\TransferStateChanged;
use Owise\Exceptions\WiseApiError;
use Owise\Models\WiseRecipient;
use Owise\Models\WiseTransfer;
use Owise\Support\Money;
use Owise\WiseManager;
use Owise\WiseProfile;

/**
 * Keeps the local transfer mirror in sync with a Wise state-change webhook.
 */
class SyncTransferState
{
    public function handle(TransferStateChanged $event): void
    {
        $transfer = $this->transferModel()->where('wise_transfer_id', $event->transferId)->first();

        if ($transfer === null) {
            $this->importUnknown($event->transferId, $event->payload->profileId);

            return;
        }

        $transfer->applyRemoteStatus($event->current, $event->occurredAt);
    }

    private function importUnknown(?string $transferId, ?string $profileId): void
    {
        if (! (bool) config('wise.payouts.import_unknown', false) || $transferId === null || $transferId === '') {
            return;
        }

        try {
            $profile = $this->resolveProfile($profileId);
            $remote = $profile->transfers()->find((int) $transferId);

            $existing = $this->transferModel()->where('wise_transfer_id', $remote->id)->first();
            if ($existing !== null) {
                $existing->applyRemoteStatus($remote->status);

                return;
            }

            $this->transferModel()->create([
                'wise_transfer_id' => $remote->id,
                'customer_transaction_id' => $remote->customerTransactionId ?? (string) Str::uuid(),
                'profile_id' => $profile->profileId(),
                'account' => $profile->context()->name,
                'recipient_id' => $this->recipientIdFor($profile, $remote->targetAccount),
                'source_currency' => $remote->sourceCurrency ?? '',
                'target_currency' => $remote->targetCurrency ?? '',
                'source_amount' => $remote->sourceValue !== null ? Money::toMinor($remote->sourceValue) : null,
                'target_amount' => $remote->targetValue !== null ? Money::toMinor($remote->targetValue) : null,
                'rate' => $remote->rate !== null ? (string) $remote->rate : null,
                'reference' => $remote->reference,
                'status' => TransferStatus::Created,
                'wise_status' => $remote->status,
                'funding_mode' => FundingMode::Manual,
                'wise_status_occurred_at' => now(),
            ]);
        } catch (WiseApiError $e) {
            if ($e->status === 404) {
                return;
            }

            throw $e;
        } catch (UniqueConstraintViolationException) {
            // Another worker imported this transfer already; best-effort import.
        }
    }

    /**
     * Resolve the local recipient key matching the remote target account, when the recipient
     * belongs to the transfer's profile. Returns null when there is no match.
     */
    private function recipientIdFor(WiseProfile $profile, ?int $targetAccount): ?int
    {
        if ($targetAccount === null) {
            return null;
        }

        $id = $this->recipientModel()
            ->where('profile_id', $profile->profileId())
            ->where('wise_recipient_id', $targetAccount)
            ->value('id');

        return is_int($id) || is_string($id) ? (int) $id : null;
    }

    private function resolveProfile(?string $profileId): WiseProfile
    {
        $manager = app(WiseManager::class);

        if ($profileId !== null) {
            foreach (config('wise.profiles', []) as $name => $profile) {
                if (is_array($profile) && (string) ($profile['profile_id'] ?? '') === $profileId) {
                    return $manager->profile($name);
                }
            }
        }

        return $manager->profile();
    }

    /** @return Builder<WiseTransfer> */
    private function transferModel(): Builder
    {
        $class = config('wise.models.transfer', WiseTransfer::class);

        return $class::query();
    }

    /** @return Builder<WiseRecipient> */
    private function recipientModel(): Builder
    {
        $class = config('wise.models.recipient', WiseRecipient::class);

        return $class::query();
    }
}
