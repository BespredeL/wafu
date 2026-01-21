# WAFU (Web Application Firewall Universal)

[![Readme EN](https://img.shields.io/badge/README-EN-blue.svg)](https://github.com/BespredeL/wafu/blob/master/README.md)
[![Readme RU](https://img.shields.io/badge/README-RU-blue.svg)](https://github.com/BespredeL/wafu/blob/master/README_RU.md)
[![GitHub license](https://img.shields.io/badge/license-MIT-458a7b.svg)](https://github.com/BespredeL/wafu/blob/master/LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-777BB4.svg)](https://www.php.net/)

WAFU is a **config-driven, modular Web Application Firewall** for PHP 8.1+, designed for **Laravel, Symfony and standalone PHP applications**.

It supports **local and remote rulesets**, caching, pattern sets, and production-safe enforcement with a flexible architecture that allows you to customize security rules without modifying code.

---

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Configuration](#configuration)
- [Modes](#modes)
- [Built-in Modules](#built-in-modules)
- [Pattern Sets](#pattern-sets)
- [Remote Rulesets](#remote-rulesets)
- [Framework Integration](#framework-integration)
- [Security Philosophy](#security-philosophy)
- [Troubleshooting](#troubleshooting)
- [License](#license)

---

## Features

- ✅ **Fully config-driven** - No hardcoded rules, everything is configurable
- ✅ **Modular architecture** - Easy to extend with custom modules
- ✅ **Comprehensive protection** - SQLi, XSS, RCE, LFI, Path Traversal detection
- ✅ **Bot detection** - Identify and challenge suspicious user agents
- ✅ **Rate limiting** - Protect against brute force and DDoS attacks
- ✅ **IP blocking** - IPv4/IPv6 + CIDR support for flexible blocking
- ✅ **Pattern sets** - Reusable detection patterns across modules
- ✅ **Remote rules** - Load rulesets from remote endpoints with caching
- ✅ **Report-only mode** - Test rules safely before enforcement
- ✅ **PSR-3 logging** - Integrate with any PSR-3 compatible logger
- ✅ **Framework safe** - No forced exit, works seamlessly with Laravel/Symfony
- ✅ **Trusted proxies** - Proper handling of X-Forwarded-For headers
- ✅ **ETag support** - Efficient remote ruleset updates

---

## Requirements

- PHP >= 8.1
- `ext-mbstring` extension
- PSR-3 compatible logger (optional, but recommended)

---

## Installation

Install via Composer:

```bash
composer require bespredel/wafu
```

---

## Quick Start

### Standalone PHP

```php
<?php

use Bespredel\Wafu\Core\Kernel;

// Initialize kernel with config file
$kernel = new Kernel(__DIR__ . '/config/wafu.php');

// Handle request
$result = $kernel->handle(
    $_SERVER,
    $_GET,
    $_POST,
    $_COOKIE,
    getallheaders()
);

// Check if request was blocked
if ($result->isBlocked()) {
    http_response_code($result->getStatus() ?: 403);
    echo $result->getBody() ?: 'Blocked by WAFU';
    exit;
}

// Continue with your application logic
```

### Laravel

1. **Publish configuration** (optional):

```bash
php artisan vendor:publish --tag=wafu-config
```

2. **Register middleware** in `app/Http/Kernel.php`:

```php
protected $middleware = [
    // ... other middleware
    \Bespredel\Wafu\Adapters\Laravel\WafuMiddleware::class,
];
```

Or apply to specific routes in `routes/web.php`:

```php
Route::middleware([\Bespredel\Wafu\Adapters\Laravel\WafuMiddleware::class])
    ->group(function () {
        // Your protected routes
    });
```

3. **Configure** in `config/wafu.php` (see [Configuration](#configuration))

### Symfony

1. **Register service** in `config/services.yaml`:

```yaml
services:
    Bespredel\Wafu\Adapters\Symfony\WafuSubscriber:
        arguments:
            $wafKernel: '@Bespredel\Wafu\Core\Kernel'
            $logger: '@logger'
        tags:
            - { name: kernel.event_subscriber }
    
    Bespredel\Wafu\Core\Kernel:
        arguments:
            $configPath: '%kernel.project_dir%/config/wafu.php'
```

2. **Create config file** at `config/wafu.php` (copy from `vendor/bespredel/wafu/src/config/wafu.php`)

---

## Configuration

WAFU is fully configurable through a PHP array. The main configuration options:

### Master Switch

```php
'enabled' => true, // Enable/disable WAFU globally
```

### Mode

```php
'mode' => 'enforce', // or 'report'
```

- **`enforce`**: Block malicious requests (production mode)
- **`report`**: Only log matches without blocking (recommended for initial testing)

### Trusted Proxies

If your application is behind a proxy/load balancer (Nginx, Cloudflare, etc.):

```php
'trusted_proxies' => [
    '127.0.0.1',
    '10.0.0.0/8',
    '172.16.0.0/12',
    '192.168.0.0/16',
],
'trust_forwarded_headers' => true, // Enable X-Forwarded-For trust
```

**⚠️ Security Note**: Only enable `trust_forwarded_headers` if you trust your proxy. Otherwise, leave it `false` to prevent XFF spoofing.

### Pipeline (Execution Order)

Define the order in which modules are executed:

```php
'pipeline' => [
    'ip_blocklist',      // Fast checks first
    'method_allowlist',
    'uri_rules',
    'path_traversal',    // Pattern matching
    'lfi',
    'rce',
    'bad_bots',
    'not_found_abuse',
    'rate_limit',        // Resource-intensive checks last
    'sql_injection',
    'xss',
],
```

**Recommendation**: Place lightweight checks (IP blocking, method validation) first, and resource-intensive regex checks last.

### Actions

Actions define what happens when a module detects a threat:

```php
'actions' => [
    'block' => [
        'class'     => \Bespredel\Wafu\Actions\BlockAction::class,
        'status'    => 403,
        'message'   => 'Blocked by WAFU',
        'terminate' => true, // true for standalone, false for frameworks
    ],
    
    'challenge' => [
        'class'       => \Bespredel\Wafu\Actions\ChallengeAction::class,
        'status'      => 429,
        'message'     => 'Too many requests. Please retry later.',
        'retry_after' => 15,
        'terminate'   => true,
    ],
    
    'log' => [
        'class'   => \Bespredel\Wafu\Actions\LogAction::class,
        'channel' => 'security',
        'level'   => 'warning',
    ],
],
```

---

## Modes

### Enforce Mode

Blocks malicious requests immediately. Use in production after testing.

```php
'mode' => 'enforce',
```

### Report Mode

Logs threats without blocking. Perfect for:
- Initial deployment
- Testing new rules
- Monitoring false positives

```php
'mode' => 'report',
```

**Best Practice**: Start with `report` mode, monitor logs for a few days, then switch to `enforce`.

---

## Built-in Modules

### IpBlocklistModule

Block requests from specific IPs or CIDR ranges:

```php
'ip_blocklist' => [
    'class'     => \Bespredel\Wafu\Modules\IpBlocklistModule::class,
    'blocklist' => [
        '1.2.3.4',
        '10.0.0.0/8',
        '2001:db8::/32', // IPv6 support
    ],
    'on_match'  => 'block',
    'reason'    => 'IP blocked',
],
```

### MethodAllowlistModule

Allow only specific HTTP methods:

```php
'method_allowlist' => [
    'class'   => \Bespredel\Wafu\Modules\MethodAllowlistModule::class,
    'allow'   => ['GET', 'POST', 'HEAD'],
    'on_deny' => 'block',
    'reason'  => 'HTTP method not allowed',
],
```

### UriAllowDenyModule

Control access to specific URIs:

```php
'uri_rules' => [
    'class'        => \Bespredel\Wafu\Modules\UriAllowDenyModule::class,
    'allow_prefix' => ['/api', '/login'],
    'allow_regex'  => ['/^\/api\/v\d+\/.*$/'],
    'deny_prefix'  => ['/.env', '/.git', '/vendor'],
    'deny_regex'   => ['uri_deny'], // Reference to pattern set
    'on_deny'      => 'block',
    'reason'       => 'URI is not allowed',
],
```

### RegexMatchModule

Detect patterns in request data (SQLi, XSS, etc.):

```php
'sql_injection' => [
    'class'    => \Bespredel\Wafu\Modules\RegexMatchModule::class,
    'targets'  => ['query', 'body', 'cookies'],
    'patterns' => ['sql_keywords'], // Reference to pattern set
    'on_match' => 'block',
    'reason'   => 'SQL injection detected',
],
```

### HeaderModule

Detect bots and scanners by User-Agent:

```php
'bad_bots' => [
    'class'    => \Bespredel\Wafu\Modules\HeaderModule::class,
    'headers'  => ['User-Agent'],
    'patterns' => ['bad_bots_ua'],
    'on_match' => 'challenge',
    'reason'   => 'Bot/scanner detected',
],
```

### RateLimitModule

Protect against brute force and DDoS:

```php
'rate_limit' => [
    'class'          => \Bespredel\Wafu\Modules\RateLimitModule::class,
    'limit'          => 120,        // Requests per interval
    'interval'       => 60,          // Seconds
    'key_by'         => 'ip',        // or 'ip+uri'
    'on_exceed'      => 'challenge',
    'reason'         => 'Rate limit exceeded',
    'gc_probability' => 100,         // 1% chance to run GC
    'ttl_multiplier' => 5,           // TTL = interval * multiplier
],
```

### NotFoundAbuseModule

Detect scanning/brute-forcing by monitoring 404 errors:

```php
'not_found_abuse' => [
    'class'          => \Bespredel\Wafu\Modules\NotFoundAbuseModule::class,
    'threshold'      => 20,          // Max 404s per interval
    'interval'       => 300,          // 5 minutes
    'key_by'         => 'ip',
    'on_exceed'      => 'block',
    'reason'         => 'Too many 404 errors',
    'gc_probability' => 100,
    'ttl_multiplier' => 5,
],
```

### PathTraversalModule, LfiModule, RceModule

Protect against path traversal, local file inclusion, and remote code execution:

```php
'path_traversal' => [
    'class'    => \Bespredel\Wafu\Modules\PathTraversalModule::class,
    'targets'  => ['uri', 'query', 'body', 'cookies'],
    'patterns' => ['path_traversal'],
    'on_match' => 'block',
],

'lfi' => [
    'class'    => \Bespredel\Wafu\Modules\LfiModule::class,
    'targets'  => ['query', 'body', 'cookies', 'uri'],
    'patterns' => ['lfi_files'],
    'on_match' => 'block',
],

'rce' => [
    'class'    => \Bespredel\Wafu\Modules\RceModule::class,
    'targets'  => ['query', 'body', 'cookies', 'uri'],
    'patterns' => ['rce_signatures'],
    'on_match' => 'block',
],
```

---

## Pattern Sets

Pattern sets allow you to define reusable detection patterns:

```php
'patterns' => [
    'sql_keywords' => [
        '/\bUNION\b/i',
        '/\bSELECT\b/i',
        '/\bINSERT\b/i',
        // ... more patterns
    ],
    
    'xss_basic' => [
        '/<\s*script\b/i',
        '/javascript\s*:/i',
        '/document\.cookie/i',
    ],
],
```

Modules can reference pattern sets by name:

```php
'patterns' => ['sql_keywords', 'xss_basic'],
```

Or use direct regex patterns:

```php
'patterns' => [
    '/\bSELECT\b/i',
    '/<\s*script\b/i',
],
```

---

## Remote Rulesets

WAFU can load rulesets from remote endpoints, enabling:
- Centralized rule management
- Dynamic updates without deployment
- A/B testing of rules

### Configuration

```php
'remote_rules' => [
    'enabled'            => true,
    'endpoint'           => 'https://example.com/api/ruleset.json',
    
    // Optional authentication
    'headers'            => [
        'X-WAF-KEY' => 'your-secret-key',
    ],
    
    // Cache settings
    'cache_dir'          => sys_get_temp_dir() . '/wafu-cache',
    'cache_file'         => 'ruleset.json',
    'use_cache_on_error' => true, // Use cache if remote fails
    'max_ttl'            => 86400, // Max cache TTL (24 hours)
    
    // Signature verification (optional, requires ext-sodium)
    'signature'          => [
        'enabled'           => false,
        'public_key_base64' => '',
        'field'             => 'signature',
        'algo'              => 'ed25519',
    ],
    
    // Merge strategy
    'merge_strategy'     => 'remote_wins', // or 'local_wins'
],
```

### Features

- **TTL-based caching**: Rulesets are cached locally with TTL support
- **ETag support**: Efficient updates using HTTP ETags
- **Safe fallback**: Falls back to local config on errors
- **Signature verification**: Optional Ed25519 signature verification
- **Merge strategies**: Choose how remote and local configs merge

### Ruleset Format

Remote rulesets should be JSON files with the same structure as the local PHP config:

```json
{
    "patterns": {
        "sql_keywords": ["/\\bSELECT\\b/i"]
    },
    "modules": {
        "sql_injection": {
            "patterns": ["sql_keywords"],
            "on_match": "block"
        }
    }
}
```

---

## Framework Integration

### Laravel

WAFU integrates seamlessly with Laravel through a service provider and middleware.

**Service Provider** (auto-registered):

The `WafuServiceProvider` automatically:
- Registers the `Kernel` as a singleton
- Merges package config with your app config
- Publishes config file via `php artisan vendor:publish --tag=wafu-config`

**Middleware**:

```php
// app/Http/Kernel.php
protected $middleware = [
    \Bespredel\Wafu\Adapters\Laravel\WafuMiddleware::class,
];
```

The middleware automatically:
- Extracts request data
- Runs WAFU checks
- Returns appropriate responses
- Integrates with Laravel's logging system

### Symfony

WAFU integrates via an event subscriber:

```yaml
# config/services.yaml
services:
    Bespredel\Wafu\Adapters\Symfony\WafuSubscriber:
        arguments:
            $wafKernel: '@Bespredel\Wafu\Core\Kernel'
            $logger: '@logger'
        tags:
            - { name: kernel.event_subscriber }
    
    Bespredel\Wafu\Core\Kernel:
        arguments:
            $configPath: '%kernel.project_dir%/config/wafu.php'
```

The subscriber listens to `KernelEvents::REQUEST` and processes requests before they reach your controllers.

---

## Security Philosophy

### Start with Report Mode

1. **Deploy in `report` mode** to observe behavior
2. **Monitor logs** for false positives
3. **Tune rules** based on real traffic
4. **Switch to `enforce` mode** when confident

### Defense in Depth

WAFU is one layer of security. Combine with:
- Input validation
- Output encoding
- SQL prepared statements
- CSRF protection
- HTTPS/TLS
- Regular security updates

### Performance Considerations

- Place lightweight checks first in the pipeline
- Use rate limiting to prevent resource exhaustion
- Monitor performance impact
- Consider caching for expensive operations

---

## Troubleshooting

### Requests are being blocked incorrectly

1. **Check logs**: Enable PSR-3 logging to see what's being matched
2. **Use report mode**: Switch to `report` mode to see matches without blocking
3. **Review patterns**: Check if your patterns are too aggressive
4. **Whitelist**: Use URI allowlists for legitimate endpoints

### Performance issues

1. **Optimize pipeline**: Move expensive checks later
2. **Reduce pattern complexity**: Simplify regex patterns
3. **Check rate limits**: Ensure rate limiting isn't too aggressive
4. **Monitor storage**: Clean up rate limit storage files periodically

### Remote rules not loading

1. **Check connectivity**: Ensure the endpoint is reachable
2. **Verify format**: Ensure JSON is valid
3. **Check cache**: Clear cache directory if needed
4. **Review logs**: Check for HTTP errors
5. **Test locally**: Verify ruleset JSON structure

### IP detection issues

1. **Check trusted proxies**: Configure if behind a proxy
2. **Verify headers**: Ensure X-Forwarded-For is trusted
3. **Test IP extraction**: Log the detected IP to verify

---

## License

This package is open-source software licensed under the [MIT license](LICENSE).

---

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

---

## Support

For issues, questions, or contributions, please visit the [GitHub repository](https://github.com/BespredeL/wafu).
