<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Adapters\Laravel;

use Bespredel\Wafu\Core\Kernel;
use Bespredel\Wafu\Core\ContextKeys;
use Closure;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

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
     *
     * @return Response
     *
     * @throws \ReflectionException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower($name)] = is_array($values) ? implode(', ', $values) : (string)$values;
        }

        $result = $this->kernel->handleWithContext(
            $request->server->all(),
            $request->query->all(),
            $request->request->all(),
            $request->cookies->all(),
            $headers
        );

        $context = $result['context'];
        $decision = $result['decision'];

        // PSR-3 logger is available for action
        $context->setAttribute(ContextKeys::LOGGER, $this->logger);

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

        return $next($request);
    }
}