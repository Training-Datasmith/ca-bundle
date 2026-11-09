# Architecture: ca-bundle

## Purpose

Provides a reliable path to a CA (Certificate Authority) bundle for TLS verification. It locates the system's CA bundle on common Linux/macOS distributions and Windows, falling back to a bundled copy of the Mozilla CA bundle when none is found. Used by Composer and other packages that need a path to pass to cURL's `CURLOPT_CAINFO`.

## Directory Structure

```
src/
  Ca_Bundle.php         — Locates or validates the system/bundled CA bundle path

tests/
  Ca_Bundle_Test.php    — Unit and integration tests for path resolution
```

## Key Design Decisions

- **System-first lookup** — checks `openssl.cafile` and `openssl.capath` PHP ini settings, then well-known OS paths (Debian, Red Hat, Arch, macOS Homebrew, Windows), before falling back to the bundled PEM file.
- **Validity caching** — `validate_ca_file()` caches its result per path to avoid redundant file I/O across multiple calls within the same process.
- **openssl verification** — validates candidate CA files with `openssl_x509_parse()` after normalizing TRUSTED markers, not just `file_exists()`.
- **Static state** — `$ca_path` and `$ca_file_validity` are static to act as a process-lifetime cache; consumers can call `reset()` in tests.

## Extension Points

- No public extension points. The class is a utility; consumers call `Ca_Bundle::get_system_ca_root_bundle_path()` and pass the result to cURL or stream context options.

## Dependency Flow

```
Ca_Bundle::get_system_ca_root_bundle_path(logger?)
  ├── check openssl.cafile ini
  ├── check openssl.capath ini
  ├── iterate known OS CA paths
  └── fall back to bundled mozilla CA PEM
```
