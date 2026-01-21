<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Actions;

use Bespredel\Wafu\Contracts\ActionInterface;
use Bespredel\Wafu\Core\Context;

final class BlockAction implements ActionInterface
{
    /**
     * @param int    $statusCode
     * @param string $message
     * @param bool   $terminate
     */
    public function __construct(
        private int    $statusCode = 403,
        private string $message = 'Blocked by WAFU',
        private bool   $terminate = true
    )
    {
    }

    /**
     * Execute action.
     *
     * @param Context $context
     *
     * @return void
     */
    public function execute(Context $context): void
    {
        if ($this->statusCode < 100 || $this->statusCode >= 600) {
            $this->statusCode = 403;
        }

        $safeMessage = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $this->message);
        if ($safeMessage === null) {
            $safeMessage = 'Blocked by WAFU';
        }

        $context->setAttribute('wafu.response', [
            'status'  => $this->statusCode,
            'headers' => ['Content-Type' => 'text/plain; charset=utf-8'],
            'body'    => $safeMessage,
        ]);

        // Standalone termination
        if ($this->terminate) {
            if (!headers_sent()) {
                http_response_code($this->statusCode);
                header('Content-Type: text/plain; charset=utf-8');
            }

            echo $safeMessage;

            exit;
        }
    }
}
