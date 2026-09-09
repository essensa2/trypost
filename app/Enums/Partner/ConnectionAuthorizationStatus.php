<?php

declare(strict_types=1);

namespace App\Enums\Partner;

enum ConnectionAuthorizationStatus: string
{
    case Pending = 'pending';
    case Claimed = 'claimed';
    case Completed = 'completed';
    case Expired = 'expired';
    case Revoked = 'revoked';
}
