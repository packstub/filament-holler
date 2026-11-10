<?php

use Filament\Facades\Filament;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Packstub\Calls\Facades\Calls;
use Packstub\FilamentCalls\Actions\CallAction;
use Packstub\FilamentCalls\FilamentCallsPlugin;
use Packstub\FilamentCalls\Tests\Fixtures\User;

beforeEach(function (): void {
    $this->ion = user('Ion');
    $this->ana = user('Ana');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('refuses to ring someone who cannot open the panel, whatever canCall says', function (): void {
    $locked = user('Locked');
    FilamentCallsPlugin::get()->canCall(fn (): bool => true);

    expect(Calls::context()->canCall($this->ion, $locked))->toBeFalse()
        ->and(Calls::context()->canCall($this->ion, $this->ana))->toBeTrue();

    $this->actingAs($this->ion)
        ->postJson(Filament::getPanel('admin')->route('filament-calls.store'), ['callee' => $locked->getKey()])
        ->assertStatus(422)
        ->assertJsonMissingPath('peer');
});

it('hides the Call button for someone the signed-in user may not call', function (): void {
    $this->actingAs($this->ion);
    FilamentCallsPlugin::get()->canCall(fn (User $callee): bool => $callee->name !== 'Ana');

    expect(CallAction::make()->user($this->ana)->isVisible())->toBeFalse()
        ->and(CallAction::make()->user(user('Mihai'))->isVisible())->toBeTrue()
        ->and(CallAction::make()->user(user('Locked'))->isVisible())->toBeFalse();
});

it('asks two users of a panel with tenancy to share a tenant', function (): void {
    $tenantOf = fn (int $id) => tap(new class extends Model {}, fn (Model $model) => $model->forceFill(['id' => $id]));
    $member = fn (array $tenants) => new class($tenants) extends User implements HasTenants
    {
        public function __construct(public array $tenantList = [])
        {
            parent::__construct();
        }

        public function getTenants(Panel $panel): Collection
        {
            return collect($this->tenantList);
        }

        public function canAccessTenant(Model $tenant): bool
        {
            return true;
        }
    };

    $panel = Filament::getPanel('admin');
    $share = function ($caller, $callee) use ($panel): bool {
        FilamentCallsPlugin::flushTenantCache();

        return FilamentCallsPlugin::sharesTenant($caller, $callee, $panel);
    };

    expect($share($member([$tenantOf(1), $tenantOf(2)]), $member([$tenantOf(2)])))->toBeTrue()
        ->and($share($member([$tenantOf(1)]), $member([$tenantOf(3)])))->toBeFalse()
        ->and($share($this->ion, $this->ana))->toBeFalse();

    // A closure keeps the tenant rule through the injected `$sharesTenant`.
    FilamentCallsPlugin::get()->canCall(fn (bool $sharesTenant): bool => $sharesTenant);
    expect(FilamentCallsPlugin::get()->isCallAllowed($this->ion, $this->ana))->toBeTrue();
});

it('does not take the tenant being viewed as shared when only the callee is in it', function (): void {
    $tenantOf = fn (int $id) => tap(new class extends Model {}, fn (Model $model) => $model->forceFill(['id' => $id]));
    $member = fn (array $tenants) => new class($tenants) extends User implements HasTenants
    {
        public function __construct(public array $tenantList = [])
        {
            parent::__construct();
        }

        public function getTenants(Panel $panel): Collection
        {
            return collect($this->tenantList);
        }

        public function canAccessTenant(Model $tenant): bool
        {
            return collect($this->tenantList)->contains(fn (Model $mine) => $mine->getKey() === $tenant->getKey());
        }
    };

    $panel = Filament::getPanel('admin');
    Filament::setTenant($tenantOf(1), isQuiet: true);
    FilamentCallsPlugin::flushTenantCache();

    // The core asks between two people on a group call, neither of them necessarily the one signed in.
    expect(FilamentCallsPlugin::sharesTenant($member([$tenantOf(2)]), $member([$tenantOf(1)]), $panel))->toBeFalse()
        ->and(FilamentCallsPlugin::sharesTenant($member([$tenantOf(1)]), $member([$tenantOf(1)]), $panel))->toBeTrue();

    Filament::setTenant(null, isQuiet: true);
});

it('keeps the calls of a panel in its guard', function (): void {
    expect(Calls::context()->scope())->toBe(Filament::getPanel('admin')->getAuthGuard());

    $call = Calls::start($this->ion, $this->ana);
    expect($call->scope)->toBe(Filament::getPanel('admin')->getAuthGuard());
});

it('follows the core\'s pop_out config unless the plugin says otherwise', function (): void {
    expect(FilamentCallsPlugin::get()->isPopOut())->toBeTrue();

    config()->set('packstub-calls.pop_out', false);
    expect(FilamentCallsPlugin::get()->isPopOut())->toBeFalse();

    FilamentCallsPlugin::get()->popOut(true);
    expect(FilamentCallsPlugin::get()->isPopOut())->toBeTrue();
});

it('keeps the calls of a panel that requires a verified email to people who verified theirs', function (): void {
    $panel = Filament::getPanel('verified');
    Filament::setCurrentPanel($panel);
    $this->ana->markEmailAsVerified();

    // The routes ask what the panel's pages ask: an unverified caller never reaches them.
    $this->actingAs($this->ion)
        ->getJson($panel->route('filament-calls.poll'))
        ->assertForbidden();
    $this->actingAs($this->ion)
        ->postJson($panel->route('filament-calls.store'), ['callee' => $this->ana->getKey()])
        ->assertForbidden();
    expect(Calls::openCallsOf((string) $this->ana->getKey()))->toBeEmpty();

    // Nor rings someone who has not verified (a sign-up with somebody else's address).
    Filament::setCurrentPanel($panel);
    expect(FilamentCallsPlugin::get()->isCallAllowed($this->ana, $this->ion))->toBeFalse();
    $this->ion->markEmailAsVerified();
    expect(FilamentCallsPlugin::get()->isCallAllowed($this->ana, $this->ion))->toBeTrue();

    // The widget stays off the verify-your-email page.
    $this->actingAs(user('Mihai'))
        ->get($panel->getEmailVerificationPromptUrl())
        ->assertOk()
        ->assertDontSee('packstubCalls(', escape: false);

    // On the panel without the requirement nothing changes.
    expect(FilamentCallsPlugin::routeMiddleware(Filament::getPanel('admin')))->toBe([]);
});

it('asks for multi-factor authentication on the call routes where the panel requires it', function (): void {
    $panel = Panel::make()->id('mfa')->multiFactorAuthentication([], isRequired: true);

    expect(FilamentCallsPlugin::routeMiddleware($panel))->toBe([$panel->getMultiFactorAuthenticationRequiredMiddleware()]);
});

it('keeps the Call button hidden for someone the user may not call, whatever visible() the app adds', function (): void {
    $this->actingAs($this->ion);
    FilamentCallsPlugin::get()->canCall(fn (User $callee): bool => $callee->name !== 'Ana');

    expect(CallAction::make()->user($this->ana)->visible(true)->isVisible())->toBeFalse()
        ->and(CallAction::make()->user($this->ion)->visible(fn (): bool => true)->isVisible())->toBeFalse()
        ->and(CallAction::make()->user(user('Mihai'))->visible(true)->isVisible())->toBeTrue()
        ->and(CallAction::make()->user(user('Dana'))->visible(false)->isVisible())->toBeFalse()
        ->and(CallAction::make()->user(user('Radu'))->hidden()->isVisible())->toBeFalse();
});
