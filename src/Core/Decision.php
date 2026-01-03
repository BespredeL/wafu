<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Core;

use Bespredel\Wafu\Contracts\ActionInterface;

final class Decision
{
    /**
     * @var bool
     */
    private bool $blocked;

    /**
     * @var string
     */
    private string $reason;

    /**
     * @var ActionInterface|null
     */
    private ?ActionInterface $action;

    /**
     * @var int
     */
    private int $status;

    /**
     * @var array
     */
    private array $headers;

    /**
     * @var string|null
     */
    private ?string $body;

    /**
     * @var array
     */
    private array $meta;

    /**
     * @param bool                 $blocked
     * @param string               $reason
     * @param ActionInterface|null $action
     * @param int                  $status
     * @param array                $headers
     * @param string|null          $body
     * @param array                $meta
     */
    private function __construct(
        bool             $blocked,
        string           $reason = '',
        ?ActionInterface $action = null,
        int              $status = 0,
        array            $headers = [],
        ?string          $body = null,
        array            $meta = []
    )
    {
        $this->blocked = $blocked;
        $this->reason = $reason;
        $this->action = $action;
        $this->status = $status;
        $this->headers = $headers;
        $this->body = $body;
        $this->meta = $meta;
    }

    /**
     * @return self
     */
    public static function allow(): self
    {
        return new self(false);
    }

    /**
     * @param ActionInterface $action
     * @param string          $reason
     *
     * @return self
     */
    public static function block(ActionInterface $action, string $reason = 'Blocked by WAFU'): self
    {
        return new self(true, $reason, $action);
    }

    /**
     * @param ActionInterface $action
     * @param string          $reason
     *
     * @return self
     */
    public static function action(ActionInterface $action, string $reason = ''): self
    {
        return new self(false, $reason, $action);
    }

    /**
     * Blocking with a ready-made response specification (via middleware/subscriber).
     *
     * @param ActionInterface $action
     * @param string          $reason
     * @param int             $status
     * @param array           $headers
     * @param string|null     $body
     * @param array           $meta
     *
     * @return self
     */
    public static function blockWithResponse(
        ActionInterface $action,
        string          $reason,
        int             $status,
        array           $headers = [],
        ?string         $body = null,
        array           $meta = []
    ): self
    {
        return new self(true, $reason, $action, $status, $headers, $body, $meta);
    }

    /**
     * @return bool
     */
    public function isBlocked(): bool
    {
        return $this->blocked;
    }

    /**
     * @return string
     */
    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * @return ActionInterface|null
     */
    public function getAction(): ?ActionInterface
    {
        return $this->action;
    }

    /**
     * @return int
     */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * @return array
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * @return string|null
     */
    public function getBody(): ?string
    {
        return $this->body;
    }

    /**
     * @return array
     */
    public function getMeta(): array
    {
        return $this->meta;
    }
}