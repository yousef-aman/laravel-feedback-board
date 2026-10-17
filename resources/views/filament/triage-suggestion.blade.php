<div class="fb-scope fb-triage-suggestion" data-fb-theme="{{ \YousefAman\FeedbackBoard\Support\Ui::theme() }}" dir="{{ __('feedback-board::feedback-board.dir') }}">
    @include('feedback-board::partials.assets')
    @if (filled($triage['error_code'] ?? null))
        <p class="fb-notice fb-notice--warning">{{ __('feedback-board::feedback-board.triage.rerun_failed', ['reason' => \YousefAman\FeedbackBoard\Triage\Support\TriageError::reason((string) $triage['error_code'])]) }} {{ __('feedback-board::feedback-board.triage.previous_kept') }}</p>
    @endif
    @if (($triage['summary'] ?? null) !== null)
        <p><strong>{{ __('feedback-board::feedback-board.triage.summary') }}:</strong> <span dir="auto">{{ $triage['summary'] }}</span></p>
    @endif
    @if (($triage['suggested_type'] ?? null) !== null)
        <p>{{ __('feedback-board::feedback-board.triage.suggested_type') }}: {{ \YousefAman\FeedbackBoard\Enums\FeedbackType::tryFrom((string) $triage['suggested_type'])?->label() ?? $triage['suggested_type'] }}</p>
    @endif
    @if (($triage['suggested_priority'] ?? null) !== null)
        <p>{{ __('feedback-board::feedback-board.triage.suggested_priority') }}: {{ \YousefAman\FeedbackBoard\Enums\FeedbackPriority::tryFrom((string) $triage['suggested_priority'])?->label() ?? $triage['suggested_priority'] }}</p>
    @endif
</div>
