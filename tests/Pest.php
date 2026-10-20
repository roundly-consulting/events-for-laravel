<?php

declare(strict_types=1);

use RoundlyConsulting\Events\Testing\EventExpectations;
use RoundlyConsulting\Events\Tests\Fixtures\ApiTestCase;
use RoundlyConsulting\Events\Tests\Fixtures\NewYorkTestCase;
use RoundlyConsulting\Events\Tests\Fixtures\NoAuthApiTestCase;
use RoundlyConsulting\Events\Tests\Fixtures\SwappedModelsTestCase;
use RoundlyConsulting\Events\Tests\Fixtures\TokenGuardApiTestCase;
use RoundlyConsulting\Events\Tests\Fixtures\UlidKeyTestCase;
use RoundlyConsulting\Events\Tests\Fixtures\UuidKeyTestCase;
use RoundlyConsulting\Events\Tests\Install\InstallTestCase;
use RoundlyConsulting\Events\Tests\TestCase;

// The package's own matchers, as a host registers them.
EventExpectations::register();

// Explicit directories, never a blanket `->in(__DIR__)`: Pest binds one test case per directory.
uses(TestCase::class)->in('Arch', 'Feature', 'Unit', 'RealEngine');

// The JSON API, switched on before boot.
uses(ApiTestCase::class)->in('Http');

// The JSON API with an empty auth_middleware: a sibling of Http, since Pest binds one test case per file.
uses(NoAuthApiTestCase::class)->in('NoAuth');

// The JSON API behind a token guard that is not the app's default.
uses(TokenGuardApiTestCase::class)->in('TokenGuard');

// events:install, publishing into a sandbox without the package's tables.
uses(InstallTestCase::class)->in('Install');

// The model-swap proofs need events.models.* pointed at host subclasses before boot.
uses(SwappedModelsTestCase::class)->in('ModelSwap');

// A host on America/New_York: every stored instant must still be UTC.
uses(NewYorkTestCase::class)->in('Time');

// Key-type legs: the migrations read the key types, so each leg sets them before boot.
uses(UuidKeyTestCase::class)->in('KeyTypes/UuidKeyTest.php');
uses(UlidKeyTestCase::class)->in('KeyTypes/UlidKeyTest.php');
