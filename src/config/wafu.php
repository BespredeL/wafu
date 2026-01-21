<?php

declare(strict_types=1);

use Bespredel\Wafu\Actions\BlockAction;
use Bespredel\Wafu\Actions\ChallengeAction;
use Bespredel\Wafu\Actions\LogAction;
use Bespredel\Wafu\Modules\HeaderModule;
use Bespredel\Wafu\Modules\IpBlocklistModule;
use Bespredel\Wafu\Modules\LfiModule;
use Bespredel\Wafu\Modules\MethodAllowlistModule;
use Bespredel\Wafu\Modules\NotFoundAbuseModule;
use Bespredel\Wafu\Modules\PathTraversalModule;
use Bespredel\Wafu\Modules\RateLimitModule;
use Bespredel\Wafu\Modules\RceModule;
use Bespredel\Wafu\Modules\RegexMatchModule;
use Bespredel\Wafu\Modules\UriAllowDenyModule;

return [

    /* ---------------------------------------------------------------------
     | Master switch
     | ---------------------------------------------------------------------
     */
    'enabled'                 => true,

    /* ---------------------------------------------------------------------
     | Mode
     | ---------------------------------------------------------------------
     |
     | enforce - block
     | report - log only (recommended for initial testing)
     */
    'mode'                    => 'enforce',

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies & forwarded headers
    |--------------------------------------------------------------------------
    |
    | If the application is behind a trusted proxy/balancer (Nginx, Cloudflare, etc.),
    | Specify the proxy's CIDR/IP and enable trust_forwarded_headers=true.
    |
    | Otherwise, leave trust_forwarded_headers=false to prevent XFF spoofing.
    */
    'trusted_proxies'         => [
        // '127.0.0.1',
        // '10.0.0.0/8',
        // '172.16.0.0/12',
        // '192.168.0.0/16',
        // '203.0.113.10', // an example of a public IP proxy
    ],
    'trust_forwarded_headers' => false,

    /* ---------------------------------------------------------------------
     | Pipeline (execution order)
     | ---------------------------------------------------------------------
     |
     | Recommended: light checks first, regex checks later.
     */
    'pipeline'                => [
        //
        'ip_blocklist',
        'method_allowlist',
        'uri_rules',

        //
        'path_traversal',
        'lfi',
        'rce',

        //
        'bad_bots',
        'not_found_abuse',
        'rate_limit',

        //
        'sql_injection',
        'xss',
    ],

    /* ---------------------------------------------------------------------
     | Actions
     | ---------------------------------------------------------------------
     |
     | terminate:
     |  - true  (standalone PHP): the action itself terminates execution (echo+exit)
     |  - false (Laravel/Symfony): the action does NOT exit, and the adapter will return a Response
     */
    'actions'                 => [
        'block' => [
            'class'     => BlockAction::class,
            'status'    => 403,
            'message'   => 'Blocked by WAFU',
            'terminate' => true,
        ],

        'challenge' => [
            'class'       => ChallengeAction::class,
            'status'      => 429,
            'message'     => 'Too many requests. Please retry later.',
            'retry_after' => 15,
            'terminate'   => true,
        ],

        'log' => [
            'class'   => LogAction::class,
            'channel' => 'security',
            'level'   => 'warning',
        ],
    ],

    /* ---------------------------------------------------------------------
     | Pattern sets
     | ---------------------------------------------------------------------
     |
     | Pattern sets are reused in modules via patterns => ['set_name', ...]
     */
    'patterns'                => [
        /*
         * SQLi
         */
        'sql_keywords'   => [
            '/\bUNION\b/i',
            '/\bSELECT\b/i',
            '/\bINSERT\b/i',
            '/\bUPDATE\b/i',
            '/\bDELETE\b/i',
            '/\bDROP\b/i',
            '/\bSLEEP\s*\(/i',
            '/\bBENCHMARK\s*\(/i',
            '/\bINFORMATION_SCHEMA\b/i',
            '/(--|#|\/\*)/m',
            '/\bOR\b\s+1\s*=\s*1/i',
        ],

        /*
         * XSS
         */
        'xss_basic'      => [
            '/<\s*script\b/i',
            //'/on\w+\s*=/i',
            '/javascript\s*:/i',
            '/<\s*img\b[^>]*\bonerror\s*=/i',
            '/document\.cookie/i',
        ],

        /*
         * Bots
         */
        'bad_bots_ua'    => [
            '/\bcurl\b/i',
            '/\bwget\b/i',
            '/python-requests/i',
            '/\bokhttp\b/i',
            '/\bnikto\b/i',
            '/\bsqlmap\b/i',
            '/\bmasscan\b/i',
            '/\bnmap\b/i',
        ],

        /*
         * Path traversal
         */
        'path_traversal' => [
            // ../ or ..\
            '/\.\.(\/|\\\\)/',

            // encoded
            '/%2e%2e(\/|%2f|\\\\|%5c)/i',

            // double encoded ../
            '/%252e%252e%252f/i',

            // overlong UTF-8 dot variants
            '/%c0%ae%c0%ae/i',
        ],

        /*
         * LFI
         */
        'lfi_files'      => [
            //
            '/\bphp:\/\/(?:filter|input|stdin|memory|temp|fd)\b/i',
            '/\b(?:expect|data|zip|phar):\/\//i',

            //
            '/\/etc\/passwd\b/i',
            '/\/etc\/shadow\b/i',
            '/\/proc\/self\/environ\b/i',
            '/\/proc\/(?:self|[0-9]+)\/cmdline\b/i',

            //
            '/\b(?:\.ssh\/authorized_keys|\.ssh\/id_rsa|\.ssh\/id_ed25519)\b/i',
            '/\b(?:wp-config\.php|config\.php|configuration\.php|\.env)\b/i',

            //
            '/\b(?:access\.log|error\.log|nginx\.log|apache2\/.*log)\b/i',
        ],

        /*
         * RCE
         */
        'rce_signatures' => [
            // Separators/Traversaries
            '/(;|\|\||&&|\||`|\$\(|\${|\%60)/',
            '/\b(?:bash|sh|cmd|powershell|pwsh)\b/i',

            // Typical Download/Execute Utilities
            '/\b(?:curl|wget|fetch|tftp)\b/i',
            '/\b(?:nc|netcat|ncat|socat)\b/i',

            // Inline Execution
            '/\bpython\s*-c\b/i',
            '/\bperl\s*-e\b/i',
            '/\bphp\s*-r\b/i',

            // /bin/sh -c
            '/\/bin\/(?:ba)?sh\b.*\s-c\b/i',
        ],

        /*
         * URI deny rules.
         */
        'uri_deny'       => [
            '/\.env(\.|$)/i',
            '/^\/\.git/',
            '/^\/vendor\b/',
            '/\.(sql|bak|old|backup|swp)$/i',
        ],
    ],

    /* ---------------------------------------------------------------------
     | Modules
     | ---------------------------------------------------------------------
     |
     | Patterns supports:
     | - Set names: 'sql_keywords'
     | - Direct regex: '/.../i'
     |
     | The modular registry will expand everything into a final regex list.
     */
    'modules'                 => [
        /*
         * IP/CIDR blocklist (IPv4/IPv6).
         */
        'ip_blocklist'     => [
            'class'     => IpBlocklistModule::class,
            'blocklist' => [
                // '1.2.3.4',
                // '10.0.0.0/8',
                // '2001:db8::/32',
            ],
            'on_match'  => 'block',
            'reason'    => 'IP blocked',
        ],

        /*
         * Allowlist HTTP methods.
         */
        'method_allowlist' => [
            'class'   => MethodAllowlistModule::class,
            'allow'   => ['GET', 'POST', 'HEAD'],
            'on_deny' => 'block',
            'reason'  => 'HTTP method not allowed',
        ],

        /*
         * URI allow/deny rules.
         *
         * If allow_* is set, everything that is not matched will be prohibited.
         */
        'uri_rules'        => [
            'class'        => UriAllowDenyModule::class,

            //Allowlist (optional). If not needed, leave blank.
            'allow_prefix' => [
                '/', // Allow all (example). For strict mode, remove and specify dotted paths.
                // '/api',
                // '/login',
            ],
            'allow_regex'  => [
                // '/^\/api\/v\d+\/.*$/',
            ],

            // Deny takes priority
            'deny_prefix'  => [
                '/.env',
                '/.git',
                '/vendor',
            ],
            'deny_regex'   => [
                'uri_deny', // link to pattern set
            ],

            'on_deny' => 'block',
            'reason'  => 'URI is not allowed',
        ],

        /*
         * Path traversal
         */
        'path_traversal'   => [
            'class'    => PathTraversalModule::class,
            'targets'  => ['uri', 'query', 'body', 'cookies'],
            'patterns' => ['path_traversal'],
            'on_match' => 'block',
        ],

        /*
         * LFI
         */
        'lfi'              => [
            'class'    => LfiModule::class,
            'targets'  => ['query', 'body', 'cookies', 'uri'],
            'patterns' => ['lfi_files'],
            'on_match' => 'block',
        ],

        /*
         * RCE
         */
        'rce'              => [
            'class'    => RceModule::class,
            'targets'  => [
                'query',
                'body',
                'cookies',
                //'headers',
                'uri',
            ],
            'patterns' => ['rce_signatures'],
            'on_match' => 'block',
        ],

        /*
         * Bot/scanner detection by headers.
         */
        'bad_bots'         => [
            'class'    => HeaderModule::class,
            'headers'  => ['User-Agent'],
            'patterns' => [
                'bad_bots_ua', // link to pattern set
            ],
            'on_match' => 'challenge',
            'reason'   => 'Bot/scanner detected',
        ],

        /*
         * Rate limit.
         */
        'rate_limit'       => [
            'class'          => RateLimitModule::class,
            'limit'          => 120,
            'interval'       => 60,
            'key_by'         => 'ip', // 'ip+uri'
            'on_exceed'      => 'challenge',
            'reason'         => 'Rate limit exceeded',

            // optional:
            // 'storage_dir'    => __DIR__ . '/../storage/wafu-rl',
            'gc_probability' => 100, // 1/N queries will trigger GC (100 => 1%)
            'ttl_multiplier' => 5,   // ttl = interval * ttl_multiplier
        ],

        /*
         * 404 abuse detection.
         */
        'not_found_abuse'  => [
            'class'          => NotFoundAbuseModule::class,
            'threshold'      => 20,
            'interval'       => 300,
            'key_by'         => 'ip',     // you can 'ip+uri' if you want to count separately for each URI
            'on_exceed'      => 'block',
            'reason'         => 'Too many 404 errors',
            'gc_probability' => 100,
            'ttl_multiplier' => 5,
        ],

        /*
         * SQL injection (regex patterns via set name).
         */
        'sql_injection'    => [
            'class'    => RegexMatchModule::class,
            'targets'  => ['query', 'body', 'cookies'],
            'patterns' => [
                'sql_keywords', // link to pattern set
            ],
            'on_match' => 'block',
            'reason'   => 'SQL injection detected',
        ],

        /*
         * XSS (regex patterns via set name).
         */
        'xss'              => [
            'class'    => RegexMatchModule::class,
            'targets'  => ['query', 'body', 'cookies'],
            'patterns' => [
                'xss_basic', // link to pattern set
            ],
            'on_match' => 'block',
            'reason'   => 'XSS detected',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Remote rules
    |--------------------------------------------------------------------------
    |
    | Allows ruleset retrieval via HTTP, caching on disk, and use without deployment.
    |
    | Kernel:
    | - downloads ruleset if needed (TTL/ETag)
    | - uses cache on error (if use_cache_on_error=true)
    | - merge_strategy:
    |       remote_wins: remote over local
    |       local_wins: local over remote
    |
    | IMPORTANT: The remote_rules section is always taken from the local config (for client control).
    */
    'remote_rules'            => [

        // Enable remote source rules download
        'enabled'            => false,

        // URL to ruleset (JSON)
        // An example of using your endpoint: 'https://waf.example.com/api/v1/rulesets/current',
        'endpoint'           => 'https://raw.githubusercontent.com/BespredeL/wafu/refs/heads/master/ruleset.json',

        // Authorization Headers (added to the request)
        'headers'            => [
            // 'X-WAF-KEY' => 'secret',
            // 'X-WAF-ENV' => 'production',
        ],

        // Disk cache
        'cache_dir'          => sys_get_temp_dir() . '/wafu-cache',
        'cache_file'         => 'ruleset.json',

        // If the remote server is unavailable, use the cache (if available)
        'use_cache_on_error' => true,

        // Force an upper limit on the TTL (0 = no limit).
        // The TTL is taken from ruleset['ttl'] and will be "clipped" to max_ttl.
        'max_ttl'            => 60 * 60 * 24,

        // Ruleset signature verification (optional, requires ext-sodium)
        'signature'          => [
            'enabled'           => false,
            'public_key_base64' => '',
            'field'             => 'signature',
            'algo'              => 'ed25519',
        ],

        // remote_wins: remote over local
        // local_wins : local over remote
        'merge_strategy'     => 'remote_wins',
    ],
];