<x-queue-layout :title="__('queue.appt_detail_title')">
<div class="centered">
    <div>{{ __('queue.appt_detail_heading') }}</div>
    <section class="panel">
        <div>{{ $appointment->slot->branch->name }}</div>
        <p>{{ $appointment->service->name }}</p>
        <p>{{ $appointment->slot->starts_at->timezone($appointment->slot->branch->timezone)->format('Y-m-d H:i') }}</p>
        @php
            $statusMap = [
                'booked'     => __('queue.appt_status_booked'),
                'checked_in' => __('queue.appt_status_checked_in'),
                'cancelled'  => __('queue.appt_status_cancelled'),
                'expired'    => __('queue.appt_status_expired'),
            ];
        @endphp
        <p>{{ __('queue.appt_status_label', ['status' => $statusMap[$appointment->status] ?? $appointment->status]) }}</p>
        @if($errors->any())<p role="alert">{{ $errors->first() }}</p>@endif
        @if($appointment->status === 'booked')
            <p>{{ __('queue.appt_checkin_instructions') }}</p>
            <form method="post" action="{{ route('appointment.check-in', $secret) }}">@csrf
                <button class="button" data-submit>{{ __('queue.checkin_btn') }}</button>
            </form>
            <form method="post" action="{{ route('appointment.cancel', $secret) }}" style="margin-top:20px">@csrf
                <button class="text-button danger" data-submit>{{ __('queue.cancel_appointment_btn') }}</button>
            </form>
        @elseif($appointment->status === 'checked_in' && $appointment->ticket_id)
            <a class="button" href="{{ route('ticket.show', $secret) }}">{{ __('queue.follow_ticket_btn') }}</a>
        @endif
    </section>
    <p>{{ __('queue.appt_private_note') }}</p>
</div>
</x-queue-layout>
