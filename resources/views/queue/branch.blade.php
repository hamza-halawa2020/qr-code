<x-queue-layout :title="$branch->name . ' | دورك'">
    <div class="centered"><span class="eyebrow">{{ $branch->name }}</span><h1>خدمتك تبدأ<br>برقم واحد.</h1><p>احجز دورك وتابع عدد المنتظرين قبلك من موبايلك.</p>
    <section class="panel booking-panel">
        <span class="status-dot {{ $branch->acceptsBookings() ? '' : 'off' }}">{{ $branch->acceptsBookings() ? 'نستقبل العملاء الآن' : 'الحجز مغلق حاليًا' }}</span>
        @if($active?->status->isActive())
            <h2>عندك دور بالفعل: {{ $active->display_number }}</h2><a class="button" href="{{ route('ticket.show', $secret) }}">متابعة دوري</a>
        @elseif($branch->acceptsBookings())
            <h2>جاهز تحجز دورك؟</h2><p>مش محتاج تعمل حساب أو تدخل بيانات شخصية.</p>
            <form method="post" action="{{ route('branch.book', $branch) }}">@csrf<button class="button" data-submit>احجز دور الآن ←</button></form>
        @else
            <h2>نتشرف بزيارتك في مواعيد العمل</h2><p>{{ $branch->opens_at }} — {{ $branch->closes_at }}</p>
        @endif
    </section><p class="muted">كل شبابيك الفرع بتخدم نفس الطابور حسب ترتيب الحجز.</p></div>
</x-queue-layout>
