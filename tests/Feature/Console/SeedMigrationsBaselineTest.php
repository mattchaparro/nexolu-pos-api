<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SeedMigrationsBaselineTest extends TestCase
{
    use DatabaseTransactions;

    private const BASELINE_MIGRATION = '0000_00_00_000000_baseline_legacy_schema';

    public function test_it_seeds_the_baseline_when_missing(): void
    {
        DB::table('migrations')->where('migration', self::BASELINE_MIGRATION)->delete();

        $this->artisan('migrate:baseline')
            ->expectsOutputToContain('Baseline marcado como migrado')
            ->assertSuccessful();

        $this->assertDatabaseHas('migrations', ['migration' => self::BASELINE_MIGRATION, 'batch' => 0]);
    }

    public function test_it_is_idempotent_on_a_second_run(): void
    {
        DB::table('migrations')->where('migration', self::BASELINE_MIGRATION)->delete();
        DB::table('migrations')->insert(['migration' => self::BASELINE_MIGRATION, 'batch' => 0]);

        $this->artisan('migrate:baseline')
            ->expectsOutputToContain('ya estaba marcado')
            ->assertSuccessful();

        $this->assertSame(
            1,
            DB::table('migrations')->where('migration', self::BASELINE_MIGRATION)->count(),
        );
    }

    public function test_dry_run_reports_without_inserting(): void
    {
        DB::table('migrations')->where('migration', self::BASELINE_MIGRATION)->delete();

        $this->artisan('migrate:baseline', ['--dry-run' => true])
            ->expectsOutputToContain('[dry-run] Falta marcar el baseline')
            ->assertSuccessful();

        $this->assertDatabaseMissing('migrations', ['migration' => self::BASELINE_MIGRATION]);
    }
}
