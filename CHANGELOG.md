# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2024-12-22

### Added
- Initial release of framework-agnostic PHP SDK
- PSR-4 autoloading
- PSR-3 logger interface support
- PSR-18 HTTP client interface
- Document management (upload, download, status tracking)
- Signer management (create, list, search)
- Assignment management (create, cancel, resend)
- Webhook support (register, verify signatures)
- Comprehensive exception hierarchy
- Docker development environment
- Complete documentation and examples

### Fixed
- PHP 7.4 compatibility (replaced `str_contains()` and `str_ends_with()`)

### Security
- HMAC-SHA256 webhook signature verification
- Timing-safe signature comparison

## PHP Compatibility

- **PHP 7.4**: Full support with positional arguments
- **PHP 8.0+**: Full support with named arguments
- **PHP 8.1+**: Recommended for best developer experience

[1.0.0]: https://github.com/assinafy/php-sdk/releases/tag/v1.0.0

