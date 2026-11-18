<x-filament-panels::page>
<div class="queue-grid" wire:poll.5s>
    @if($counter && $shift)
        <section class="queue-card"><div class="queue-row"><p>وردية نشطة منذ {{ $shift->started_at->timezone($counter->branch->timezone)->format('Y-m-d H:i') }}</p><x-filament::button color="gray" wire:click="act('end_shift')" wire:confirm="إنهاء الوردية وإيقاف الشباك مؤقتًا؟" wire:loading.attr="disabled" :disabled="(bool) $current">إنهاء الوردية</x-filament::button></div></section>
    @endif
    @if(!$counter)
        <section class="queue-card"><h2 class="queue-title">حسابك غير مرتبط بشباك</h2><p>اطلب من الإدارة تخصيص شباك لك من صفحة إعدادات التشغيل.</p></section>
    @else
        <section class="queue-card"><h2 class="queue-title">الخدمات المخصصة لك</h2><p>{{ $savedServices->pluck('name')->join('، ') ?: 'لم يحدد مدير النظام خدماتك بعد. تواصل معه لتخصيص الخدمات.' }}</p><p class="queue-muted">مدير النظام هو المسؤول عن تحديد خدماتك. «التالي» يستدعي أقدم عميل مناسب لها.</p></section>
        <section class="queue-card"><div class="queue-row"><h2 class="queue-title">{{ $counter->branch->name }} — {{ $counter->name }}</h2><span>{{ $counter->is_paused ? 'في استراحة' : 'الشباك يعمل' }}</span></div>
        @if($current)
            <p class="queue-muted">وضع التشغيل: {{ $counter->branch->automatic_queue ? 'تلقائي' : 'يدوي' }}</p><p>العميل الحالي — {{ $current->status->label() }}</p><div class="queue-number">{{ $current->display_number }}</div>@if($current->customer_name)<p>اسم العميل: {{ $current->customer_name }}</p>@endif @if($current->appointment_at)<p>موعد مسبق: {{ $current->appointment_at->timezone($counter->branch->timezone)->format('H:i') }} — أولوية مؤكدة</p>@endif<p>الخدمة: {{ $current->service_name ?? 'غير محددة' }}</p><p>موبايل العميل: <bdi>{{ $current->customer_phone ?? 'غير مسجل' }}</bdi></p>
            <div class="queue-row">
            @if($current->status->value === 'called')
                <x-filament::button wire:click="act('start', {{ $current->id }})" wire:loading.attr="disabled">بدء الخدمة</x-filament::button>
                <x-filament::button color="gray" wire:click="act('recall', {{ $current->id }})" wire:loading.attr="disabled">إعادة النداء</x-filament::button>
                <x-filament::button color="danger" wire:click="act('absent', {{ $current->id }})" wire:confirm="تسجيل أن العميل لم يحضر؟" wire:loading.attr="disabled" :disabled="$current->callDeadline()->isFuture()">لم يحضر</x-filament::button>
            @else
                <x-filament::button wire:click="act('complete', {{ $current->id }})" wire:loading.attr="disabled">إنهاء الخدمة</x-filament::button>
            @endif
            </div>
            @if($current->status->value === 'called')
                <div wire:key="call-countdown-{{ $current->id }}-{{ $current->callDeadline()->timestamp }}-{{ (int) $counter->branch->automatic_queue }}" x-data="{left: {{ max(0, (int) ceil(now()->diffInSeconds($current->callDeadline(), false))) }}, end: 0, timer: null, init(){this.end=performance.now()+this.left*1000; this.timer=setInterval(()=>{this.left=Math.max(0,Math.ceil((this.end-performance.now())/1000))},250)}, destroy(){clearInterval(this.timer)}}" style="margin-top:16px">
                    <p>المتبقي لبدء الخدمة: <strong role="timer" data-call-countdown dir="ltr" x-text="Math.floor(left/60).toString().padStart(2,'0')+':'+(left%60).toString().padStart(2,'0')"></strong></p>
                    <p class="queue-muted">{{ $counter->branch->automatic_queue ? 'عند انتهاء المهلة يتم تسجيل الغياب ونداء التالي تلقائيًا. بدء الخدمة يوقف العدّاد.' : 'وضع يدوي: انتهاء العدّاد لا يغيّر التذكرة. الموظف يقرر بدء الخدمة أو تسجيل الغياب ثم نداء التالي.' }}</p>
                </div>
            @endif
        @else
            <div class="queue-number">—</div><p class="queue-muted">جاهز لاستقبال العميل التالي</p>
            <x-filament::button wire:click="act('next')" wire:loading.attr="disabled" :disabled="$counter->is_paused">استدعاء التالي</x-filament::button>
        @endif
        <div class="queue-row" style="margin-top:24px"><x-filament::button color="gray" wire:click="act('pause')" wire:loading.attr="disabled">{{ $counter->is_paused ? 'استئناف العمل' : 'استراحة' }}</x-filament::button></div>
        </section>
        <section class="queue-card"><h2 class="queue-title">حجز يدوي</h2><p class="queue-muted">اختار الخدمة. رقم الموبايل اختياري للعميل اللي ما معهوش موبايل.</p><form wire:submit="manualBooking" class="queue-row"><label>الخدمة <select class="queue-input" wire:model="manualServiceId" required><option value="">اختار الخدمة</option>@foreach($counter->branch->services()->where('is_active', true)->orderBy('name')->get() as $service)<option value="{{ $service->id }}">{{ $service->name }}</option>@endforeach</select></label><label>رقم الموبايل — اختياري <input class="queue-input" wire:model="manualPhone" type="tel" dir="ltr" maxlength="30"></label><x-filament::button type="submit" color="gray" wire:loading.attr="disabled">حجز يدوي</x-filament::button></form></section>
        @if($deferred->isNotEmpty())<section class="queue-card"><h2 class="queue-title">بانتظار فرصة إعادة النداء</h2><p class="queue-muted">كل خدمة مكتملة مناسبة تجهز أقدم عميل مؤجل. المواعيد المستحقة لها الأولوية.</p><div class="queue-row">@foreach($deferred as $item)<x-filament::badge color="warning">{{ $item->display_number }} — {{ $item->service_name }} — {{ $item->retry_attempts }} / {{ $item->retry_limit }} فرص مستخدمة</x-filament::badge>@endforeach</div></section>@endif
        <section class="queue-card"><h2 class="queue-title">المنتظرون المناسبون لخدماتك ({{ $waiting->count() }})</h2><div class="queue-row">@forelse($waiting->take(30) as $ticket)<x-filament::badge color="gray">{{ $ticket->display_number }} — {{ $ticket->service_name ?? 'تذكرة قديمة' }}</x-filament::badge>@empty<p class="queue-muted">لا يوجد عملاء منتظرون مناسبون لخدماتك.</p>@endforelse</div></section>
    @endif
</div>
</x-filament-panels::page>
