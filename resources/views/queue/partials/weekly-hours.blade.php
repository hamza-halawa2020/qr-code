@php($today = now($branch->timezone ?: 'Africa/Cairo')->dayOfWeek)
<div class="weekly-hours">
    <div class="weekly-hours-title">{{ __('queue.branch_hours') }}</div>
    <ul class="hours-list">
        @foreach($branch->weeklySchedule() as $row)
            <li @class(['is-today' => $row->day_of_week === $today])>
                <span>{{ $row->dayLabel() }}</span>
                <b>{{ $row->hoursLabel() }}</b>
            </li>
        @endforeach
    </ul>
</div>
