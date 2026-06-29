{{-- resources/views/livewire/feedback-detail.blade.php --}}
<div class="fb-detail" dir="{{ __('feedback-board::feedback-board.dir') }}">
    <h3>{{ $item->title }}</h3>
    <p>{{ $item->description }}</p>

    <div class="fb-detail__controls">
        <select wire:change="changeStatus($event.target.value)">
            @foreach ($statuses as $s)
                <option value="{{ $s->value }}" @selected($item->status === $s)>{{ $s->label() }}</option>
            @endforeach
        </select>
        <select wire:change="setPriority($event.target.value)">
            @foreach ($priorities as $p)
                <option value="{{ $p->value }}" @selected($item->priority === $p)>{{ $p->label() }}</option>
            @endforeach
        </select>
        @if ($item->assignee_id)
            <button type="button" wire:click="unassign">{{ __('feedback-board::feedback-board.board.unassign') }}</button>
        @else
            <button type="button" wire:click="assignToMe">{{ __('feedback-board::feedback-board.board.assign_me') }}</button>
        @endif
    </div>

    <h4>{{ __('feedback-board::feedback-board.board.attachments') }}</h4>
    <ul>
        @foreach ($attachments as $att)
            <li><a href="{{ route('feedback-board.attachments.show', $att) }}">{{ $att->original_name }}</a></li>
        @endforeach
    </ul>

    @php($triage = $item->ai_state['triage'] ?? null)
    @if ($triage)
        <div class="fb-triage" wire:key="triage-{{ $item->id }}"
             @if(($triage['status'] ?? null) === 'pending') wire:poll.5s @endif>
            @if (($triage['status'] ?? null) === 'pending')
                <p>{{ __('feedback-board::feedback-board.triage.analyzing') }}</p>
            @elseif (($triage['status'] ?? null) === 'failed')
                <p>{{ __('feedback-board::feedback-board.triage.failed') }}</p>
                <button type="button" wire:click="requestTriage">{{ __('feedback-board::feedback-board.triage.retriage') }}</button>
            @elseif (($triage['status'] ?? null) === 'completed')
                <p><strong>{{ __('feedback-board::feedback-board.triage.summary') }}:</strong> {{ $triage['summary'] }}</p>

                @if (($triage['suggested_type'] ?? null) && $triage['suggested_type'] !== ($item->type?->value))
                    <button type="button" wire:click="applyTriage('type')">
                        {{ __('feedback-board::feedback-board.triage.apply_type') }}: {{ $triage['suggested_type'] }}
                    </button>
                @endif

                @if (($triage['suggested_priority'] ?? null) && $triage['suggested_priority'] !== ($item->priority?->value))
                    <button type="button" wire:click="applyTriage('priority')">
                        {{ __('feedback-board::feedback-board.triage.apply_priority') }}: {{ $triage['suggested_priority'] }}
                    </button>
                @endif

                <button type="button" wire:click="requestTriage">{{ __('feedback-board::feedback-board.triage.retriage') }}</button>
            @endif
        </div>
    @endif

    @php($runner = $item->ai_state['runner'] ?? null)
    @php($runnerConfigured = (bool) \YousefAman\FeedbackBoard\Runner\Support\RunnerGate::configured())
    @if ($runnerConfigured)
        <div class="fb-runner" wire:key="runner-{{ $item->id }}"
             @if(in_array(($runner['status'] ?? null), ['dispatched','running'], true)) wire:poll.5s @endif>
            @if (! config('feedback-board.ingest.enabled'))
                <p class="fb-runner-warn">{{ __('feedback-board::feedback-board.runner.ingest_disabled') }}</p>
            @elseif (($runner['status'] ?? null) === null)
                <button type="button" wire:click="dispatchToRunner">{{ __('feedback-board::feedback-board.runner.send') }}</button>
            @else
                @if (in_array($runner['status'], ['dispatched','running'], true))
                    <p>{{ __('feedback-board::feedback-board.runner.'.$runner['status']) }} — {{ $runner['dispatched_at'] ?? '' }}</p>
                @elseif ($runner['status'] === 'failed')
                    <p>{{ __('feedback-board::feedback-board.runner.failed') }}: {{ $runner['error_code'] ?? '' }}</p>
                @elseif ($runner['status'] === 'completed')
                    <p>{{ __('feedback-board::feedback-board.runner.completed') }}</p>
                @endif

                @if (($runner['diff'] ?? null) !== null)
                    <details><summary>{{ __('feedback-board::feedback-board.runner.diff') }}</summary><pre>{{ $runner['diff'] }}</pre></details>
                @endif
                @if (($runner['pr_url'] ?? null) !== null)
                    <a href="{{ $runner['pr_url'] }}" target="_blank" rel="noopener noreferrer">{{ $runner['pr_url'] }}</a>
                @endif

                @if (! in_array($runner['status'], ['dispatched','running'], true))
                    <button type="button" wire:click="dispatchToRunner">{{ __('feedback-board::feedback-board.runner.resend') }}</button>
                @endif
            @endif
        </div>
    @endif

    <h4>{{ __('feedback-board::feedback-board.board.thread') }}</h4>
    @foreach ($comments as $c)
        <div wire:key="dc-{{ $c->id }}" class="fb-comment fb-comment--{{ $c->author_role->value }} @if($c->is_internal) fb-comment--internal @endif">
            {{ $c->body }}
        </div>
    @endforeach

    <form wire:submit="reply">
        <textarea wire:model="body" placeholder="{{ __('feedback-board::feedback-board.board.reply') }}"></textarea>
        @error('body') <span class="fb-error">{{ $message }}</span> @enderror
        <button type="submit">{{ __('feedback-board::feedback-board.board.reply') }}</button>
    </form>

    <form wire:submit="internalNote">
        <textarea wire:model="note" placeholder="{{ __('feedback-board::feedback-board.board.internal_note') }}"></textarea>
        @error('note') <span class="fb-error">{{ $message }}</span> @enderror
        <button type="submit">{{ __('feedback-board::feedback-board.board.internal_note') }}</button>
    </form>

    <h4>{{ __('feedback-board::feedback-board.board.activity') }}</h4>
    <ul class="fb-activity">
        @foreach ($activities as $a)
            <li wire:key="act-{{ $a->id }}">{{ $a->action->value }} — {{ $a->created_at }}</li>
        @endforeach
    </ul>
</div>
