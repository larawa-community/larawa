<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ApiKeyService;
use App\Support\WorkspaceIds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EnterpriseRbacTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_user_membership_defaults_to_requested_role(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create();

        $workspace->users()->attach($user);

        $this->assertSame('workspace_user', $workspace->users()->firstOrFail()->pivot->role);
        $this->assertTrue(Schema::hasColumn('users', 'disabled_at'));
        $this->assertTrue(Schema::hasColumn('workspaces', 'suspended_at'));
        $this->assertTrue(Schema::hasColumn('workspaces', 'deleted_at'));
    }

    public function test_site_admin_can_create_users_workspaces_and_assign_workspace_admins(): void
    {
        $workspace = Workspace::create(['name' => 'Platform', 'slug' => 'platform']);
        $siteAdmin = User::factory()->create();
        $workspace->users()->attach($siteAdmin, ['role' => 'site_admin']);

        $this->actingAs($siteAdmin)
            ->from(route('dashboard.workspaces.index'))
            ->post(route('dashboard.workspaces.store'), [
                'name' => 'Support',
            ])
            ->assertRedirect(route('dashboard.workspaces.index'));

        $managedWorkspace = Workspace::where('name', 'Support')->firstOrFail();
        $this->assertMatchesRegularExpression('/^support-\d{6}$/', $managedWorkspace->slug);

        $this->actingAs($siteAdmin)
            ->from(route('dashboard.users.index'))
            ->post(route('dashboard.users.store'), [
                'name' => 'Workspace Manager',
                'email' => 'manager@example.test',
                'password' => 'password123',
                'workspace_id' => $managedWorkspace->id,
                'role' => 'workspace_admin',
            ])
            ->assertRedirect(route('dashboard.users.index'));

        $manager = User::where('email', 'manager@example.test')->firstOrFail();

        $this->assertSame('workspace_admin', $manager->roleForWorkspace($managedWorkspace));

        $this->actingAs($siteAdmin)
            ->get(route('dashboard.workspaces.index'))
            ->assertOk()
            ->assertSee('Support')
            ->assertDontSee('manager@example.test');

        $this->actingAs($siteAdmin)
            ->get(route('dashboard.workspaces.show', $managedWorkspace))
            ->assertOk()
            ->assertSee('Support')
            ->assertSee('manager@example.test');
    }

    public function test_site_admin_quick_create_generates_credentials_and_can_create_site_admin(): void
    {
        $otherWorkspace = Workspace::create(['name' => 'Earlier Workspace', 'slug' => 'earlier-workspace']);
        $workspace = Workspace::create(['name' => 'Platform', 'slug' => 'platform']);
        $siteAdmin = User::factory()->create();
        $otherWorkspace->users()->attach($siteAdmin, ['role' => 'workspace_admin']);
        $workspace->users()->attach($siteAdmin, ['role' => 'site_admin']);

        $response = $this->actingAs($siteAdmin)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->from(route('dashboard.users.index'))
            ->post(route('dashboard.users.store'), [
                'name' => 'Second Admin',
                'email' => 'second-admin@example.test',
                'role' => 'site_admin',
            ]);

        $response
            ->assertRedirect(route('dashboard.users.index'))
            ->assertSessionHas('created_user_credentials');

        $created = User::where('email', 'second-admin@example.test')->firstOrFail();
        $this->assertTrue($created->isSiteAdmin());
        $this->assertDatabaseHas('workspace_users', [
            'workspace_id' => $workspace->id,
            'user_id' => $created->id,
            'role' => 'site_admin',
        ]);
        $this->assertDatabaseMissing('workspace_users', [
            'workspace_id' => $otherWorkspace->id,
            'user_id' => $created->id,
            'role' => 'site_admin',
        ]);

        $credentials = session('created_user_credentials');
        $this->assertSame(route('login'), $credentials['login_url']);
        $this->assertSame('second-admin@example.test', $credentials['email']);
        $this->assertNotEmpty($credentials['password']);

        $this->actingAs($siteAdmin)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->withSession(['created_user_credentials' => $credentials])
            ->get(route('dashboard.users.index'))
            ->assertOk()
            ->assertSee('Account Created')
            ->assertSee('second-admin@example.test')
            ->assertSee($credentials['password'])
            ->assertSee('email%28username%29', false);
    }

    public function test_site_admin_workspace_cannot_be_suspended_or_deleted(): void
    {
        $workspace = Workspace::create(['name' => 'Platform', 'slug' => 'platform']);
        $siteAdmin = User::factory()->create();
        $workspace->users()->attach($siteAdmin, ['role' => 'site_admin']);

        $this->actingAs($siteAdmin)
            ->from(route('dashboard.workspaces.show', $workspace))
            ->patch(route('dashboard.workspaces.suspension', $workspace))
            ->assertRedirect(route('dashboard.workspaces.show', $workspace))
            ->assertSessionHasErrors('workspace');

        $this->assertNull($workspace->fresh()->suspended_at);

        $this->actingAs($siteAdmin)
            ->from(route('dashboard.workspaces.show', $workspace))
            ->delete(route('dashboard.workspaces.destroy', $workspace))
            ->assertRedirect(route('dashboard.workspaces.show', $workspace))
            ->assertSessionHasErrors('workspace');

        $this->assertDatabaseHas('workspaces', [
            'id' => $workspace->id,
            'deleted_at' => null,
        ]);
    }

    public function test_suspended_site_admin_workspace_can_be_reactivated(): void
    {
        $workspace = Workspace::create(['name' => 'Platform', 'slug' => 'platform', 'suspended_at' => now()]);
        $siteAdmin = User::factory()->create();
        $workspace->users()->attach($siteAdmin, ['role' => 'site_admin']);

        $this->actingAs($siteAdmin)
            ->from(route('dashboard.workspaces.show', $workspace))
            ->patch(route('dashboard.workspaces.suspension', $workspace))
            ->assertRedirect(route('dashboard.workspaces.show', $workspace));

        $this->assertNull($workspace->fresh()->suspended_at);
    }

    public function test_site_admin_role_cannot_be_assigned_to_non_system_workspace(): void
    {
        $workspace = Workspace::create(['name' => 'Platform', 'slug' => 'platform']);
        $target = Workspace::create(['name' => 'Support', 'slug' => 'support']);
        $siteAdmin = User::factory()->create();
        $candidate = User::factory()->create();
        $workspace->users()->attach($siteAdmin, ['role' => 'site_admin']);

        $this->actingAs($siteAdmin)
            ->from(route('dashboard.users.show', $candidate))
            ->post(route('dashboard.users.workspaces.store', $candidate), [
                'workspace_id' => $target->id,
                'role' => 'site_admin',
            ])
            ->assertRedirect(route('dashboard.users.show', $candidate))
            ->assertSessionHasErrors('role');

        $this->assertFalse($candidate->fresh()->isSiteAdmin());
        $this->assertDatabaseMissing('workspace_users', [
            'workspace_id' => $target->id,
            'user_id' => $candidate->id,
            'role' => 'site_admin',
        ]);
    }

    public function test_site_admin_membership_cannot_be_removed_when_it_breaks_system_workspace_invariant(): void
    {
        $workspace = Workspace::create(['name' => 'Platform', 'slug' => 'platform']);
        $siteAdmin = User::factory()->create();
        $secondAdmin = User::factory()->create();
        $workspace->users()->attach($siteAdmin, ['role' => 'site_admin']);
        $workspace->users()->attach($secondAdmin, ['role' => 'site_admin']);

        $this->actingAs($siteAdmin)
            ->from(route('dashboard.users.show', $secondAdmin))
            ->delete(route('dashboard.users.workspaces.destroy', [$secondAdmin, $workspace]))
            ->assertRedirect(route('dashboard.users.show', $secondAdmin))
            ->assertSessionHasErrors('workspace');

        $this->assertDatabaseHas('workspace_users', [
            'workspace_id' => $workspace->id,
            'user_id' => $secondAdmin->id,
            'role' => 'site_admin',
        ]);
    }

    public function test_site_admin_user_delete_cannot_remove_last_site_admin_from_system_workspace(): void
    {
        $workspace = Workspace::create(['name' => 'Platform', 'slug' => 'platform']);
        $otherWorkspace = Workspace::create(['name' => 'Other Admin', 'slug' => 'other-admin']);
        $siteAdmin = User::factory()->create();
        $otherSiteAdmin = User::factory()->create();
        $workspace->users()->attach($siteAdmin, ['role' => 'site_admin']);
        $otherWorkspace->users()->attach($otherSiteAdmin, ['role' => 'site_admin']);

        $this->actingAs($siteAdmin)
            ->from(route('dashboard.users.show', $otherSiteAdmin))
            ->delete(route('dashboard.users.destroy', $otherSiteAdmin))
            ->assertRedirect(route('dashboard.users.show', $otherSiteAdmin))
            ->assertSessionHasErrors('user');

        $this->assertDatabaseHas('workspace_users', [
            'workspace_id' => $otherWorkspace->id,
            'user_id' => $otherSiteAdmin->id,
            'role' => 'site_admin',
        ]);
    }

    public function test_initial_user_cannot_be_disabled_or_deleted_by_another_site_admin(): void
    {
        $workspace = Workspace::create(['name' => 'Platform', 'slug' => 'platform']);
        $initialUser = User::factory()->create();
        $secondAdmin = User::factory()->create();
        $workspace->users()->attach($initialUser, ['role' => 'site_admin']);
        $workspace->users()->attach($secondAdmin, ['role' => 'site_admin']);

        $this->assertTrue($initialUser->isInitialUser());

        $this->actingAs($secondAdmin)
            ->from(route('dashboard.users.show', $initialUser))
            ->patch(route('dashboard.users.disabled', $initialUser))
            ->assertRedirect(route('dashboard.users.show', $initialUser))
            ->assertSessionHasErrors('user');

        $this->assertNull($initialUser->fresh()->disabled_at);

        $this->actingAs($secondAdmin)
            ->from(route('dashboard.users.show', $initialUser))
            ->delete(route('dashboard.users.destroy', $initialUser))
            ->assertRedirect(route('dashboard.users.show', $initialUser))
            ->assertSessionHasErrors('user');

        $this->assertDatabaseHas('users', [
            'id' => $initialUser->id,
            'email' => $initialUser->email,
        ]);
    }

    public function test_disabled_initial_user_can_be_reenabled(): void
    {
        $workspace = Workspace::create(['name' => 'Platform', 'slug' => 'platform']);
        $initialUser = User::factory()->create(['disabled_at' => now()]);
        $secondAdmin = User::factory()->create();
        $workspace->users()->attach($initialUser, ['role' => 'site_admin']);
        $workspace->users()->attach($secondAdmin, ['role' => 'site_admin']);

        $this->actingAs($secondAdmin)
            ->from(route('dashboard.users.show', $initialUser))
            ->patch(route('dashboard.users.disabled', $initialUser))
            ->assertRedirect(route('dashboard.users.show', $initialUser));

        $this->assertNull($initialUser->fresh()->disabled_at);
    }

    public function test_site_admin_reset_password_generates_credentials(): void
    {
        $workspace = Workspace::create(['name' => 'Platform', 'slug' => 'platform']);
        $siteAdmin = User::factory()->create();
        $workspace->users()->attach($siteAdmin, ['role' => 'site_admin']);
        $managedUser = User::factory()->create(['email' => 'agent@example.test']);
        $workspace->users()->attach($managedUser, ['role' => 'workspace_user']);

        $response = $this->actingAs($siteAdmin)
            ->from(route('dashboard.users.show', $managedUser))
            ->patch(route('dashboard.users.password', $managedUser));

        $response
            ->assertRedirect(route('dashboard.users.show', $managedUser))
            ->assertSessionHas('reset_user_credentials');

        $credentials = session('reset_user_credentials');
        $this->assertSame(route('login'), $credentials['login_url']);
        $this->assertSame('agent@example.test', $credentials['email']);
        $this->assertNotEmpty($credentials['password']);

        $this->actingAs($siteAdmin)
            ->withSession(['reset_user_credentials' => $credentials])
            ->get(route('dashboard.users.show', $managedUser))
            ->assertOk()
            ->assertSee('Password Reset')
            ->assertSee('agent@example.test')
            ->assertSee($credentials['password'])
            ->assertSee('email%28username%29', false);
    }

    public function test_site_admin_can_filter_and_open_workspace_details(): void
    {
        $workspace = Workspace::create(['name' => 'Platform', 'slug' => 'platform']);
        $siteAdmin = User::factory()->create();
        $workspace->users()->attach($siteAdmin, ['role' => 'site_admin']);
        $target = Workspace::create(['name' => 'North Support', 'slug' => 'north-support']);
        Workspace::create(['name' => 'South Sales', 'slug' => 'south-sales', 'suspended_at' => now()]);

        $this->actingAs($siteAdmin)
            ->get(route('dashboard.workspaces.index', ['q' => 'north', 'status' => 'active']))
            ->assertOk()
            ->assertSee('North Support')
            ->assertDontSee('South Sales');

        $this->actingAs($siteAdmin)
            ->get(route('dashboard.workspaces.show', $target))
            ->assertOk()
            ->assertSee('Add Workspace Member')
            ->assertSee('Workspace Actions');
    }

    public function test_multi_workspace_user_must_choose_workspace_before_dashboard(): void
    {
        $first = Workspace::create(['name' => 'First', 'slug' => 'first']);
        $second = Workspace::create(['name' => 'Second', 'slug' => 'second']);
        $user = User::factory()->create();
        $first->users()->attach($user, ['role' => 'workspace_user']);
        $second->users()->attach($user, ['role' => 'workspace_user']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('dashboard.workspace.select'));

        $this->actingAs($user)
            ->get(route('dashboard.workspace.select'))
            ->assertOk()
            ->assertSee('Choose Workspace')
            ->assertSee('Workspace ID: first')
            ->assertSee('Workspace ID: second');
    }

    public function test_selecting_workspace_stores_session_and_dashboard_uses_it(): void
    {
        $first = Workspace::create(['name' => 'First', 'slug' => 'first']);
        $second = Workspace::create(['name' => 'Second', 'slug' => 'second']);
        $user = User::factory()->create();
        $first->users()->attach($user, ['role' => 'workspace_admin']);
        $second->users()->attach($user, ['role' => 'workspace_admin']);

        $this->actingAs($user)
            ->post(route('dashboard.workspace.select.store'), ['workspace_id' => $second->id])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('dashboard_workspace_id', $second->id);

        $this->actingAs($user)
            ->withSession(['dashboard_workspace_id' => $second->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Second')
            ->assertSee('Change workspace');
    }

    public function test_single_workspace_user_bypasses_workspace_chooser(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => 'workspace_user']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSessionHas('dashboard_workspace_id', $workspace->id);
    }

    public function test_user_cannot_select_unassigned_workspace(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $foreignWorkspace = Workspace::create(['name' => 'Other', 'slug' => 'other']);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => 'workspace_user']);

        $this->actingAs($user)
            ->post(route('dashboard.workspace.select.store'), ['workspace_id' => $foreignWorkspace->id])
            ->assertSessionHasErrors('workspace_id');
    }

    public function test_non_site_admin_cannot_select_suspended_workspace(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $suspendedWorkspace = Workspace::create(['name' => 'Suspended', 'slug' => 'suspended', 'suspended_at' => now()]);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => 'workspace_user']);
        $suspendedWorkspace->users()->attach($user, ['role' => 'workspace_user']);

        $this->actingAs($user)
            ->get(route('dashboard.workspace.select'))
            ->assertOk()
            ->assertSee('Acme')
            ->assertDontSee('Suspended');

        $this->actingAs($user)
            ->post(route('dashboard.workspace.select.store'), ['workspace_id' => $suspendedWorkspace->id])
            ->assertSessionHasErrors('workspace_id');
    }

    public function test_workspace_ids_are_generated_from_english_names_or_uuid_fallback(): void
    {
        $this->assertMatchesRegularExpression('/^english-name-\d{6}$/', WorkspaceIds::generate('English Name'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/', WorkspaceIds::generate('東京'));
    }

    public function test_site_admin_can_assign_existing_user_from_workspace_detail(): void
    {
        $workspace = Workspace::create(['name' => 'Platform', 'slug' => 'platform']);
        $siteAdmin = User::factory()->create();
        $workspace->users()->attach($siteAdmin, ['role' => 'site_admin']);
        $target = Workspace::create(['name' => 'North Support', 'slug' => 'north-support']);
        $agent = User::factory()->create(['name' => 'Candidate Agent', 'email' => 'candidate@example.test']);

        $this->actingAs($siteAdmin)
            ->get(route('dashboard.workspaces.show', $target))
            ->assertOk()
            ->assertSee('user@example.com')
            ->assertSee('workspace_user');

        $this->actingAs($siteAdmin)
            ->from(route('dashboard.workspaces.show', $target))
            ->post(route('dashboard.workspaces.admins.store', $target), [
                'email' => $agent->email,
                'role' => 'workspace_user',
            ])
            ->assertRedirect(route('dashboard.workspaces.show', $target));

        $this->assertSame('workspace_user', $agent->fresh()->roleForWorkspace($target));
    }

    public function test_site_admin_can_remove_workspace_member_from_workspace_detail(): void
    {
        $workspace = Workspace::create(['name' => 'Platform', 'slug' => 'platform']);
        $siteAdmin = User::factory()->create();
        $workspace->users()->attach($siteAdmin, ['role' => 'site_admin']);
        $target = Workspace::create(['name' => 'North Support', 'slug' => 'north-support']);
        $admin = User::factory()->create(['name' => 'Workspace Manager', 'email' => 'manager@example.test']);
        $agent = User::factory()->create(['name' => 'Workspace Agent', 'email' => 'agent@example.test']);
        $target->users()->attach($admin, ['role' => 'workspace_admin']);
        $target->users()->attach($agent, ['role' => 'workspace_user']);

        $this->actingAs($siteAdmin)
            ->get(route('dashboard.workspaces.show', $target))
            ->assertOk()
            ->assertSee('Workspace Agent')
            ->assertSee('Remove');

        $this->actingAs($siteAdmin)
            ->from(route('dashboard.workspaces.show', $target))
            ->delete(route('dashboard.users.workspaces.destroy', [$agent, $target]))
            ->assertRedirect(route('dashboard.workspaces.show', $target));

        $this->assertNull($agent->fresh()->roleForWorkspace($target));
        $this->assertSame('workspace_admin', $admin->fresh()->roleForWorkspace($target));
    }

    public function test_site_admin_cannot_remove_last_workspace_admin_membership(): void
    {
        $workspace = Workspace::create(['name' => 'Platform', 'slug' => 'platform']);
        $siteAdmin = User::factory()->create();
        $workspace->users()->attach($siteAdmin, ['role' => 'site_admin']);
        $target = Workspace::create(['name' => 'North Support', 'slug' => 'north-support']);
        $admin = User::factory()->create();
        $target->users()->attach($admin, ['role' => 'workspace_admin']);

        $this->actingAs($siteAdmin)
            ->from(route('dashboard.workspaces.show', $target))
            ->delete(route('dashboard.users.workspaces.destroy', [$admin, $target]))
            ->assertRedirect(route('dashboard.workspaces.show', $target))
            ->assertSessionHasErrors('workspace');

        $this->assertSame('workspace_admin', $admin->fresh()->roleForWorkspace($target));
    }

    public function test_site_admin_sees_error_when_workspace_detail_email_does_not_exist(): void
    {
        $workspace = Workspace::create(['name' => 'Platform', 'slug' => 'platform']);
        $siteAdmin = User::factory()->create();
        $workspace->users()->attach($siteAdmin, ['role' => 'site_admin']);
        $target = Workspace::create(['name' => 'North Support', 'slug' => 'north-support']);

        $this->actingAs($siteAdmin)
            ->from(route('dashboard.workspaces.show', $target))
            ->post(route('dashboard.workspaces.admins.store', $target), [
                'email' => 'missing@example.test',
                'role' => 'workspace_user',
            ])
            ->assertInvalid('email');
    }

    public function test_site_admin_can_filter_and_open_user_details(): void
    {
        $workspace = Workspace::create(['name' => 'Platform', 'slug' => 'platform']);
        $siteAdmin = User::factory()->create();
        $workspace->users()->attach($siteAdmin, ['role' => 'site_admin']);
        $agent = User::factory()->create(['name' => 'North Agent', 'email' => 'north-agent@example.test']);
        $workspace->users()->attach($agent, ['role' => 'workspace_user']);
        User::factory()->create(['name' => 'South Agent', 'email' => 'south-agent@example.test']);

        $this->actingAs($siteAdmin)
            ->get(route('dashboard.users.index', ['q' => 'north', 'role' => 'workspace_user']))
            ->assertOk()
            ->assertSee('North Agent')
            ->assertSee('Workspace User')
            ->assertDontSee('South Agent');

        $this->actingAs($siteAdmin)
            ->get(route('dashboard.users.show', $agent))
            ->assertOk()
            ->assertSee('Workspace Memberships')
            ->assertDontSee('Assign Workspace');
    }

    public function test_workspace_admin_can_invite_users_and_manage_sessions(): void
    {
        Http::fake([
            config('larawa.worker_url').'/internal/sessions' => Http::response(['status' => 'initializing'], 202),
        ]);

        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $admin = User::factory()->create();
        $workspace->users()->attach($admin, ['role' => 'workspace_admin']);

        $this->actingAs($admin)
            ->from(route('dashboard.workspace-users.index'))
            ->post(route('dashboard.workspace-users.store'), [
                'name' => 'Agent',
                'email' => 'agent@example.test',
                'role' => 'workspace_user',
            ])
            ->assertRedirect(route('dashboard.workspace-users.index'))
            ->assertSessionHas('created_workspace_user_credentials');

        $agent = User::where('email', 'agent@example.test')->firstOrFail();
        $this->assertSame('workspace_user', $agent->roleForWorkspace($workspace));
        $credentials = session('created_workspace_user_credentials');
        $this->assertIsArray($credentials);

        $this->actingAs($admin)
            ->withSession(['created_workspace_user_credentials' => $credentials])
            ->get(route('dashboard.workspace-users.index'))
            ->assertOk()
            ->assertSee('Account Created')
            ->assertSee('Download CSV')
            ->assertSee($credentials['password']);

        $this->actingAs($admin)
            ->post(route('dashboard.sessions.store'), ['name' => 'Support line'])
            ->assertRedirect();

        $this->assertDatabaseHas('whatsapp_sessions', [
            'workspace_id' => $workspace->id,
            'name' => 'Support line',
        ]);
    }

    public function test_workspace_admin_invite_creates_new_users_only(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $admin = User::factory()->create();
        $workspace->users()->attach($admin, ['role' => 'workspace_admin']);
        User::factory()->create(['name' => 'Existing Agent', 'email' => 'existing@example.test']);

        $this->actingAs($admin)
            ->get(route('dashboard.workspace-users.index'))
            ->assertOk()
            ->assertSee('Create Account')
            ->assertDontSee('Temporary password')
            ->assertDontSee('existing@example.test');

        $this->actingAs($admin)
            ->from(route('dashboard.workspace-users.index'))
            ->post(route('dashboard.workspace-users.store'), [
                'name' => 'Duplicate Agent',
                'email' => 'existing@example.test',
                'role' => 'workspace_user',
            ])
            ->assertInvalid('email');

        $this->assertNull(User::where('email', 'existing@example.test')->firstOrFail()->roleForWorkspace($workspace));
    }

    public function test_workspace_admin_can_filter_and_open_member_details(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $admin = User::factory()->create();
        $workspace->users()->attach($admin, ['role' => 'workspace_admin']);
        $target = User::factory()->create(['name' => 'North Agent', 'email' => 'north-agent@example.test']);
        $other = User::factory()->create(['name' => 'South Agent', 'email' => 'south-agent@example.test']);
        $workspace->users()->attach($target, ['role' => 'workspace_user']);
        $workspace->users()->attach($other, ['role' => 'workspace_user']);

        $this->actingAs($admin)
            ->get(route('dashboard.workspace-users.index', ['q' => 'north', 'role' => 'workspace_user']))
            ->assertOk()
            ->assertSee('North Agent')
            ->assertSee('Remove')
            ->assertDontSee('South Agent');

        $this->actingAs($admin)
            ->get(route('dashboard.workspace-users.show', $target))
            ->assertOk()
            ->assertSee('Save Role')
            ->assertSee('Remove From Workspace');

        $this->actingAs($admin)->get(route('dashboard.settings.index'))->assertForbidden();
    }

    public function test_workspace_admin_cannot_remove_self_but_another_admin_can_remove_them(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $admin = User::factory()->create(['name' => 'Primary Admin']);
        $otherAdmin = User::factory()->create(['name' => 'Backup Admin']);
        $workspace->users()->attach($admin, ['role' => 'workspace_admin']);
        $workspace->users()->attach($otherAdmin, ['role' => 'workspace_admin']);

        $this->actingAs($admin)
            ->get(route('dashboard.workspace-users.show', $admin))
            ->assertOk()
            ->assertSee('Another workspace admin must remove this account from the workspace.')
            ->assertDontSee('Remove From Workspace');

        $this->actingAs($admin)
            ->delete(route('dashboard.workspace-users.destroy', $admin))
            ->assertStatus(422);

        $this->assertSame('workspace_admin', $admin->fresh()->roleForWorkspace($workspace));

        $this->actingAs($otherAdmin)
            ->delete(route('dashboard.workspace-users.destroy', $admin))
            ->assertRedirect();

        $this->assertNull($admin->fresh()->roleForWorkspace($workspace));
        $this->assertSame('workspace_admin', $otherAdmin->fresh()->roleForWorkspace($workspace));
    }

    public function test_workspace_user_has_read_only_dashboard_access_and_gets_403_for_admin_actions(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => 'workspace_user']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Sessions')
            ->assertSee('Message Log')
            ->assertSee('Webhook Logs')
            ->assertDontSee('API Keys')
            ->assertDontSee('Workspace Users');

        $this->actingAs($user)->get(route('dashboard.sessions.index'))->assertOk();
        $this->actingAs($user)->get(route('dashboard.messages.index'))->assertOk();
        $this->actingAs($user)->get(route('dashboard.webhooks.index'))->assertOk();

        $this->actingAs($user)->post(route('dashboard.sessions.store'), ['name' => 'Denied'])->assertForbidden();
        $this->actingAs($user)->get(route('dashboard.api-keys.index'))->assertForbidden();
        $this->actingAs($user)->get(route('dashboard.settings.index'))->assertForbidden();
        $this->actingAs($user)->get(route('dashboard.users.index'))->assertForbidden();
    }

    public function test_log_visibility_is_global_for_site_admin_and_workspace_scoped_for_workspace_admin(): void
    {
        $platform = Workspace::create(['name' => 'Platform', 'slug' => 'platform']);
        $client = Workspace::create(['name' => 'Client Support', 'slug' => 'client-support']);
        $siteAdmin = User::factory()->create(['name' => 'Site Admin', 'email' => 'site-admin@example.test']);
        $workspaceAdmin = User::factory()->create(['name' => 'Workspace Admin', 'email' => 'workspace-admin@example.test']);
        $actor = User::factory()->create(['name' => 'Audrey Actor', 'email' => 'audrey@example.test']);
        $platform->users()->attach($siteAdmin, ['role' => 'site_admin']);
        $platform->users()->attach($workspaceAdmin, ['role' => 'workspace_admin']);

        $platformSession = $platform->whatsappSessions()->create(['name' => 'Platform line', 'status' => 'ready']);
        $clientSession = $client->whatsappSessions()->create(['name' => 'Client line', 'status' => 'ready']);
        Message::create([
            'workspace_id' => $platform->id,
            'whatsapp_session_id' => $platformSession->id,
            'wa_message_id' => 'platform-message',
            'direction' => 'incoming',
            'type' => 'text',
            'status' => 'received',
            'body' => 'Platform visible message',
        ]);
        Message::create([
            'workspace_id' => $client->id,
            'whatsapp_session_id' => $clientSession->id,
            'wa_message_id' => 'client-message',
            'direction' => 'incoming',
            'type' => 'text',
            'status' => 'received',
            'body' => 'Client visible message',
        ]);
        AuditLog::create([
            'workspace_id' => $platform->id,
            'user_id' => $actor->id,
            'action' => 'platform.audit',
        ]);
        AuditLog::create([
            'workspace_id' => $client->id,
            'user_id' => $actor->id,
            'action' => 'client.audit',
        ]);

        $this->actingAs($siteAdmin)
            ->withSession(['dashboard_workspace_id' => $platform->id])
            ->get(route('dashboard.messages.index'))
            ->assertOk()
            ->assertSee('Message Log')
            ->assertSee('Platform visible message')
            ->assertSee('Client visible message')
            ->assertSee('Platform')
            ->assertSee('Client Support');

        $this->actingAs($siteAdmin)
            ->withSession(['dashboard_workspace_id' => $platform->id])
            ->get(route('dashboard.audit.index'))
            ->assertOk()
            ->assertSee('platform.audit')
            ->assertSee('client.audit')
            ->assertSee('Audrey Actor')
            ->assertSee('audrey@example.test')
            ->assertDontSee('user:'.$actor->id);

        $this->actingAs($workspaceAdmin)
            ->withSession(['dashboard_workspace_id' => $platform->id])
            ->get(route('dashboard.messages.index'))
            ->assertOk()
            ->assertSee('Platform visible message')
            ->assertDontSee('Client visible message')
            ->assertDontSee('Client Support');

        $this->actingAs($workspaceAdmin)
            ->withSession(['dashboard_workspace_id' => $platform->id])
            ->get(route('dashboard.audit.index'))
            ->assertOk()
            ->assertSee('platform.audit')
            ->assertSee('Audrey Actor')
            ->assertDontSee('client.audit')
            ->assertDontSee('user:'.$actor->id);
    }

    public function test_workspace_admin_cannot_access_other_workspaces(): void
    {
        $first = Workspace::create(['name' => 'First', 'slug' => 'first']);
        $second = Workspace::create(['name' => 'Second', 'slug' => 'second']);
        $admin = User::factory()->create();
        $first->users()->attach($admin, ['role' => 'workspace_admin']);
        $session = $second->whatsappSessions()->create(['name' => 'Other line', 'status' => 'ready']);

        $this->actingAs($admin)
            ->get(route('dashboard.sessions.show', $session))
            ->assertForbidden();
    }

    public function test_site_admin_can_list_but_not_access_other_workspace_whatsapp_sessions(): void
    {
        $platform = Workspace::create(['name' => 'Platform', 'slug' => 'platform']);
        $other = Workspace::create(['name' => 'Client Support', 'slug' => 'client-support']);
        $siteAdmin = User::factory()->create();
        $platform->users()->attach($siteAdmin, ['role' => 'site_admin']);
        $ownSession = $platform->whatsappSessions()->create([
            'name' => 'Platform line',
            'status' => 'ready',
            'phone_number' => '15551230000',
        ]);
        $otherSession = $other->whatsappSessions()->create([
            'name' => 'Client line',
            'status' => 'ready',
            'phone_number' => '15557654321',
        ]);

        $this->actingAs($siteAdmin)
            ->withSession(['dashboard_workspace_id' => $platform->id])
            ->get(route('dashboard.sessions.index'))
            ->assertOk()
            ->assertSee('Platform line')
            ->assertSee('15551230000')
            ->assertSee('Client line')
            ->assertSee('+1 ****4321')
            ->assertDontSee('15557654321')
            ->assertSee(route('dashboard.sessions.show', $ownSession), false)
            ->assertDontSee(route('dashboard.sessions.show', $otherSession), false);

        $this->actingAs($siteAdmin)
            ->withSession(['dashboard_workspace_id' => $platform->id])
            ->get(route('dashboard.sessions.show', $otherSession))
            ->assertNotFound();

        $this->actingAs($siteAdmin)
            ->withSession(['dashboard_workspace_id' => $platform->id])
            ->get(route('dashboard.sessions.snapshot', $otherSession))
            ->assertNotFound();

        $this->actingAs($siteAdmin)
            ->withSession(['dashboard_workspace_id' => $platform->id])
            ->post(route('dashboard.sessions.refresh', $otherSession))
            ->assertNotFound();
    }

    public function test_suspended_workspace_api_keys_are_rejected(): void
    {
        $workspace = Workspace::create([
            'name' => 'Acme',
            'slug' => 'acme',
            'suspended_at' => now(),
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'Suspended key', ['sessions:read']);

        $this->withToken($plainText)
            ->getJson('/api/v1/sessions')
            ->assertForbidden()
            ->assertJsonPath('message', 'Workspace is suspended.');

        $this->assertNull(ApiKey::firstOrFail()->last_used_at);
    }
}
