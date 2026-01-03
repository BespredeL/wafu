# WAFU (Web Application Firewall Universal)

[![Readme EN](https://img.shields.io/badge/README-EN-blue.svg)](https://github.com/BespredeL/wafu/blob/master/README.md)
[![Readme RU](https://img.shields.io/badge/README-RU-blue.svg)](https://github.com/BespredeL/wafu/blob/master/README_RU.md)
[![GitHub license](https://img.shields.io/badge/license-MIT-458a7b.svg)](https://github.com/BespredeL/wafu/blob/master/LICENSE)

WAFU is a **config-driven, modular Web Application Firewall** for PHP 8.1+,
designed for **Laravel, Symfony and standalone PHP applications**.

It supports **local and remote rulesets**, caching, pattern sets, and production-safe enforcement.

---

## Features

- Fully config-driven (no hardcoded rules)
- Modular architecture
- SQLi / XSS / RCE / LFI / Path Traversal protection
- Bot detection
- Rate limiting
- IPv4 / IPv6 + CIDR blocking
- Pattern sets
- Remote rules loading with cache & TTL
- Report-only mode
- PSR-3 logging
- Safe framework integration (no forced exit)

---

## Installation

```bash
composer require bespredel/wafu
```

---

## Quick Start (Standalone)

```php
use Bespredel\Wafu\Core\Kernel;

$kernel = new Kernel(__DIR__ . '/config/wafu.php');

$result = $kernel->handle($_SERVER, $_GET, $_POST, $_COOKIE, getallheaders());

if ($result->isBlocked()) {
    http_response_code(403);
    echo 'Blocked by WAFU';
    exit;
}
```

---

## Modes

```php
'mode' => 'enforce', // or 'report'
```

- **enforce**: block malicious requests
- **report**: only log matches (recommended first)

---

## Built-in Modules

- RegexMatchModule (SQLi, XSS)
- HeaderModule (bots)
- RateLimitModule
- IpBlocklistModule
- MethodAllowlistModule
- UriAllowDenyModule
- RceModule
- LfiModule
- PathTraversalModule

---

## Pattern Sets

Pattern sets allow reusable detection logic:

```php
'patterns' => [
  'sql_keywords' => ['/SELECT/i', '/UNION/i'],
]
```

Modules reference them by name.

---

## Remote Rulesets

WAFU can load rulesets remotely:

```php
'remote_rules' => [
  'enabled' => true,
  'endpoint' => 'https://github.com/BespredeL/wafu/blob/master/ruleset.json',
]
```

Features:

- TTL-based cache
- ETag support
- Safe fallback to local config
- Optional signature verification

---

## Laravel Integration

Register middleware:

```php
\Bespredel\Wafu\Adapters\Laravel\WafuMiddleware::class
```

---

## Symfony Integration

```yaml
Bespredel\Wafu\Adapters\Symfony\WafuSubscriber:
  tags: [ kernel.event_subscriber ]
```

---

## Security Philosophy

Start with `report` mode.
Observe logs.
Then switch to `enforce`.

---

## License

MIT
