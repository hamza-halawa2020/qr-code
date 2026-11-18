<x-queue-layout :title="$counter->name">
    <section class="display-screen" id="live-queue" data-kind="counter" data-state-url="{{ route('display.state', [$counter, $secret]) }}" data-auth-url="{{ route('display.firebase', [$counter, $secret]) }}">
        <span class="eyebrow">{{ $counter->branch->name }}</span><h1 id="display-name">{{ $counter->name }}</h1><p id="display-label">{{ $snapshot['number'] ? 'نتشرف بخدمة رقم' : ($snapshot['paused'] ? 'الشباك في استراحة' : 'في انتظار العميل التالي') }}</p><div class="display-number" id="display-number">{{ $snapshot['number'] ?? '—' }}</div><p id="connection-status" class="connection" role="status">جاري الاتصال…</p><small id="last-updated"></small>
    </section>
    <script type="application/json" id="queue-config">{!! json_encode(['firebase' => config('firebase.web'), 'enabled' => config('firebase.enabled'), 'initial' => $snapshot], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
</x-queue-layout>
