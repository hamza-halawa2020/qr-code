<x-queue-layout :title="__('queue.board_title', ['branch' => $branch->name])">
    <div id="branch-board"
         data-state-url="{{ route('board.state', [$branch, $secret]) }}"
         data-auth-url="{{ route('board.firebase', [$branch, $secret]) }}">
        <div>{{ $branch->name }}</div>
        <p>{{ __('queue.board_note') }}</p>
        <p class="muted"><span id="board-hours">{{ __('queue.hours_today') }}: {{ $branch->hoursLabelFor() }}</span></p>
        <div class="branch-grid" id="board-counters">
            @foreach($snapshot['counters'] as $item)
                <section class="panel">
                    <div>{{ $item['name'] }}</div>
                    <div class="ahead-number">{{ $item['number'] ?? '—' }}</div>
                    <p>{{ $item['status'] }}</p>
                </section>
            @endforeach
        </div>
        <p id="board-connection" role="status">{{ __('queue.board_connecting') }}</p>
        <script type="application/json" id="board-config">{!! json_encode([
            'enabled' => config('firebase.enabled'),
            'firebase' => config('firebase.web'),
            'initial'  => $snapshot,
            'strings'  => __('queue.js'),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
    </div>
</x-queue-layout>
