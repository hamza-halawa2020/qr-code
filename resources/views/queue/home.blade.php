<x-queue-layout>
    <section class="intro"><span class="eyebrow">أهلًا بيك</span><h1>خد دورك.<br>وتابع انتظارك بسهولة.</h1><p>اختار الفرع عشان تحجز رقمك، وتعرف كام عميل منتظر قبلك.</p></section>
    <section class="branch-grid">
        @forelse($branches as $branch)
            <a class="branch-card" href="{{ route('branch.show', $branch) }}"><span class="status-dot {{ $branch->acceptsBookings() ? '' : 'off' }}">{{ $branch->acceptsBookings() ? 'الحجز متاح' : 'الحجز مغلق' }}</span><h2>{{ $branch->name }}</h2><p>مواعيد العمل {{ $branch->opens_at }} — {{ $branch->closes_at }}</p><strong>الدخول للفرع ←</strong></a>
        @empty
            <div class="panel"><h2>بنجهّز استقبالكم</h2><p>لم يتم إضافة فروع بعد. يرجى الرجوع للإدارة.</p></div>
        @endforelse
    </section>
    <a class="quiet-link" href="/admin">دخول الموظفين والإدارة ←</a>
</x-queue-layout>
