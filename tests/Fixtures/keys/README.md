# Test keys

`sca-test-private.pem` / `sca-test-public.pem` are a throwaway 2048-bit RSA keypair used only by the SCA signing tests.
They are committed so the tests run on platforms where PHP cannot generate keys without an `openssl.cnf` (e.g. Windows).
They protect nothing; never use them with a real Wise account.
