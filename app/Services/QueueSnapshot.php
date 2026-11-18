<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Models\Counter;
use App\Models\Ticket;

class QueueSnapshot
{
    public function ticket(Ticket $ticket): array
    {
        $ticket->load('counter', 'branch');

        return [
            'id' => $ticket->public_id, 'number' => $ticket->display_number,
            'status' => $ticket->status->value, 'label' => $ticket->status->label(),
            'heading' => $ticket->status === TicketStatus::Waiting ? 'دورك محفوظ.' : $ticket->status->label(),
            'description' => match ($ticket->status) {
                TicketStatus::Called => 'دورك الآن. اتوجه للشباك الموضح في تذكرتك.',
                TicketStatus::Serving => 'جاري تقديم خدمتك. نتشرف بزيارتك.',
                TicketStatus::Completed => 'تمت خدمتك. شكرًا لزيارتك، تقدر تقفل الصفحة.',
                TicketStatus::Cancelled => 'تم إلغاء التذكرة. تقدر تحجز دورًا جديدًا من صفحة الفرع.',
                TicketStatus::NoShow => 'تم تسجيل عدم الحضور. راجع الاستقبال لو ما زلت محتاج الخدمة.',
                TicketStatus::Deferred => 'فاتك النداء. هننادي عليك تاني بعد انتهاء خدمة عميل آخر لنفس الخدمة؛ خليك قريب وتابع الصفحة.',
                TicketStatus::Expired => 'انتهت صلاحية التذكرة. احجز دورًا جديدًا في مواعيد العمل.',
                default => 'خليك متابع الصفحة وشاشات الفرع عشان تعرف وقت النداء.',
            },
            'ahead' => $ticket->status === TicketStatus::Deferred ? null : ($ticket->status->value === 'waiting' ? $ticket->ahead() : 0),
            'ahead_label' => $ticket->status === TicketStatus::Deferred ? 'في انتظار اكتمال خدمة عميل آخر لإعادة النداء' : ($ticket->branch_service_id ? 'منتظرين لنفس الخدمة قبلك' : 'منتظرين بتذاكر قديمة قبلك'),
            'retry_note' => $ticket->retry_limit ? 'فرص النداء الإضافية المستخدمة: '.$ticket->retry_attempts.' من '.$ticket->retry_limit : '',
            'near_turn' => app(CustomerExperienceService::class)->isNear($ticket),
            'estimate' => app(WaitEstimateService::class)->estimate($ticket)['label'],
            'counter' => $ticket->counter?->name, 'version' => $ticket->branch->version,
            'updated_at' => now()->toIso8601String(),
        ];
    }

    public function counter(Counter $counter): array
    {
        return [
            'name' => $counter->name, 'paused' => $counter->is_paused,
            'number' => $counter->currentTicket()?->display_number,
            'version' => $counter->branch->version, 'updated_at' => now()->toIso8601String(),
        ];
    }
}
