<div class="fb-runner-result">
    <p><strong>{{ __('feedback-board::feedback-board.runner.status') }}:</strong> {{ $runner['status'] ?? '' }}</p>
    @if (($runner['error_code'] ?? null) !== null)
        <p>{{ __('feedback-board::feedback-board.runner.failed') }}: {{ $runner['error_code'] }}</p>
    @endif
    @if (($runner['diff'] ?? null) !== null)
        <pre>{{ $runner['diff'] }}</pre>
    @endif
    @if (($runner['pr_url'] ?? null) !== null)
        <a href="{{ $runner['pr_url'] }}" target="_blank" rel="noopener noreferrer">{{ $runner['pr_url'] }}</a>
    @endif
</div>
