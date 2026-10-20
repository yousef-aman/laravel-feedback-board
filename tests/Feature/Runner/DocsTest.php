<?php

it('ships the runner contract docs and reference recipe', function () {
    $root = __DIR__.'/../../..';
    expect(file_exists($root.'/docs/runner-contract.md'))->toBeTrue()
        ->and(file_exists($root.'/docs/examples/runner/receiver.example.php'))->toBeTrue()
        ->and(file_exists($root.'/docs/examples/runner/github-actions-feedback-runner.example.yml'))->toBeTrue();

    $contract = file_get_contents($root.'/docs/runner-contract.md');
    expect($contract)->toContain('runner_status')
        ->and($contract)->toContain('runner_result')
        ->and($contract)->toContain('X-Feedback-Signature')
        ->and($contract)->toContain('task_id');

    expect(file_get_contents($root.'/README.md'))->toContain('AI Runner Contract');
});

it('ships the reference post-back script', function () {
    expect(file_exists(dirname(__DIR__, 3).'/docs/examples/runner/post-back.sh'))->toBeTrue();
});

it('pins the post-back script in the reference workflow by its SHA-256', function () {
    $root = dirname(__DIR__, 3);
    $workflow = file_get_contents($root.'/docs/examples/runner/github-actions-feedback-runner.example.yml');

    expect($workflow)->toContain('POST_BACK_SHA256: '.hash_file('sha256', $root.'/docs/examples/runner/post-back.sh'));
});

it("uses GitHub's own actions in versions that run on Node.js 24", function (string $file) {
    // The first major of each that runs on Node.js 24 by default; older ones are deprecated on Node.js 20.
    $node24 = ['checkout' => 5, 'upload-artifact' => 6, 'download-artifact' => 7];

    preg_match_all('#uses: actions/([a-z-]+)@v(\d+)#', file_get_contents(dirname(__DIR__, 3).'/'.$file), $uses, PREG_SET_ORDER);

    expect($uses)->not->toBeEmpty();

    foreach ($uses as [$use, $action, $major]) {
        expect((int) $major)->toBeGreaterThanOrEqual($node24[$action] ?? PHP_INT_MAX, $use);
    }
})->with([
    'the reference workflow' => 'docs/examples/runner/github-actions-feedback-runner.example.yml',
    "the package's CI" => '.github/workflows/tests.yml',
]);
