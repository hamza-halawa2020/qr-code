<x-queue-layout :title="__('queue.appt_page_title', ['branch' => $branch->name])">
<div class="centered">
    <span class="eyebrow">{{ $branch->name }}</span>
    <div>{{ __('queue.appt_heading') }}</div>
    <p>{{ __('queue.appt_priority_note') }}</p>
    @include('queue.partials.weekly-hours', ['branch' => $branch])
    <section class="panel">
        <form method="get" class="booking-form">
            <label>{{ __('queue.label_branch') }}
                <select onchange="location.href=this.value">
                    @foreach(\App\Models\Branch::orderBy('name')->get() as $item)
                        <option value="{{ route('appointments.index', $item) }}" @selected($item->id === $branch->id)>{{ $item->name }}</option>
                    @endforeach
                </select>
            </label>
            <label>{{ __('queue.label_service') }}
                <select name="service_id">
                    <option value="">{{ __('queue.label_all_services') }}</option>
                    @foreach($services as $service)
                        <option value="{{ $service->id }}" @selected((string)$selectedService === (string)$service->id)>{{ $service->name }}</option>
                    @endforeach
                </select>
            </label>
            <label>{{ __('queue.label_date') }}
                <input name="date" type="date" value="{{ $date }}"
                       min="{{ now($branch->timezone)->toDateString() }}"
                       max="{{ now($branch->timezone)->addDays(30)->toDateString() }}" required>
            </label>
            <button class="button">{{ __('queue.show_times_btn') }}</button>
        </form>
    </section>
    <section class="panel" style="margin-top:24px">
        <div>{{ __('queue.booking_details_title') }}</div>
        @if($errors->any())<p role="alert">{{ $errors->first() }}</p>@endif
        <form method="post" action="{{ route('appointments.book', $branch) }}" class="booking-form">@csrf
            <label>{{ __('queue.label_slot') }}
                <select name="slot_id" required>
                    <option value="">{{ __('queue.select_slot') }}</option>
                    @foreach($appointmentSlots as $slot)
                        <option value="{{ $slot->id }}" @disabled($slot->reserved >= $slot->capacity)>
                            {{ __('queue.slot_option', ['time' => $slot->starts_at->timezone($branch->timezone)->format('H:i'), 'service' => $slot->service->name, 'available' => max(0, $slot->capacity - $slot->reserved)]) }}
                        </option>
                    @endforeach
                </select>
            </label>
            <label>{{ __('queue.label_full_name') }}
                <input name="customer_name" required maxlength="120" autocomplete="name">
            </label>
            <label>{{ __('queue.label_phone') }}
                <input type="tel" name="customer_phone" required maxlength="30" dir="ltr" autocomplete="tel">
            </label>
            <label>{{ __('queue.label_email_optional') }}
                <input type="email" name="customer_email" maxlength="255" autocomplete="email">
            </label>
            <button class="button" data-submit @disabled($appointmentSlots->isEmpty())>{{ __('queue.confirm_appointment') }}</button>
        </form>
    </section>
    <p>{{ __('queue.appt_footer_note') }}</p>
</div>
</x-queue-layout>
