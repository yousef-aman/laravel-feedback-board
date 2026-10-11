<div class="fb-scope fb-form fb-sheet" data-fb-theme="{{ \YousefAman\FeedbackBoard\Support\Ui::theme() }}" dir="{{ __('feedback-board::feedback-board.dir') }}"
     x-data="{ @include('feedback-board::partials.upload-queue') }"
     x-on:paste="pasted($event)"
     x-on:fb-launcher-opened.window="$wire.sent && $wire.set('sent', false)">
    @include('feedback-board::partials.assets')
    {{-- The head lives here, not around the component, so a paste while focus rests on its close button reaches the same scope. --}}
    <div class="fb-slide-over__head">
        <div class="fb-slide-over__titles">
            <h2 id="{{ $titleId }}" class="fb-slide-over__title">{{ __('feedback-board::feedback-board.form.heading') }}</h2>
            <p class="fb-slide-over__sub">{{ __('feedback-board::feedback-board.form.intro') }}</p>
        </div>
        <button type="button" class="fb-icon-btn" x-on:click="$dispatch('fb-overlay-close')" aria-label="{{ __('feedback-board::feedback-board.close') }}">
            <x-feedback-board::icon name="x-mark" />
        </button>
    </div>
    @if ($sent)
        <div class="fb-sheet__body">
            <div class="fb-sent" role="status" tabindex="-1" x-init="$nextTick(() => $el.focus())">
                <span class="fb-sent__icon"><x-feedback-board::icon name="check-circle" /></span>
                <p class="fb-sent__title">{{ __('feedback-board::feedback-board.form.sent_title') }}</p>
                <p class="fb-sent__body">{{ __('feedback-board::feedback-board.form.sent_ref') }} <span class="fb-sent__ref" dir="ltr">#{{ $sentId }}</span>. {{ __('feedback-board::feedback-board.form.sent_body') }}</p>
                @if ($attachmentsFailed)
                    <p class="fb-notice fb-notice--warning">{{ __('feedback-board::feedback-board.form.attachments_failed') }}</p>
                @endif
                <div class="fb-sent__actions">
                    <a class="fb-btn fb-btn--primary" href="{{ $followUrl }}">{{ __('feedback-board::feedback-board.form.follow') }}</a>
                    <button type="button" class="fb-btn" wire:click="$set('sent', false)">{{ __('feedback-board::feedback-board.form.send_another') }}</button>
                </div>
            </div>
        </div>
    @else
        <form wire:submit="submit" class="fb-sheet">
            <div class="fb-sheet__body">
                <fieldset class="fb-fieldset">
                    <legend class="fb-label fb-legend">{{ __('feedback-board::feedback-board.form.type') }}</legend>
                    <div class="fb-types">
                        @foreach ($types as $type)
                            <label class="fb-type">
                                <input type="radio" class="fb-type__input" name="{{ $uid }}-type" value="{{ $type->value }}" wire:model="type">
                                <span class="fb-type__card">
                                    <x-feedback-board::type-icon :type="$type" class="fb-type-icon--sm" />
                                    <span class="fb-type__text">
                                        <span class="fb-type__label">{{ $type->label() }}</span>
                                        <span class="fb-type__hint">{{ $type->hint() }}</span>
                                    </span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @error('type') <span class="fb-error">{{ $message }}</span> @enderror
                </fieldset>

                <div class="fb-field">
                    <label class="fb-label" for="{{ $uid }}-title">{{ __('feedback-board::feedback-board.form.title') }} <span class="fb-required" aria-hidden="true">*</span></label>
                    <input type="text" id="{{ $uid }}-title" class="fb-input" wire:model="title" maxlength="255" aria-required="true" dir="auto" placeholder="{{ __('feedback-board::feedback-board.form.title_placeholder') }}" />
                    @error('title') <span class="fb-error">{{ $message }}</span> @enderror
                </div>

                <div class="fb-field">
                    <label class="fb-label" for="{{ $uid }}-description">{{ __('feedback-board::feedback-board.form.description') }}</label>
                    <textarea id="{{ $uid }}-description" class="fb-textarea" wire:model="description" rows="4" dir="auto" placeholder="{{ __('feedback-board::feedback-board.form.description_placeholder') }}"></textarea>
                    @error('description') <span class="fb-error">{{ $message }}</span> @enderror
                </div>

                <div class="fb-field">
                    <span class="fb-label">{{ __('feedback-board::feedback-board.form.attachments') }}</span>
                    <label class="fb-dropzone" x-bind:class="{ 'fb-dropzone--over': dragging }"
                           x-on:dragover.prevent="dragging = true" x-on:dragleave="dragging = false" x-on:drop.prevent="dropped($event)">
                        <input type="file" class="fb-file-input" multiple x-on:change="picked($event)">
                        <x-feedback-board::icon name="arrow-up-tray" class="fb-dropzone__icon" />
                        <span class="fb-dropzone__title">{{ __('feedback-board::feedback-board.form.drop') }} <span class="fb-dropzone__browse">{{ __('feedback-board::feedback-board.form.browse') }}</span></span>
                        <span class="fb-dropzone__hint">{{ __('feedback-board::feedback-board.form.paste_hint') }} · {{ __('feedback-board::feedback-board.form.attachments_hint', ['types' => $attachmentTypes, 'size' => $attachmentSize]) }} · {{ __('feedback-board::feedback-board.form.max_files', ['count' => $maxFiles]) }}</span>
                    </label>
                    <p class="fb-hint" x-show="uploading > 0" x-cloak>{{ __('feedback-board::feedback-board.form.uploading') }} <span x-text="progress + '%'"></span></p>
                    @if ($files !== [])
                        <ul class="fb-uploads">
                            @foreach ($files as $index => $file)
                                <li class="fb-upload" wire:key="{{ $uid }}-file-{{ $index }}-{{ md5($file->getFilename()) }}">
                                    <span class="fb-upload__thumb">
                                        @if (\YousefAman\FeedbackBoard\Support\Images::matches($file->getMimeType(), $file->getClientOriginalName()) && $file->isPreviewable())
                                            <img src="{{ $file->temporaryUrl() }}" alt="">
                                        @else
                                            <x-feedback-board::icon name="document" />
                                        @endif
                                    </span>
                                    <span class="fb-upload__meta">
                                        <span class="fb-upload__name" dir="auto">{{ $file->getClientOriginalName() }}</span>
                                        <span class="fb-upload__size">{{ \YousefAman\FeedbackBoard\Services\AttachmentStorage::humanSize((int) $file->getSize()) }}</span>
                                    </span>
                                    <button type="button" class="fb-icon-btn" wire:click="removeFile({{ $index }})">
                                        <x-feedback-board::icon name="x-mark" />
                                        <span class="fb-sr-only">{{ __('feedback-board::feedback-board.form.remove_file', ['name' => $file->getClientOriginalName()]) }}</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @foreach (['upload', 'files', 'files.*'] as $field)
                        @error($field) <span class="fb-error">{{ $message }}</span> @enderror
                    @endforeach
                </div>

                <div class="fb-context-note">
                    <x-feedback-board::icon name="information-circle" />
                    <p>{{ __('feedback-board::feedback-board.form.context') }} <span class="fb-context-note__url" dir="ltr">{{ $pageUrl }}</span></p>
                </div>
            </div>

            <div class="fb-sheet__foot">
                <button type="submit" class="fb-btn fb-btn--primary fb-btn--lg" x-bind:disabled="uploading > 0" wire:loading.attr="disabled" wire:target="submit">
                    <x-feedback-board::icon name="paper-airplane" />{{ __('feedback-board::feedback-board.form.submit') }}
                </button>
                <button type="button" class="fb-btn fb-btn--lg" x-on:click="$dispatch('fb-overlay-close')">{{ __('feedback-board::feedback-board.form.cancel') }}</button>
                <a class="fb-link fb-sheet__link" href="{{ $reportsUrl }}">{{ __('feedback-board::feedback-board.form.view_reports') }}</a>
            </div>
        </form>
    @endif
</div>
