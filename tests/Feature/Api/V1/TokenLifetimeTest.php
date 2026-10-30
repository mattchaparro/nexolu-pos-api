<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * El token vence por inactividad, no a las 4 h del login (ver
 * App\Support\Auth\TokenLifetime): a Central Cell se le cerraba la sesion en
 * medio de una venta.
 */
class TokenLifetimeTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{0: string, 1: PersonalAccessToken} */
    private function tokenFor(User $user): array
    {
        $plain = $user->createToken('tablet')->plainTextToken;

        return [$plain, PersonalAccessToken::findToken($plain)];
    }

    private function me(string $plain): int
    {
        // Sin actingAs: el guard tiene que validar el token de verdad.
        $this->app['auth']->forgetGuards();

        return $this->withToken($plain)->getJson('/api/v1/me')->status();
    }

    public function test_a_token_in_use_survives_well_past_four_hours(): void
    {
        $user = User::factory()->create(['business_id' => Business::factory()->create()->id]);
        [$plain, $token] = $this->tokenFor($user);
        $token->forceFill(['created_at' => now()->subHours(9), 'last_used_at' => now()->subMinutes(5)])->save();

        $this->assertSame(200, $this->me($plain));
    }

    public function test_a_token_idle_for_more_than_a_day_expires(): void
    {
        $user = User::factory()->create(['business_id' => Business::factory()->create()->id]);
        [$plain, $token] = $this->tokenFor($user);
        $token->forceFill(['created_at' => now()->subDays(3), 'last_used_at' => now()->subHours(25)])->save();

        $this->assertSame(401, $this->me($plain));
    }

    public function test_a_new_token_never_used_counts_from_its_creation(): void
    {
        $user = User::factory()->create(['business_id' => Business::factory()->create()->id]);
        [$plain, $token] = $this->tokenFor($user);
        $token->forceFill(['created_at' => now()->subHours(25), 'last_used_at' => null])->save();

        $this->assertSame(401, $this->me($plain));
    }

    public function test_every_token_expires_after_the_maximum_lifetime_even_if_used(): void
    {
        $user = User::factory()->create(['business_id' => Business::factory()->create()->id]);
        [$plain, $token] = $this->tokenFor($user);
        $token->forceFill(['created_at' => now()->subDays(31), 'last_used_at' => now()->subMinute()])->save();

        $this->assertSame(401, $this->me($plain));
    }

    public function test_using_the_token_keeps_it_alive(): void
    {
        $user = User::factory()->create(['business_id' => Business::factory()->create()->id]);
        [$plain, $token] = $this->tokenFor($user);
        $token->forceFill(['created_at' => now()->subHours(20), 'last_used_at' => now()->subHours(20)])->save();

        $this->assertSame(200, $this->me($plain));
        $this->assertTrue($token->fresh()->last_used_at->gt(now()->subMinute()));
    }
}
