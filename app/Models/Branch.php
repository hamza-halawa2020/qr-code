<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Throwable;

class Branch extends Model
{
    protected $guarded = [];
    protected $hidden = ['board_token_hash', 'board_token'];

    /** Match the database defaults so freshly created models also have valid hours. */
    protected $attributes = [
        'timezone'  => 'Africa/Cairo',
        'opens_at'  => '09:00',
        'closes_at' => '17:00',
    ];

    protected function casts(): array
    {
        return ['is_open' => 'boolean', 'is_active' => 'boolean', 'automatic_queue' => 'boolean', 'appointments_enabled' => 'boolean'];
    }

    public function counters(): HasMany
    {
        return $this->hasMany(Counter::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(BranchService::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(BranchSchedule::class);
    }

    public function appointmentSlots(): HasMany { return $this->hasMany(AppointmentSlot::class); }

    /** Always returns 7 rows (0=Sunday … 6=Saturday), filling gaps from the legacy columns. */
    public function weeklySchedule(): Collection
    {
        $saved = ($this->relationLoaded('schedules') ? $this->getRelation('schedules') : $this->schedules()->get())->keyBy('day_of_week');

        return collect(range(0, 6))->map(fn (int $day) => $saved->get($day) ?? new BranchSchedule([
            'branch_id'   => $this->id,
            'day_of_week' => $day,
            'opens_at'    => $this->opens_at,
            'closes_at'   => $this->closes_at,
            'is_closed'   => false,
        ]));
    }

    public function hoursFor(Carbon|string|null $date = null): array
    {
        $moment = $this->branchMoment($date);
        $row = $this->weeklySchedule()->get($moment->dayOfWeek);

        return [
            'opens_at'  => $row->opens_at ?: $this->opens_at,
            'closes_at' => $row->closes_at ?: $this->closes_at,
            'is_closed' => (bool) $row->is_closed,
        ];
    }

    public function hoursLabelFor(Carbon|string|null $date = null): string
    {
        $hours = $this->hoursFor($date);

        return $hours['is_closed']
            ? __('queue.day_closed')
            : $hours['opens_at'].' — '.$hours['closes_at'];
    }

    public function acceptsBookingsAt(Carbon $moment): bool
    {
        $local = $moment->copy()->timezone($this->timezone ?: 'Africa/Cairo');
        $hours = $this->hoursFor($local);
        $time = $local->format('H:i');

        return $this->is_open
            && ! $hours['is_closed']
            && $time >= $hours['opens_at']
            && $time < $hours['closes_at'];
    }

    public function acceptsBookings(): bool
    {
        return $this->acceptsBookingsAt(now());
    }

    /** The saved public board link for this branch, if it has been generated. */
    public function boardUrl(): ?string
    {
        if (! $this->board_token) {
            return null;
        }

        try {
            return route('board.show', [$this, decrypt($this->board_token)]);
        } catch (Throwable) {
            return null;
        }
    }

    private function branchMoment(Carbon|string|null $date): Carbon
    {
        $timezone = $this->timezone ?: 'Africa/Cairo';

        if ($date instanceof Carbon) {
            return $date->copy()->timezone($timezone);
        }

        return $date ? Carbon::parse($date, $timezone) : now($timezone);
    }
}
