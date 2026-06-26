{{-- resources/views/livewire/my-reports-board.blade.php --}}
<div dir="{{ __('feedback-board::feedback-board.dir') }}">
    <h2>{{ __('feedback-board::feedback-board.board.my_reports') }}</h2>
    @livewire('feedback-board::feedback-form')
    <ul>
        @foreach ($items as $item)
            <li wire:key="item-{{ $item->id }}" wire:click="open({{ $item->id }})" style="cursor:pointer">
                <strong>{{ $item->title }}</strong>
                <span class="fb-badge fb-badge--{{ $item->status->color() }}">{{ $item->status->label() }}</span>
            </li>
        @endforeach
    </ul>

    @if ($selected)
        @livewire('feedback-board::feedback-item-modal', ['item' => $selected], key('modal-'.$selected->id))
    @endif
</div>
