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
                    @foreach(\App\Models\Branch::where('appointments_enabled', true)->orderBy('name')->get() as $item)
                        <option value="{{ route('appointments.index', $item) }}" @selected($item->id === $branch->id)>{{ $item->name }}</option>
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
        @if(!$appointmentsEnabled)
            <p>{{ __('queue.appt_disabled') }}</p>
        @else
        @if($errors->any())<p role="alert">{{ $errors->first() }}</p>@endif
        <form method="post" action="{{ route('appointments.book', $branch) }}" class="booking-form">@csrf
            <input type="hidden" name="date" value="{{ $date }}">
            <label>{{ __('queue.label_service') }}
                <select name="service_id" required>
                    <option value="">{{ __('queue.adm_appt_select_service') }}</option>
                    @foreach($services as $service)
                        <option value="{{ $service->id }}">{{ $service->name }}</option>
                    @endforeach
                </select>
            </label>
            <label>{{ __('queue.label_slot') }}
                <select name="time" required>
                    <option value="">{{ __('queue.select_slot') }}</option>
                    @foreach($appointmentSlots as $slot)
                        @php($localTime = $slot->starts_at->timezone($branch->timezone)->format('H:i'))
                        <option value="{{ $localTime }}" @disabled($slot->available === 0)>
                            {{ __('queue.slot_option_time', ['time' => $localTime, 'available' => $slot->available]) }}
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
        @endif
    </section>
    <p>{{ __('queue.appt_footer_note') }}</p>
</div>
</x-queue-layout>
