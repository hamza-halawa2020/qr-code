<x-queue-layout :title="$branch->name . ' | شاشة الفرع'">
    <div id="branch-board" data-state-url="{{ route('board.state', [$branch, $secret]) }}" data-auth-url="{{ route('board.firebase', [$branch, $secret]) }}">
        <h1>{{ $branch->name }}</h1><p>تابع رقمك والشباك المطلوب. أرقام الخدمات والمواعيد قد تتنادى بترتيب مختلف.</p>
        <div class="branch-grid" id="board-counters">@foreach($snapshot['counters'] as $item)<section class="panel"><h2>{{ $item['name'] }}</h2><div class="ahead-number">{{ $item['number'] ?? '—' }}</div><p>{{ $item['status'] }}</p></section>@endforeach</div>
        <p id="board-connection" role="status">جاري تحديث الشاشة…</p>
        <script type="application/json" id="board-config">{!! json_encode(['enabled' => config('firebase.enabled'), 'firebase' => config('firebase.web'), 'initial' => $snapshot], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
    </div>
</x-queue-layout>
