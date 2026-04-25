<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Core;

final class ContextKeys
{
    public const MATCH            = 'wafu.match';
    public const RESPONSE         = 'wafu.response';
    public const LOGGER           = 'psr_logger';
    public const HTTP_STATUS_CODE = 'http_status_code';
    public const REPORT_ONLY      = 'wafu.report_only';
    public const REPORT_DECISION  = 'wafu.report_decision';
}
