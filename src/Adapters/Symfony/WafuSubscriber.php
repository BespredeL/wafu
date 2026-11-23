<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Adapters\Symfony;

use Bespredel\Wafu\Core\Context;
use Bespredel\Wafu\Core\Kernel;
use Bespredel\Wafu\Core\ContextKeys;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class WafuSubscriber implements EventSubscriberInterface
{
    /**
     * Constructor.
     *
     * @param Kernel          $wafKernel
     * @param LoggerInterface $logger
     */
    public function __construct(
        private Kernel          $wafKernel,
        private LoggerInterface $logger
    )
    {
    }

    /**
     * Get subscribed events.
     *
     * @return array[]
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST  => ['onKernelRequest', 100],
            KernelEvents::RESPONSE => ['onKernelResponse', -100],
        ];
    }

    /**
     * Handle kernel request.
     *
     * @param RequestEvent $event
     *
     * @return void
     *
     * @throws \ReflectionException
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower($name)] = is_array($values) ? implode(', ', $values) : (string)$values;
        }

        $result = $this->wafKernel->handleWithContext(
            $request->server->all(),
            $request->query->all(),
            $request->request->all(),
            $request->cookies->all(),
            $headers,
            [ContextKeys::LOGGER => $this->logger]
        );

        $context = $result['context'];
        $decision = $result['decision'];

        $request->attributes->set('_wafu_context', $context);

        if ($decision->isBlocked()) {
            $respSpec = $context->getAttribute(ContextKeys::RESPONSE, []);

            $status = $decision->getStatus() > 0
                ? $decision->getStatus()
                : (int)($respSpec['status'] ?? Response::HTTP_FORBIDDEN);

            $body = $decision->getBody()
                ?? (is_string($respSpec['body'] ?? null) ? $respSpec['body'] : null)
                ?? ($decision->getReason() ?: 'Blocked by WAFU');

            $headersOut = array_replace((array)($respSpec['headers'] ?? []), $decision->getHeaders());
            if (!isset($headersOut['Content-Type']) && !isset($headersOut['content-type'])) {
                $headersOut['Content-Type'] = 'text/plain; charset=utf-8';
            }

            $event->setResponse(new Response($body, $status, $headersOut));
        }
    }

    /**
     * Handle kernel response (e.g. for NotFoundAbuse module).
     *
     * @param ResponseEvent $event
     *
     * @return void
     *
     * @throws \ReflectionException
     */
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();
        if ($response->getStatusCode() === Response::HTTP_NOT_FOUND) {
            $request = $event->getRequest();
            $context = $request->attributes->get('_wafu_context');
            if ($context instanceof Context) {
                $this->wafKernel->handleResponse($context, 404);
            }
        }
    }
}