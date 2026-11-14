<?php

namespace Owise\Enums;

/**
 * Known Wise transfer states. Persist raw Wise values as strings so newly introduced states
 * remain readable before this package is updated.
 */
enum WiseTransferStatus: string
{
    case IncomingPaymentWaiting = 'incoming_payment_waiting';
    case IncomingPaymentInitiated = 'incoming_payment_initiated';
    case Processing = 'processing';
    case FundsConverted = 'funds_converted';
    case OutgoingPaymentSent = 'outgoing_payment_sent';
    case Cancelled = 'cancelled';
    case FundsRefunded = 'funds_refunded';
    case BouncedBack = 'bounced_back';
    case ChargedBack = 'charged_back';

    /**
     * Get the values that indicate the transfer was funded.
     *
     * @return list<string>
     */
    public static function fundedValues(): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
            [
                self::Processing,
                self::FundsConverted,
                self::OutgoingPaymentSent,
                self::BouncedBack,
                self::ChargedBack,
                self::FundsRefunded,
            ],
        );
    }

    /**
     * Get the values that indicate the transfer failed.
     *
     * @return list<string>
     */
    public static function failedValues(): array
    {
        return [self::Cancelled->value, self::FundsRefunded->value, self::ChargedBack->value];
    }

    /**
     * Statuses that indicate funds entered Wise's processing flow.
     *
     * Adverse terminal states are excluded because they don't tell us when funding occurred.
     */
    public function confirmsFundsArrived(): bool
    {
        return in_array($this, [self::Processing, self::FundsConverted, self::OutgoingPaymentSent], true);
    }

    /** Whether this state proves that the transfer was funded at some point. */
    public function indicatesPreviouslyFunded(): bool
    {
        return in_array($this, [
            self::Processing,
            self::FundsConverted,
            self::OutgoingPaymentSent,
            self::BouncedBack,
            self::ChargedBack,
            self::FundsRefunded,
        ], true);
    }

    /**
     * Determine whether the status is a failed terminal state.
     */
    public function isFailed(): bool
    {
        return in_array($this, [self::Cancelled, self::FundsRefunded, self::ChargedBack], true);
    }
}
