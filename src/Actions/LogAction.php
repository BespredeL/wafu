<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Actions;

use Bespredel\Wafu\Contracts\ActionInterface;
use Bespredel\Wafu\Core\Context;
use Psr\Log\LoggerInterface;

final class LogAction implements ActionInterface
{
    /**
     * @param string $channel
     * @param string $level
     */
    public function __construct(
        private string $channel = 'wafu',
        private string $level = 'warning'
    )
    {
    }

    /**
     * @param Context $context
     *
     * @return void
     */
    public function execute(Context $context): void
    {
        $message = sprintf(
            '%s request %s from %s',
            $context->getMethod(),
            $context->getUri(),
            $context->getIp()
        );

        $payload = [
            'channel' => $this->channel,
            'level'   => $this->level,
            'ip'      => $context->getIp(),
            'method'  => $context->getMethod(),
            'uri'     => $context->getUri(),
            'headers' => $context->getHeaders(),
            'query'   => $context->getQuery(),
            'body'    => $context->getBody(),
            'match'   => $context->getAttribute('wafu.match'),
        ];

        $logger = $context->getAttribute('psr_logger');
        if ($logger instanceof LoggerInterface) {
            $lvl = strtolower($this->level);
            $lvl = in_array($lvl, [
                'debug',
                'info',
                'notice',
                'warning',
                'error',
                'critical',
                'alert',
                'emergency',
            ], true) ? $lvl : 'warning';

            $logger->log($lvl, $message, $payload);
            return;
        }

        $record = sprintf(
            "[%s][%s][%s] %s %s\n",
            date('Y-m-d H:i:s'),
            $this->channel,
            strtoupper($this->level),
            $message,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        error_log($record);
    }
}