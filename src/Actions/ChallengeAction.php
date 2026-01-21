<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Actions;

use Bespredel\Wafu\Contracts\ActionInterface;
use Bespredel\Wafu\Core\Context;

final class ChallengeAction implements ActionInterface
{
    /**
     * @param int    $statusCode
     * @param string $message
     * @param int    $retryAfter
     * @param bool   $terminate
     */
    public function __construct(
        private int    $statusCode = 429,
        private string $message = 'Too many requests. Please complete the challenge.',
        private int    $retryAfter = 10,
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
        $headers = [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Retry-After'  => (string)$this->retryAfter,
        ];

        $context->setAttribute('wafu.response', [
            'status'  => $this->statusCode,
            'headers' => $headers,
            'body'    => $this->message,
        ]);

        if ($this->terminate) {
            if (!headers_sent()) {
                http_response_code($this->statusCode);
                header('Content-Type: text/plain; charset=utf-8');
                header('Retry-After: ' . $this->retryAfter);
            }

            echo $this->message;
            
            exit;
        }
    }
}