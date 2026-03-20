<?php

declare (strict_types=1);
/*
 * This file is part of composer/ca-bundle.
 *
 * (c) Composer <https://github.com/composer>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */
namespace Composer\Ca_Bundle;

use Psr\Log\Logger_Interface;
/**
 * @author Chris Smith <chris@cs278.org>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Ca_Bundle
{
    /** @var string|null */
    private static $ca_path;
    /** @var array<string, bool> */
    private static $ca_file_validity = [];
    /**
     * Returns the system CA bundle path, or a path to the bundled one
     *
     * This method was adapted from Sslurp.
     * https://github.com/EvanDotPro/Sslurp
     *
     * (c) Evan Coury <me@evancoury.com>
     *
     * For the full copyright and license information, please see below:
     *
     * Copyright (c) 2013, Evan Coury
     * All rights reserved.
     *
     * Redistribution and use in source and binary forms, with or without modification,
     * are permitted provided that the following conditions are met:
     *
     *     * Redistributions of source code must retain the above copyright notice,
     *       this list of conditions and the following disclaimer.
     *
     *     * Redistributions in binary form must reproduce the above copyright notice,
     *       this list of conditions and the following disclaimer in the documentation
     *       and/or other materials provided with the distribution.
     *
     * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
     * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
     * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
     * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR
     * ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
     * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
     * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON
     * ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
     * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
     * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
     *
     * @param  LoggerInterface $logger optional logger for information about which CA files were loaded
     * @return string          path to a CA bundle file or directory
     */
    public static function get_system_ca_root_bundle_path(?Logger_Interface $logger = null)
    {
        if (self::$ca_path !== null) {
            return self::$ca_path;
        }
        $ca_bundle_paths = [];
        // If SSL_CERT_FILE env variable points to a valid certificate/bundle, use that.
        // This mimics how OpenSSL uses the SSL_CERT_FILE env variable.
        $ca_bundle_paths[] = self::get_env_variable('SSL_CERT_FILE');
        // If SSL_CERT_DIR env variable points to a valid certificate/bundle, use that.
        // This mimics how OpenSSL uses the SSL_CERT_FILE env variable.
        $ca_bundle_paths[] = self::get_env_variable('SSL_CERT_DIR');
        $ca_bundle_paths[] = ini_get('openssl.cafile');
        $ca_bundle_paths[] = ini_get('openssl.capath');
        $other_locations = [
            '/etc/pki/ca-trust/extracted/pem/tls-ca-bundle.pem',
            // Fedora, RHEL, CentOS (ca-certificates package) - NEW
            '/etc/pki/tls/certs/ca-bundle.crt',
            // Fedora, RHEL, CentOS (ca-certificates package) - Deprecated
            '/etc/ssl/certs/ca-certificates.crt',
            // Debian, Ubuntu, Gentoo, Arch Linux (ca-certificates package)
            '/etc/ssl/ca-bundle.pem',
            // SUSE, openSUSE (ca-certificates package)
            '/usr/ssl/certs/ca-bundle.crt',
            // Cygwin
            '/opt/local/share/curl/curl-ca-bundle.crt',
            // OS X macports, curl-ca-bundle package
            '/usr/local/share/curl/curl-ca-bundle.crt',
            // Default cURL CA bunde path (without --with-ca-bundle option)
            '/usr/share/ssl/certs/ca-bundle.crt',
            // Really old RedHat?
            '/etc/ssl/cert.pem',
            // OpenBSD
            '/usr/local/etc/openssl/cert.pem',
            // OS X homebrew, openssl package
            '/usr/local/etc/openssl@1.1/cert.pem',
            // OS X homebrew, openssl@1.1 package
            '/opt/homebrew/etc/openssl@3/cert.pem',
            // macOS silicon homebrew, openssl@3 package
            '/opt/homebrew/etc/openssl@1.1/cert.pem',
            // macOS silicon homebrew, openssl@1.1 package
            '/etc/pki/tls/certs',
            '/etc/ssl/certs',
        ];
        $ca_bundle_paths = array_merge($ca_bundle_paths, $other_locations);
        foreach ($ca_bundle_paths as $ca_bundle) {
            if ($ca_bundle && self::ca_file_usable($ca_bundle, $logger)) {
                return self::$ca_path = $ca_bundle;
            }
            if ($ca_bundle && self::ca_dir_usable($ca_bundle, $logger)) {
                return self::$ca_path = $ca_bundle;
            }
        }
        return self::$ca_path = static::get_bundled_ca_bundle_path();
        // Bundled CA file, last resort
    }
    /**
     * Returns the path to the bundled CA file
     *
     * In case you don't want to trust the user or the system, you can use this directly
     *
     * @return string path to a CA bundle file
     */
    public static function get_bundled_ca_bundle_path(): string
    {
        $ca_bundle_file = __DIR__ . '/../res/cacert.pem';
        // cURL does not understand 'phar://' paths
        // see https://github.com/composer/ca-bundle/issues/10
        if (0 === strpos($ca_bundle_file, 'phar://')) {
            $temp_ca_bundle_file = tempnam(sys_get_temp_dir(), 'openssl-ca-bundle-');
            if (false === $temp_ca_bundle_file) {
                throw new \RuntimeException('Could not create a temporary file to store the bundled CA file');
            }
            file_put_contents($temp_ca_bundle_file, file_get_contents($ca_bundle_file));
            register_shutdown_function(function () use ($temp_ca_bundle_file): void {
                @unlink($temp_ca_bundle_file);
            });
            $ca_bundle_file = $temp_ca_bundle_file;
        }
        return $ca_bundle_file;
    }
    /**
     * Validates a CA file using opensl_x509_parse only if it is safe to use
     *
     * @param string          $filename
     * @param LoggerInterface $logger   optional logger for information about which CA files were loaded
     *
     * @return bool
     */
    public static function validate_ca_file($filename, ?Logger_Interface $logger = null)
    {
        static $warned = false;
        if (isset(self::$ca_file_validity[$filename])) {
            return self::$ca_file_validity[$filename];
        }
        $contents = file_get_contents($filename);
        if (is_string($contents) && strlen($contents) > 0) {
            $contents = preg_replace('/^(\-+(?:BEGIN|END))\s+TRUSTED\s+(CERTIFICATE\-+)$/m', '$1 $2', $contents);
            if (null === $contents) {
                // regex extraction failed
                $is_valid = false;
            } else {
                $is_valid = (bool) openssl_x509_parse($contents);
            }
        } else {
            $is_valid = false;
        }
        if ($logger) {
            $logger->debug('Checked CA file ' . realpath($filename) . ': ' . ($is_valid ? 'valid' : 'invalid'));
        }
        return self::$ca_file_validity[$filename] = $is_valid;
    }
    /**
     * Test if it is safe to use the PHP function openssl_x509_parse().
     *
     * This checks if OpenSSL extensions is vulnerable to remote code execution
     * via the exploit documented as CVE-2013-6420.
     */
    public static function is_openssl_parse_safe(): bool
    {
        return true;
    }
    /**
     * Resets the static caches
     */
    public static function reset(): void
    {
        self::$ca_file_validity = [];
        self::$ca_path = null;
    }
    /**
     * @return string|false
     */
    private static function get_env_variable(string $name)
    {
        if (isset($_SERVER[$name])) {
            return (string) $_SERVER[$name];
        }
        if (PHP_SAPI === 'cli' && ($value = getenv($name)) !== false && $value !== null) {
            return (string) $value;
        }
        return false;
    }
    /**
     * @param  string|false $certFile
     */
    private static function ca_file_usable($cert_file, ?Logger_Interface $logger = null): bool
    {
        return $cert_file && self::is_file($cert_file, $logger) && self::is_readable($cert_file, $logger) && self::validate_ca_file($cert_file, $logger);
    }
    /**
     * @param  string|false $certDir
     */
    private static function ca_dir_usable($cert_dir, ?Logger_Interface $logger = null): bool
    {
        return $cert_dir && self::is_dir($cert_dir, $logger) && self::is_readable($cert_dir, $logger) && self::glob($cert_dir . '/*', $logger);
    }
    /**
     * @return bool
     */
    private static function is_file(string $cert_file, ?Logger_Interface $logger = null)
    {
        $is_file = @is_file($cert_file);
        if (!$is_file && $logger) {
            $logger->debug(sprintf('Checked CA file %s does not exist or it is not a file.', $cert_file));
        }
        return $is_file;
    }
    /**
     * @return bool
     */
    private static function is_dir(string $cert_dir, ?Logger_Interface $logger = null)
    {
        $is_dir = @is_dir($cert_dir);
        if (!$is_dir && $logger) {
            $logger->debug(sprintf('Checked directory %s does not exist or it is not a directory.', $cert_dir));
        }
        return $is_dir;
    }
    /**
     * @return bool
     */
    private static function is_readable(string $cert_file_or_dir, ?Logger_Interface $logger = null)
    {
        $is_readable = @is_readable($cert_file_or_dir);
        if (!$is_readable && $logger) {
            $logger->debug(sprintf('Checked file or directory %s is not readable.', $cert_file_or_dir));
        }
        return $is_readable;
    }
    private static function glob(string $pattern, ?Logger_Interface $logger = null): bool
    {
        $certs = glob($pattern);
        if ($certs === false) {
            if ($logger) {
                $logger->debug(sprintf('An error occurred while trying to find certificates for pattern: %s', $pattern));
            }
            return false;
        }
        if (count($certs) === 0) {
            if ($logger) {
                $logger->debug(sprintf('No CA files found for pattern: %s', $pattern));
            }
            return false;
        }
        return true;
    }
}