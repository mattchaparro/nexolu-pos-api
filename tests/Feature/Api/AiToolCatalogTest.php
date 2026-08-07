<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Cubre GET /api/ai/tools/catalog: el catalogo de permisos/features reales
 * que el Nexolu IA Core cachea ~24h para no confiar en constantes quemadas
 * de su lado (ver App\Capabilities\Registry, unica fuente de verdad).
 */
class AiToolCatalogTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ia_core.api_key' => 'test-ia-core-key']);
    }

    public function test_rejects_request_without_a_valid_api_key(): void
    {
        $this->getJson('/api/ai/tools/catalog')->assertStatus(401);

        $this->withHeader('Authorization', 'Bearer wrong-key')
            ->getJson('/api/ai/tools/catalog')
            ->assertStatus(401);
    }

    public function test_returns_the_real_permission_and_feature_per_tool(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer test-ia-core-key')
            ->getJson('/api/ai/tools/catalog');

        $response->assertOk();
        $tools = $response->json('tools');

        $this->assertSame(['required_permission' => 'reports.sales', 'required_feature' => null], $tools['ventas_resumen']);
        $this->assertSame(['required_permission' => 'reports.sales', 'required_feature' => null], $tools['ventas_por_dia']);
        $this->assertSame(['required_permission' => 'cash_shift.manage', 'required_feature' => null], $tools['estado_caja']);
        $this->assertSame(['required_permission' => 'inventory.view', 'required_feature' => null], $tools['inventario']);
        $this->assertSame(['required_permission' => 'inventory.view', 'required_feature' => null], $tools['stock_producto']);
        $this->assertSame(['required_permission' => 'expenses.create', 'required_feature' => 'expenses'], $tools['crear_gasto']);
        $this->assertSame(['required_permission' => 'inventory.add', 'required_feature' => null], $tools['crear_producto']);
        $this->assertSame(['required_permission' => 'clients.manage', 'required_feature' => 'clients'], $tools['crear_cliente']);
    }
}
