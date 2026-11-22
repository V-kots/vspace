<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SpaRoutesTest extends TestCase
{
    public function test_spa_pages_are_available_on_direct_navigation(): void
    {
        $paths = [
            '/cabinet',
            '/search',
            '/friends',
            '/posts',
            '/profile/00000000-0000-0000-0000-000000000000',
        ];

        foreach ($paths as $path) {
            $route = Route::getRoutes()->match(Request::create($path));

            $this->assertSame('spa', $route->defaults['view'] ?? null, $path);
        }
    }
}
