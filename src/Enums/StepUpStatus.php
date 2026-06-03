<?php

declare(strict_types=1);

namespace Padosoft\Rebel\StepUp\Enums;

enum StepUpStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Failed = 'failed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}
