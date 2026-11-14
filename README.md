<p align="center">
    <img src="art/logo.png" alt="Owise Package Logo" width="400">
</p>

<p align="center">
    <a href="https://github.com/Theocode12/owise/actions">
        <img src="https://github.com/Theocode12/owise/workflows/tests/badge.svg" alt="Build Status">
    </a>
    <a href="https://packagist.org/packages/theocode12/owise">
        <img src="https://img.shields.io/packagist/dt/theocode12/owise" alt="Total Downloads">
    </a>
    <a href="https://packagist.org/packages/theocode12/owise">
        <img src="https://img.shields.io/packagist/v/theocode12/owise" alt="Latest Stable Version">
    </a>
    <a href="https://packagist.org/packages/theocode12/owise">
        <img src="https://img.shields.io/packagist/l/theocode12/owise" alt="License">
    </a>
</p>

## Introduction

Owise is a Laravel package that makes working with the Wise Platform API simpler and more intuitive. Inspired by the experience Laravel Cashier provides for Stripe, Owise brings a familiar Laravel approach to integrating with Wise, taking care of much of the underlying complexity so developers can focus on building their applications.

Owise is unofficial and is not affiliated with, endorsed by, or supported by Wise Payments Ltd.

## Installation

Install Owise into your Laravel application using Composer:

```bash
composer require theocode12/owise
```

Laravel's package discovery automatically registers the Owise service provider, so no manual registration is necessary.

Next, publish the package configuration file:

```bash
php artisan vendor:publish --tag=wise-config
```

This creates a `config/wise.php` file in your application, where you can configure your Wise API connection and package settings.

### Database setup

Owise includes database-backed features for managing recipients, OAuth authorizations, and payout tracking.

If you intend to use any of these features, run your application's migrations:

```bash
php artisan migrate
```

Database migrations are not required if you only intend to make API requests.

## Configuration

After publishing the configuration file, Owise can be configured through your application's environment variables.

For a starting point, add your Wise sandbox credentials to your `.env` file:

```dotenv
WISE_API_TOKEN=your-wise-sandbox-token
WISE_PROFILE_ID=your-profile-id
WISE_ENVIRONMENT=sandbox
```

Owise defaults to the sandbox environment. Use sandbox credentials while developing and testing your integration.

You can find the available settings in `config/wise.php`.

### API versioning

Owise uses the `2026Q4` global API version by default:

```dotenv
WISE_API_VERSION=2026Q4
```

Version-numbered API endpoints (e.g. `v1`, `v2`, `v3`) are not supported.

## Making your first API request

The `Wise` facade gives you access to your Wise account and its API operations.

To retrieve the profiles associated with your Wise account:

```php
use Owise\Facades\Wise;

$profiles = Wise::profile()->profiles()->list();

foreach ($profiles as $profile) {
    echo $profile->id;
    echo $profile->fullName;
}
```

You can also retrieve a specific profile using its ID:

```php
$profile = Wise::profile()->profiles()->find($profileId);
```

### Working with your Wise profile

If you've already configured a profile ID, you can access it directly:

```php
$profile = Wise::profile();

$profileId = $profile->profileId();

$balances = $profile->balances()->list();
```

Each balance is returned as a `Balance` object, with monetary values represented using `Brick\Money`.

You can access the balance currency and available cash amount:

```php
foreach ($balances as $balance) {
    $currency = $balance->currency;
    $available = $balance->cash();
}
```

## Creating a quote

Once you've accessed your Wise profile, you can use it to create quotes between currencies.

A quote gives you the exchange rate, fees, and payment options for a conversion. You can create a quote by specifying the source and target currencies, along with the amount you want to send or receive.

For example, to find out how much EUR 100 would cost in GBP:

```php
use Brick\Money\Money;
use Owise\Facades\Wise;

$quote = Wise::profile()->quotes()->create(
    sourceCurrency: 'GBP',
    targetCurrency: 'EUR',
    targetAmount: Money::of('100.00', 'EUR'),
);
```

By providing `targetAmount`, you're asking Wise to calculate how much GBP is required to receive EUR 100.

You can access the details of the quote through its properties:

```php
$quote->id;
$quote->sourceAmount;
$quote->targetAmount;
$quote->rate;
$quote->rateExpirationTime;
```

Owise returns a `Quote` object, with monetary values represented using `Brick\Money`.

You can also create a quote by specifying the amount you want to send instead:

```php
$quote = Wise::profile()->quotes()->create(
    sourceCurrency: 'GBP',
    targetCurrency: 'EUR',
    sourceAmount: Money::of('100.00', 'GBP'),
);
```

When creating a quote, provide either `sourceAmount` or `targetAmount`, but not both.

### Retrieving a quote

If you need to retrieve a previously created quote, use its ID:

```php
$quote = Wise::profile()->quotes()->find($quoteId);
```

You can also update a quote with a recipient once you've created one:

```php
$quote = Wise::profile()->quotes()->update(
    quoteId: $quote->id,
    targetAccount: $recipientId,
);
```

This associates the recipient with the quote, allowing Wise to return the updated quote details.

## Adding a recipient

Before creating a recipient, you need to know what bank details Wise requires. These requirements depend on the destination currency and payment route, so they can vary between recipients.

You can retrieve the required recipient details using the quote you created earlier:

```php
$requirements = Wise::profile()->recipients()->requirements($quote->id);
```

The requirements response contains the available recipient types and the fields required for each type.

To see which recipient types are available:

```php
$types = $requirements->types();
```

For example, you might receive `iban` as one of the available recipient types. You can retrieve its required fields using:

```php
$fields = $requirements->fields('iban');

foreach ($fields as $field) {
    echo $field->key;
    echo $field->name;
    echo $field->type;
    echo $field->required;
}
```

Each field provides information about the bank details Wise expects, including its name, input type, whether it is required, and any allowed values.

Use this information to build a form for collecting the recipient's bank details. The field's `key` is the name to use when submitting its value under `details`.

### Creating the recipient

Once you've collected the required bank details, you can create a recipient through the Wise API:

```php
$recipient = Wise::profile()->recipients()->create([
    'currency' => 'EUR',
    'type' => 'iban',
    'accountHolderName' => 'John Doe',
    'details' => [
        'IBAN' => 'DE89370400440532013000',
    ],
]);
```

The `currency`, `type`, `accountHolderName`, and `details` values should match the requirements returned by Wise. The example above uses an IBAN recipient; use a recipient type and field names supported by the requirements for your payment route.

Owise returns a `Recipient` object containing the details of the newly created recipient.

You can retrieve it later using its ID:

```php
$recipient = Wise::profile()->recipients()->find($recipient->id);
```

### Refreshing recipient requirements

Some recipient fields can affect the information Wise requires. When a field has `refreshRequirementsOnChange` set to `true`, submit the current recipient details to retrieve the updated requirements:

```php
$requirements = Wise::profile()->recipients()->refreshRequirements(
    quoteId: $quote->id,
    payload: [
        'type' => 'iban',
        'details' => [
            'IBAN' => 'DE89370400440532013000',
        ],
    ],
);
```

This is useful when building dynamic recipient forms, where the fields required by Wise may change based on the information entered by the user.


## Associating recipients with users

If you're building an application where users send money to different recipients, Owise provides the `Payable` trait to manage recipient ownership through Eloquent.

Add the trait to your model:

```php
use Illuminate\Foundation\Auth\User as Authenticatable;
use Owise\Concerns\Payable;

class User extends Authenticatable
{
    use Payable;
}
```
> **Note:** Make sure you have run `php artisan migrate` before using the `Payable` trait. Owise uses your application's database to persist Wise recipients and their associations with your Eloquent models.

Once added, you can create recipients directly through the user model. Owise handles creating the recipient with Wise and storing its association with the user.

```php
$recipient = $user->addWiseRecipient(
    currency: 'EUR',
    type: 'iban',
    details: [
        'IBAN' => 'DE89370400440532013000',
    ],
    default: true,
);
```

The recipient is now associated with the user and can be retrieved through the relationship:

```php
$recipients = $user->wiseRecipients;

$recipient = $user->defaultWiseRecipient('EUR');
```

You can also check whether a user has an active recipient for a particular currency:

```php
if ($user->hasWiseRecipient('EUR')) {
    // The user has an active EUR recipient.
}
```

Owise stores the Wise recipient ID and relevant recipient information locally. Bank account details are not stored in your application's database.

### Using an existing recipient

If a recipient already exists in your Wise account, you can associate it with a user using its Wise recipient ID:

```php
$recipient = $user->attachWiseRecipient($recipientId);
```

Owise verifies that the recipient belongs to the configured Wise profile before associating it with the user.

This allows you to manage recipients through your application's Eloquent models without having to manually maintain the relationship yourself.

## Sending a payout

Once a recipient is associated with a payable model, you can use the `payout()` method to send money to them.

The payout builder handles the steps involved in sending a transfer: creating a quote, checking transfer requirements, creating the transfer, and funding it.

```php
use Brick\Money\Money;

$transfer = $user->payout(Money::of('100.00', 'EUR'))
    ->from('GBP')
    ->to($recipient)
    ->reference('Invoice 42')
    ->idempotencyKey('invoice-42')
    ->fund()
    ->send();
```

In this example, `100.00 EUR` is the target amount, and the funds will be sent from the user's GBP balance. The `to()` method accepts either a `WiseRecipient` model or its local ID.

Owise creates the quote and transfer, checks the requirements returned by Wise, and funds the transfer using the configured Wise profile balance. If the transfer requires additional details, the workflow will report which fields are missing so you can provide them.

### Choosing the amount

By default, the amount passed to `payout()` is the amount the recipient should receive. You can instead specify the amount to send from your source currency using `amountIsSource()`:

```php
$transfer = $user->payout(Money::of('100.00', 'GBP'))
    ->from('GBP')
    ->to($recipient)
    ->amountIsSource()
    ->idempotencyKey('invoice-43')
    ->fund()
    ->send();
```

You can also configure a default source currency in `config/wise.php`. If no source currency is configured, pass one using `from()`.

### Previewing a payout

If you want to see the quote before sending the payout, use `preview()`:

```php
$quote = $user->payout(Money::of('100.00', 'EUR'))
    ->from('GBP')
    ->to($recipient)
    ->preview();
```

This returns a quote from Wise without creating or saving a transfer. The quote is not reused when you later call `send()`; the payout workflow creates its own quote.

### Manual funding

By default, the payout uses the funding mode configured for the Wise profile. You can override it with `fund()`.

For API funding, call `fund()` or `fund(true)`. To create the transfer without funding it through the API, use `fund(false)`:

```php
$transfer = $user->payout(Money::of('100.00', 'EUR'))
    ->from('GBP')
    ->to($recipient)
    ->idempotencyKey('invoice-44')
    ->fund(false)
    ->send();
```

With manual funding, Owise creates the transfer and leaves it awaiting funding. You can then handle the funding process separately in Wise.

### Idempotency

Every payout sent with `send()` or `sendLater()` must have an idempotency key. This protects against accidentally creating duplicate payouts when a request is retried.

Use the same key when retrying or resuming the same payout. Use a new key when starting a different payout.

### Tracking payouts

Each payout is stored locally as a `WiseTransfer` model and associated with the payable:

```php
$transfers = $user->wiseTransfers;

$transfer = $user->wiseTransfers()->latest()->first();
```

You can use the model's helper methods to check its state:

```php
$transfer->isPending();
$transfer->isCompleted();
$transfer->isFailed();
$transfer->awaitingFunding();
```

The local transfer record also exposes the source amount, target amount, fee, and reference:

```php
$transfer->sourceAmount();
$transfer->targetAmount();
$transfer->fee();
$transfer->reference;
```

### Sending payouts in the background

For applications where you don't want the payout workflow to run during the current request, use `sendLater()` instead of `send()`:

```php
$transfer = $user->payout(Money::of('100.00', 'EUR'))
    ->from('GBP')
    ->to($recipient)
    ->idempotencyKey('invoice-45')
    ->fund()
    ->sendLater();
```

This persists the payout draft and dispatches the workflow to Laravel's queue. Make sure your queue worker is running to process it.

You can optionally specify the queue:

```php
->sendLater('payouts');
```

## Application events

Owise dispatches Laravel events when relevant actions occur or when Wise sends a webhook. You can listen for these events using Laravel's event listeners.

Events are enabled by default. To disable application event dispatching, update `config/wise.php`:

```php
'events' => [
    'application' => [
        'enabled' => false,
    ],
],
```

Disabling application events does not stop webhook processing or local transfer updates.

### Available events

Owise provides events for the following operations:

| Area | Events |
|---|---|
| Webhooks | `WebhookReceived`, `WebhookHandled` |
| Transfers | `TransferCreated`, `TransferFunded`, `TransferAwaitingFunding`, `TransferStatusUpdated`, `TransferCompleted`, `TransferFailed`, `TransferReturned`, `TransferNeedsAttention` |
| Transfer webhooks | `TransferStateChanged`, `TransferActiveCases`, `TransferPayoutFailure`, `TransferRefunded` |
| Recipients | `RecipientCreated`, `RecipientRemoved` |
| Balances | `BalanceCredited`, `BalanceUpdated`, `BalanceConverted` |
| Profiles | `ProfileVerificationStateChanged` |
| OAuth | `WiseAuthorizationRevoked` |

For example, you can listen for a completed transfer using a standard Laravel event listener:

```php
namespace App\Listeners;

use Owise\Events\TransferCompleted;

class HandleCompletedTransfer
{
    public function handle(TransferCompleted $event): void
    {
        $transfer = $event->transfer;

        // Update your application after the transfer completes.
    }
}
```

Register the listener through Laravel's event discovery or your application's event service provider.

You can use these events to update application records, notify users, or trigger other parts of your application when a Wise operation changes.

## Authentication

Owise supports personal API tokens and partner OAuth credentials. The authentication method is configured globally and applies to all configured Wise profiles.

### Personal API token

Personal tokens are used by default. Add your token to the `.env` file:

```dotenv
WISE_AUTH=personal_token
WISE_API_TOKEN=your-wise-api-token
```

This authentication method is intended for Wise endpoints available to personal-token and SMB integrations.

If your integration requires Strong Customer Authentication (SCA), configure your private key under `wise.sca` in `config/wise.php`.

### Partner OAuth

Owise supports Wise's partner OAuth registration-code flow for provisioning and managing authorizations.

Configure your partner credentials and mTLS certificates:

```dotenv
WISE_AUTH=oauth
WISE_CLIENT_ID=your-client-id
WISE_CLIENT_SECRET=your-client-secret
WISE_MTLS_ENABLED=true
WISE_MTLS_CERTIFICATE=/secure/client.crt
WISE_MTLS_PRIVATE_KEY=/secure/client.key
```

After running your migrations, import an authorization using the registration code created through Wise:

```php
$authorization = Wise::oauth()->useRegistrationCode(
    $email,
    $registrationCode,
);
```

If the stored authorization needs to be recovered after a token failure, use:

```php
$authorization = Wise::oauth()->recover();
```

Owise does not implement Wise's browser-based authorization-code flow.

### Client credentials

Owise uses client credentials automatically for application-level webhook operations.

After configuring your partner credentials and mTLS, you can manage application webhooks using:

```php
Wise::applicationWebhooks();
```

You can also use the `wise:webhooks --application` command to manage application-level webhook subscriptions.

## Webhooks

Owise provides a webhook endpoint for receiving notifications from Wise. It verifies incoming signatures, queues valid notifications for processing, and dispatches Laravel events.

### Enabling the webhook route

Enable the package route in `config/wise.php`:

```php
'routes' => [
    'enabled' => true,
    'prefix' => 'wise',
],
```

The endpoint is available at:

```text
POST /wise/webhook
```

If you change the prefix, the endpoint follows it. Owise registers the route outside Laravel's `web` middleware group, so you do not need to add a CSRF exemption.

### Signature verification

Webhook signature verification is enabled by default. Owise verifies the `X-Signature-SHA256` header against the original request body using Wise's public key for the configured environment.

You can provide your own public keys in `config/wise.php`:

```php
'webhooks' => [
    'public_keys' => [
        // PEM-encoded public keys
    ],
],
```

This is useful if you need to support key rotation. When no custom keys are configured, Owise uses its bundled Wise public key for the selected environment.

Verification fails closed: requests with missing or invalid signatures are rejected. You can disable verification for local development, but Owise will not allow it to be disabled in production.

### Processing webhooks

When a valid notification arrives, Owise:

1. Validates the payload.
2. Dispatches `ProcessWiseWebhook` to the queue.
3. Fires the `WebhookReceived` event.
4. Processes the notification using the handler configured for its `event_type`.
5. Fires `WebhookHandled` after processing.

The job retries failed processing attempts with backoff. You can configure its queue and connection in `config/wise.php`:

```php
'webhooks' => [
    'queue' => 'webhooks',
    'connection' => null,
],
```

Owise normalizes supported Wise webhook payload versions into an `Owise\Webhooks\Payload` object. Use its `occurredAt` value when reconciling resource state rather than relying on delivery order.

### Managing webhook subscriptions

Use the `wise:webhooks` Artisan command to list, create, and delete subscriptions.

List subscriptions for the configured profile:

```bash
php artisan wise:webhooks
```

Create subscriptions for the event types configured in `wise.webhooks.events`:

```bash
php artisan wise:webhooks --create --url=https://example.com/wise/webhook
```

Delete a subscription by its ID:

```bash
php artisan wise:webhooks --delete=SUBSCRIPTION_ID
```

You can also manage application-level subscriptions by passing `--application`:

```bash
php artisan wise:webhooks --application
php artisan wise:webhooks --application --create --url=https://example.com/wise/webhook
php artisan wise:webhooks --application --delete=SUBSCRIPTION_ID
```

Application-level operations use client credentials and require the application key to be configured as `wise.client_key`.

### Mutual TLS delivery

You can request mutual TLS (mTLS) delivery when creating a subscription by passing `--mtls`:

```bash
php artisan wise:webhooks --application --create --url=https://example.com/wise/webhook --mtls
```

To explicitly disable mTLS for a subscription, use `--no-mtls`. You can also set `wise.webhooks.mtls_enabled` in the package configuration. Command-line options take precedence over the configuration value.

Your endpoint must be configured to terminate mTLS and present a trusted client certificate. Confirm that Wise accepts the setting for the subscription scope you're using.

### Testing application webhooks

Wise test notifications are available for application-level subscriptions. Send one using the subscription ID:

```bash
php artisan wise:webhooks --application --test=SUBSCRIPTION_ID
```

Test notifications are acknowledged and queued, but Owise skips the normal domain handlers for them.

## Errors

Owise maps errors from the Wise SDK into its own exception classes. This gives you a consistent way to handle API failures without depending directly on the SDK's exception types.

### Handling API errors

Catch `WiseApiError` to handle general Wise API failures:

```php
use Owise\Exceptions\WiseApiError;

try {
    $transfer = Wise::transfers()->create(
        targetAccount: $recipientId,
        quoteUuid: $quoteId,
        customerTransactionId: $transactionId,
        details: ['reference' => 'Invoice 42'],
    );
} catch (WiseApiError $e) {
    report($e);

    logger()->warning('Wise API request failed', [
        'status' => $e->status,
        'errors' => $e->errors,
        'trace_id' => $e->traceId,
        'correlation_id' => $e->correlationId,
    ]);
}
```

`WiseApiError` exposes the HTTP status, parsed Wise errors, trace ID, correlation ID, and raw error response. You can also check whether a particular Wise error code is present:

```php
if ($e->hasErrorCode('some.error.code')) {
    // Handle this specific Wise error.
}
```

### Specific exceptions

Owise provides specific exceptions for common failure cases:

| Exception | When it occurs |
|---|---|
| `AuthenticationFailed` | Wise rejects the credentials. |
| `PermissionDenied` | The credentials or profile do not have permission to perform the operation. |
| `RateLimited` | Wise returns a rate-limit response. |
| `ConnectionFailed` | The request cannot reach Wise. |
| `StrongCustomerAuthenticationRequired` | Wise requires an SCA challenge for the request. |
| `InvalidRecipientDetails` | Recipient details are invalid. |
| `TransferCannotBeCancelled` | Wise does not allow the transfer to be cancelled in its current state. |
| `TransferFundingRejected` | Wise rejects a funding request. |
| `TransferFundingOutcomeUnknown` | Owise cannot determine whether an ambiguous funding request succeeded. |
| `TransferRequirementsMissing` | Required transfer details have not been supplied. |
| `IdempotencyConflict` | An existing idempotency key is reused with different payout details. |
| `MissingProfile` | The requested Wise profile is not configured. |
| `ConnectionRevoked` | The stored Wise authorization has been revoked. |

Specific exceptions can be caught before their parent exception:

```php
use Owise\Exceptions\RateLimited;
use Owise\Exceptions\WiseApiError;

try {
    $balances = Wise::balances()->list();
} catch (RateLimited $e) {
    $retryAfter = $e->retryAfter;
    // Handle the rate limit.
} catch (WiseApiError $e) {
    // Handle other Wise API errors.
}
```

For connection failures, catch `ConnectionFailed` separately. It represents a transport failure rather than an HTTP error returned by Wise.

When investigating a failed request, use the Wise trace ID or correlation ID when contacting Wise support. Avoid logging access tokens, private keys, or full recipient payloads containing bank details.

## Testing

Owise provides testing utilities for applications using Pest or PHPUnit. These allow you to test your Wise integration without making real requests to the Wise API.

### Faking Wise requests

Use `Wise::fake()` in your tests to intercept requests to Wise and return predefined responses:

```php
use Owise\Facades\Wise;

it('retrieves a Wise profile', function () {
    $fake = Wise::fake();

    $profile = Wise::profile()->profiles()->find(101);

    expect($profile->id)->toBe(101);

    $fake->assertSent(
        Endpoints::FIND_PROFILE,
    );
});
```

Owise provides default responses for its public API operations, so you can test your application without having to configure responses manually.

### Asserting API requests

The fake records requests made to Wise and provides assertions for checking which operations were performed.

For example, when testing a payout:

```php
$fake->assertQuoteCreated();
$fake->assertTransferCreated();
$fake->assertTransferFunded();
```

You can also inspect request payloads:

```php
$fake->assertTransferCreated(
    fn (?array $body): bool =>
        $body['targetAccount'] === $recipient->wise_recipient_id,
);
```

To assert how many times an endpoint was called, use `assertSentCount()`:

```php
use Owise\Testing\Endpoints;

$fake->assertSentCount(Endpoints::CREATE_TRANSFER, 1);
```

To ensure no Wise requests were made:

```php
$fake->assertNothingSent();
```

### Testing webhooks

Owise includes helpers for testing webhook notifications through your application's actual webhook endpoint.

Add the `InteractsWithWiseWebhooks` trait to your Pest test file:

```php
use Owise\Testing\InteractsWithWiseWebhooks;

uses(InteractsWithWiseWebhooks::class);
```

Use `WebhookFactory` to generate a notification:

```php
use Owise\Testing\WebhookFactory;

$payload = WebhookFactory::transferStateChanged(
    transferId: $transfer->wise_transfer_id,
    from: 'processing',
    to: 'outgoing_payment_sent',
);
```

Send the notification through your webhook route:

```php
$this->postWiseWebhook($payload)
    ->assertOk();
```

`postWiseWebhook()` signs the payload using Owise's test key pair, allowing the real webhook signature verification middleware to run during your test.

You can also generate balance notifications using `WebhookFactory::balanceCredited()` and `WebhookFactory::balanceUpdated()`.


## API reference

Owise exposes the Wise API through a manager and a set of API groups. You can use the default configured profile or select a specific profile when working with multiple Wise accounts.

### Manager and profiles

The `Wise` facade provides access to the package's main entry points:

```php
use Owise\Facades\Wise;

// Use the default configured profile.
$profiles = Wise::profiles()->list();

// Select a specific profile.
$profile = Wise::profile('business');

$profileId = $profile->profileId();

// Access an API through the selected profile.
$balances = $profile->balances()->list();
```

The manager also provides access to application-level webhooks and OAuth:

```php
Wise::applicationWebhooks();

Wise::oauth();
```

### Available API groups

Each profile exposes the following API groups:

| API group | Available operations |
|---|---|
| `profiles()` | `list`, `find` |
| `quotes()` | `create`, `find`, `update` |
| `recipients()` | `requirements`, `refreshRequirements`, `create`, `find`, `list`, `delete` |
| `transfers()` | `requirements`, `create`, `find`, `list`, `cancel`, `fund`, `payments` |
| `balances()` | `list`, `find`, `create`, `close`, `capacity`, `move`, `convert`, `statement` |
| `statements()` | `get` |
| `webhooks()` | `create`, `list`, `find`, `delete` |
| `simulate()` | `transfer`, `transferState`, `balanceTopUp` (sandbox only) |

Application-level webhook operations are available separately through `Wise::applicationWebhooks()`.

### Data objects

Owise returns typed data objects from its API methods. These objects live in the `Owise\Data` namespace and provide access to the fields returned by Wise.

They also support `toArray()` and JSON serialization:

```php
$quote = Wise::quotes()->find($quoteId);

$quote->id;
$quote->sourceCurrency;
$quote->targetCurrency;

$data = $quote->toArray();
```

Optional fields may be `null`, depending on the response from Wise.

Where Wise returns a valid amount and currency, Owise represents the value using `Brick\Money\Money`. Use the currency attached to the returned amount rather than assuming it matches the currency of the overall quote, particularly when working with payment options and balance movements.

Some data objects also provide helper methods:

- `Quote::paymentOption()`
- `RequirementsForm::type()`
- `RequirementsForm::fields()`
- `RequirementsForm::rules()`
- `RequirementsForm::needsRefresh()`
- `RecipientPage::hasMore()`
- `FundingResult::isCompleted()`
- `FundingResult::isRejected()`

### Underlying SDK

If you need an operation that Owise does not expose directly, you can access the underlying Wise SDK client through the selected profile:

```php
$client = Wise::profile()->sdk();
```

Calls made through the SDK bypass Owise's data objects and exception mapping. Use the Owise API groups where possible to keep your application consistent with the package's conventions.


## License

Owise is open-sourced software licensed under the [MIT license](LICENSE).
