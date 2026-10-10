<div class="fb-scope fb-form" data-fb-theme="{{ \YousefAman\FeedbackBoard\Support\Ui::theme() }}" dir="{{ __('feedback-board::feedback-board.dir') }}"
     x-data x-on:fb-launcher-opened.window="$wire.sent && $wire.set('sent', false)">
    @include('feedback-board::partials.assets')
    @if ($sent)
        <div class="fb-form__success" role="status">
            <svg class="fb-form__success-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="m8 12 3 3 5-6"/></svg>
            <p class="fb-form__success-title">{{ __('feedback-board::feedback-board.form.sent_title') }}</p>
            <p class="fb-form__success-body">{{ __('feedback-board::feedback-board.form.sent_body') }}</p>
            <div class="fb-form__success-actions">
                <a class="fb-btn" href="{{ route('feedback-board.page') }}">{{ __('feedback-board::feedback-board.form.view_reports') }}</a>
                <button type="button" class="fb-btn fb-btn--primary" wire:click="$set('sent', false)">{{ __('feedback-board::feedback-board.form.send_another') }}</button>
            </div>
        </div>
    @else
        <form wire:submit="submit" class="fb-form__fields">
            <label class="fb-field">
                <span class="fb-label">{{ __('feedback-board::feedback-board.form.title') }}</span>
                <input type="text" class="fb-input" wire:model="title" maxlength="255" dir="auto" />
                @error('title') <span class="fb-error">{{ $message }}</span> @enderror
            </label>

            <label class="fb-field">
                <span class="fb-label">{{ __('feedback-board::feedback-board.form.type') }}</span>
                <select class="fb-select" wire:model="type">
                    <option value="bug">{{ __('feedback-board::feedback-board.type.bug') }}</option>
                    <option value="feature">{{ __('feedback-board::feedback-board.type.feature') }}</option>
                    <option value="question">{{ __('feedback-board::feedback-board.type.question') }}</option>
                    <option value="other">{{ __('feedback-board::feedback-board.type.other') }}</option>
                </select>
                @error('type') <span class="fb-error">{{ $message }}</span> @enderror
            </label>

            <label class="fb-field">
                <span class="fb-label">{{ __('feedback-board::feedback-board.form.description') }}</span>
                <textarea class="fb-textarea" wire:model="description" rows="5" dir="auto"></textarea>
                @error('description') <span class="fb-error">{{ $message }}</span> @enderror
            </label>

            <label class="fb-field">
                <span class="fb-label">{{ __('feedback-board::feedback-board.form.attachments') }}</span>
                <input type="file" class="fb-file" wire:model="files" multiple />
                <span class="fb-hint">{{ __('feedback-board::feedback-board.form.attachments_hint', ['types' => $attachmentTypes, 'size' => $attachmentSize]) }}</span>
                <span class="fb-hint" wire:loading wire:target="files">{{ __('feedback-board::feedback-board.form.uploading') }}</span>
                @if ($errors->has('files') || $errors->has('files.*'))
                    <span class="fb-error">{{ $errors->first('files') ?: $errors->first('files.*') }}</span>
                @endif
            </label>

            <div class="fb-form__actions">
                <button type="submit" class="fb-btn fb-btn--primary" wire:loading.attr="disabled" wire:target="submit,files">{{ __('feedback-board::feedback-board.form.submit') }}</button>
            </div>
        </form>
    @endif
</div>
