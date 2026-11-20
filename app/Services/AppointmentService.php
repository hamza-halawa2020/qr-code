<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\Branch;
use App\Models\OutboxMessage;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AppointmentService
{
    /** Allowed gaps between two consecutive bookings, in minutes. */
    public const INTERVALS = [15, 30, 45, 60, 90, 120];

    public function slots(Branch $branch, array $input): array
    {
        $data = Validator::make($input, ['date' => 'nullable|date_format:Y-m-d'])->validate();
        $date = $data['date'] ?? now($branch->timezone)->toDateString();

        return [
            'branch' => $branch,
            'services' => $branch->services()->where('is_active', true)->get(),
            'date' => $date,
            'appointmentSlots' => $this->slotView($branch, $this->generateTimes($branch, $date)),
        ];
    }

    public function book(Branch $branch, Session $session, array $input): string
    {
        $data = Validator::make($input, [
            'date' => 'required|date_format:Y-m-d',
            'time' => 'required|date_format:H:i',
            'service_id' => ['required', 'integer', Rule::exists('branch_services', 'id')->where('branch_id', $branch->id)->where('is_active', true)],
            'customer_name' => 'required|string|max:120',
            'customer_phone' => 'required|string|max:30',
            'customer_email' => 'nullable|email|max:255',
        ])->validate();

        $key = 'appointment.'.$branch->id.'.'.$data['date'].'.'.$data['time'];
        $secret = $session->get($key) ?? Str::random(64);
        if (Appointment::where('token_hash', hash('sha256', $secret))->whereIn('status', ['cancelled', 'expired'])->exists()) {
            $secret = Str::random(64);
        }
        $session->put($key, $secret);

        DB::transaction(function () use ($branch, $secret, $data) {
            Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();

            $times = $this->generateTimes($branch, $data['date']);
            $requested = Carbon::createFromFormat('Y-m-d H:i', $data['date'].' '.$data['time'], $branch->timezone ?: 'Africa/Cairo')->second(0)->utc();
            $this->require(in_array($requested->toDateTimeString(), array_map(fn (Carbon $time) => $time->toDateTimeString(), $times), true), 'الموعد غير متاح. اختار وقتًا من الأوقات المعروضة.');
            $this->require($requested->lte(now()->addDays(30)), 'الموعد لم يعد متاحًا للحجز.');

            $slot = AppointmentSlot::firstOrCreate(['branch_id' => $branch->id, 'starts_at' => $requested]);
            $this->require($slot->appointments()->whereIn('status', ['booked', 'checked_in'])->count() < 1, 'اكتمل حجز هذا الموعد. اختار وقتًا آخر.');

            $details = app(BookingDetailsService::class)->validate($branch, ['service_id' => $data['service_id'], 'customer_phone' => $data['customer_phone']]);
            Appointment::create([
                'appointment_slot_id' => $slot->id,
                'branch_service_id' => $data['service_id'],
                'token_hash' => hash('sha256', $secret),
                'customer_name' => trim($data['customer_name']),
                'customer_phone' => $details['customer_phone'],
                'customer_email' => $data['customer_email'] ?? null,
            ]);
        }, 5);

        return $secret;
    }

    public function find(string $secret): Appointment
    {
        abort_unless(strlen($secret) === 64, 404);

        return Appointment::with(['slot.branch', 'service', 'ticket'])->where('token_hash', hash('sha256', $secret))->firstOrFail();
    }

    public function checkIn(string $secret, Session $session): string
    {
        $appointment = $this->find($secret);

        return DB::transaction(function () use ($appointment, $secret, $session) {
            $branch = Branch::whereKey($appointment->slot->branch_id)->lockForUpdate()->firstOrFail();
            $appointment = Appointment::with('slot')->lockForUpdate()->findOrFail($appointment->id);
            if ($appointment->status === 'checked_in' && $appointment->ticket_id) {
                return $secret;
            }
            $this->require($appointment->status === 'booked', 'هذا الموعد غير متاح لتأكيد الحضور.');
            $this->require(now()->betweenIncluded($appointment->slot->starts_at->copy()->subMinutes(15), $appointment->slot->starts_at->copy()->addMinutes(30)), 'تأكيد الحضور متاح من 15 دقيقة قبل الموعد حتى 30 دقيقة بعده.');
            $active = app(CustomerQueueService::class)->branchData($branch, $session)['active'];
            $this->require(! $active?->status->isActive(), 'عندك تذكرة نشطة بالفعل؛ أنهِها أو ألغِها قبل تأكيد موعدك.');
            $ticket = app(QueueService::class)->book($branch, $secret, 'appointment-'.$appointment->id, ['service_id' => $appointment->branch_service_id, 'customer_phone' => $appointment->customer_phone], true);
            $ticket->update(['appointment_at' => $appointment->slot->starts_at, 'customer_name' => $appointment->customer_name]);
            $branch->increment('version');
            OutboxMessage::create(['branch_id' => $branch->id, 'ticket_id' => $ticket->id, 'version' => $branch->version, 'type' => 'appointment_checked_in']);
            $appointment->update(['status' => 'checked_in', 'ticket_id' => $ticket->id]);
            $session->put('ticket.'.$branch->id, $secret);

            return $secret;
        }, 5);
    }

    public function cancel(string $secret): void
    {
        $appointment = $this->find($secret);
        DB::transaction(function () use ($appointment) {
            Branch::whereKey($appointment->slot->branch_id)->lockForUpdate()->firstOrFail();
            $appointment = Appointment::lockForUpdate()->findOrFail($appointment->id);
            $this->require($appointment->status === 'booked', 'لا يمكن إلغاء موعد تم تأكيد حضوره من هنا.');
            $appointment->update(['status' => 'cancelled']);
        });
    }

    /** Change the branch-wide gap between bookings. */
    public function saveInterval(User $actor, ?int $branchId, mixed $interval): void
    {
        $admin = User::findOrFail($actor->id);
        abort_unless($admin->is_active && $admin->role === 'admin', 403);
        $data = Validator::make(['interval' => (int) $interval], ['interval' => ['required', Rule::in(self::INTERVALS)]])->validate();
        Branch::findOrFail($branchId)->update(['appointment_interval_minutes' => $data['interval']]);
    }

    public function adminData(User $user, ?int $branchId): array
    {
        $branches = app(BranchAccessService::class)->branches($user);
        $branch = $branches->firstWhere('id', $branchId);
        $today = now($branch?->timezone ?: 'Africa/Cairo')->toDateString();
        $slots = collect();

        if ($branch) {
            foreach (range(0, 2) as $offset) {
                $slots = $slots->concat($this->slotView($branch, $this->generateTimes($branch, Carbon::parse($today)->addDays($offset)->toDateString())));
            }
        }

        return [
            'branches' => $branches,
            'branch' => $branch,
            'intervals' => self::INTERVALS,
            'appointmentSlots' => $slots,
            'appointments' => Appointment::with(['service', 'ticket', 'slot'])->whereHas('slot', fn ($q) => $q->where('branch_id', $branch?->id ?? 0))->orderByDesc('id')->limit(100)->get(),
        ];
    }

    /**
     * Start times for one branch day, expressed in UTC.
     * The last start is the one whose full slot still fits before closing.
     *
     * @return array<int, Carbon>
     */
    private function generateTimes(Branch $branch, string $date): array
    {
        $timezone = $branch->timezone ?: 'Africa/Cairo';
        $hours = $branch->hoursFor($date);
        if ($hours['is_closed'] || ! $hours['opens_at'] || ! $hours['closes_at']) {
            return [];
        }

        $interval = (int) ($branch->appointment_interval_minutes ?: 60);
        if (! in_array($interval, self::INTERVALS, true)) {
            $interval = 60;
        }

        $opens = Carbon::createFromFormat('Y-m-d H:i', $date.' '.$hours['opens_at'], $timezone)->second(0);
        $closes = Carbon::createFromFormat('Y-m-d H:i', $date.' '.$hours['closes_at'], $timezone)->second(0);
        $now = now($timezone);
        $times = [];

        for ($cursor = $opens->copy(); $cursor->copy()->addMinutes($interval)->lessThanOrEqualTo($closes); $cursor->addMinutes($interval)) {
            if ($cursor->greaterThan($now)) {
                $times[] = $cursor->copy()->utc();
            }
        }

        return $times;
    }

    /** Attach booking counts to the generated times, reusing stored slots when present. */
    private function slotView(Branch $branch, array $times): Collection
    {
        if ($times === []) {
            return collect();
        }

        $keys = array_map(fn (Carbon $time) => $time->toDateTimeString(), $times);
        $existing = AppointmentSlot::where('branch_id', $branch->id)->whereIn('starts_at', $keys)
            ->withCount(['appointments as reserved' => fn ($q) => $q->whereIn('status', ['booked', 'checked_in'])])
            ->get()->keyBy(fn (AppointmentSlot $slot) => $slot->starts_at->toDateTimeString());

        return collect($times)->map(function (Carbon $time) use ($existing) {
            $slot = $existing->get($time->toDateTimeString());
            $reserved = (int) ($slot?->reserved ?? 0);

            return (object) [
                'id' => $slot?->id,
                'starts_at' => $time,
                'reserved' => $reserved,
                'available' => max(0, 1 - $reserved),
            ];
        })->values();
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['appointment' => $message]);
        }
    }
}
