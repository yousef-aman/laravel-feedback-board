<?php

use YousefAman\FeedbackBoard\Support\Ui;

/**
 * Minimal reader for the package stylesheet: every style rule with the at-rule prelude it sits in.
 * Descends into @media/@supports; skips @keyframes (its from/to blocks are not selectors).
 *
 * @return list<array{selector: string, body: string, at: ?string}>
 */
function fbCssRules(string $css): array
{
    $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
    $rules = [];

    $walk = function (string $chunk, ?string $at) use (&$walk, &$rules): void {
        $offset = 0;
        $length = strlen($chunk);

        while (($open = strpos($chunk, '{', $offset)) !== false) {
            $prelude = trim(substr($chunk, $offset, $open - $offset));
            $depth = 1;
            $cursor = $open + 1;

            while ($cursor < $length && $depth > 0) {
                $depth += match ($chunk[$cursor]) { '{' => 1, '}' => -1, default => 0 };
                $cursor++;
            }

            $body = substr($chunk, $open + 1, $cursor - $open - 2);

            if (str_starts_with($prelude, '@media') || str_starts_with($prelude, '@supports')) {
                $walk($body, $prelude);
            } elseif (! str_starts_with($prelude, '@')) {
                $rules[] = ['selector' => $prelude, 'body' => trim($body), 'at' => $at];
            }

            $offset = $cursor;
        }
    };

    $walk($css, null);

    return $rules;
}

/** Split a selector list on top-level commas (commas inside :where(...) stay put). */
function fbSplitSelectors(string $list): array
{
    $parts = [];
    $depth = 0;
    $buffer = '';

    foreach (str_split($list) as $char) {
        $depth += match ($char) { '(' => 1, ')' => -1, default => 0 };

        if ($char === ',' && $depth === 0) {
            $parts[] = trim($buffer);
            $buffer = '';

            continue;
        }

        $buffer .= $char;
    }

    $parts[] = trim($buffer);

    return $parts;
}

/** @return array<string, string> private custom property => value */
function fbDeclarations(string $body): array
{
    preg_match_all('/(--_fb-[a-z0-9-]+)\s*:\s*([^;]+);/', $body, $m);

    return array_combine($m[1], array_map(fn ($v) => preg_replace('/\s+/', ' ', trim($v)), $m[2]));
}

function fbStylesheet(): string
{
    return (string) file_get_contents(Ui::stylesheetPath());
}

it('scopes every selector to a package class', function () {
    $unscoped = [];

    foreach (fbCssRules(fbStylesheet()) as $rule) {
        foreach (fbSplitSelectors($rule['selector']) as $selector) {
            $selector = (string) preg_replace('/\s+/', ' ', $selector);

            if (! preg_match('/^(\.dark |\.fi |\.fi\.dark )?\.fb-/', $selector)) {
                $unscoped[] = $selector;
            }
        }
    }

    expect($unscoped)->toBe([]);
});

it('is self-contained and override-friendly', function () {
    $css = (string) preg_replace('#/\*.*?\*/#s', '', fbStylesheet());

    expect($css)->not->toContain('@import')
        ->and($css)->not->toContain('url(')
        ->and($css)->not->toContain('!important')
        ->and($css)->not->toContain('@layer')
        ->and($css)->not->toContain(':has(')
        ->and($css)->not->toContain('color-mix(');
});

it('declares every private --_fb- token it reads', function () {
    $css = fbStylesheet();
    preg_match_all('/var\(\s*(--_fb-[a-z0-9-]+)/', $css, $used);
    preg_match_all('/(--_fb-[a-z0-9-]+)\s*:/', $css, $declared);

    expect(array_values(array_diff(array_unique($used[1]), $declared[1])))->toBe([]);
});

it('never declares a public --fb- token and reads each one only with a fallback', function () {
    $css = (string) preg_replace('#/\*.*?\*/#s', '', fbStylesheet());
    preg_match_all('/(?<![\w-])(--fb-[a-z0-9-]+)\s*:/', $css, $declared);
    preg_match_all('/var\(\s*(--fb-[a-z0-9-]+)\s*([,)])/', $css, $reads);

    expect($declared[1])->toBe([])
        ->and(array_values(array_unique($reads[1])))->toEqualCanonicalizing([
            '--fb-accent', '--fb-accent-hover', '--fb-accent-contrast', '--fb-radius', '--fb-font', '--fb-z',
            // Internal bridge tokens printed by the Filament plugin's render hook; not public API.
            '--fb-panel-accent', '--fb-panel-accent-hover', '--fb-panel-accent-contrast',
        ])
        ->and(array_unique($reads[2]))->toBe([',']);
});

it('reads the panel bridge tokens only inside a Filament panel and beneath the public tokens', function () {
    foreach (fbCssRules(fbStylesheet()) as $rule) {
        if (! str_contains($rule['body'], '--fb-panel-')) {
            continue;
        }

        foreach (fbSplitSelectors($rule['selector']) as $selector) {
            expect($selector)->toMatch('/^\.fi[ .]/');
        }

        preg_match_all('/var\(\s*--fb-panel-/', $rule['body'], $bridge);
        preg_match_all('/var\(\s*--fb-accent[a-z-]*\s*,\s*var\(\s*--fb-panel-/', $rule['body'], $nested);
        expect(count($nested[0]))->toBe(count($bridge[0]));
    }
});

it('follows the panel accent in both themes and out-ranks the generic dark tokens', function () {
    $rules = collect(fbCssRules(fbStylesheet()));
    $tokens = ['--_fb-accent', '--_fb-accent-hover', '--_fb-accent-contrast', '--_fb-link', '--_fb-ring'];

    $light = $rules->first(fn ($r) => $r['at'] === null && $r['selector'] === '.fi .fb-scope');
    $dark = $rules->first(fn ($r) => $r['at'] === null && str_contains($r['selector'], '.fi.dark .fb-scope[data-fb-theme="class"]'));
    $system = $rules->first(fn ($r) => str_contains((string) $r['at'], 'prefers-color-scheme: dark')
        && $r['selector'] === '.fi .fb-scope[data-fb-theme="system"]');

    foreach ([$light, $dark, $system] as $rule) {
        expect($rule)->not->toBeNull();

        foreach ($tokens as $token) {
            expect(fbDeclarations($rule['body'])[$token] ?? '')->toContain('var(--fb-panel-');
        }
    }

    expect(fbSplitSelectors($dark['selector']))->toBe(['.fi.dark .fb-scope[data-fb-theme="class"]', '.fi .fb-scope[data-fb-theme="dark"]'])
        ->and(fbDeclarations($dark['body']))->toBe(fbDeclarations($system['body']));
});

it('uses no class name that collides with the Facebook SDK', function () {
    preg_match_all('/\.(fb-[a-z0-9_-]*[a-z0-9])/', fbStylesheet(), $m);

    expect(array_intersect(array_unique($m[1]), ['fb-page', 'fb-post', 'fb-comments', 'fb-like', 'fb-video', 'fb-group', 'fb-quote', 'fb-save', 'fb-send', 'fb-follow']))->toBe([]);
});

it('declares the same dark tokens for the class/forced and the system selectors', function () {
    $rules = collect(fbCssRules(fbStylesheet()));

    $outside = $rules->first(fn ($r) => $r['at'] === null && str_contains($r['selector'], '.fb-scope[data-fb-theme="dark"]'));
    $inside = $rules->first(fn ($r) => str_contains((string) $r['at'], 'prefers-color-scheme: dark')
        && str_contains($r['selector'], '.fb-scope[data-fb-theme="system"]'));

    expect($outside)->not->toBeNull()
        ->and($inside)->not->toBeNull()
        ->and(fbSplitSelectors($outside['selector']))->toBe(['.dark .fb-scope[data-fb-theme="class"]', '.fb-scope[data-fb-theme="dark"]'])
        ->and(fbSplitSelectors($inside['selector']))->toBe(['.fb-scope[data-fb-theme="system"]'])
        ->and(fbDeclarations($inside['body']))->toBe(fbDeclarations($outside['body']))
        ->and($outside['body'])->toContain('color-scheme: dark')
        ->and($inside['body'])->toContain('color-scheme: dark');
});

it('keeps the standalone pages light under the class theme', function () {
    expect(fbStylesheet())->not->toContain('.fb-standalone[data-fb-theme="class"]')
        ->and(fbStylesheet())->not->toContain('.fb-standalone [data-fb-theme="class"]');
});

it('reserves ::before for accent tints', function () {
    foreach (fbCssRules(fbStylesheet()) as $rule) {
        $before = array_filter(
            fbSplitSelectors($rule['selector']),
            fn (string $selector) => str_contains($selector, '::before') && $selector !== '.fb-scope *::before',
        );

        if ($before !== []) {
            expect($rule['body'])->toContain('opacity: var(--_fb-tint-opacity)');
        }
    }
});

it('only overrides tokens in dark mode that the light theme declares', function () {
    $rules = collect(fbCssRules(fbStylesheet()));
    $light = $rules->first(fn ($r) => $r['at'] === null && $r['selector'] === '.fb-scope');
    $dark = $rules->first(fn ($r) => $r['at'] === null && str_contains($r['selector'], '.fb-scope[data-fb-theme="dark"]'));

    expect(array_diff(array_keys(fbDeclarations($dark['body'])), array_keys(fbDeclarations($light['body']))))->toBe([]);
});

it('paints its own background on host-facing surfaces under a forced or system theme', function () {
    $selectors = collect(fbCssRules(fbStylesheet()))
        ->filter(fn ($r) => str_contains($r['body'], 'background: var(--_fb-bg)'))
        ->flatMap(fn ($r) => fbSplitSelectors($r['selector']))
        ->all();

    foreach (['fb-reports', 'fb-kanban', 'fb-runner-result', 'fb-triage-suggestion'] as $root) {
        expect($selectors)->toContain('.'.$root.':not([data-fb-theme="class"])');
    }
});
