{{-- resources/views/livewire/feedback-detail.blade.php — the developer's card dialog content --}}
<div class="fb-scope fb-detail" data-fb-theme="{{ \YousefAman\FeedbackBoard\Support\Ui::theme() }}" dir="{{ __('feedback-board::feedback-board.dir') }}"
     x-data="{
        lightbox: null,
        zoom(el) { this.lightbox = { src: el.dataset.fbSrc, name: el.dataset.fbName } },
        closeLightbox() { this.lightbox = null },
        showActivity: true,
        init() { try { this.showActivity = localStorage.getItem('feedback-board.activity-hidden') !== '1' } catch (e) {} },
        toggleActivity() { this.showActivity = ! this.showActivity; try { localStorage.setItem('feedback-board.activity-hidden', this.showActivity ? '0' : '1') } catch (e) {} },
     }">
    @include('feedback-board::partials.assets')

    <div @class(['fb-cover', 'fb-cover--empty' => $cover === null])>
        <div class="fb-cover__bar">
            <label class="fb-sr-only" for="{{ $uid }}-status">{{ __('feedback-board::feedback-board.board.status') }}</label>
            <select id="{{ $uid }}-status" class="fb-select fb-cover__status" wire:change="changeStatus($event.target.value)">
                @foreach ($statuses as $s)
                    <option value="{{ $s->value }}" @selected($item->status === $s)>{{ $s->label() }}</option>
                @endforeach
            </select>
            <button type="button" class="fb-round-btn" x-on:click="$dispatch('fb-overlay-close')">
                <x-feedback-board::icon name="x-mark" />
                <span class="fb-sr-only">{{ __('feedback-board::feedback-board.close') }}</span>
            </button>
        </div>
        @if ($cover)
            <x-feedback-board::thumb :attachment="$cover" />
        @endif
    </div>

    <div class="fb-dialog__cols">
        <div class="fb-dialog__main">
            <header class="fb-headline">
                <x-feedback-board::type-icon :type="$item->type" />
                <div class="fb-headline__text">
                    <h2 id="fb-drawer-title" class="fb-headline__title" dir="auto">{{ $item->title }}</h2>
                    <p class="fb-headline__sub">
                        <span>#{{ $item->id }} · {{ $item->type->label() }} ·</span>
                        <span>{{ __('feedback-board::feedback-board.detail.sent_by') }} <bdi>{{ $reporterName ?? __('feedback-board::feedback-board.activity.someone') }}</bdi></span>
                        @if ($item->created_at)
                            <time datetime="{{ $item->created_at->toIso8601String() }}">{{ $item->created_at->diffForHumans() }}</time>
                        @endif
                    </p>
                </div>
            </header>

            <div class="fb-props">
                <div class="fb-prop">
                    <label class="fb-prop__label" for="{{ $uid }}-priority">{{ __('feedback-board::feedback-board.board.priority') }}</label>
                    <select id="{{ $uid }}-priority" class="fb-select" wire:change="setPriority($event.target.value)">
                        <option value="" @selected($item->priority === null)>{{ __('feedback-board::feedback-board.board.no_priority') }}</option>
                        @foreach ($priorities as $p)
                            <option value="{{ $p->value }}" @selected($item->priority === $p)>{{ $p->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="fb-prop">
                    <span class="fb-prop__label">{{ __('feedback-board::feedback-board.board.assignee') }}</span>
                    <div class="fb-prop__who">
                        @if ($assigneeName !== null)
                            <x-feedback-board::avatar :name="$assigneeName" />
                            <bdi>{{ $assigneeName }}</bdi>
                            <button type="button" class="fb-btn fb-btn--sm" wire:click="unassign">{{ __('feedback-board::feedback-board.board.unassign') }}</button>
                        @else
                            <span class="fb-muted">{{ __('feedback-board::feedback-board.board.unassigned') }}</span>
                            <button type="button" class="fb-btn fb-btn--sm" wire:click="assignToMe">{{ __('feedback-board::feedback-board.board.assign_me') }}</button>
                        @endif
                    </div>
                </div>
                <div class="fb-prop">
                    <span class="fb-prop__label">{{ __('feedback-board::feedback-board.detail.reporter') }}</span>
                    <div class="fb-prop__who">
                        @if ($reporterName !== null)
                            <x-feedback-board::avatar :name="$reporterName" />
                            <bdi>{{ $reporterName }}</bdi>
                        @else
                            <span class="fb-muted">{{ __('feedback-board::feedback-board.activity.someone') }}</span>
                        @endif
                    </div>
                </div>
            </div>

            <section class="fb-section">
                <h3 class="fb-section-title"><x-feedback-board::icon name="queue-list" />{{ __('feedback-board::feedback-board.item.description') }}</h3>
                <p class="fb-prose" dir="auto">{{ filled($item->description) ? $item->description : __('feedback-board::feedback-board.item.no_description') }}</p>
            </section>

            @if ($gallery->isNotEmpty())
                <section class="fb-section">
                    <h3 class="fb-section-title"><x-feedback-board::icon name="paper-clip" />{{ __('feedback-board::feedback-board.board.attachments') }} · {{ $gallery->count() }}</h3>
                    <ul class="fb-gallery">
                        @foreach ($gallery as $attachment)
                            <li class="fb-gallery__item" wire:key="g-{{ $attachment->id }}">
                                @if ($attachment->isImage())
                                    <x-feedback-board::thumb :attachment="$attachment" />
                                @else
                                    <a class="fb-gallery__file" href="{{ $attachment->url() }}"><x-feedback-board::icon name="document" /><span class="fb-sr-only">{{ __('feedback-board::feedback-board.detail.download', ['name' => $attachment->original_name]) }}</span></a>
                                @endif
                                <span class="fb-gallery__name" dir="auto">{{ $attachment->original_name }}</span>
                                @if ($attachment->created_at)
                                    <time class="fb-gallery__time" datetime="{{ $attachment->created_at->toIso8601String() }}">{{ $attachment->created_at->diffForHumans() }}</time>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($context !== [])
                <details class="fb-details fb-tech">
                    <summary>{{ __('feedback-board::feedback-board.board.context') }}</summary>
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
                </details>
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
                            {{-- Times as words in the app's language, set explicitly (Carbon follows it only through its Laravel provider): a raw ISO string scrambles in RTL text. An unparsable value is shown as stored. --}}
                            @php($sentAt = \YousefAman\FeedbackBoard\Runner\Support\RunnerState::parseTime($runner['dispatched_at'] ?? null))
                            <p class="fb-muted">
                                {{ __('feedback-board::feedback-board.runner.'.$runner['status']) }} —
                                @if ($sentAt)
                                    <time datetime="{{ $sentAt->toIso8601String() }}" title="{{ $sentAt->toIso8601String() }}">{{ $sentAt->locale(app()->getLocale(), 'en')->diffForHumans() }}</time>
                                @else
                                    {{ $runner['dispatched_at'] ?? '' }}
                                @endif
                            </p>
                            @if (\YousefAman\FeedbackBoard\Runner\Support\RunnerState::isStale($runner))
                                @php($lastActivity = $runner['updated_at'] ?? $runner['dispatched_at'] ?? '')
                                @php($lastAt = \YousefAman\FeedbackBoard\Runner\Support\RunnerState::parseTime($lastActivity))
                                <p class="fb-notice fb-notice--warning" @if ($lastAt) title="{{ $lastAt->toIso8601String() }}" @endif>{{ __('feedback-board::feedback-board.runner.stale', ['time' => $lastAt?->locale(app()->getLocale(), 'en')->diffForHumans(['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE]) ?? $lastActivity]) }}</p>
                            @endif
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

                        @if (\YousefAman\FeedbackBoard\Runner\Support\RunnerState::canDispatch($runner))
                            <div class="fb-actions">
                                <button type="button" class="fb-btn fb-btn--sm" wire:click="dispatchToRunner">{{ __('feedback-board::feedback-board.runner.resend') }}</button>
                            </div>
                        @endif
                    @endif
                </div>
            @endif

            @if ($githubQueued)
                <p class="fb-notice">{{ __('feedback-board::feedback-board.detail.github_queued') }}</p>
            @elseif ($canGithub)
                <div class="fb-actions">
                    <button type="button" class="fb-btn fb-btn--sm" wire:click="sendToGithub" wire:loading.attr="disabled" wire:target="sendToGithub">
                        <x-feedback-board::icon name="code-bracket" />{{ __('feedback-board::feedback-board.detail.send_github') }}
                    </button>
                </div>
            @endif
        </div>

        <aside class="fb-dialog__side" aria-labelledby="{{ $uid }}-side-title">
            <div class="fb-side-head">
                <h3 id="{{ $uid }}-side-title" class="fb-side-head__title"><x-feedback-board::icon name="chat-bubble-left-right" />{{ __('feedback-board::feedback-board.detail.side_title') }}</h3>
                <button type="button" class="fb-btn fb-btn--sm" x-on:click="toggleActivity()">
                    <span x-show="showActivity">{{ __('feedback-board::feedback-board.detail.hide_activity') }}</span>
                    <span x-show="! showActivity" x-cloak>{{ __('feedback-board::feedback-board.detail.show_activity') }}</span>
                </button>
            </div>

            <form class="fb-dev-composer"
                  x-data="{ mode: 'reply', @include('feedback-board::partials.upload-queue') }"
                  x-bind:class="{ 'fb-dev-composer--internal': mode === 'internal' }"
                  x-on:submit.prevent="mode === 'internal' ? $wire.internalNote() : $wire.reply()"
                  @if ($canAttach) x-on:paste="pasted($event)" x-on:dragover.prevent x-on:drop.prevent="dropped($event)" @endif>
                <div class="fb-segmented" role="group" aria-label="{{ __('feedback-board::feedback-board.detail.mode_label') }}">
                    <button type="button" class="fb-segmented__btn" x-bind:aria-pressed="mode === 'reply'" x-bind:class="{ 'fb-tint': mode === 'reply' }" x-on:click="mode = 'reply'">
                        <x-feedback-board::icon name="chat-bubble-left-right" />{{ __('feedback-board::feedback-board.detail.mode_reply') }}
                    </button>
                    <button type="button" class="fb-segmented__btn fb-segmented__btn--internal" x-bind:aria-pressed="mode === 'internal'" x-on:click="mode = 'internal'">
                        <x-feedback-board::icon name="lock-closed" />{{ __('feedback-board::feedback-board.detail.mode_internal') }}
                    </button>
                </div>
                <label class="fb-sr-only" for="{{ $uid }}-body">{{ __('feedback-board::feedback-board.detail.comment_label') }}</label>
                <textarea id="{{ $uid }}-body" class="fb-textarea" wire:model="body" rows="3" dir="auto"
                          placeholder="{{ __('feedback-board::feedback-board.detail.placeholder') }}"></textarea>
                @include('feedback-board::partials.pending-images')
                <div class="fb-dev-composer__row">
                    @if ($canAttach)
                        <label class="fb-icon-btn fb-reply__attach">
                            <input type="file" class="fb-file-input" accept="image/*" multiple x-on:change="picked($event)">
                            <x-feedback-board::icon name="photo" />
                            <span class="fb-sr-only">{{ __('feedback-board::feedback-board.composer.attach_image') }}</span>
                        </label>
                    @endif
                    <span class="fb-hint" x-show="mode === 'reply'">{{ __('feedback-board::feedback-board.detail.hint_reply') }}</span>
                    <span class="fb-hint" x-show="mode === 'internal'" x-cloak>{{ __('feedback-board::feedback-board.detail.hint_internal') }}</span>
                    <button type="submit" class="fb-btn fb-btn--primary fb-btn--sm" x-bind:disabled="uploading > 0" wire:loading.attr="disabled" wire:target="reply, internalNote">
                        {{ __('feedback-board::feedback-board.composer.send') }}
                    </button>
                </div>
                <p class="fb-hint" x-show="uploading > 0" x-cloak>{{ __('feedback-board::feedback-board.form.uploading') }} <span x-text="progress + '%'"></span></p>
                @foreach (['body', 'upload', 'files', 'files.*'] as $field)
                    @error($field) <span class="fb-error">{{ $message }}</span> @enderror
                @endforeach
                @if ($imagesFailed)
                    <p class="fb-error">{{ __('feedback-board::feedback-board.composer.images_failed') }}</p>
                @endif
            </form>

            <div class="fb-feed">
                @forelse ($feed as $entry)
                    @if ($entry['kind'] === 'comment')
                        @php($comment = $entry['model'])
                        @php($commentImages = $comment->attachments->filter(fn ($attachment) => $attachment->isImage()))
                        @php($commentFiles = $comment->attachments->reject(fn ($attachment) => $attachment->isImage()))
                        <article wire:key="feed-c-{{ $comment->id }}" @class(['fb-entry', 'fb-entry--internal' => $comment->is_internal])>
                            <x-feedback-board::avatar :name="$entry['name']" size="lg" />
                            <div class="fb-entry__body">
                                <p class="fb-entry__head">
                                    <bdi class="fb-entry__name">{{ $entry['name'] }}</bdi>
                                    <span @class(['fb-role', 'fb-role--team fb-tint' => $comment->author_role !== \YousefAman\FeedbackBoard\Enums\AuthorRole::Reporter])>{{ $comment->author_role->label() }}</span>
                                    @if ($comment->created_at)
                                        <time datetime="{{ $comment->created_at->toIso8601String() }}">{{ $comment->created_at->diffForHumans() }}</time>
                                    @endif
                                    @if ($comment->is_internal)
                                        <span class="fb-lock-tag"><x-feedback-board::icon name="lock-closed" />{{ __('feedback-board::feedback-board.detail.internal_tag') }}</span>
                                    @endif
                                </p>
                                <div class="fb-entry__text">
                                    @if (filled($comment->body))
                                        <p class="fb-prose" dir="auto">{{ $comment->body }}</p>
                                    @endif
                                    @if ($commentImages->isNotEmpty())
                                        <div class="fb-thumbs">
                                            @foreach ($commentImages as $image)
                                                <x-feedback-board::thumb :attachment="$image" />
                                            @endforeach
                                        </div>
                                    @endif
                                    @if ($commentFiles->isNotEmpty())
                                        <ul class="fb-doc-links">
                                            @foreach ($commentFiles as $file)
                                                <li><a class="fb-doc-link" href="{{ $file->url() }}"><x-feedback-board::icon name="paper-clip" /><span dir="auto">{{ $file->original_name }}</span></a></li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            </div>
                        </article>
                    @else
                        <p class="fb-entry fb-entry--activity" wire:key="feed-a-{{ $entry['model']->id }}" x-show="showActivity">
                            <span class="fb-entry__dot" aria-hidden="true"></span>
                            <span>
                                <span class="fb-entry__sentence">{{ $entry['sentence'] }}</span>
                                @if ($entry['model']->created_at)
                                    · <time datetime="{{ $entry['model']->created_at->toIso8601String() }}">{{ $entry['model']->created_at->diffForHumans() }}</time>
                                @endif
                            </span>
                        </p>
                    @endif
                @empty
                    <p class="fb-muted">{{ __('feedback-board::feedback-board.detail.empty_feed') }}</p>
                @endforelse
            </div>
        </aside>
    </div>

    @include('feedback-board::partials.lightbox')
</div>
