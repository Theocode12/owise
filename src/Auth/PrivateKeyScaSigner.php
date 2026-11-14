<?php

namespace Owise\Auth;

use OpenSSLAsymmetricKey;
use Owise\Exceptions\InvalidConfiguration;
use Owise\Exceptions\WiseException;
use SensitiveParameter;

/**
 * Signs Wise SCA one-time tokens (RSA-SHA256, Base64) with the private key whose public key is uploaded to Wise.
 */
final readonly class PrivateKeyScaSigner
{
    /**
     * The loaded SCA private key.
     */
    private OpenSSLAsymmetricKey $key;

    /**
     * Create a new SCA signer from a PEM string or key file path.
     *
     * @return void
     *
     * @throws InvalidConfiguration
     */
    public function __construct(
        #[SensitiveParameter] string $privateKey,
        #[SensitiveParameter] ?string $passphrase = null,
    ) {
        $pem = str_contains($privateKey, '-----BEGIN') ? $privateKey : $this->readKeyFile($privateKey);
        $key = openssl_pkey_get_private($pem, $passphrase ?? '');

        if ($key === false) {
            throw new InvalidConfiguration('The Wise SCA private key could not be loaded; check the key and passphrase.');
        }

        $this->key = $key;
    }

    /**
     * Sign a one-time token with RSA-SHA256 and encode it.
     *
     *
     * @throws WiseException
     */
    public function sign(string $oneTimeToken): string
    {
        if (! openssl_sign($oneTimeToken, $signature, $this->key, OPENSSL_ALGO_SHA256)) {
            throw new WiseException('Signing the Wise SCA one-time token failed.');
        }

        return base64_encode($signature);
    }

    /**
     * Read a private key from a file path.
     *
     *
     * @throws InvalidConfiguration
     */
    private function readKeyFile(string $path): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidConfiguration("The Wise SCA private key file '{$path}' does not exist or is not readable.");
        }

        return (string) file_get_contents($path);
    }
}
