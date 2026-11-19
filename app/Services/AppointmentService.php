<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\Branch;
use App\Models\OutboxMessage;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AppointmentService
{
    public function slots(Branch $branch, array $input): array
    {
        $data = Validator::make($input, ['date' => 'nullable|date_format:Y-m-d', 'service_id' => ['nullable', 'integer', Rule::exists('branch_services', 'id')->where('branch_id', $branch->id)->where('is_active', true)]])->validate();
        $date = $data['date'] ?? now($branch->timezone)->toDateString();
        $start = Carbon::parse($date, $branch->timezone)->utc();
        $end = Carbon::parse($date, $branch->timezone)->addDay()->utc();
        $branch->loadMissing('schedules');
        $slots = AppointmentSlot::with('service')->withCount(['appointments as reserved' => fn ($q) => $q->whereIn('status', ['booked', 'checked_in'])])
            ->where('branch_id', $branch->id)->whereHas('service', fn ($q) => $q->where('is_active', true))
            ->where('starts_at', '>=', $start)->where('starts_at', '<', $end)->where('starts_at', '>', now())
            ->when($data['service_id'] ?? null, fn ($q, $id) => $q->where('branch_service_id', $id))->orderBy('starts_at')->get()
            ->filter(fn ($slot) => $this->withinHours($branch, $slot->starts_at))->values();

        return ['branch' => $branch, 'services' => $branch->services()->where('is_active', true)->get(), 'date' => $date, 'selectedService' => $data['service_id'] ?? null, 'appointmentSlots' => $slots];
    }

    public function book(Branch $branch, Session $session, array $input): string
    {
        $data = Validator::make($input, ['slot_id' => 'required|integer', 'customer_name' => 'required|string|max:120', 'customer_phone' => 'required|string|max:30', 'customer_email' => 'nullable|email|max:255'])->validate();
        $key = 'appointment.'.$branch->id.'.'.$data['slot_id'];
        $secret = $session->get($key) ?? Str::random(64);
        if (Appointment::where('token_hash', hash('sha256', $secret))->whereIn('status', ['cancelled', 'expired'])->exists()) {
            $secret = Str::random(64);
        }
        $session->put($key, $secret);
        DB::transaction(function () use ($branch, $secret, $data) {
            Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $slot = AppointmentSlot::where('branch_id', $branch->id)->lockForUpdate()->findOrFail($data['slot_id']);
            if (Appointment::where('token_hash', hash('sha256', $secret))->exists()) {
                return;
            }
            $branch->loadMissing('schedules');
            $this->require($slot->starts_at->isFuture() && $slot->starts_at->lte(now()->addDays(30)), 'الموعد لم يعد متاحًا للحجز.');
            $this->require($this->withinHours($branch, $slot->starts_at), 'مواعيد العمل في هذا اليوم لا تسمح بهذا الحجز.');
            $details = app(BookingDetailsService::class)->validate($branch, ['service_id' => $slot->branch_service_id, 'customer_phone' => $data['customer_phone']]);
            $this->require($slot->appointments()->whereIn('status', ['booked', 'checked_in'])->count() < $slot->capacity, 'اكتمل حجز هذا الموعد. اختار وقتًا آخر.');
            Appointment::create(['appointment_slot_id' => $slot->id, 'token_hash' => hash('sha256', $secret), 'customer_name' => trim($data['customer_name']), 'customer_phone' => $details['customer_phone'], 'customer_email' => $data['customer_email'] ?? null]);
        }, 5);

        return $secret;
    }

    public function find(string $secret): Appointment
    {
        abort_unless(strlen($secret) === 64, 404);

        return Appointment::with(['slot.branch', 'slot.service', 'ticket'])->where('token_hash', hash('sha256', $secret))->firstOrFail();
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
            $ticket = app(QueueService::class)->book($branch, $secret, 'appointment-'.$appointment->id, ['service_id' => $appointment->slot->branch_service_id, 'customer_phone' => $appointment->customer_phone], true);
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

    public function createSlot(User $actor, ?int $branchId, array $input): void
    {
        $admin = User::findOrFail($actor->id);
        abort_unless($admin->is_active && $admin->role === 'admin', 403);
        $branch = Branch::findOrFail($branchId);
        $data = Validator::make($input, ['serviceId' => ['required', 'integer', Rule::exists('branch_services', 'id')->where('branch_id', $branch->id)->where('is_active', true)], 'date' => 'required|date_format:Y-m-d', 'time' => 'required|date_format:H:i', 'capacity' => 'required|integer|min:1|max:100'])->validate();
        $starts = Carbon::createFromFormat('Y-m-d H:i', $data['date'].' '.$data['time'], $branch->timezone)->second(0)->utc();
        $this->require($starts->isFuture() && $starts->lte(now()->addDays(30)), 'اختار موعدًا قادمًا خلال 30 يومًا.');
        $this->require($this->withinHours($branch, $starts), 'اختار وقتًا داخل مواعيد عمل الفرع.');
        DB::transaction(function () use ($branch, $data, $starts) {
            Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $this->require(! AppointmentSlot::where('branch_service_id', $data['serviceId'])->where('starts_at', $starts)->exists(), 'الموعد موجود بالفعل.');
            AppointmentSlot::create(['branch_id' => $branch->id, 'branch_service_id' => $data['serviceId'], 'starts_at' => $starts, 'capacity' => $data['capacity']]);
        });
    }

    public function adminData(User $user, ?int $branchId): array
    {
        $branches = app(BranchAccessService::class)->branches($user);
        $branch = $branches->firstWhere('id', $branchId);

        return ['branches' => $branches, 'branch' => $branch, 'services' => $branch?->services()->get() ?? collect(),
            'appointmentSlots' => AppointmentSlot::with('service')->withCount(['appointments as reserved' => fn ($q) => $q->whereIn('status', ['booked', 'checked_in'])])->where('branch_id', $branch?->id ?? 0)->where('starts_at', '>=', now()->subDay())->orderBy('starts_at')->limit(100)->get(),
            'appointments' => Appointment::with(['slot.service', 'ticket'])->whereHas('slot', fn ($q) => $q->where('branch_id', $branch?->id ?? 0))->orderByDesc('id')->limit(100)->get()];
    }

    private function withinHours(Branch $branch, Carbon $moment): bool
    {
        $hours = $branch->hoursFor($moment);
        $time = $moment->copy()->timezone($branch->timezone ?: 'Africa/Cairo')->format('H:i');

        return ! $hours['is_closed'] && $time >= $hours['opens_at'] && $time < $hours['closes_at'];
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['appointment' => $message]);
        }
    }
}
