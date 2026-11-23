<?php

namespace App\Models;

use App\Enums\TicketStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketEvent extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'from_status' => TicketStatus::class, 'to_status' => TicketStatus::class];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function label(): string
    {
        $key = 'queue.event_' . $this->type;
        $translated = __($key);
        if ($translated !== $key) {
            return $translated;
        }
        // Arabic fallbacks for unrecognised types
        return match ($this->type) {
            'booked'       => 'حجز التذكرة',
            'called'       => 'استدعاء العميل',
            'recalled'     => 'إعادة النداء',
            'start'        => 'بدء الخدمة',
            'complete'     => 'إنهاء الخدمة',
            'absent'       => 'تسجيل الغياب',
            'cancel'       => 'إلغاء التذكرة',
            'day_closed'   => 'انتهاء يوم العمل',
            'deferred'     => 'تأجيل لإعادة النداء',
            'retry_ready'  => 'جاهز لإعادة النداء',
            'retry_called' => 'نداء فرصة إضافية',
            'requeued'     => 'إرجاع للطابور',
            default        => $this->type,
        };
    }
}
