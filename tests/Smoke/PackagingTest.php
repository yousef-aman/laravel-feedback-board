<?php

use Illuminate\Support\Facades\Process;

// Composer installs the dist archive that GitHub builds with `git archive`, so export-ignore
// rules decide what ships. These tests list the archive git itself would build (the committed
// tree, filtered by the working copy's .gitattributes) instead of re-implementing gitattributes
// pattern rules, which treat `dir`, `dir/` and `dir/**` differently.
function packagingGit(string|array $command): array
{
    $root = dirname(__DIR__, 2);

    if (! Process::path($root)->run(['git', 'rev-parse', '--is-inside-work-tree'])->successful()) {
        // A CI lane without a git checkout must not go green by skipping.
        getenv('CI')
            ? test()->fail('The packaging tests need a git checkout in CI.')
            : test()->markTestSkipped('Not a git checkout.');
    }

    $output = Process::path($root)->run($command)->throw()->output();

    return array_values(array_filter(explode("\n", $output), fn (string $line) => $line !== '' && ! str_ends_with($line, '/')));
}

function archivedFiles(): array
{
    return packagingGit('git archive --worktree-attributes --format=tar HEAD | tar -t');
}

it('ships every tracked file the package needs at runtime', function () {
    $runtime = packagingGit(['git', 'ls-tree', '-r', '--name-only', 'HEAD', '--',
        'src', 'config', 'database', 'resources', 'routes', 'docs/runner-contract.md', 'docs/examples',
        'composer.json', 'LICENSE', 'README.md', 'CHANGELOG.md',
    ]);

    expect($runtime)->toContain('resources/dist/feedback-board.css', 'docs/runner-contract.md')
        ->and(array_values(array_diff($runtime, archivedFiles())))->toBe([]);
});

it('keeps development-only files out of the dist archive', function () {
    $leaked = preg_grep('#^(tests/|\.github/|phpunit\.xml\.dist$|\.gitignore$|\.gitattributes$)#', archivedFiles());

    expect(array_values($leaked))->toBe([]);
});
