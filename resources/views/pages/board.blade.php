{{-- resources/views/pages/board.blade.php --}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ __('feedback-board::feedback-board.dir') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="{{ \YousefAman\FeedbackBoard\Support\Ui::colorScheme() }}">
    <title>{{ __('feedback-board::feedback-board.board.title') }}</title>
    @livewireStyles
</head>
<body class="fb-scope fb-standalone" data-fb-theme="{{ \YousefAman\FeedbackBoard\Support\Ui::theme() }}">
    @include('feedback-board::partials.assets')
    @include('feedback-board::partials.standalone-header', ['wide' => true])
    <main class="fb-standalone__main fb-standalone__main--wide">
        <header class="fb-head">
            <div class="fb-head__titles">
                <h1 class="fb-head__title">{{ __('feedback-board::feedback-board.board.title') }}</h1>
                <p class="fb-head__sub">{{ __('feedback-board::feedback-board.kanban.subheading') }}</p>
            </div>
        </header>
        @livewire('feedback-board::feedback-kanban')
    </main>
    @livewireScripts
</body>
</html>
