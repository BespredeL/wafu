<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Adapters\Laravel;

use Bespredel\Wafu\Core\Kernel;
use Closure;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

final class WafuMiddleware
{
    /**
     * @param Kernel          $kernel
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Kernel          $kernel,
        private readonly LoggerInterface $logger
    )
    {
    }

    /**
     * @param Request $request
     * @param Closure $next
     *
     * @return Response
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

        // PSR-3 logger доступен экшенам
        $context->setAttribute('psr_logger', $this->logger);

        if ($decision->isBlocked()) {
            $status = $decision->getStatus() > 0 ? $decision->getStatus() : 403;
            $body = $decision->getBody() ?? ($decision->getReason() ?: 'Blocked by WAFU');
            $resp = response($body, $status);

            foreach ($decision->getHeaders() as $k => $v) {
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