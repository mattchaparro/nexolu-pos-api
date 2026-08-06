<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Reminder;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ReminderTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::sync();
    }

    private function admin(): User
    {
        $business = Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_admin_can_create_a_reminder(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/reminders', [
            'title' => 'Pagar arriendo',
            'due_date' => now()->addDay()->toDateString(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('title', 'Pagar arriendo')
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('recurrence', 'none')
            ->assertJsonPath('is_recurring', false);

        $this->assertDatabaseHas('reminders', [
            'business_id' => $admin->business_id,
            'created_by_user_id' => $admin->id,
            'title' => 'Pagar arriendo',
        ]);
    }

    public function test_notify_whatsapp_is_forced_false_without_a_notify_time(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/reminders', [
            'title' => 'Sin hora',
            'due_date' => now()->addDay()->toDateString(),
            'notify_whatsapp' => true,
        ]);

        $response->assertCreated()->assertJsonPath('notify_whatsapp', false);
    }

    public function test_store_validates_required_fields(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/reminders', [])
            ->assertStatus(422);
    }

    public function test_index_lists_pending_ordered_by_due_date_and_recent_completed(): void
    {
        $admin = $this->admin();

        $later = Reminder::factory()->for($admin->business, 'business')->create([
            'created_by_user_id' => $admin->id,
            'due_date' => now()->addDays(5),
        ]);
        $sooner = Reminder::factory()->for($admin->business, 'business')->create([
            'created_by_user_id' => $admin->id,
            'due_date' => now()->addDay(),
        ]);
        $done = Reminder::factory()->done()->for($admin->business, 'business')->create([
            'created_by_user_id' => $admin->id,
        ]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/reminders');

        $response->assertOk();
        $pendingIds = array_column($response->json('pending'), 'id');
        $this->assertSame([$sooner->id, $later->id], $pendingIds);
        $completedIds = array_column($response->json('completed'), 'id');
        $this->assertContains($done->id, $completedIds);
    }

    public function test_destroy_removes_the_reminder(): void
    {
        $admin = $this->admin();
        $reminder = Reminder::factory()->for($admin->business, 'business')->create(['created_by_user_id' => $admin->id]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/reminders/{$reminder->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('reminders', ['id' => $reminder->id]);
    }

    public function test_completing_a_single_reminder_closes_it(): void
    {
        $admin = $this->admin();
        $reminder = Reminder::factory()->for($admin->business, 'business')->create(['created_by_user_id' => $admin->id]);

        $response = $this->actingAs($admin, 'sanctum')->postJson("/api/v1/reminders/{$reminder->id}/complete");

        $response->assertOk()->assertJsonPath('status', 'done');
        $this->assertNotNull($reminder->fresh()->completed_at);
    }

    public function test_completing_a_monthly_reminder_advances_the_due_date_and_stays_a_single_row(): void
    {
        $admin = $this->admin();
        $reminder = Reminder::factory()->recurring('monthly')->for($admin->business, 'business')->create([
            'created_by_user_id' => $admin->id,
            'due_date' => '2026-01-31',
            'series_anchor_date' => '2026-01-31',
        ]);

        $response = $this->actingAs($admin, 'sanctum')->postJson("/api/v1/reminders/{$reminder->id}/complete");

        $response->assertOk()->assertJsonPath('status', 'pending');
        $fresh = $reminder->fresh();
        $this->assertSame('2026-02-28', $fresh->due_date->toDateString());
        $this->assertNotNull($fresh->last_completed_at);
        $this->assertSame(1, Reminder::withoutGlobalScopes()->where('id', $reminder->id)->count());
    }

    public function test_completing_a_weekly_reminder_advances_seven_days(): void
    {
        $admin = $this->admin();
        $reminder = Reminder::factory()->recurring('weekly')->for($admin->business, 'business')->create([
            'created_by_user_id' => $admin->id,
            'due_date' => '2026-07-21',
            'series_anchor_date' => '2026-07-21',
        ]);

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/reminders/{$reminder->id}/complete")->assertOk();

        $this->assertSame('2026-07-28', $reminder->fresh()->due_date->toDateString());
    }

    public function test_completing_a_recurring_reminder_closes_the_series_once_past_end_date(): void
    {
        $admin = $this->admin();
        $reminder = Reminder::factory()->recurring('weekly')->for($admin->business, 'business')->create([
            'created_by_user_id' => $admin->id,
            'due_date' => '2026-07-20',
            'series_anchor_date' => '2026-07-20',
            'end_date' => '2026-07-25',
        ]);

        $response = $this->actingAs($admin, 'sanctum')->postJson("/api/v1/reminders/{$reminder->id}/complete");

        $response->assertOk()->assertJsonPath('status', 'done');
    }

    public function test_completing_a_recurring_reminder_continues_while_within_end_date(): void
    {
        $admin = $this->admin();
        $reminder = Reminder::factory()->recurring('weekly')->for($admin->business, 'business')->create([
            'created_by_user_id' => $admin->id,
            'due_date' => '2026-07-20',
            'series_anchor_date' => '2026-07-20',
            'end_date' => '2026-08-30',
        ]);

        $response = $this->actingAs($admin, 'sanctum')->postJson("/api/v1/reminders/{$reminder->id}/complete");

        $response->assertOk()->assertJsonPath('status', 'pending');
    }

    public function test_postpone_moves_the_due_date_without_touching_recurrence(): void
    {
        $admin = $this->admin();
        $reminder = Reminder::factory()->recurring('weekly')->for($admin->business, 'business')->create([
            'created_by_user_id' => $admin->id,
            'due_date' => '2026-07-21',
        ]);

        $response = $this->actingAs($admin, 'sanctum')->postJson("/api/v1/reminders/{$reminder->id}/postpone", [
            'due_date' => '2026-07-22',
        ]);

        $response->assertOk()->assertJsonPath('due_date', '2026-07-22');
        $this->assertSame('weekly', $reminder->fresh()->recurrence);
        $this->assertSame('pending', $reminder->fresh()->status);
    }

    public function test_postpone_fails_on_an_already_completed_reminder(): void
    {
        $admin = $this->admin();
        $reminder = Reminder::factory()->done()->for($admin->business, 'business')->create(['created_by_user_id' => $admin->id]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/reminders/{$reminder->id}/postpone", ['due_date' => now()->addDay()->toDateString()])
            ->assertStatus(422);
    }

    public function test_postponing_does_not_run_the_cadence_of_the_following_occurrences(): void
    {
        $admin = $this->admin();
        // Nace martes 2026-07-21.
        $reminder = Reminder::factory()->recurring('weekly')->for($admin->business, 'business')->create([
            'created_by_user_id' => $admin->id,
            'due_date' => '2026-07-21',
            'series_anchor_date' => '2026-07-21',
        ]);

        // Se pospone la ocurrencia de esta semana a miercoles.
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/reminders/{$reminder->id}/postpone", ['due_date' => '2026-07-22'])
            ->assertOk();

        // Al completar, el siguiente ciclo tiene que caer martes 2026-07-28
        // (no miercoles 29): el ancla no se movio con postpone().
        $response = $this->actingAs($admin, 'sanctum')->postJson("/api/v1/reminders/{$reminder->id}/complete");

        $response->assertOk()->assertJsonPath('due_date', '2026-07-28');
    }

    public function test_an_employee_without_the_permission_is_rejected(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->create(['business_id' => $business->id]);
        $employee->assignRole('employee');

        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/v1/reminders', ['title' => 'x', 'due_date' => now()->addDay()->toDateString()])
            ->assertStatus(403);
    }

    public function test_a_business_without_the_reminders_feature_is_rejected(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['reminders' => false]]);
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/reminders', ['title' => 'x', 'due_date' => now()->addDay()->toDateString()])
            ->assertStatus(403);
    }
}
