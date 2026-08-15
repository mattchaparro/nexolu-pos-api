<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ApplySchemaPatchesTest extends TestCase
{
    use DatabaseTransactions;

    private string $patchesDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->patchesDir = storage_path('framework/testing/schema-patches-'.uniqid());
        mkdir($this->patchesDir, 0777, true);
        config(['database.schema_patches_path' => $this->patchesDir]);
    }

    protected function tearDown(): void
    {
        // CREATE TABLE hace commit implicito en MySQL - DatabaseTransactions
        // no revierte DDL, hay que limpiar las tablas de prueba a mano.
        Schema::dropIfExists('schema_patch_test_dummy_a');
        Schema::dropIfExists('schema_patch_test_dummy_b');
        DB::table('schema_patches')->where('filename', 'like', '%schema_patch_test%')->delete();

        foreach (glob($this->patchesDir.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->patchesDir);

        parent::tearDown();
    }

    public function test_it_applies_pending_patches_and_records_them(): void
    {
        file_put_contents($this->patchesDir.'/2020_01_01_000001_schema_patch_test_a.sql', <<<'SQL'
            -- comentario con punto y coma (2020-01-01); no debe romper el split
            CREATE TABLE IF NOT EXISTS `schema_patch_test_dummy_a` (
              `id` bigint unsigned NOT NULL AUTO_INCREMENT,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);

        $this->artisan('schema:apply-patches')
            ->expectsOutputToContain('Aplicado: 2020_01_01_000001_schema_patch_test_a.sql')
            ->assertSuccessful();

        $this->assertTrue(Schema::hasTable('schema_patch_test_dummy_a'));
        $this->assertDatabaseHas('schema_patches', ['filename' => '2020_01_01_000001_schema_patch_test_a.sql']);
    }

    public function test_it_skips_already_applied_patches_on_a_second_run(): void
    {
        file_put_contents(
            $this->patchesDir.'/2020_01_01_000001_schema_patch_test_a.sql',
            'CREATE TABLE IF NOT EXISTS `schema_patch_test_dummy_a` (`id` bigint unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY (`id`)) ENGINE=InnoDB;',
        );

        $this->artisan('schema:apply-patches')->assertSuccessful();
        $this->artisan('schema:apply-patches')
            ->expectsOutput('No hay patches pendientes.')
            ->assertSuccessful();

        $this->assertSame(
            1,
            DB::table('schema_patches')->where('filename', '2020_01_01_000001_schema_patch_test_a.sql')->count(),
        );
    }

    public function test_dry_run_lists_pending_patches_without_applying_them(): void
    {
        file_put_contents(
            $this->patchesDir.'/2020_01_01_000001_schema_patch_test_b.sql',
            'CREATE TABLE IF NOT EXISTS `schema_patch_test_dummy_b` (`id` bigint unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY (`id`)) ENGINE=InnoDB;',
        );

        $this->artisan('schema:apply-patches', ['--dry-run' => true])
            ->expectsOutputToContain('[dry-run] Pendiente: 2020_01_01_000001_schema_patch_test_b.sql')
            ->assertSuccessful();

        $this->assertFalse(Schema::hasTable('schema_patch_test_dummy_b'));
        $this->assertDatabaseMissing('schema_patches', ['filename' => '2020_01_01_000001_schema_patch_test_b.sql']);
    }
}
