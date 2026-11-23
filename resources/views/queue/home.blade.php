<x-queue-layout>
    <section class="intro">
        <span class="eyebrow">{{ __('queue.home_eyebrow') }}</span>
        <div>{!! __('queue.home_heading') !!}</div>
        <p>{{ __('queue.home_subheading') }}</p>
    </section>
    <section class="branch-grid">
        @forelse($branches as $branch)
            <a class="branch-card" href="{{ route('branch.show', $branch) }}">
                <span class="status-dot {{ $branch->acceptsBookings() ? '' : 'off' }}">
                    {{ $branch->acceptsBookings() ? __('queue.branch_open') : __('queue.branch_closed') }}
                </span>
                <div>{{ $branch->name }}</div>
                @include('queue.partials.weekly-hours', ['branch' => $branch])
                <strong>{{ __('queue.branch_enter') }}</strong>
            </a>
        @empty
            <div class="panel">
                <div>{{ __('queue.no_branches_title') }}</div>
                <p>{{ __('queue.no_branches_body') }}</p>
            </div>
        @endforelse
    </section>
    <!-- <a class="quiet-link" href="/admin">{{ __('queue.staff_login') }}</a> -->
</x-queue-layout>
