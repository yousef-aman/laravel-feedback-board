<?php

uses(YousefAman\FeedbackBoard\Tests\TestCase::class)->in('Feature', 'Unit', 'Smoke');

// Filament smoke tests boot a real panel; see tests/Filament/FilamentTestCase.php.
uses(YousefAman\FeedbackBoard\Tests\Filament\FilamentTestCase::class)->in('Filament');
// Two panels on two guards; see tests/Guards/GuardsTestCase.php.
uses(YousefAman\FeedbackBoard\Tests\Guards\GuardsTestCase::class)->in('Guards');
// One panel with Filament tenancy; see tests/Tenancy/TenancyTestCase.php.
uses(YousefAman\FeedbackBoard\Tests\Tenancy\TenancyTestCase::class)->in('Tenancy');

// FeedbackDetailTest asserts that Livewire::test() throws an HttpException when a
// non-developer opens the detail panel (mount calls abort_unless). Livewire v3's test
// framework normally converts abort() to a 403 response (not a throw), so we apply
// withoutExceptionHandling() only for this file to make exceptions propagate as expected.
// KanbanTest must NOT have this active because it relies on ->assertForbidden().
// We use an empty uses() to add a beforeEach hook without conflicting with the TestCase
// already set for Feature/ above.
uses()
    ->beforeEach(fn () => $this->withoutExceptionHandling())
    ->in('Feature/FeedbackDetailTest.php');
