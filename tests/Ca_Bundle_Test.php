<?php

declare(strict_types=1);

namespace Composer\Ca_Bundle;

use Composer\Ca_Bundle\Ca_Bundle;
use PHPUnit\Framework\TestCase;

class Ca_Bundle_Test extends TestCase
{
    public function test_ca_path(): void
    {
        $ca_bundle = new Ca_Bundle();
        $this->reset_env();
        $ca_path = $ca_bundle::get_system_ca_root_bundle_path(null);

        $this->assertFileExists($ca_path);
    }

    public function test_ca_path_not_null(): void
    {
        $ca_bundle = new Ca_Bundle();
        $this->reset_env();
        $ca_path_no_null = $ca_bundle::get_system_ca_root_bundle_path(null);

        $this->assertFileExists($ca_path_no_null);
    }

    public function test_cert_dir(): void
    {
        $ca_bundle = new Ca_Bundle();
        $ca_bundle::reset();
        $cert_dir = 'SSL_CERT_DIR=';
        $cert_path = __DIR__.'/../res';
        $this->reset_env();
        $this->set_env($cert_dir.$cert_path);
        $ssl_cert_dir = $ca_bundle::get_system_ca_root_bundle_path(null);

        $this->assertFileExists($ssl_cert_dir);
    }

    public function test_cert_file(): void
    {
        $ca_bundle = new Ca_Bundle();
        $ca_bundle::reset();
        $cert_file = 'SSL_CERT_FILE=';
        $cert_file_path = __DIR__.'/../res/cacert.pem';
        $this->reset_env();
        $this->set_env($cert_file.$cert_file_path);
        $ssl_cert_file = $ca_bundle::get_system_ca_root_bundle_path(null);

        $this->assertFileExists($ssl_cert_file);
    }

    public function test_ssl_ca_file(): void
    {
        $ssl_ca_file = 'openssl.cafile';
        $cert_file_path = __DIR__.'/../res/cacert.pem';
        ini_set($ssl_ca_file, $cert_file_path);
        $ca_bundle = new Ca_Bundle();
        $this->reset_env();
        $open_ca_file = $ca_bundle::get_system_ca_root_bundle_path(null);

        $this->assertFileExists($open_ca_file);
    }

    public function test_ssl_ca_path(): void
    {
        $ca_bundle = new Ca_Bundle();
        $ssl_ca_path = 'openssl.capath';
        $cert_path = __DIR__.'/../res';
        $this->reset_env();
        ini_set($ssl_ca_path, $cert_path);
        $open_ca_path = $ca_bundle::get_system_ca_root_bundle_path(null);

        $this->assertFileExists($open_ca_path);
    }

    public function test_validate_ca_file(): void
    {
        $cert_file_path = __DIR__.'/../res/cacert.pem';
        $ca_bundle = new Ca_Bundle();
        $valid_result = $ca_bundle::validate_ca_file($cert_file_path, null);

        $this->assertTrue($valid_result);
    }

    public function test_validate_trusted_ca_file(): void
    {
        $cert_file_path = __DIR__.'/Fixtures/ca-bundle.trust.crt';
        $ca_bundle = new Ca_Bundle();
        $valid_result = $ca_bundle::validate_ca_file($cert_file_path, null);

        $this->assertTrue($valid_result);
    }

    public function test_open_base_dir(): void
    {
        $old_value = ini_get('open_basedir') ?: '';
        ini_set('open_basedir', dirname(__DIR__));
        $cert_file_path = Ca_Bundle::get_system_ca_root_bundle_path();
        $valid_result = Ca_Bundle::validate_ca_file($cert_file_path, null);
        $this->assertTrue($valid_result);
        ini_set('open_basedir', $old_value);
    }

    public function set_env(string $env_string): void
    {
        putenv($env_string);
    }

    public function reset_env(): void
    {
        $cert_dir = 'SSL_CERT_DIR=';
        $cert_file = 'SSL_CERT_FILE=';

        $this->set_env($cert_dir);
        $this->set_env($cert_file);
    }
}
