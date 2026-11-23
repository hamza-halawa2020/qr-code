<x-queue-layout :title="$branch->name . ' | ' . __('queue.site_title')">
    <div class="centered">
        <span class="eyebrow">{{ $branch->name }}</span>
        <div>{!! __('queue.branch_heading') !!}</div>
        <p>{{ __('queue.branch_subheading') }}</p>
        @include('queue.partials.weekly-hours', ['branch' => $branch])
        @if($branch->appointments_enabled)
        <p><a class="button secondary" href="{{ route('appointments.index', $branch) }}">{{ __('queue.appointment_cta') }}</a></p>
        @endif
        <section class="panel booking-panel">
            <span class="status-dot {{ $branch->acceptsBookings() ? '' : 'off' }}">
                {{ $branch->acceptsBookings() ? __('queue.accepting_now') : __('queue.booking_closed') }}
            </span>
            @if($active?->status->isActive())
                <div>{{ __('queue.active_ticket_heading', ['number' => $active->display_number]) }}</div>
                <a class="button" href="{{ route('ticket.show', $secret) }}">{{ __('queue.follow_my_ticket') }}</a>
            @elseif($branch->acceptsBookings() && $services->isNotEmpty())
                <div>{{ __('queue.book_heading') }}</div>
                <p>{{ __('queue.book_subheading') }}</p>
                <form method="post" action="{{ route('branch.book', $branch) }}" class="booking-form">@csrf
                    <label for="service_id">{{ __('queue.label_service') }}</label>
                    <select id="service_id" name="service_id" required>
                        <option value="">{{ __('queue.select_service') }}</option>
                        @foreach($services as $service)
                            <option value="{{ $service->id }}" @selected((string) old('service_id') === (string) $service->id)>{{ $service->name }}</option>
                        @endforeach
                    </select>
                    @error('service_id')<small role="alert">{{ $message }}</small>@enderror
                    <label for="customer_phone">{{ __('queue.label_phone') }}</label>
                    <input id="customer_phone" name="customer_phone" type="tel" inputmode="tel"
                           autocomplete="tel" maxlength="30" required value="{{ old('customer_phone') }}"
                           placeholder="01012345678" dir="ltr" aria-describedby="phone-help">
                    <small id="phone-help">{{ __('queue.phone_help') }}</small>
                    @error('customer_phone')<small role="alert">{{ $message }}</small>@enderror
                    <button class="button" data-submit>{{ __('queue.book_submit') }}</button>
                </form>
            @elseif($branch->acceptsBookings())
                <div>{{ __('queue.no_services_heading') }}</div>
                <p>{{ __('queue.no_services_body') }}</p>
            @else
                <div>{{ __('queue.closed_heading') }}</div>
                <p class="muted">{{ __('queue.closed_hours_note') }}</p>
            @endif
        </section>
        <p class="muted">{{ __('queue.queue_note') }}</p>
    </div>
</x-queue-layout>
