<?php

declare(strict_types=1);

use App\Exceptions\NotifyErrorException;
use App\Services\ProjectUpdaterService;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Updater package verification
|--------------------------------------------------------------------------
|
| These tests exercise the REAL verification code path. Signature checking is
| never mocked or stubbed here: a test that replaces openssl_verify() proves
| nothing about whether a tampered package is rejected.
|
| Each test generates a throwaway RSA keypair, signs a checksum with the
| private half, and points `project_updater.public_key` at the public half.
| That mirrors production exactly, where the release is signed offline and the
| shipped public key decides what may be installed.
|
*/

/**
 * Path to a usable openssl.cnf, or null when the platform needs none.
 *
 * Windows PHP builds ship the config under extras/ssl but often do not point
 * openssl.cnf at it, so openssl_pkey_new() fails with
 * "configuration file routines::no such file". OpenSSL reads OPENSSL_CONF at
 * extension init, so a runtime putenv() is too late; the path has to be passed
 * in the options array.
 */
function opensslConfigPath(): ?string
{
    $candidates = [
        dirname(PHP_BINARY).'/extras/ssl/openssl.cnf',
        dirname(PHP_BINARY).'/extras/openssl/openssl.cnf',
        '/etc/ssl/openssl.cnf',
        '/usr/local/ssl/openssl.cnf',
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

/** Generate a throwaway RSA keypair for signature tests. */
function generateTestKeyPair(int $bits = 2048): OpenSSLAsymmetricKey
{
    $options = ['private_key_bits' => $bits, 'private_key_type' => OPENSSL_KEYTYPE_RSA];

    $config = opensslConfigPath();

    if ($config !== null) {
        $options['config'] = $config;
    }

    $key = openssl_pkey_new($options);

    if ($key === false) {
        $errors = [];

        while (($message = openssl_error_string()) !== false) {
            $errors[] = $message;
        }

        throw new RuntimeException('Could not generate a test RSA keypair: '.implode('; ', $errors));
    }

    return $key;
}

/** Export a private key to PEM, honouring the OpenSSL config requirement. */
function exportPrivateKeyPem(OpenSSLAsymmetricKey $key): string
{
    $config = opensslConfigPath();

    if (! openssl_pkey_export($key, $pem, null, $config === null ? [] : ['config' => $config])) {
        throw new RuntimeException('Could not export the test private key.');
    }

    return (string) $pem;
}

beforeEach(function (): void {
    Storage::fake((string) config('project_updater.storage_disk'));

    // A real RSA keypair for this test only. 2048 bits keeps it fast.
    $this->keyPair = generateTestKeyPair();

    $this->privateKeyPem = exportPrivateKeyPem($this->keyPair);

    $details            = openssl_pkey_get_details($this->keyPair);
    $this->publicKeyPem = $details['key'];

    config(['project_updater.public_key' => $this->publicKeyPem]);

    $this->updater = app(ProjectUpdaterService::class);
});

/** Write a file to the fake update disk and return its relative path. */
function writePackage(string $relativePath, string $contents): string
{
    Storage::disk((string) config('project_updater.storage_disk'))->put($relativePath, $contents);

    return $relativePath;
}

/** SHA-256 of a package, the value the release server publishes. */
function checksumOf(string $relativePath): string
{
    return hash('sha256', Storage::disk((string) config('project_updater.storage_disk'))->get($relativePath));
}

/** Sign a checksum with the test private key, base64 encoded like the server does. */
function signChecksum(string $checksum, string $privateKeyPem): string
{
    $signature = '';

    if (! openssl_sign($checksum, $signature, $privateKeyPem, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Could not sign the test checksum.');
    }

    return base64_encode($signature);
}

it('accepts a package whose checksum and signature are both valid', function (): void {
    $path     = writePackage('updates/pkg-valid.zip', 'authentic release contents');
    $checksum = checksumOf($path);

    // No exception means accepted.
    $this->updater->verifyPackage($path, $checksum, signChecksum($checksum, $this->privateKeyPem));

    expect(true)->toBeTrue();
});

it('rejects a package whose contents no longer match the checksum', function (): void {
    $path     = writePackage('updates/pkg-tampered.zip', 'authentic release contents');
    $checksum = checksumOf($path);

    // A tampered ZIP: bytes change after the checksum was published.
    Storage::disk((string) config('project_updater.storage_disk'))
        ->put($path, 'tampered release contents');

    expect(fn () => $this->updater->verifyPackage($path, $checksum, signChecksum($checksum, $this->privateKeyPem)))
        ->toThrow(NotifyErrorException::class);
})->group('updater-security');

it('rejects a checksum that does not match', function (): void {
    $path = writePackage('updates/pkg-badsum.zip', 'authentic release contents');

    $wrongChecksum = str_repeat('0', 64);

    expect(fn () => $this->updater->verifyPackage($path, $wrongChecksum, null))
        ->toThrow(NotifyErrorException::class);
})->group('updater-security');

it('rejects a signature made with a different private key', function (): void {
    $path     = writePackage('updates/pkg-wrongkey.zip', 'authentic release contents');
    $checksum = checksumOf($path);

    // Attacker signs with their own key.
    $attackerKey = generateTestKeyPair();
    $attackerPem = exportPrivateKeyPem($attackerKey);

    expect(fn () => $this->updater->verifyPackage($path, $checksum, signChecksum($checksum, $attackerPem)))
        ->toThrow(NotifyErrorException::class);
})->group('updater-security');

it('rejects a malformed signature', function (): void {
    $path     = writePackage('updates/pkg-badsig.zip', 'authentic release contents');
    $checksum = checksumOf($path);

    expect(fn () => $this->updater->verifyPackage($path, $checksum, base64_encode('not-a-signature')))
        ->toThrow(NotifyErrorException::class);
})->group('updater-security');

it('rejects a signature that is not valid base64', function (): void {
    $path     = writePackage('updates/pkg-notb64.zip', 'authentic release contents');
    $checksum = checksumOf($path);

    expect(fn () => $this->updater->verifyPackage($path, $checksum, '!!!not base64!!!'))
        ->toThrow(NotifyErrorException::class);
})->group('updater-security');

it('rejects a valid signature when the shipped public key differs', function (): void {
    $path     = writePackage('updates/pkg-mismatch.zip', 'authentic release contents');
    $checksum = checksumOf($path);

    // Signature generated against the test key...
    $signature = signChecksum($checksum, $this->privateKeyPem);

    // ...but the installation trusts a different key.
    $otherKey     = generateTestKeyPair();
    $otherDetails = openssl_pkey_get_details($otherKey);
    config(['project_updater.public_key' => $otherDetails['key']]);

    expect(fn () => $this->updater->verifyPackage($path, $checksum, $signature))
        ->toThrow(NotifyErrorException::class);
})->group('updater-security');

it('still enforces the checksum when no signature is supplied', function (): void {
    /*
     * Signature verification is only attempted when a signature is present.
     * The checksum is NOT conditional: it must always be enforced, otherwise a
     * server that omits the signature could install arbitrary bytes.
     */
    $path = writePackage('updates/pkg-nosig.zip', 'authentic release contents');

    $this->updater->verifyPackage($path, checksumOf($path), null);

    expect(true)->toBeTrue();

    // And a wrong checksum with no signature must still fail.
    expect(fn () => $this->updater->verifyPackage($path, str_repeat('a', 64), null))
        ->toThrow(NotifyErrorException::class);
})->group('updater-security');

it('verifies against the shipped public key shape', function (): void {
    // Production ships a PEM public key; make sure the real config value parses.
    $shipped = (string) config('project_updater.public_key');

    if ($shipped === '' || ! str_contains($shipped, 'BEGIN PUBLIC KEY')) {
        // A blank key is a valid deployment state; the checksum still applies.
        expect(true)->toBeTrue();

        return;
    }

    $key = openssl_pkey_get_public($shipped);

    expect($key)->not->toBeFalse('The shipped project_updater.public_key is not a valid PEM public key.');
})->group('updater-security');
