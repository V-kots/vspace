<?php

namespace Tests\Feature;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TokenCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sanctum.token_cache.enabled' => true]);
        Cache::flush();
    }

    public function test_token_lookup_is_cached(): void
    {
        $user = User::factory()->create();
        $plain = $user->createToken('test')->plainTextToken;

        $token = PersonalAccessToken::findToken($plain);

        $this->assertNotNull($token);
        $this->assertSame($user->id, $token->tokenable_id);
        $this->assertTrue(Cache::has($this->cacheKey($plain)));
        $this->assertIsArray(Cache::get($this->cacheKey($plain)));
    }

    public function test_deleted_token_is_dropped_from_cache(): void
    {
        $user = User::factory()->create();
        $newToken = $user->createToken('test');
        $plain = $newToken->plainTextToken;

        $this->assertNotNull(PersonalAccessToken::findToken($plain));

        $newToken->accessToken->delete();

        $this->assertNull(PersonalAccessToken::findToken($plain));
    }

    public function test_authenticated_request_works_with_cached_token(): void
    {
        $user = User::factory()->create();
        $plain = $user->createToken('test')->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$plain];

        $this->withHeaders($headers)->getJson('/friend/list')->assertOk();
        $this->assertTrue(Cache::has($this->cacheKey($plain)));

        $this->withHeaders($headers)->getJson('/friend/list')->assertOk();
    }

    public function test_cache_is_not_used_when_disabled(): void
    {
        config(['sanctum.token_cache.enabled' => false]);

        $user = User::factory()->create();
        $plain = $user->createToken('test')->plainTextToken;

        $this->assertNotNull(PersonalAccessToken::findToken($plain));
        $this->assertFalse(Cache::has($this->cacheKey($plain)));
    }

    private function cacheKey(string $plainToken): string
    {
        [, $part] = explode('|', $plainToken, 2);

        return 'auth:token:'.hash('sha256', $part);
    }
}
