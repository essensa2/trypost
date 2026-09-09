<?php

declare(strict_types=1);

namespace App\Enums\Partner;

use App\Enums\SocialAccount\Platform;

enum ConnectionPlatform: string
{
    case Facebook = 'facebook';
    case Instagram = 'instagram';

    public function socialPlatform(): Platform
    {
        return match ($this) {
            self::Facebook => Platform::Facebook,
            self::Instagram => Platform::Instagram,
        };
    }
}
