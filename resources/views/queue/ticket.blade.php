<x-queue-layout title="متابعة دورك">
    <div class="ticket-layout" id="live-queue" data-kind="ticket" data-state-url="{{ route('ticket.state', $secret) }}" data-auth-url="{{ route('ticket.firebase', $secret) }}" data-subscribe-url="{{ route('ticket.subscribe', $secret) }}">
        <section class="ticket-main"><span class="eyebrow">{{ $ticket->branch->name }}</span><h1>دورك محفوظ.</h1><p>خليك متابع الصفحة وشاشات الفرع عشان تعرف وقت النداء.</p>
            <div class="ticket-card"><div class="ticket-top"><span>رقم دورك</span><span id="ticket-status" class="badge">{{ $snapshot['label'] }}</span></div><div class="ticket-number">{{ $snapshot['number'] }}</div><div class="ticket-bottom"><span id="ticket-message">{{ $ticket->status->value === 'waiting' ? 'استنى النداء على رقمك' : $snapshot['label'] }}</span><strong id="ticket-counter">{{ $snapshot['counter'] }}</strong></div></div>
        </section>
        <aside class="ticket-sidebar"><section class="panel"><span class="eyebrow">قبل دورك</span><div class="ahead-number" id="ticket-ahead">{{ $snapshot['ahead'] }}</div><p>عملاء منتظرين قبلك</p><small>العدد لا يشمل العملاء الجاري خدمتهم.</small></section>
            <section class="panel"><h2>خليك مستعد لدورك</h2><p>فعّل الإشعارات لو جهازك بيدعمها. وتابع شاشات الفرع كمان.</p><button type="button" class="button secondary" id="enable-notifications">تفعيل الإشعارات</button><p id="notification-message" class="muted" role="status"></p></section>
            <p id="connection-status" class="connection" role="status">جاري الاتصال…</p>
            <form method="post" action="{{ route('ticket.cancel', $secret) }}" id="cancel-ticket" @if($ticket->status->value !== 'waiting') hidden @endif>@csrf<button class="text-button danger" data-submit>إلغاء دوري</button></form>
        </aside>
    </div>
    <script type="application/json" id="queue-config">{!! json_encode(['firebase' => config('firebase.web'), 'enabled' => config('firebase.enabled'), 'vapidKey' => config('firebase.vapid_key'), 'initial' => $snapshot], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
</x-queue-layout>
