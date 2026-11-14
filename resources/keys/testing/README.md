# Testing keys

`owise-test-private.pem` / `owise-test-public.pem` are a throwaway 2048-bit RSA keypair used only
by `Owise\Testing\InteractsWithWiseWebhooks` to sign test webhook deliveries.

They are committed so the testing kit works on platforms where PHP cannot generate keys without an
`openssl.cnf` (e.g. Windows). They protect nothing; never use them with a real Wise account.
