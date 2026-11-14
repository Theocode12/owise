<?php

namespace Owise\Enums;

use Owise\Models\WiseTransfer;

/**
 * Local workflow status of a {@see WiseTransfer}. Kept separate from the last Wise
 * status (`wise_status`), which mirrors Wise's remote state machine. A manual-funding payout may
 * therefore be locally `awaiting_funding` while Wise still reports `incoming_payment_waiting`.
 */
enum TransferStatus: string
{
    case Draft = 'draft';
    case Quoted = 'quoted';
    case Created = 'created';
    case AwaitingFunding = 'awaiting_funding';
    case FailedToCreate = 'failed_to_create';
}
