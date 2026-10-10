<div class="fb-scope fb-item fb-sheet" data-fb-theme="{{ \YousefAman\FeedbackBoard\Support\Ui::theme() }}" dir="{{ __('feedback-board::feedback-board.dir') }}"
     x-data="{ lightbox: null, zoom(el) { this.lightbox = { src: el.dataset.fbSrc, name: el.dataset.fbName } }, closeLightbox() { this.lightbox = null } }">
    @include('feedback-board::partials.assets')
    <div class="fb-slide-over__head">
        <x-feedback-board::type-icon :type="$item->type" />
        <div class="fb-slide-over__titles">
            <h2 id="{{ $titleId }}" class="fb-slide-over__title" dir="auto">{{ $item->title }}</h2>
            <p class="fb-slide-over__sub">
                <span>#{{ $item->id }} · {{ $item->type->label() }}</span>
                <span class="fb-badge fb-badge--{{ $item->status->color() }}">{{ $item->status->label() }}</span>
            </p>
        </div>
        <button type="button" class="fb-icon-btn" x-on:click="$dispatch('fb-overlay-close')" aria-label="{{ __('feedback-board::feedback-board.close') }}">
            <x-feedback-board::icon name="x-mark" />
        </button>
    </div>

    <div class="fb-sheet__body">
        @if ($terminal)
            <p @class(['fb-notice', 'fb-banner', 'fb-notice--danger' => $item->status === \YousefAman\FeedbackBoard\Enums\FeedbackStatus::Declined])>
                <span>{{ __('feedback-board::feedback-board.item.'.$item->status->value.'_banner') }}</span>
                @if ($terminalAt)
                    <time datetime="{{ $terminalAt->toIso8601String() }}">{{ $terminalAt->diffForHumans() }}</time>
                @endif
            </p>
        @else
            <ol class="fb-steps" aria-label="{{ __('feedback-board::feedback-board.item.steps_label') }}">
                @foreach ($steps as $step)
                    <li @class(['fb-step', 'fb-step--done' => $step['done'], 'fb-step--current' => $step['current'] && ! $step['done']]) aria-current="{{ $step['current'] ? 'step' : 'false' }}">
                        <span class="fb-step__bar" aria-hidden="true"></span>{{ $step['status']->label() }}
                    </li>
                @endforeach
            </ol>
        @endif

        <div class="fb-meta">
            <span><x-feedback-board::icon name="clock" />{{ __('feedback-board::feedback-board.item.sent_at', ['time' => $item->created_at?->diffForHumans()]) }}</span>
            @if (is_string($item->meta['url'] ?? null) && trim($item->meta['url']) !== '')
                <span><x-feedback-board::icon name="link" /><span class="fb-meta__url" dir="ltr">{{ $item->meta['url'] }}</span></span>
            @endif
        </div>

        <section class="fb-section">
            <h3 class="fb-section-title">{{ __('feedback-board::feedback-board.item.description') }}</h3>
            <p class="fb-prose" dir="auto">{{ filled($item->description) ? $item->description : __('feedback-board::feedback-board.item.no_description') }}</p>
            @if ($images->isNotEmpty())
                <div class="fb-thumbs">
                    @foreach ($images as $image)
                        <x-feedback-board::thumb :attachment="$image" wire:key="image-{{ $image->id }}" />
                    @endforeach
                </div>
            @endif
            @if ($documents->isNotEmpty())
                <ul class="fb-doc-links">
                    @foreach ($documents as $document)
                        <li><a class="fb-doc-link" href="{{ $document->url() }}"><x-feedback-board::icon name="paper-clip" /><span dir="auto">{{ $document->original_name }}</span></a></li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="fb-section">
            <h3 class="fb-section-title">{{ __('feedback-board::feedback-board.item.conversation') }}</h3>
            @if ($comments->isEmpty())
                <p class="fb-waiting"><x-feedback-board::icon name="clock" />{{ __('feedback-board::feedback-board.item.waiting') }}</p>
            @else
                <div class="fb-chat">
                    @foreach ($comments as $comment)
                        @php($mine = $isMine($comment))
                        @php($commentImages = $comment->attachments->filter(fn ($attachment) => $attachment->isImage()))
                        <article wire:key="c-{{ $comment->id }}" @class(['fb-msg', 'fb-msg--mine' => $mine, 'fb-msg--theirs' => ! $mine])>
                            <p class="fb-msg__who">
                                <span class="fb-msg__author">{{ $mine ? __('feedback-board::feedback-board.item.you') : $comment->author_role->label() }}</span>
                                @if ($comment->created_at)
                                    <time datetime="{{ $comment->created_at->toIso8601String() }}">{{ $comment->created_at->diffForHumans() }}</time>
                                @endif
                                @if ($isNew($comment))
                                    <span class="fb-new">{{ __('feedback-board::feedback-board.item.new') }}</span>
                                @endif
                            </p>
                            <div @class(['fb-msg__bubble', 'fb-tint' => ! $mine])>
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
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    </div>

    <form class="fb-sheet__foot fb-reply" wire:submit="reply"
          x-data="{ @include('feedback-board::partials.upload-queue') }"
          @if ($canAttach) x-on:paste="pasted($event)" @endif>
        @include('feedback-board::partials.pending-images')
        <div class="fb-reply__row">
            @if ($canAttach)
                <label class="fb-icon-btn fb-reply__attach">
                    <input type="file" class="fb-file-input" accept="image/*" multiple x-on:change="picked($event)">
                    <x-feedback-board::icon name="paper-clip" />
                    <span class="fb-sr-only">{{ __('feedback-board::feedback-board.composer.attach_image') }}</span>
                </label>
            @endif
            <label class="fb-sr-only" for="{{ $uid }}-body">{{ __('feedback-board::feedback-board.composer.reply_label') }}</label>
            <textarea id="{{ $uid }}-body" class="fb-textarea fb-reply__input" wire:model="body" rows="1" dir="auto"
                      placeholder="{{ __('feedback-board::feedback-board.composer.placeholder_reporter') }}"
                      x-on:input="$el.style.height = 'auto'; $el.style.height = $el.scrollHeight + 'px'"></textarea>
            <button type="submit" class="fb-btn fb-btn--primary" x-bind:disabled="uploading > 0" wire:loading.attr="disabled" wire:target="reply">
                <x-feedback-board::icon name="paper-airplane" />{{ __('feedback-board::feedback-board.composer.send') }}
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

    @include('feedback-board::partials.lightbox')
</div>
