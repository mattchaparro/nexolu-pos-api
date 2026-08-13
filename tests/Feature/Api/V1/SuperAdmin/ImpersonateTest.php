<?php

namespace Tests\Feature\Api\V1\SuperAdmin;

use App\Models\Business;
use App\Models\LogAction;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\ActsAsSuperAdmin;
use Tests\TestCase;

class ImpersonateTest extends TestCase
{
    use ActsAsSuperAdmin, DatabaseTransactions;

    public function test_superadmin_can_impersonate_a_business_user_and_use_the_token(): void
    {
        $admin = $this->superadmin();
        $business = Business::factory()->create();
        $target = User::factory()->create(['business_id' => $business->id]);
        $target->assignRole('admin');

        $response = $this->actingAs($admin, 'sanctum')->postJson("/api/v1/superadmin/impersonate/{$target->id}");

        $response->assertOk()->assertJsonPath('user.id', $target->id);
        $this->assertDatabaseHas('log_actions', ['action' => 'superadmin.impersonation.started']);

        // actingAs() deja el usuario fijado en el guard 'sanctum' para el
        // resto del test - sin limpiarlo, la siguiente peticion (con el
        // token REAL del usuario impersonado) seguiria resolviendo al admin
        // en vez de leer el Bearer token, dando un falso negativo.
        $this->app['auth']->forgetGuards();

        $token = $response->json('token');
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('id', $target->id);
    }

    public function test_cannot_impersonate_another_superadmin(): void
    {
        $admin = $this->superadmin();
        $otherSuperadmin = $this->superadmin();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/superadmin/impersonate/{$otherSuperadmin->id}")
            ->assertStatus(422);
    }

    /**
     * El front sale de "ver como" haciendo POST /logout con el token de
     * impersonacion (ver AuthStore::stopImpersonating) - eso ya revoca el
     * token server-side; lo que este test cubre es que ademas quede
     * auditado el cierre, no solo el inicio.
     */
    public function test_ending_an_impersonation_session_is_audited(): void
    {
        $admin = $this->superadmin();
        $business = Business::factory()->create();
        $target = User::factory()->create(['business_id' => $business->id]);
        $target->assignRole('admin');

        $response = $this->actingAs($admin, 'sanctum')->postJson("/api/v1/superadmin/impersonate/{$target->id}");
        $token = $response->json('token');

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/logout')
            ->assertNoContent();

        $this->assertDatabaseHas('log_actions', [
            'action' => 'superadmin.impersonation.ended',
            'business_id' => $business->id,
        ]);

        $ended = LogAction::where('action', 'superadmin.impersonation.ended')->latest('id')->first();
        $this->assertSame($target->id, $ended->details['impersonated_user_id']);
        $this->assertSame($admin->id, $ended->details['superadmin_id']);

        // El token de impersonacion ya no sirve - queda revocado.
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me')
            ->assertUnauthorized();
    }

    public function test_a_normal_logout_is_not_audited_as_impersonation(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('phpunit')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/logout')
            ->assertNoContent();

        $this->assertDatabaseMissing('log_actions', ['action' => 'superadmin.impersonation.ended']);
    }
}
