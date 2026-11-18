<?php

namespace App\Enums;

enum TicketStatus: string
{
    case Waiting = 'waiting';
    case Deferred = 'deferred';
    case Called = 'called';
    case Serving = 'serving';
    case Completed = 'completed';
    case NoShow = 'no_show';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Waiting => 'في الانتظار', self::Called => 'دورك الآن',
            self::Deferred => 'في انتظار إعادة النداء',
            self::Serving => 'جاري تقديم الخدمة', self::Completed => 'تمت الخدمة',
            self::NoShow => 'لم يحضر', self::Cancelled => 'ملغاة', self::Expired => 'انتهت الصلاحية',
        };
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Waiting, self::Deferred, self::Called, self::Serving], true);
    }
}
