<?php

declare(strict_types=1);

use RoundlyConsulting\Events\Tests\Fixtures\NewYorkTestCase;
use RoundlyConsulting\Events\Tests\Fixtures\UlidKeyTestCase;
use RoundlyConsulting\Events\Tests\Fixtures\UuidKeyTestCase;
use RoundlyConsulting\Events\Tests\TestCase;

// Explicit directories, never a blanket `->in(__DIR__)`: Pest binds one test case per directory.
uses(TestCase::class)->in('Arch', 'Feature', 'Unit', 'RealEngine');

// A host on America/New_York: every stored instant must still be UTC.
uses(NewYorkTestCase::class)->in('Time');

// Key-type legs: the migrations read the key types, so each leg sets them before boot.
uses(UuidKeyTestCase::class)->in('KeyTypes/UuidKeyTest.php');
uses(UlidKeyTestCase::class)->in('KeyTypes/UlidKeyTest.php');
