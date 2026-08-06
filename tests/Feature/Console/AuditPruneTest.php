<?php

namespace Tests\Feature\Console;

use App\Models\LogAction;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AuditPruneTest extends TestCase
{
    use DatabaseTransactions;

    public function test_deletes_log_actions_older_than_the_retention_window(): void
    {
        $old = LogAction::create(['action' => 'x']);
        $old->forceFill(['created_at' => now()->subDays(46)])->save();

        $recent = LogAction::create(['action' => 'y']);
        $recent->forceFill(['created_at' => now()->subDays(10)])->save();

        $this->artisan('audit:prune')->assertSuccessful();

        $this->assertDatabaseMissing('log_actions', ['id' => $old->id]);
        $this->assertDatabaseHas('log_actions', ['id' => $recent->id]);
    }

    public function test_respects_the_days_option(): void
    {
        $withinCustomWindow = LogAction::create(['action' => 'x']);
        $withinCustomWindow->forceFill(['created_at' => now()->subDays(10)])->save();

        $this->artisan('audit:prune', ['--days' => 5])->assertSuccessful();

        $this->assertDatabaseMissing('log_actions', ['id' => $withinCustomWindow->id]);
    }
}
