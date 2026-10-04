<?php

namespace Tests\Feature\Filament;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolesListTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_roles_list_renders_when_a_role_has_permissions(): void
    {
        $this->withoutExceptionHandling();
        $this->actingAs(User::factory()->create(['role_id' => null]));

        Role::create(['name' => 'Editor', 'slug' => 'editor', 'permissions' => ['products.manage', 'orders.manage', 'pages.manage']]);
        Role::create(['name' => 'Viewer', 'slug' => 'viewer', 'permissions' => []]);

        $this->get('/admin/roles')
            ->assertOk()
            ->assertSee('3 granted')
            ->assertSee('None');
    }
}
