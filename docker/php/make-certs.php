<?php

// Writes the TLS test certificates into the given directory, unless they are there already: a CA, a server
// certificate for 127.0.0.1 signed by it, and a second CA that signed nothing, for the tests of a refused peer.
// Generated when the TLS servers first start, so no key is kept in the repository and none expires unnoticed.

declare(strict_types=1);

$directory = $argv[1] ?? '/certs';

// The key is written last, so its presence means every file is there, and a run that stopped midway is repaired.
if (is_file("{$directory}/server-key.pem")) {
    echo "The certificates are in {$directory} already.\n";

    exit(0);
}

// Readable by the redis user the servers run as: these secure only disposable test servers.
$write = static function (string $file, string $contents) use ($directory): void {
    if (file_put_contents("{$directory}/{$file}", $contents) === false || ! chmod("{$directory}/{$file}", 0644)) {
        throw new RuntimeException("Could not write {$directory}/{$file}.");
    }
};

$extensions = tempnam(sys_get_temp_dir(), 'openssl');
file_put_contents($extensions, <<<'CONF'
    [req]
    distinguished_name = dn
    [dn]
    [ca]
    basicConstraints = critical, CA:TRUE
    keyUsage = critical, keyCertSign, cRLSign
    [server]
    basicConstraints = CA:FALSE
    keyUsage = critical, digitalSignature, keyEncipherment
    extendedKeyUsage = serverAuth
    subjectAltName = IP:127.0.0.1
    CONF);

$key = static fn (): OpenSSLAsymmetricKey => openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'])
    ?: throw new RuntimeException('Could not create a key: '.openssl_error_string());

$sign = static function (string $name, OpenSSLAsymmetricKey $key, string $section, ?OpenSSLCertificate $issuer, ?OpenSSLAsymmetricKey $issuerKey) use ($extensions): OpenSSLCertificate {
    $options = ['config' => $extensions, 'digest_alg' => 'sha256', 'x509_extensions' => $section];
    $request = openssl_csr_new(['commonName' => $name], $key, $options);

    return openssl_csr_sign($request, $issuer, $issuerKey ?? $key, 3650, $options, random_int(1, PHP_INT_MAX))
        ?: throw new RuntimeException("Could not sign the certificate for {$name}: ".openssl_error_string());
};

$caKey = $key();
$ca = $sign('laravel-phpredis-sentinel test CA', $caKey, 'ca', null, null);
$otherKey = $key();
$otherCa = $sign('another test CA', $otherKey, 'ca', null, null);
$serverKey = $key();
$server = $sign('127.0.0.1', $serverKey, 'server', $ca, $caKey);

foreach (['ca.pem' => $ca, 'other-ca.pem' => $otherCa, 'server.pem' => $server] as $file => $certificate) {
    openssl_x509_export($certificate, $pem);
    $write($file, $pem);
}

openssl_pkey_export($serverKey, $pem);
$write('server-key.pem', $pem);

unlink($extensions);
echo "The certificates are in {$directory}.\n";
