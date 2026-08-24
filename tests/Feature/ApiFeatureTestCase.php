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
        // Sibling: randevuajandam-api ↔ randevuajandam-site (aynı üst dizin altında)
        $sitePath = realpath(__DIR__.'/../../../randevuajandam-site/database/migrations');

        if ($sitePath === false) {
            throw new \RuntimeException(
                'randevuajandam-site/database/migrations bulunamadı. '
                .'randevuajandam-site projesinin randevuajandam-api ile aynı üst dizinde olduğundan emin olun.'
            );
        }

        $this->artisan('migrate:fresh', [
            '--path' => $sitePath,
            '--realpath' => true,
            '--seed' => false,
        ]);
    }
}
