<x-filament-panels::page>
<div class="queue-grid">
    @if($counter && $shift)
        <section class="queue-card"><div class="queue-row"><p>{{ __('queue.rec_active_shift', ['time' => $shift->started_at->timezone($counter->branch->timezone)->format('Y-m-d H:i')]) }}</p><x-filament::button color="gray" wire:click="act('end_shift')" wire:loading.attr="disabled" :disabled="(bool) $current">{{ __('queue.rec_end_shift') }}</x-filament::button></div></section>
    @endif
    @if(!$counter)
        <section class="queue-card"><div class="queue-title">{{ __('queue.rec_no_counter') }}</div><p>{{ __('queue.rec_no_counter_note') }}</p></section>
    @else
        <section class="queue-card"><div class="queue-title">{{ __('queue.rec_my_services') }}</div><p>{{ $savedServices->pluck('name')->join('، ') ?: __('queue.rec_no_services') }}</p><p class="queue-muted">{{ __('queue.rec_services_note') }}</p></section>
        <section class="queue-card"><div class="queue-row"><div class="queue-title">{{ $counter->branch->name }} — {{ $counter->name }}</div><span>{{ $counter->is_paused ? __('queue.rec_pause') : __('queue.rec_resume') }}</span></div>
        @if($current)
            <p class="queue-muted">{{ __('queue.rec_queue_mode', ['mode' => $counter->branch->automatic_queue ? __('queue.rec_auto_mode') : __('queue.rec_manual_mode')]) }}</p>
            <p>{{ __('queue.rec_current_customer') }} — {{ $current->status->label() }}</p>
            <div class="queue-number">{{ $current->display_number }}</div>
            @if($current->customer_name)<p>{{ __('queue.rec_customer_name', ['name' => $current->customer_name]) }}</p>@endif
            @if($current->appointment_at)<p>{{ __('queue.rec_appointment', ['time' => $current->appointment_at->timezone($counter->branch->timezone)->format('H:i')]) }}</p>@endif
            <p>{{ __('queue.rec_service', ['name' => $current->service_name ?? __('queue.rec_service_unset')]) }}</p>
            <p>{{ __('queue.rec_phone', ['phone' => $current->customer_phone ?? __('queue.rec_phone_unset')]) }}</p>
            @if($current->status->value === 'called')
                <div wire:key="call-countdown-{{ $current->id }}-{{ $current->callDeadline()->timestamp }}-{{ (int) $counter->branch->automatic_queue }}"
                     x-data="{left: {{ max(0, (int) ceil(now()->diffInSeconds($current->callDeadline(), false))) }}, end: 0, timer: null, init(){this.end=performance.now()+this.left*1000; this.timer=setInterval(()=>{this.left=Math.max(0,Math.ceil((this.end-performance.now())/1000))},250)}, destroy(){clearInterval(this.timer)}}">
                    <div class="queue-row">
                        <x-filament::button wire:click="act('start', {{ $current->id }})" wire:loading.attr="disabled">{{ __('queue.rec_start_service') }}</x-filament::button>
                        <x-filament::button color="gray" wire:click="act('recall', {{ $current->id }})" wire:loading.attr="disabled">{{ __('queue.rec_recall') }}</x-filament::button>
                        <x-filament::button color="danger" wire:click="act('absent', {{ $current->id }})" wire:loading.attr="disabled" x-bind:disabled="left > 0" x-bind:class="{ 'fi-disabled': left > 0 }">{{ __('queue.rec_absent') }}</x-filament::button>
                    </div>
                    <div style="margin-top:16px">
                        <p>{{ __('queue.rec_time_left') }} <strong role="timer" data-call-countdown dir="ltr" x-text="Math.floor(left/60).toString().padStart(2,'0')+':'+(left%60).toString().padStart(2,'0')"></strong></p>
                        <p class="queue-muted">{{ $counter->branch->automatic_queue ? __('queue.rec_countdown_auto') : __('queue.rec_countdown_manual') }}</p>
                    </div>
                </div>
            @else
                <div class="queue-row">
                    <x-filament::button wire:click="act('complete', {{ $current->id }})" wire:loading.attr="disabled">{{ __('queue.rec_complete') }}</x-filament::button>
                </div>
            @endif
        @else
            <div class="queue-number">—</div><p class="queue-muted">{{ __('queue.rec_no_current') }}</p>
            <x-filament::button wire:click="act('next')" wire:loading.attr="disabled" :disabled="$counter->is_paused">{{ __('queue.rec_call_next') }}</x-filament::button>
        @endif
        <div class="queue-row" style="margin-top:24px"><x-filament::button color="gray" wire:click="act('pause')" wire:loading.attr="disabled">{{ $counter->is_paused ? __('queue.rec_resume') : __('queue.rec_pause') }}</x-filament::button></div>
        </section>
        <section class="queue-card"><div class="queue-title">{{ __('queue.rec_manual_booking') }}</div><p class="queue-muted">{{ __('queue.rec_manual_note') }}</p><form wire:submit="manualBooking" class="queue-row"><label>{{ __('queue.label_service') }} <select class="queue-input" wire:model="manualServiceId" required><option value="">{{ __('queue.select_service') }}</option>@foreach($counter->branch->services()->join('services', 'services.id', '=', 'branch_services.service_id')->where('branch_services.is_active', true)->orderBy('services.name')->select('branch_services.*')->get() as $service)<option value="{{ $service->id }}">{{ $service->name }}</option>@endforeach</select></label><label>{{ __('queue.rec_manual_phone') }} <input class="queue-input" wire:model="manualPhone" type="tel" dir="ltr" maxlength="30"></label><x-filament::button type="submit" color="gray" wire:loading.attr="disabled">{{ __('queue.rec_manual_submit') }}</x-filament::button></form></section>
        @if($deferred->isNotEmpty())<section class="queue-card"><div class="queue-title">{{ __('queue.rec_deferred_title') }}</div><p class="queue-muted">{{ __('queue.rec_deferred_note') }}</p><div class="queue-row">@foreach($deferred as $item)<x-filament::badge color="warning">{{ __('queue.rec_deferred_badge', ['number' => $item->display_number, 'service' => $item->service_name, 'used' => $item->retry_attempts, 'limit' => $item->retry_limit]) }}</x-filament::badge>@endforeach</div></section>@endif
        <section class="queue-card"><div class="queue-title">{{ __('queue.rec_waiting_title', ['count' => $waiting->count()]) }}</div><div class="queue-row">@forelse($waiting->take(30) as $ticket)<x-filament::badge color="gray">{{ $ticket->display_number }} — {{ $ticket->service_name ?? __('queue.rec_old_ticket') }}</x-filament::badge>@empty<p class="queue-muted">{{ __('queue.rec_no_waiting') }}</p>@endforelse</div></section>
    @endif
</div>
</x-filament-panels::page>
