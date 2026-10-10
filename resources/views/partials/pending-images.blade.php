{{-- Pending composer images, each removable; previews only for allow-listed images. Needs $files and $uid. --}}
@if ($files !== [])
    <div class="fb-pending">
        @foreach ($files as $index => $file)
            <span class="fb-pending__item" wire:key="{{ $uid }}-pending-{{ $index }}-{{ md5($file->getFilename()) }}">
                @if (\YousefAman\FeedbackBoard\Support\Images::matches($file->getMimeType(), $file->getClientOriginalName()))
                    <img src="{{ $file->temporaryUrl() }}" alt="">
                @else
                    <x-feedback-board::icon name="photo" />
                @endif
                <button type="button" class="fb-pending__remove" wire:click="removeFile({{ $index }})">
                    <x-feedback-board::icon name="x-mark" />
                    <span class="fb-sr-only">{{ __('feedback-board::feedback-board.composer.remove_image') }}</span>
                </button>
            </span>
        @endforeach
    </div>
@endif
