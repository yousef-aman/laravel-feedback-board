{{-- resources/views/livewire/feedback-detail.blade.php --}}
<div class="fb-scope fb-detail" data-fb-theme="{{ \YousefAman\FeedbackBoard\Support\Ui::theme() }}" dir="{{ __('feedback-board::feedback-board.dir') }}">
    @include('feedback-board::partials.assets')
    <header class="fb-detail__header">
        <h2 id="fb-drawer-title" class="fb-detail__title" dir="auto">{{ $item->title }}</h2>
        <div class="fb-detail__meta">
            @if ($item->type)
                <span class="fb-chip">{{ $item->type->label() }}</span>
            @endif
            @if ($reporterName)
                <span>{{ $reporterName }}</span>
            @endif
            @if ($item->created_at)
                <time datetime="{{ $item->created_at->toIso8601String() }}">{{ $item->created_at->diffForHumans() }}</time>
            @endif
        </div>
    </header>

    @if (filled($item->description))
        <p class="fb-prose" dir="auto">{{ $item->description }}</p>
    @endif

    <div class="fb-detail__controls">
        <label class="fb-field">
            <span class="fb-label">{{ __('feedback-board::feedback-board.board.status') }}</span>
            <select class="fb-select" wire:change="changeStatus($event.target.value)">
                @foreach ($statuses as $s)
                    <option value="{{ $s->value }}" @selected($item->status === $s)>{{ $s->label() }}</option>
                @endforeach
            </select>
        </label>
        <label class="fb-field">
            <span class="fb-label">{{ __('feedback-board::feedback-board.board.priority') }}</span>
            <select class="fb-select" wire:change="setPriority($event.target.value)">
                <option value="" disabled @selected($item->priority === null)>{{ __('feedback-board::feedback-board.board.no_priority') }}</option>
                @foreach ($priorities as $p)
                    <option value="{{ $p->value }}" @selected($item->priority === $p)>{{ $p->label() }}</option>
                @endforeach
            </select>
        </label>
        <div class="fb-field">
            <span class="fb-label">{{ __('feedback-board::feedback-board.board.assignee') }}</span>
            <div class="fb-detail__assignee">
                <span>{{ $assigneeName ?? __('feedback-board::feedback-board.board.unassigned') }}</span>
                @if ($item->assignee_id)
                    <button type="button" class="fb-btn fb-btn--sm" wire:click="unassign">{{ __('feedback-board::feedback-board.board.unassign') }}</button>
                @else
                    <button type="button" class="fb-btn fb-btn--sm" wire:click="assignToMe">{{ __('feedback-board::feedback-board.board.assign_me') }}</button>
                @endif
            </div>
        </div>
    </div>

    @if ($context !== [])
        <section class="fb-section">
            <h3 class="fb-section-title">{{ __('feedback-board::feedback-board.board.context') }}</h3>
            <dl class="fb-context">
                @foreach ($context as $row)
                    <dt>{{ $row['label'] }}</dt>
                    <dd>
                        @if ($row['href'] !== null)
                            <a class="fb-link" href="{{ $row['href'] }}" target="_blank" rel="noopener noreferrer" dir="ltr">{{ $row['value'] }}</a>
                        @else
                            <span dir="ltr">{{ $row['value'] }}</span>
                        @endif
                    </dd>
                @endforeach
            </dl>
        </section>
    @endif

    @if ($attachments->isNotEmpty())
        <section class="fb-section">
            <h3 class="fb-section-title">{{ __('feedback-board::feedback-board.board.attachments') }}</h3>
            <ul class="fb-attachments">
                @foreach ($attachments as $att)
                    <li>
                        <a href="{{ route('feedback-board.attachments.show', $att) }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                            <span>{{ $att->original_name }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @php($triage = $item->ai_state['triage'] ?? null)
    @if ($triage)
        <div class="fb-panel fb-triage" wire:key="triage-{{ $item->id }}"
             @if(($triage['status'] ?? null) === 'pending') wire:poll.5s @endif>
            @if (($triage['status'] ?? null) === 'pending')
                <p class="fb-muted">{{ __('feedback-board::feedback-board.triage.analyzing') }}</p>
            @elseif (($triage['status'] ?? null) === 'failed')
                <p class="fb-notice fb-notice--danger">{{ __('feedback-board::feedback-board.triage.failed') }}</p>
                <div class="fb-actions">
                    <button type="button" class="fb-btn fb-btn--sm" wire:click="requestTriage">{{ __('feedback-board::feedback-board.triage.retriage') }}</button>
                </div>
            @elseif (($triage['status'] ?? null) === 'completed')
                <p><strong>{{ __('feedback-board::feedback-board.triage.summary') }}:</strong> <span dir="auto">{{ $triage['summary'] }}</span></p>
                <div class="fb-actions">
                    @if (($triage['suggested_type'] ?? null) && $triage['suggested_type'] !== ($item->type?->value))
                        <button type="button" class="fb-btn fb-btn--sm" wire:click="applyTriage('type')">
                            {{ __('feedback-board::feedback-board.triage.apply_type') }}: {{ \YousefAman\FeedbackBoard\Enums\FeedbackType::tryFrom((string) $triage['suggested_type'])?->label() ?? $triage['suggested_type'] }}
                        </button>
                    @endif

                    @if (($triage['suggested_priority'] ?? null) && $triage['suggested_priority'] !== ($item->priority?->value))
                        <button type="button" class="fb-btn fb-btn--sm" wire:click="applyTriage('priority')">
                            {{ __('feedback-board::feedback-board.triage.apply_priority') }}: {{ \YousefAman\FeedbackBoard\Enums\FeedbackPriority::tryFrom((string) $triage['suggested_priority'])?->label() ?? $triage['suggested_priority'] }}
                        </button>
                    @endif

                    <button type="button" class="fb-btn fb-btn--sm" wire:click="requestTriage">{{ __('feedback-board::feedback-board.triage.retriage') }}</button>
                </div>
            @endif
        </div>
    @endif

    @php($runner = $item->ai_state['runner'] ?? null)
    @php($runnerConfigured = (bool) \YousefAman\FeedbackBoard\Runner\Support\RunnerGate::configured())
    @if ($runnerConfigured)
        <div class="fb-panel fb-runner" wire:key="runner-{{ $item->id }}"
             @if(in_array(($runner['status'] ?? null), ['dispatched','running'], true)) wire:poll.5s @endif>
            @if (! config('feedback-board.ingest.enabled'))
                <p class="fb-notice fb-notice--warning">{{ __('feedback-board::feedback-board.runner.ingest_disabled') }}</p>
            @elseif (($runner['status'] ?? null) === null)
                <div class="fb-actions">
                    <button type="button" class="fb-btn fb-btn--sm" wire:click="dispatchToRunner">{{ __('feedback-board::feedback-board.runner.send') }}</button>
                </div>
            @else
                @if (in_array($runner['status'], ['dispatched','running'], true))
                    <p class="fb-muted">{{ __('feedback-board::feedback-board.runner.'.$runner['status']) }} — {{ $runner['dispatched_at'] ?? '' }}</p>
                @elseif ($runner['status'] === 'failed')
                    <p class="fb-notice fb-notice--danger">{{ __('feedback-board::feedback-board.runner.failed') }}: {{ $runner['error_code'] ?? '' }}</p>
                @elseif ($runner['status'] === 'completed')
                    <p>{{ __('feedback-board::feedback-board.runner.completed') }}</p>
                @endif

                @if (($runner['diff'] ?? null) !== null)
                    <details class="fb-details"><summary>{{ __('feedback-board::feedback-board.runner.diff') }}</summary><pre class="fb-pre">{{ $runner['diff'] }}</pre></details>
                @endif
                @if (($runner['pr_url'] ?? null) !== null)
                    <a class="fb-link" href="{{ $runner['pr_url'] }}" target="_blank" rel="noopener noreferrer" dir="ltr">{{ $runner['pr_url'] }}</a>
                @endif

                @if (! in_array($runner['status'], ['dispatched','running'], true))
                    <div class="fb-actions">
                        <button type="button" class="fb-btn fb-btn--sm" wire:click="dispatchToRunner">{{ __('feedback-board::feedback-board.runner.resend') }}</button>
                    </div>
                @endif
            @endif
        </div>
    @endif

    @if ($comments->isNotEmpty())
        <section class="fb-section">
            <h3 class="fb-section-title">{{ __('feedback-board::feedback-board.board.thread') }}</h3>
            <div class="fb-thread">
                @foreach ($comments as $c)
                    <article wire:key="dc-{{ $c->id }}" @class(['fb-comment', 'fb-comment--'.$c->author_role->value, 'fb-comment--internal' => $c->is_internal])>
                        <div class="fb-comment__head">
                            <span class="fb-comment__author">{{ $c->author_role->label() }}</span>
                            @if ($c->is_internal)
                                <span class="fb-badge fb-badge--warning">{{ __('feedback-board::feedback-board.board.internal') }}</span>
                            @endif
                            @if ($c->created_at)
                                <time class="fb-comment__time" datetime="{{ $c->created_at->toIso8601String() }}">{{ $c->created_at->diffForHumans() }}</time>
                            @endif
                        </div>
                        <p class="fb-prose" dir="auto">{{ $c->body }}</p>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    <div class="fb-composer" x-data="{ tab: 'reply' }">
        <div class="fb-tabs">
            <button type="button" class="fb-tab" x-on:click="tab = 'reply'" x-bind:aria-pressed="tab === 'reply'">{{ __('feedback-board::feedback-board.board.reply') }}</button>
            <button type="button" class="fb-tab" x-on:click="tab = 'note'" x-bind:aria-pressed="tab === 'note'">{{ __('feedback-board::feedback-board.board.internal_note') }}</button>
        </div>

        <form wire:submit="reply" class="fb-composer__panel" x-show="tab === 'reply'">
            <label class="fb-field">
                <span class="fb-sr-only">{{ __('feedback-board::feedback-board.board.reply') }}</span>
                <textarea class="fb-textarea" wire:model="body" rows="4" dir="auto" placeholder="{{ __('feedback-board::feedback-board.board.reply') }}"></textarea>
                @error('body') <span class="fb-error">{{ $message }}</span> @enderror
            </label>
            <div class="fb-composer__actions">
                <button type="submit" class="fb-btn fb-btn--primary">{{ __('feedback-board::feedback-board.board.reply') }}</button>
            </div>
        </form>

        <form wire:submit="internalNote" class="fb-composer__panel fb-composer__panel--internal" x-show="tab === 'note'">
            <label class="fb-field">
                <span class="fb-sr-only">{{ __('feedback-board::feedback-board.board.internal_note') }}</span>
                <textarea class="fb-textarea" wire:model="note" rows="4" dir="auto" placeholder="{{ __('feedback-board::feedback-board.board.internal_note') }}"></textarea>
                @error('note') <span class="fb-error">{{ $message }}</span> @enderror
            </label>
            <div class="fb-composer__actions">
                <button type="submit" class="fb-btn">{{ __('feedback-board::feedback-board.board.internal_note') }}</button>
            </div>
        </form>
    </div>

    @if ($activities->isNotEmpty())
        <section class="fb-section">
            <h3 class="fb-section-title">{{ __('feedback-board::feedback-board.board.activity') }}</h3>
            <ul class="fb-activity">
                @foreach ($activities as $a)
                    <li class="fb-activity__item" wire:key="act-{{ $a->id }}">
                        <span>{{ $a->summary() }}</span>
                        @if ($a->created_at)
                            <time class="fb-activity__time" datetime="{{ $a->created_at->toIso8601String() }}">{{ $a->created_at->diffForHumans() }}</time>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
