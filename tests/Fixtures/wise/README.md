# Wise API test fixtures

These response bodies were assembled from Wise Platform examples and field schemas. They are fixtures, not captures of every Q4 response. The source paths below describe the examples' original operations; runtime request routes are separately verified against the current Q4 reference and recorded in `Plans/11-wise-calver-api-paths.md`.

Where the fixtures came from:

- **Explicit example**: copied word for word from a response example in the OpenAPI bundle behind the docs (`https://docs.wise.com/_bundle/api-reference/@legacy/index.yaml`).
- **Field examples**: the operation has no full response example. The fixture puts together the `example` value for each field in the response schema. These are the same values the docs page lists under "Response fields". Fields without an example are `null`, objects without examples are `{}`, and arrays have one item.
- **Errors guide**: copied word for word from a JSON block in an error guide.

| Fixture | Endpoint | Source | Kind |
|---|---|---|---|
| `quote.json` | `POST /v3/profiles/{profileId}/quotes` (same schema for GET/PATCH) | https://docs.wise.com/api-reference/legacy/quote/quotecreate.md | Field examples |
| `account_requirements.json` | `GET /v1/quotes/{quoteId}/account-requirements` | https://docs.wise.com/api-reference/legacy/recipient/recipientaccountrequirementsget.md | Explicit example (schema-level, top-level array) |
| `account_requirements_refresh_example.json` | `GET /v1/quotes/{quoteId}/account-requirements` | https://docs.wise.com/guides/product/send-money/recipients/account-requirements.md | Minimal test example: field shape follows the documented schema; `address.country` and its refresh behavior are the guide's documented example. Not a verbatim full API response. |
| `recipient_v1.json` | `POST /v1/accounts` | https://docs.wise.com/api-reference/legacy/recipient/recipientcreate.md | Field examples (`recipient` schema) |
| `recipient_v2.json` | `GET /v2/accounts/{accountId}` | https://docs.wise.com/api-reference/legacy/recipient/recipientget.md | Field examples (`recipient` schema, same schema as create, so identical content) |
| `recipient_v2_list.json` | `GET /v2/accounts` | https://docs.wise.com/api-reference/legacy/recipient/recipientlist.md | Explicit example |
| `transfer_requirements.json` | `POST /v1/transfer-requirements` | https://docs.wise.com/api-reference/legacy/transfer/transferrequirementsvalidate.md | Explicit example |
| `transfer.json` | `POST /v1/transfers` (also GET/cancel `transfer` variant) | https://docs.wise.com/api-reference/legacy/standard-transfer/transfercreate.md | Field examples (`transfer` schema) |
| `transfer_list.json` | `GET /v1/transfers` | https://docs.wise.com/api-reference/legacy/standard-transfer/transferlist.md | Field examples (array of `transfer`) |
| `funding.json` | `POST /v3/profiles/{profileId}/transfers/{transferId}/payments` (201) | https://docs.wise.com/api-reference/legacy/transfer/transferfund.md | Explicit example `fund-transfer-balance-completed` |
| `balances.json` | `GET /v4/profiles/{profileId}/balances?types=STANDARD` | https://docs.wise.com/api-reference/legacy/balance/balancelist.md | Field examples (array of `Balance`) |
| `balance.json` | `GET /v4/profiles/{profileId}/balances/{balanceId}` | https://docs.wise.com/api-reference/legacy/balance/balanceget.md | Field examples (`Balance`) |
| `balance_capacity.json` | `GET /v1/profiles/{profileId}/balance-capacity?currency=` | https://docs.wise.com/api-reference/legacy/balance/balancecapacity.md | Field examples |
| `balance_movement.json` | `POST /v2/profiles/{profileId}/balance-movements` (201) | https://docs.wise.com/api-reference/legacy/balance/balancemovement.md | Field examples |
| `statement.json` | `GET /v1/profiles/{profileId}/balance-statements/{balanceId}/statement.json` | https://docs.wise.com/api-reference/legacy/balance-statement/balancestatementget.md | Field examples |
| `webhook_subscription.json` | `POST /v3/profiles/{profileId}/subscriptions` (200) | https://docs.wise.com/api-reference/legacy/webhook/webhookprofilesubscriptioncreate.md | Explicit example |
| `webhook_subscription_list.json` | `GET /v3/profiles/{profileId}/subscriptions` | https://docs.wise.com/api-reference/legacy/webhook/webhookprofilesubscriptionlist.md | Explicit example |
| `oauth_token.json` | `POST /oauth/token` | https://docs.wise.com/api-reference/legacy/oauth-token/oauthtokencreate.md | Field examples (user-token grant shape; `client_credentials` omits refresh_token / refresh_token_* / created_at per docs) |
| `simulation_transfer.json` | `GET /v1/simulation/transfers/{transferId}/{status}` | https://docs.wise.com/api-reference/legacy/simulation/simulationtransferstatechange.md | Explicit example |
| `simulation_topup.json` | `POST /v1/simulation/balance/topup` | https://docs.wise.com/api-reference/legacy/simulation/simulationbalancetopup.md | Explicit example |
| `profiles.json` | `GET /v2/profiles` | https://docs.wise.com/api-reference/legacy/profile/profilelist.md | Explicit example |
| `error_cancel_409.json` | `PUT /v1/transfers/{transferId}/cancel` (409) | https://docs.wise.com/guides/developer/errors/transfers.md ("Cancel a standard transfer") | Errors guide (this example has no `path` key) |
| `error_validation_422.json` | `POST /v3/profiles/{profileId}/quotes` (422, `error.account.notFound`) | https://docs.wise.com/guides/developer/errors/quotes.md ("Create an authenticated quote") | Errors guide |

The versioned operations are validated against the [Q4 API reference](https://docs.wise.com/api-reference). In particular, current Q4 docs confirm [transfer listing](https://docs.wise.com/api-reference/standard-transfer/transferlist.md) at `GET /transfers` and both [recipient-requirements variants](https://docs.wise.com/api-reference/recipient/recipientaccountrequirementsget.md), including `GET /account-requirements` without a quote.

Notes:

- `https://docs.wise.com/guides/developer/errors.md` does not show an error body. The `{"errors":[{code,message,path?,arguments}]}` shape comes from the operation-specific error guides listed above. Those guides say `path` is only present when a field is identified.
- The API reference shows no JSON body for the cancel 409. It only says the response "will include the code `transfer.cancellation.not.allowed`". The fixture comes from the transfer errors guide.
