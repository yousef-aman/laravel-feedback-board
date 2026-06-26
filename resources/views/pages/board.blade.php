{{-- resources/views/pages/board.blade.php --}}
<!doctype html>
<html dir="{{ __('feedback-board::feedback-board.dir') }}">
<head><meta charset="utf-8"><title>{{ __('feedback-board::feedback-board.board.title') }}</title>@livewireStyles</head>
<body>
    @livewire('feedback-board::feedback-kanban')
    @livewireScripts
</body>
</html>
