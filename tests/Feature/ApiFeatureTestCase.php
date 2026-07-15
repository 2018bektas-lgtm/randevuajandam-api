<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests that need the shared site schema.
 */
abstract class ApiFeatureTestCase extends TestCase
{
    use RefreshDatabase;

    protected function migrateDatabases()
    {
        $sitePath = realpath(__DIR__.'/../../../site/database/migrations');

        $this->artisan('migrate:fresh', [
            '--path' => $sitePath,
            '--realpath' => true,
            '--seed' => false,
        ]);
    }
}
