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
     * @param Context $context
     *
     * @return void
     */
    public function execute(Context $context): void
    {
        $context->setAttribute('wafu.response', [
            'status'  => $this->statusCode,
            'headers' => ['Content-Type' => 'text/plain; charset=utf-8'],
            'body'    => $this->message,
        ]);

        // standalone termination
        if ($this->terminate) {
            if (!headers_sent()) {
                http_response_code($this->statusCode);
                header('Content-Type: text/plain; charset=utf-8');
            }

            echo $this->message;

            exit;
        }
    }
}
