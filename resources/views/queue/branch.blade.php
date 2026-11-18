<x-queue-layout :title="$branch->name . ' | دورك'">
    <div class="centered"><span class="eyebrow">{{ $branch->name }}</span><h1>خدمتك تبدأ<br>برقم واحد.</h1><p>احجز دورك وتابع عدد المنتظرين قبلك من موبايلك.</p>
    <p><a class="button secondary" href="{{ route('appointments.index', $branch) }}">حجز موعد مسبق — اختار اليوم والوقت</a></p>
    <section class="panel booking-panel">
        <span class="status-dot {{ $branch->acceptsBookings() ? '' : 'off' }}">{{ $branch->acceptsBookings() ? 'نستقبل العملاء الآن' : 'الحجز مغلق حاليًا' }}</span>
        @if($active?->status->isActive())
            <h2>عندك دور بالفعل: {{ $active->display_number }}</h2><a class="button" href="{{ route('ticket.show', $secret) }}">متابعة دوري</a>
        @elseif($branch->acceptsBookings() && $services->isNotEmpty())
            <h2>جاهز تحجز دورك؟</h2><p>اختار الخدمة واكتب رقم موبايلك. مش محتاج تعمل حساب.</p>
            <form method="post" action="{{ route('branch.book', $branch) }}" class="booking-form">@csrf
                <label for="service_id">الخدمة المطلوبة</label>
                <select id="service_id" name="service_id" required><option value="">اختار الخدمة</option>@foreach($services as $service)<option value="{{ $service->id }}" @selected((string) old('service_id') === (string) $service->id)>{{ $service->name }}</option>@endforeach</select>
                @error('service_id')<small role="alert">{{ $message }}</small>@enderror
                <label for="customer_phone">رقم الموبايل</label>
                <input id="customer_phone" name="customer_phone" type="tel" inputmode="tel" autocomplete="tel" maxlength="30" required value="{{ old('customer_phone') }}" placeholder="01012345678" dir="ltr" aria-describedby="phone-help">
                <small id="phone-help">رقم مصري أو رقم دولي بكود الدولة (+). يظهر الرقم للموظف والإدارة فقط. الإشعارات تحتاج تفعيلها من صفحة التذكرة.</small>
                @error('customer_phone')<small role="alert">{{ $message }}</small>@enderror
                <button class="button" data-submit>احجز دور الآن ←</button>
            </form>
        @elseif($branch->acceptsBookings())
            <h2>لا توجد خدمات متاحة للحجز حاليًا</h2><p>راجع موظف الاستقبال.</p>
        @else
            <h2>نتشرف بزيارتك في مواعيد العمل</h2><p>{{ $branch->opens_at }} — {{ $branch->closes_at }}</p>
        @endif
    </section><p class="muted">كل موظف يستدعي أقدم عميل محتاج خدمة من الخدمات اللي بيقدمها. أرقام الخدمات المختلفة ممكن تتنادى بترتيب مختلف.</p></div>
</x-queue-layout>
