# WP-08 Treasury review

## Disposition

**Changes requested.** The PR is directionally aligned with WP-08 and GitHub CI is green (9/9 checks), but it is not ready to approve against the current plan and Wise's official API reference.

## Findings

### 1. Blocking: total-funds uses the wrong API version

- **File:** `src/Api/BalancesApi.php:114-119`
- **Issue:** The PR calls `/v2/profiles/{profileId}/total-funds/{currency}`. Wise's official reference specifies `GET /v1/profiles/{profileId}/total-funds/{currency}`. The new feature test also fakes `/v2`, so it cannot detect this production failure.
- **Required follow-up:** Change the path to `/v1/...` and update the fixture/test URL. Verify against sandbox or the official endpoint reference.
- **Reference:** https://docs.wise.com/api-reference/balance/totalfunds

### 2. High: `TotalFunds` drops `overdraft.availableByCurrency`

- **File:** `src/Data/TotalFunds.php:21-41`
- **Issue:** The current plan explicitly requires mapping `overdraft{limit,used,available,availableByCurrency}`. The model exposes only the first three overdraft amounts; `availableByCurrency` is retained only inside `raw`, so callers cannot use the typed API for this documented response field.
- **Required follow-up:** Add a typed list of money values, parse `overdraft.availableByCurrency`, and cover it in the fixture/test.
- **Reference:** https://docs.wise.com/api-reference/balance/totalfunds

### 3. High: movement validation does not enforce same-currency jar moves

- **File:** `src/Api/BalancesApi.php:175-190`
- **Issue:** `move()` fetches both balances only to check `investmentState`; it never compares their currencies with the requested amount. The public contract and current plan define this method as a same-currency move. A cross-currency call can therefore reach Wise as a malformed movement instead of failing locally with a clear `InvalidArgumentException`.
- **Required follow-up:** Validate source currency, target currency, and amount currency before the movement POST; add a no-HTTP test for mismatched currencies.
- **Reference:** https://docs.wise.com/api-reference/balance/balancemovement

### 4. Medium: conversion quote guard has an unsafe three-state condition

- **File:** `src/Api/BalancesApi.php:140-147`
- **Issue:** The guard rejects a non-`BALANCE` non-null `payOut`, but allows a quote with `payOut === null` even when no enabled `BALANCE` payment option exists. Conversely, a non-BALANCE quote is reported as `BalanceNotActionable`, which conflates an invalid quote contract with an invested balance. The current plan says conversion should skip a balance pre-check and treat the movement response as authoritative.
- **Required follow-up:** Remove this response-side balance guard and rely on the documented movement response; add tests for the quote-to-movement contract.

### 5. Medium: `all()` default does not match the current plan

- **File:** `src/Api/BalancesApi.php:38-45`
- **Issue:** The current plan specifies `all(?string $type = 'STANDARD')`, while the PR defaults to `['STANDARD', 'SAVINGS']`. This changes the default query and can introduce savings jars into callers that previously requested standard balances.
- **Required follow-up:** Align the signature/default with the plan.

## Verification gaps

- The PR tests only the PDF binary statement path; the plan's acceptance criteria require all supported non-JSON formats (`csv`, `xlsx`, `camt053`, `mt940`, and `qif`) to return bytes.
- No sandbox evidence was included for the corrected total-funds endpoint or the statement download formats.
- The PR adds `ramsey/uuid`, although the plan specifies UUID v5 without a new dependency. Confirm this intentional dependency change or use the existing dependency set.

## Decision

Do not approve until findings 1 and 2 are addressed and behavior is reconciled with the plan. Re-run the full test suite after the endpoint correction.
