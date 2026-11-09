composer/ca-bundle
==================

Small utility library that lets you find a path to the system CA bundle,
and includes a fallback to the Mozilla CA bundle.

Originally written as part of [composer/composer](https://github.com/composer/composer),
now extracted and made available as a stand-alone library.


Installation
------------

Install the latest version with:

```bash
$ composer require composer/ca-bundle
```


Requirements
------------

* PHP 5.3.2 is required but using the latest version of PHP is highly recommended.


Basic usage
-----------

### `Composer\Ca_Bundle\Ca_Bundle`

- `Ca_Bundle::get_system_ca_root_bundle_path()`: Returns the system CA bundle path, or a path to the bundled one as fallback
- `Ca_Bundle::get_bundled_ca_bundle_path()`: Returns the path to the bundled CA file
- `Ca_Bundle::validate_ca_file($filename)`: Validates a CA file using openssl_x509_parse only if it is safe to use
- `Ca_Bundle::is_openssl_parse_safe()`: Test if it is safe to use the PHP function openssl_x509_parse()
- `Ca_Bundle::reset()`: Resets the static caches


#### To use with curl

```php
$curl = curl_init("https://example.org/");

$ca_path_or_file = \Composer\Ca_Bundle\Ca_Bundle::get_system_ca_root_bundle_path();
if (is_dir($ca_path_or_file)) {
    curl_setopt($curl, CURLOPT_CAPATH, $ca_path_or_file);
} else {
    curl_setopt($curl, CURLOPT_CAINFO, $ca_path_or_file);
}

$result = curl_exec($curl);
```

#### To use with php streams

```php
$opts = array(
    'http' => array(
        'method' => "GET"
    )
);

$ca_path_or_file = \Composer\Ca_Bundle\Ca_Bundle::get_system_ca_root_bundle_path();
if (is_dir($ca_path_or_file)) {
    $opts['ssl']['capath'] = $ca_path_or_file;
} else {
    $opts['ssl']['cafile'] = $ca_path_or_file;
}

$context = stream_context_create($opts);
$result = file_get_contents('https://example.com', false, $context);
```

#### To use with Guzzle

```php
$client = new \GuzzleHttp\Client([
    \GuzzleHttp\RequestOptions::VERIFY => \Composer\Ca_Bundle\Ca_Bundle::get_system_ca_root_bundle_path()
]);
```

License
-------

composer/ca-bundle is licensed under the MIT License, see the LICENSE file for details.
