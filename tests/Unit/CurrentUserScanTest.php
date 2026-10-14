<?php

/**
 * Every read of the signed-in user goes through FeedbackBoard::user(), or the trait built on it, so a
 * reporter on another guard (feedback-board.auth.guards) is seen everywhere. Pest's arch expectations
 * cannot see method calls on objects or Blade directives, so this scans the sources.
 */
it('reads the signed-in user only through FeedbackBoard::user()', function () {
    $root = dirname(__DIR__, 2);
    $allowed = ['src/FeedbackBoard.php', 'src/Support/Concerns/AuthorizesFeedbackUser.php'];
    $patterns = [
        'auth(...)' => '/(?<![\w$>])auth\(/',
        'Auth::guard(' => '/\bAuth::guard\(/',
        'Auth::user/check/id/guest' => '/\bAuth::(user|check|id|guest)\(/',
        '->authorize(' => '/->authorize\(/',
        'Gate:: without forUser' => '/\bGate::(allows|denies|check|any|none|authorize|inspect)\(/',
        'request()->user()' => '/request\(\)->user\(/',
        '$request->user()' => '/\$request->user\(/',
        'Blade @auth/@guest/@can' => '/@(auth|guest|can|cannot|canany)\b/',
    ];
    $files = [];

    foreach (['src', 'resources/views', 'resources/views-filament'] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (str_ends_with($file->getFilename(), '.php')) {
                $files[] = $file->getPathname();
            }
        }
    }

    $hits = [];

    foreach ($files as $path) {
        $relative = substr($path, strlen($root) + 1);

        if (in_array($relative, $allowed, true)) {
            continue;
        }

        $source = (string) file_get_contents($path);

        foreach ($patterns as $label => $pattern) {
            preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as [, $offset]) {
                $hits[] = $relative.':'.(substr_count(substr($source, 0, $offset), "\n") + 1).' '.$label;
            }
        }
    }

    expect($files)->not->toBeEmpty()
        ->and($hits)->toBe([]);
});
