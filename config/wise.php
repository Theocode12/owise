<?php

use Owise\Models\WiseRecipient;
use Owise\Models\WiseTransfer;
use Owise\Webhooks\Handlers\BalanceCredit;
use Owise\Webhooks\Handlers\BalanceUpdate;
use Owise\Webhooks\Handlers\ProfileVerificationStateChanged;
use Owise\Webhooks\Handlers\TransferActiveCases;
use Owise\Webhooks\Handlers\TransferPayoutFailure;
use Owise\Webhooks\Handlers\TransferRefund;
use Owise\Webhooks\Handlers\TransferStateChanged;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Profile
    |--------------------------------------------------------------------------
    |
    | The configured Wise profile used when none is specified. Pass a
    | different name to Wise::profile('name') to select another profile.
    |
    */

    'default_profile' => env('WISE_DEFAULT_PROFILE', 'default'),

    'environment' => env('WISE_ENVIRONMENT', 'sandbox'),

    // Wise's global URL API version; this release explicitly verifies 2026Q4.
    'api_version' => env('WISE_API_VERSION', '2026Q4'),

    /*
    | Owise profile token providers (not an exhaustive list of Wise auth methods):
    | - personal_token: a Wise personal API token for supported SMB endpoints.
    | - oauth: a stored OAuth user token; this package implements the
    |   registration-code flow, not the browser authorization-code flow.
    | - client_credentials: an application-level token. Use it for profile
    |   requests only when Wise allows it for your integration model (for
    |   example, the Embedded model).
    | Custom providers can be registered with WiseManager::extendAuth().
    | All configured profiles share this provider. OAuth/mTLS requirements and
    | endpoint access depend on your Wise integration agreement.
    */
    'auth' => env('WISE_AUTH', 'personal_token'),

    'token' => env('WISE_API_TOKEN'),

    'client_key' => env('WISE_CLIENT_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Wise URLs
    |--------------------------------------------------------------------------
    |
    | The Wise API and website base URLs for each environment. These are the
    | defaults used by the SDK adapter; override them to route requests
    | through a proxy or another Wise environment.
    |
    */

    'urls' => [
        'sandbox' => [
            'api_base_url' => 'https://api.wise-sandbox.com',
            'mtls_api_base_url' => 'https://api-mtls.wise-sandbox.com',
            'web_base_url' => 'https://wise-sandbox.com',
        ],
        'production' => [
            'api_base_url' => 'https://api.wise.com',
            'mtls_api_base_url' => 'https://api-mtls.transferwise.com',
            'web_base_url' => 'https://wise.com',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Profiles
    |--------------------------------------------------------------------------
    |
    | Wise profiles keyed by a local name. All profiles share the library-wide
    | environment and authentication. Configure one or more profile IDs here.
    |
    */

    'profiles' => [
        'default' => [
            'profile_id' => env('WISE_PROFILE_ID'),
            'funding' => env('WISE_FUNDING', 'manual'),
        ],
    ],

    'models' => [
        'recipient' => WiseRecipient::class,
        'transfer' => WiseTransfer::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Application Events
    |--------------------------------------------------------------------------
    |
    | Owise application events are enabled by default. Disabling them suppresses
    | only events published for host-application listeners; internal webhook
    | processing and Laravel notifications are unaffected.
    |
    */

    'events' => [
        'application' => [
            'enabled' => (bool) env('WISE_EVENTS_APPLICATION_ENABLED', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Payouts
    |--------------------------------------------------------------------------
    |
    | Defaults for the payout (quote → transfer → fund) workflow. The source
    | currency is the currency you fund payouts from; set it globally here or
    | per payout with `->from()`. Reconciliation windows are operational
    | safeguards, not guarantees from Wise.
    |
    */

    'payouts' => [
        'source_currency' => env('WISE_PAYOUTS_SOURCE_CURRENCY'),

        // Recently sent transfers are reconciled for this many days because Wise
        // documents that bounce-backs may arrive several weeks after sending.
        'sent_reconciliation_days' => (int) env('WISE_PAYOUTS_SENT_RECONCILIATION_DAYS', 60),

        // Import transfers created outside the package when a webhook arrives for
        // an unknown transfer id.
        'import_unknown' => (bool) env('WISE_PAYOUTS_IMPORT_UNKNOWN', false),

        // How long a draft, quote or pending API funding must be stale before
        // `wise:transfers:sync` resumes it. Keeps a fresh row from being resumed
        // while its HTTP call may still be in flight.
        'resume_after_minutes' => (int) env('WISE_PAYOUTS_RESUME_AFTER_MINUTES', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | OAuth
    |--------------------------------------------------------------------------
    |
    | Partner application credentials and registration-code token behaviour.
    | The caller explicitly provisions a Wise user; credentials are shared by
    | all configured profiles. Keep client secrets in deployment secrets.
    |
    */

    'oauth' => [
        'client_id' => env('WISE_CLIENT_ID'),
        'client_secret' => env('WISE_CLIENT_SECRET'),
        'refresh_before_seconds' => 6 * 3600,
        'client_credentials_refresh_before_seconds' => 300,
        'mtls' => [
            'enabled' => (bool) env('WISE_MTLS_ENABLED', false),
            'certificate' => env('WISE_MTLS_CERTIFICATE'),
            'private_key' => env('WISE_MTLS_PRIVATE_KEY'),
            'passphrase' => env('WISE_MTLS_PASSPHRASE'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | Incoming webhook endpoint, signature verification and event processing
    | options. Ingestion is stateless: Owise verifies the RSA-SHA256
    | signature, dispatches a queued job and fires Laravel events, without
    | persisting deliveries.
    |
    */

    'webhooks' => [

        // PEM public keys used to verify signatures. Set to an array of PEM
        // strings to override the shipped keys or to support key rotation.
        'public_keys' => null,

        // Verify the X-Signature-SHA256 header (fail closed). Can only be
        // disabled outside production.
        'verify' => true,

        // Queue / connection used to process deliveries. Null uses Laravel's
        // default queue connection and queue.
        'queue' => null,
        'connection' => null,

        // Schema version sent when creating subscriptions.
        'schema_version' => '4.0.0',

        // Request mTLS delivery for new subscriptions. Requires an
        // mTLS-terminating endpoint; confirm Wise accepts it for your scope.
        'mtls_enabled' => env('WISE_WEBHOOK_MTLS_ENABLED'),

        // Name used to label subscriptions created by wise:webhooks.
        'name' => 'Owise',

        // Event types subscribed to with wise:webhooks --create.
        'events' => [
            'transfers#state-change',
            'transfers#active-cases',
            'transfers#payout-failure',
            'transfers#refund',
            'balances#credit',
            'balances#update',
            'profiles#verification-state-change',
        ],

        // Maps an event type to the handler that turns it into a typed
        // domain event. Add or replace handlers here.
        'handlers' => [
            'transfers#state-change' => TransferStateChanged::class,
            'transfers#active-cases' => TransferActiveCases::class,
            'transfers#payout-failure' => TransferPayoutFailure::class,
            'transfers#refund' => TransferRefund::class,
            'balances#credit' => BalanceCredit::class,
            'balances#update' => BalanceUpdate::class,
            'profiles#verification-state-change' => ProfileVerificationStateChanged::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Strong Customer Authentication
    |--------------------------------------------------------------------------
    |
    | One-time-token signing for UK/EEA personal tokens that require SCA.
    | Provide a private key and optional passphrase; individual profiles may
    | override these with their own "sca_private_key" / "sca_passphrase".
    |
    */

    'sca' => [
        'private_key' => env('WISE_SCA_PRIVATE_KEY'),
        'passphrase' => env('WISE_SCA_PRIVATE_KEY_PASSPHRASE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Client
    |--------------------------------------------------------------------------
    |
    | Options for outgoing requests to the Wise API: timeouts and automatic
    | retries.
    |
    */

    'http' => [
        'timeout' => 30,
        'connect_timeout' => 10,
        'retry' => [
            'enabled' => false,
            'times' => 3,
            'methods' => ['GET'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | The log channel used for request/response logging. Null uses Laravel's
    | default logging channel.
    |
    */

    'logging' => [
        'channel' => env('WISE_LOG_CHANNEL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | The package's HTTP routes are opt-in so that installing Owise does not
    | expose endpoints unexpectedly. Enable them and set the URL prefix.
    |
    */

    'routes' => [
        'enabled' => false,
        'prefix' => 'wise',
    ],

];
