<x-queue-layout :title="__('queue.ticket_page_title')">
    <div class="ticket-layout" id="live-queue" data-kind="ticket"
         data-state-url="{{ route('ticket.state', $secret) }}"
         data-auth-url="{{ route('ticket.firebase', $secret) }}"
         data-subscribe-url="{{ route('ticket.subscribe', $secret) }}"
         data-unsubscribe-url="{{ route('ticket.unsubscribe', $secret) }}">
        <section class="ticket-main">
            <span class="eyebrow">{{ $ticket->branch->name }}</span>
            @if($ticket->service_name)
                <p>{{ __('queue.requested_service', ['name' => $ticket->service_name]) }}</p>
            @endif
            <div id="ticket-heading">{{ $snapshot['heading'] }}</div>
            <p id="ticket-description">{{ $snapshot['description'] }}</p>
            <p id="retry-note">{{ $snapshot['retry_note'] }}</p>
            <div class="ticket-card">
                <div class="ticket-top">
                    <span>{{ __('queue.your_ticket_number') }}</span>
                    <span id="ticket-status" class="badge">{{ $snapshot['label'] }}</span>
                </div>
                <div class="ticket-number">{{ $snapshot['number'] }}</div>
                <div class="ticket-bottom">
                    <span id="ticket-message">{{ $ticket->status->value === 'waiting' ? __('queue.waiting_message') : $snapshot['label'] }}</span>
                    <strong id="ticket-counter">{{ $snapshot['counter'] }}</strong>
                </div>
            </div>
        </section>
        <aside class="ticket-sidebar">
            <section class="panel" id="near-turn" @if(!$snapshot['near_turn']) hidden @endif>
                <div>{{ __('queue.near_turn_title') }}</div>
                <p>{{ __('queue.near_turn_body') }}</p>
            </section>
            <section class="panel" id="estimate-section" @if($ticket->status->value !== 'waiting') hidden @endif>
                <div>{{ __('queue.estimate_title') }}</div>
                <p id="wait-estimate">{{ $snapshot['estimate'] }}</p>
                <small>{{ __('queue.estimate_note') }}</small>
            </section>
            <section class="panel">
                <span class="eyebrow">{{ __('queue.ahead_eyebrow') }}</span>
                <div class="ahead-number" id="ticket-ahead">{{ $snapshot['ahead'] }}</div>
                <p id="ahead-description">{{ $snapshot['ahead_label'] }}</p>
                <small>{{ __('queue.ahead_note') }}</small>
            </section>
            <section class="panel">
                <div>{{ __('queue.notifications_title') }}</div>
                <p>{{ __('queue.notifications_body') }}</p>
                <button type="button" class="button secondary" id="enable-notifications">{{ __('queue.enable_notifications') }}</button>
                <button type="button" class="text-button" id="disable-notifications" hidden>{{ __('queue.disable_notifications') }}</button>
                <p id="notification-message" class="muted" role="status"></p>
            </section>
            <p id="connection-status" class="connection" role="status">{{ __('queue.js.connecting') }}</p>
            <small id="last-updated"></small>
            <form method="post" action="{{ route('ticket.cancel', $secret) }}" id="cancel-ticket"
                  @if(!in_array($ticket->status->value, ['waiting', 'deferred'])) hidden @endif>
                @csrf
                <button class="text-button danger" data-submit>{{ __('queue.cancel_ticket') }}</button>
            </form>
            <section class="panel" id="rating-section" @if($ticket->status->value !== 'completed') hidden @endif>
                <div>{{ __('queue.rating_title') }}</div>
                @if($ticket->rated_at)
                    <p>{{ __('queue.rating_thanks', ['rating' => $ticket->rating]) }}</p>
                @else
                    <form method="post" action="{{ route('ticket.rate', $secret) }}" class="booking-form">@csrf
                        <label>{{ __('queue.rating_label') }}
                            <select name="rating" aria-label="{{ __('queue.rating_label') }}" required>
                                <option value="">{{ __('queue.rating_select') }}</option>
                                @foreach([5,4,3,2,1] as $value)
                                    <option value="{{ $value }}">{{ $value }} / 5</option>
                                @endforeach
                            </select>
                        </label>
                        <label>{{ __('queue.rating_comment_label') }}
                            <input name="rating_comment" maxlength="1000">
                        </label>
                        <small>{{ __('queue.rating_comment_note') }}</small>
                        <button class="button" data-submit>{{ __('queue.rating_submit') }}</button>
                    </form>
                    @if($errors->any())<p role="alert">{{ $errors->first() }}</p>@endif
                @endif
            </section>
        </aside>
    </div>
    <script type="application/json" id="queue-config">{!! json_encode([
        'firebase'  => config('firebase.web'),
        'enabled'   => config('firebase.enabled'),
        'vapidKey'  => config('firebase.vapid_key'),
        'initial'   => $snapshot,
        'strings'   => __('queue.js'),
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
</x-queue-layout>
