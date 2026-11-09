<?php

declare(strict_types=1);

/**
 * Verify gap packages (org forks left on Packagist) and pin-demo self-tests.
 */

const INDEX_REFERENCES = [
    'phpunit/phpunit' => [
        'a37d66fabf01af0eedabffe271b70dbe71723001',
        '698cbbc1e75a1ef1968f9cbfec89be056b288d0f',
    ],
    'phpstan/phpstan' => [
        '63f848a4b1dbe2f2cb87acb3ff32596661e45417',
        'ff62edb2f684beab2738a854e9becf425d3e662e',
    ],
    'psr/log' => [
        'bde874f9b44efec5901a6783185c050a3855b0f0',
        'a0759f9365a72d83ba292ff05ad2b029a6a72fcb',
    ],
];

/** @var array<string, array{min: string, max: string}> */
const GAP_VERSION_RANGES = [
    'phpunit/phpunit' => ['min' => '8.5.52', 'max' => '9.0.0'],
    'phpstan/phpstan' => ['min' => '1.10.0', 'max' => '2.0.0'],
    'psr/log' => ['min' => '1.0.0', 'max' => '2.0.0'],
];

/**
 * @param array{reference?: string, aliases?: list<string>} $entry
 */
function check_installed_php_reference(string $name, array $entry, string $want_reference, ?string $want_alias): ?string
{
    $reference = $entry['reference'] ?? null;
    if (!is_string($reference) || strlen($reference) !== 40 || !ctype_xdigit($reference)) {
        return sprintf('check-cluster-pins (installed.php): %s has no valid 40-char reference.', $name);
    }
    if (!hash_equals(strtolower($want_reference), strtolower($reference))) {
        return sprintf(
            'check-cluster-pins (installed.php): %s reference mismatch (expected %s, got %s).',
            $name,
            $want_reference,
            $reference
        );
    }
    if ($want_alias !== null) {
        $aliases = $entry['aliases'] ?? [];
        if (!in_array($want_alias, $aliases, true)) {
            return sprintf(
                'check-cluster-pins (installed.php): %s alias mismatch (expected %s in %s).',
                $name,
                $want_alias,
                json_encode($aliases)
            );
        }
    }

    return null;
}

/**
 * @param array{source?: array{reference?: string}} $package
 */
function check_installed_json_reference(string $name, array $package, string $want_reference): ?string
{
    $source = $package['source'] ?? null;
    if (!is_array($source)) {
        return sprintf('check-cluster-pins (installed.json): %s has no source block.', $name);
    }
    $reference = $source['reference'] ?? null;
    if (!is_string($reference) || strlen($reference) !== 40 || !ctype_xdigit($reference)) {
        return sprintf('check-cluster-pins (installed.json): %s has no valid 40-char source.reference.', $name);
    }
    if (!hash_equals(strtolower($want_reference), strtolower($reference))) {
        return sprintf(
            'check-cluster-pins (installed.json): %s source.reference mismatch (expected %s, got %s).',
            $name,
            $want_reference,
            $reference
        );
    }

    return null;
}

function run_wrong_sha_demos(): void
{
    $good = '0123456789abcdef0123456789abcdef01234567';
    $bad = 'fedcba9876543210fedcba9876543210fedcba98';
    $alias = '1.2.3';
    $demo_name = 'demo/wrong-sha';

    $installed_php_entry = [
        'reference' => $good,
        'aliases' => [$alias],
    ];
    $installed_json_pkg = [
        'name' => $demo_name,
        'source' => ['reference' => $good],
    ];

    if (check_installed_php_reference($demo_name, $installed_php_entry, $bad, $alias) === null) {
        fwrite(STDERR, "check-cluster-pins demo: installed.php must fail on wrong SHA.\n");
        exit(1);
    }
    if (check_installed_php_reference($demo_name, $installed_php_entry, $good, $alias) !== null) {
        fwrite(STDERR, "check-cluster-pins demo: installed.php must pass on correct SHA.\n");
        exit(1);
    }

    if (check_installed_json_reference($demo_name, $installed_json_pkg, $bad) === null) {
        fwrite(STDERR, "check-cluster-pins demo: installed.json must fail on wrong SHA.\n");
        exit(1);
    }
    if (check_installed_json_reference($demo_name, $installed_json_pkg, $good) !== null) {
        fwrite(STDERR, "check-cluster-pins demo: installed.json must pass on correct SHA.\n");
        exit(1);
    }
}

/**
 * @param array<string, array{reference?: string, version?: string, install_path?: string}> $versions
 * @param array<string, array<string, mixed>> $json_by_name
 */
function check_gap_packages(array $versions, array $json_by_name): bool
{
    $failed = false;

    foreach (array_keys(GAP_VERSION_RANGES) as $name) {
        if (!isset($versions[$name])) {
            fwrite(STDERR, sprintf("check-cluster-pins: %s is not installed.\n", $name));
            $failed = true;
            continue;
        }

        $pkg_php = $versions[$name];
        $reference = $pkg_php['reference'] ?? null;
        if (!is_string($reference) || strlen($reference) !== 40) {
            fwrite(STDERR, sprintf("check-cluster-pins: %s has no installed.php reference.\n", $name));
            $failed = true;
            continue;
        }

        $ref_lower = strtolower($reference);
        foreach (INDEX_REFERENCES[$name] as $index_ref) {
            if (hash_equals(strtolower($index_ref), $ref_lower)) {
                fwrite(STDERR, sprintf(
                    "check-cluster-pins: %s must not match Training-Datasmith index SHA %s.\n",
                    $name,
                    $index_ref
                ));
                $failed = true;
            }
        }

        if (!isset($json_by_name[$name])) {
            fwrite(STDERR, sprintf("check-cluster-pins: %s missing from installed.json.\n", $name));
            $failed = true;
            continue;
        }

        $json_pkg = $json_by_name[$name];
        $json_ref = null;
        if (isset($json_pkg['source']['reference']) && is_string($json_pkg['source']['reference'])) {
            $json_ref = $json_pkg['source']['reference'];
        } elseif (isset($json_pkg['dist']['reference']) && is_string($json_pkg['dist']['reference'])) {
            $json_ref = $json_pkg['dist']['reference'];
        }
        if (!is_string($json_ref) || strlen($json_ref) !== 40) {
            fwrite(STDERR, sprintf("check-cluster-pins: %s has no installed.json source.reference or dist.reference.\n", $name));
            $failed = true;
            continue;
        }

        if (!hash_equals($ref_lower, strtolower($json_ref))) {
            fwrite(STDERR, sprintf(
                "check-cluster-pins: %s installed.php reference %s != installed.json source.reference %s.\n",
                $name,
                $reference,
                $json_ref
            ));
            $failed = true;
        }

        $url = '';
        if (isset($json_pkg['source']['url']) && is_string($json_pkg['source']['url'])) {
            $url = $json_pkg['source']['url'];
        } elseif (isset($json_pkg['dist']['url']) && is_string($json_pkg['dist']['url'])) {
            $url = $json_pkg['dist']['url'];
        }
        if ($url !== '' && stripos($url, 'Training-Datasmith') !== false) {
            fwrite(STDERR, sprintf("check-cluster-pins: %s must not come from Training-Datasmith (%s).\n", $name, $url));
            $failed = true;
        }

        $version = $pkg_php['version'] ?? ($json_pkg['version'] ?? null);
        if (!is_string($version) || $version === '') {
            fwrite(STDERR, sprintf("check-cluster-pins: %s has no version.\n", $name));
            $failed = true;
            continue;
        }

        $normalized = ltrim($version, 'vV');
        $range = GAP_VERSION_RANGES[$name];
        if (version_compare($normalized, $range['min'], '<') || version_compare($normalized, $range['max'], '>=')) {
            fwrite(STDERR, sprintf(
                "check-cluster-pins: %s version %s outside [%s, %s).\n",
                $name,
                $version,
                $range['min'],
                $range['max']
            ));
            $failed = true;
        }
    }

    return !$failed;
}

run_wrong_sha_demos();

$installed_php = dirname(__DIR__) . '/vendor/composer/installed.php';
if (!is_readable($installed_php)) {
    fwrite(STDERR, "check-cluster-pins: vendor/composer/installed.php not found; run composer update first.\n");
    exit(1);
}

/** @var array{versions?: array<string, array<string, mixed>>} $installed */
$installed = require $installed_php;
$versions = $installed['versions'] ?? [];

$installed_json_path = dirname(__DIR__) . '/vendor/composer/installed.json';
if (!is_readable($installed_json_path)) {
    fwrite(STDERR, "check-cluster-pins: vendor/composer/installed.json not found.\n");
    exit(1);
}

$raw = file_get_contents($installed_json_path);
if ($raw === false) {
    fwrite(STDERR, "check-cluster-pins: could not read installed.json.\n");
    exit(1);
}

/** @var array{packages?: list<array<string, mixed>>, 'packages-dev'?: list<array<string, mixed>>} $json_data */
$json_data = json_decode($raw, true);
if (!is_array($json_data)) {
    fwrite(STDERR, "check-cluster-pins: invalid installed.json.\n");
    exit(1);
}

$json_packages = array_merge($json_data['packages'] ?? [], $json_data['packages-dev'] ?? []);
/** @var array<string, array<string, mixed>> $json_by_name */
$json_by_name = [];
foreach ($json_packages as $package) {
    if (is_array($package) && isset($package['name']) && is_string($package['name'])) {
        $json_by_name[$package['name']] = $package;
    }
}

if (!check_gap_packages($versions, $json_by_name)) {
    exit(1);
}

fwrite(STDOUT, "check-cluster-pins: all cluster packages match expected SHAs and aliases.\n");
exit(0);
