<?php

declare(strict_types=1);

$root = dirname(__DIR__);

/** @var list<string> */
$scan_paths = [
    'src',
    'tests',
    'composer.json',
    'phpunit.xml.dist',
    'phpstan.neon.dist',
    'README.md',
    'ARCHITECTURE.md',
];

/** @var list<string> */
$forbidden_tokens = [
    'Logger_Interface',
    'CaBundle',
    'CaBundleTest',
    'getSystemCaRootBundlePath',
    'getBundledCaBundlePath',
    'validateCaFile',
    'isOpensslParseSafe',
    'getEnvVariable',
    'caFileUsable',
    'caDirUsable',
    'Composer\\CaBundle\\',
    'namespace Composer\\CaBundle',
    'testCaPath',
    'testCaPathNotNull',
    'testCertDir',
    'testCertFile',
    'testSslCaFile',
    'testSslCaPath',
    'testValidateCaFile',
    'testValidateTrustedCaFile',
    'testOpenBaseDir',
    'resetEnv',
    'setEnv',
    'get_bundle_path',
    'is_valid_ca_file',
];

/** @var list<string> */
$external_allowlist = [
    'LoggerInterface',
    'TestCase',
    'assertFileExists',
    'assertTrue',
    'RuntimeException',
    'PHPUnit',
    'Framework',
    'Stringable',
    'SSL_CERT_DIR',
    'SSL_CERT_FILE',
];

$failures = [];

foreach ($scan_paths as $relative) {
    $path = $root . '/' . $relative;
    if (is_dir($path)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            if ($file->getExtension() !== 'php' && $file->getExtension() !== 'dist' && $file->getExtension() !== 'json' && $file->getExtension() !== 'md' && $file->getExtension() !== 'neon') {
                if (!preg_match('/\.(php|dist|json|md|neon)$/i', $file->getFilename())) {
                    continue;
                }
            }
            if (strpos($file->getPathname(), '/Fixtures/') !== false) {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            if ($contents === false) {
                continue;
            }
            scan_contents($file->getPathname(), $contents, $forbidden_tokens, $external_allowlist, $failures);
        }
        continue;
    }

    if (!is_readable($path)) {
        continue;
    }
    $contents = file_get_contents($path);
    if ($contents === false) {
        continue;
    }
    scan_contents($path, $contents, $forbidden_tokens, $external_allowlist, $failures);
}

/**
 * @param list<string> $forbidden_tokens
 * @param list<string> $external_allowlist
 * @param list<string> $failures
 */
function scan_contents(
    string $file,
    string $contents,
    array $forbidden_tokens,
    array $external_allowlist,
    array &$failures
): void {
    foreach ($forbidden_tokens as $token) {
        if (strpos($contents, $token) !== false) {
            $failures[] = sprintf('%s: forbidden token %s', $file, $token);
        }
    }

    if (preg_match_all('/\bfunction\s+([a-z][a-zA-Z0-9_]*)\s*\(/', $contents, $matches)) {
        foreach ($matches[1] as $method) {
            if (in_array($method, ['__construct', '__destruct', '__invoke'], true)) {
                continue;
            }
            if (preg_match('/[A-Z]/', substr($method, 1)) && !in_array($method, $external_allowlist, true)) {
                $failures[] = sprintf('%s: repo-owned camelCase method %s', $file, $method);
            }
        }
    }

    if (preg_match_all('/\$(caBundle|caPath|certDir|certFile|sslCert|openCa|validResult|envString|oldValue|certPath|certFilePath)\b/', $contents, $var_matches)) {
        foreach ($var_matches[1] as $var) {
            $failures[] = sprintf('%s: repo-owned camelCase variable $%s', $file, $var);
        }
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, $failure . "\n");
    }
    exit(1);
}

fwrite(STDOUT, "rename-check: no stale repo-owned names.\n");
exit(0);
