<x-queue-layout :title="$counter->name">
    <section class="display-screen" id="live-queue" data-kind="counter"
             data-state-url="{{ route('display.state', [$counter, $secret]) }}"
             data-auth-url="{{ route('display.firebase', [$counter, $secret]) }}">
        <span class="eyebrow">{{ $counter->branch->name }}</span>
        <div id="display-name">{{ $counter->name }}</div>
        <p id="display-label">{{ $snapshot['number'] ? __('queue.display_serving') : ($snapshot['paused'] ? __('queue.display_paused') : __('queue.display_waiting')) }}</p>
        <div class="display-number" id="display-number">{{ $snapshot['number'] ?? '—' }}</div>
        <p id="connection-status" class="connection" role="status">{{ __('queue.js.connecting') }}</p>
        <small id="last-updated"></small>
    </section>
    <script type="application/json" id="queue-config">{!! json_encode([
        'firebase' => config('firebase.web'),
        'enabled'  => config('firebase.enabled'),
        'initial'  => $snapshot,
        'strings'  => __('queue.js'),
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
</x-queue-layout>
