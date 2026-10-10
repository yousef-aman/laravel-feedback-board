<?php

use Illuminate\Support\Facades\Process;

// Composer installs from `git archive`, so an export-ignore rule decides what ships. Asking
// git itself (rather than parsing .gitattributes) keeps the check true to its pattern rules.
// check-attr does not apply a directory's attribute to the files inside it, but git archive
// skips the whole directory, so a path counts as ignored when it or any parent directory is.
function exportIgnored(array $paths): array
{
    $root = dirname(__DIR__, 2);

    if (! Process::path($root)->run(['git', 'rev-parse', '--is-inside-work-tree'])->successful()) {
        test()->markTestSkipped('Not a git checkout.');
    }

    $candidates = collect($paths)->flatMap(function (string $path) {
        $segments = explode('/', $path);

        return array_map(fn (int $n) => implode('/', array_slice($segments, 0, $n)), range(1, count($segments)));
    })->unique()->values();

    $result = Process::path($root)
        ->input($candidates->implode("\n")."\n")
        ->run(['git', 'check-attr', '--stdin', 'export-ignore'])
        ->throw();

    $ignored = collect(explode("\n", trim($result->output())))
        ->filter(fn (string $line) => str_ends_with($line, ': export-ignore: set'))
        ->map(fn (string $line) => substr($line, 0, -strlen(': export-ignore: set')))
        ->all();

    return array_values(array_filter($paths, fn (string $path) => collect($ignored)
        ->contains(fn (string $i) => $path === $i || str_starts_with($path, $i.'/'))));
}

it('ships every file the package needs at runtime', function () {
    $root = dirname(__DIR__, 2);
    $paths = ['composer.json', 'LICENSE', 'README.md', 'CHANGELOG.md'];

    foreach (['src', 'config', 'database', 'resources', 'routes'] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir", FilesystemIterator::SKIP_DOTS)) as $file) {
            $paths[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }

    expect($paths)->toContain('resources/dist/feedback-board.css')
        ->and(exportIgnored($paths))->toBe([]);
});

it('keeps development-only files out of the dist archive', function () {
    $paths = ['tests/Pest.php', 'docs/superpowers/specs/x.md', '.github/workflows/tests.yml', 'phpunit.xml.dist', '.gitignore', '.gitattributes'];

    expect(exportIgnored($paths))->toBe($paths);
});
