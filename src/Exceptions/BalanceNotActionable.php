<?php

namespace Owise\Exceptions;

/**
 * Thrown before any HTTP call when a balance is not actionable via the API
 * (Wise only allows operations on `NOT_INVESTED` balances).
 */
class BalanceNotActionable extends WiseException {}
