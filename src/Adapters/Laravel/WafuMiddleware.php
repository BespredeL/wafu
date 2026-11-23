<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Adapters\Laravel;

use Bespredel\Wafu\Core\ContextKeys;
use Bespredel\Wafu\Core\Kernel;
use Closure;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laravel middleware for WAFU.
 *
 * Usage - apply all configured modules:
 *   Route::middleware('wafu')->group(function () { ... });
 *
 * Usage - apply only specific modules by their snake_case alias:
 *   Route::middleware('wafu:ip_blocklist,rate_limit')->group(function () { ... });
 *   Route::middleware('wafu:sql_injection,xss,lfi')->prefix('/admin')->group(function () { ... });
 *
 * The alias is derived from the module class basename converted to snake_case,
 * e.g. IpBlocklistModule => ip_blocklist_module  (or simply "ip_blocklist").
 * Both the full snake_case name and any prefix up to "_module" are accepted.
 */
final class WafuMiddleware
{
    /**
     * Constructor.
     *
     * @param Kernel          $kernel
     * @param LoggerInterface $logger
     */
    public function __construct(
        private Kernel          $kernel,
        private LoggerInterface $logger
    )
    {
    }

    /**
     * Handle the incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @param string  ...$modules Optional list of module aliases to run (empty = all configured modules).
     *
     * @return Response
     *
     * @throws \ReflectionException
     */
    public function handle(Request $request, Closure $next, string ...$modules): Response
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower($name)] = is_array($values) ? implode(', ', $values) : (string)$values;
        }

        $only = $this->normalizeModules($modules);

        $result = $this->kernel->handleWithContext(
            $request->server->all(),
            $request->query->all(),
            $request->request->all(),
            $request->cookies->all(),
            $headers,
            [ContextKeys::LOGGER => $this->logger],
            $only
        );

        $context = $result['context'];
        $decision = $result['decision'];

        if ($decision->isBlocked()) {
            $respSpec = $context->getAttribute(ContextKeys::RESPONSE, []);

            $status = $decision->getStatus() > 0
                ? $decision->getStatus()
                : (int)($respSpec['status'] ?? 403);

            $body = $decision->getBody()
                ?? (is_string($respSpec['body'] ?? null) ? $respSpec['body'] : null)
                ?? ($decision->getReason() ?: 'Blocked by WAFU');

            $resp = response($body, $status);

            $headersOut = array_replace((array)($respSpec['headers'] ?? []), $decision->getHeaders());
            foreach ($headersOut as $k => $v) {
                $resp->header((string)$k, (string)$v);
            }

            if (!$resp->headers->has('Content-Type')) {
                $resp->header('Content-Type', 'text/plain; charset=utf-8');
            }

            return $resp;
        }

        $response = $next($request);

        if ($response->getStatusCode() === 404) {
            $this->kernel->handleResponse($context, 404, $only);
        }

        return $response;
    }

    /**
     * Normalize raw middleware parameter strings into a flat array of module aliases.
     *
     * @param string[] $modules
     *
     * @return string[]
     */
    private function normalizeModules(array $modules): array
    {
        if ($modules === []) {
            return [];
        }

        $result = [];
        foreach ($modules as $param) {
            foreach (explode(',', $param) as $alias) {
                $alias = trim($alias);
                if ($alias !== '') {
                    $result[] = $alias;
                }
            }
        }

        return $result;
    }
}