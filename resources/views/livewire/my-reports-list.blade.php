<div class="fb-my-reports" dir="{{ __('feedback-board::feedback-board.dir') }}">
    <h2>{{ __('feedback-board::feedback-board.board.my_reports') }}</h2>
    @forelse($items as $item)
        <div class="fb-report-item">
            <strong>{{ $item->title }}</strong>
            <span class="fb-status">{{ __('feedback-board::feedback-board.status.' . $item->status->value) }}</span>
            @livewire('feedback-board::reply-inline', ['item' => $item], key($item->id))
        </div>
    @empty
        <p>{{ __('feedback-board::feedback-board.board.no_reports', [], null) }}</p>
    @endforelse
</div>
