<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature/StockNest');
pest()->extend(TestCase::class)->in('Concurrency');

// PHPUnit defaults to the historical workflow; office suites enable the new workflow explicitly.
