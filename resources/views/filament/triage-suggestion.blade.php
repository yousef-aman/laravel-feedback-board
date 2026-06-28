<div class="fb-triage-suggestion">
    @if (($triage['summary'] ?? null) !== null)
        <p><strong>{{ __('feedback-board::feedback-board.triage.summary') }}:</strong> {{ $triage['summary'] }}</p>
    @endif
    @if (($triage['suggested_type'] ?? null) !== null)
        <p>{{ __('feedback-board::feedback-board.triage.suggested_type') }}: {{ $triage['suggested_type'] }}</p>
    @endif
    @if (($triage['suggested_priority'] ?? null) !== null)
        <p>{{ __('feedback-board::feedback-board.triage.suggested_priority') }}: {{ $triage['suggested_priority'] }}</p>
    @endif
</div>
