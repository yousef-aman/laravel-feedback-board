<form wire:submit="submit" class="fb-form" dir="{{ __('feedback-board::feedback-board.dir') }}">
    <input type="text" wire:model="title" placeholder="{{ __('feedback-board::feedback-board.form.title') }}" />
    @error('title') <span class="fb-error">{{ $message }}</span> @enderror

    <select wire:model="type">
        <option value="bug">{{ __('feedback-board::feedback-board.type.bug') }}</option>
        <option value="feature">{{ __('feedback-board::feedback-board.type.feature') }}</option>
        <option value="question">{{ __('feedback-board::feedback-board.type.question') }}</option>
        <option value="other">{{ __('feedback-board::feedback-board.type.other') }}</option>
    </select>

    <textarea wire:model="description" placeholder="{{ __('feedback-board::feedback-board.form.description') }}"></textarea>
    <input type="file" wire:model="files" multiple />

    <button type="submit">{{ __('feedback-board::feedback-board.form.submit') }}</button>
</form>
