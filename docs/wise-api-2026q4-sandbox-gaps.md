# Wise API 2026Q4 — sandbox gaps

A memory aid for the Wise hand-off email: these Wise Platform operations are described in the
official docs but return `404 Resource not found` on the `2026Q4` sandbox, so they appear to be
missing/unavailable. Send this list to Wise as feedback.

- Probed: **2026-11-13** against `https://api.wise-sandbox.com`
- Evidence source: `reviews/v1-production-readiness.md` (decision D1, section A1)

## Summary

Three documented operations return **404** (route missing) on `2026Q4`. The package removed them
from the v1 surface and kept their plumbing dormant so they can be re-enabled once Wise fixes the
routes.

## Gaps (404 — documented but not working)

### 1. No-quote recipient requirements

- Request: `GET /2026Q4/account-requirements?source=GBP&target=EUR&sourceAmount=1000`
- Result: `404 Resource not found` (also 404 in `live-smoke-report.json`)
- Docs: recipient requirements without a quote — https://docs.wise.com/api-reference/recipient
- Package impact: `RecipientsApi::requirementsFor()` removed; quote-based `requirements()` kept.

### 2. Non-JSON balance statement downloads

- Request: `GET /2026Q4/profiles/1/balance-statements/1/statement.pdf`
  (also `.csv`, `.xlsx`, `.xml`, `.mt940`, `.qif`)
- Result: `404`
- Docs: balance statements — https://docs.wise.com/api-reference/balance
- Package impact: `StatementsApi::download()` removed; the JSON route `statement.json` works
  (returns `401` unauth, i.e. the route exists). Binary-download plumbing kept dormant.

### 3. OAuth user registration (create a Wise user)

- Request: `POST /2026Q4/user/signup/registration_code`
- Result: `404`
- Docs: user signup / registration code — https://docs.wise.com/api-reference/oauth-token
- Package impact: `OAuthManager::registerUser()` / `resumeRegistration()` removed;
  `createWiseUser()` kept dormant. Token exchange `POST /oauth/token` works (`401` unauth).

## Routes that DO work on 2026Q4

These return `401` (unauthenticated) rather than `404`, which means the route exists and is served:

- `POST /2026Q4/oauth/token`
- `GET /2026Q4/profiles/{profileId}/balance-statements/{balanceId}/statement.json`

## Feedback notes

- When emailing Wise, frame it as: "the following endpoints are documented in the current API
  reference but return 404 on the 2026Q4 sandbox; please confirm when they will be available."
- Include the exact request paths and the `Resource not found` responses above.
- Re-check these routes after each quarterly CalVer bump; the package keeps the dormant plumbing
  so re-enabling is a one-method change once the routes go live.
