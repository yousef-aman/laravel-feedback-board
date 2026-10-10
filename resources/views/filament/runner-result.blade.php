<div class="fb-scope fb-runner-result" data-fb-theme="{{ \YousefAman\FeedbackBoard\Support\Ui::theme() }}" dir="{{ __('feedback-board::feedback-board.dir') }}">
    @include('feedback-board::partials.assets')
    <p><strong>{{ __('feedback-board::feedback-board.runner.status') }}:</strong> {{ $runner['status'] ?? '' }}</p>
    @if (($runner['error_code'] ?? null) !== null)
        <p class="fb-notice fb-notice--danger">{{ __('feedback-board::feedback-board.runner.failed') }}: {{ $runner['error_code'] }}</p>
    @endif
    @if (($runner['diff'] ?? null) !== null)
        <pre class="fb-pre">{{ $runner['diff'] }}</pre>
    @endif
    @if (($runner['pr_url'] ?? null) !== null)
        <a class="fb-link" href="{{ $runner['pr_url'] }}" target="_blank" rel="noopener noreferrer" dir="ltr">{{ $runner['pr_url'] }}</a>
    @endif
</div>
