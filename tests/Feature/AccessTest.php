<?php

use Filament\Facades\Filament;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Packstub\FilamentHoller\Actions\CallAction;
use Packstub\FilamentHoller\FilamentHollerPlugin;
use Packstub\FilamentHoller\Tests\Fixtures\User;
use Packstub\Holler\Facades\Holler;

beforeEach(function (): void {
    $this->ion = user('Ion');
    $this->ana = user('Ana');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('refuses to ring someone who cannot open the panel, whatever canCall says', function (): void {
    $locked = user('Locked');
    FilamentHollerPlugin::get()->canCall(fn (): bool => true);

    expect(Holler::context()->canCall($this->ion, $locked))->toBeFalse()
        ->and(Holler::context()->canCall($this->ion, $this->ana))->toBeTrue();

    $this->actingAs($this->ion)
        ->postJson(Filament::getPanel('admin')->route('filament-holler.store'), ['callee' => $locked->getKey()])
        ->assertStatus(422)
        ->assertJsonMissingPath('peer');
});

it('hides the Call button for someone the signed-in user may not call', function (): void {
    $this->actingAs($this->ion);
    FilamentHollerPlugin::get()->canCall(fn (User $callee): bool => $callee->name !== 'Ana');

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
        FilamentHollerPlugin::flushTenantCache();

        return FilamentHollerPlugin::sharesTenant($caller, $callee, $panel);
    };

    expect($share($member([$tenantOf(1), $tenantOf(2)]), $member([$tenantOf(2)])))->toBeTrue()
        ->and($share($member([$tenantOf(1)]), $member([$tenantOf(3)])))->toBeFalse()
        ->and($share($this->ion, $this->ana))->toBeFalse();

    // A closure keeps the tenant rule through the injected `$sharesTenant`.
    FilamentHollerPlugin::get()->canCall(fn (bool $sharesTenant): bool => $sharesTenant);
    expect(FilamentHollerPlugin::get()->isCallAllowed($this->ion, $this->ana))->toBeTrue();
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
    FilamentHollerPlugin::flushTenantCache();

    // The core asks between two people on a group call, neither of them necessarily the one signed in.
    expect(FilamentHollerPlugin::sharesTenant($member([$tenantOf(2)]), $member([$tenantOf(1)]), $panel))->toBeFalse()
        ->and(FilamentHollerPlugin::sharesTenant($member([$tenantOf(1)]), $member([$tenantOf(1)]), $panel))->toBeTrue();

    Filament::setTenant(null, isQuiet: true);
});

it('keeps the calls of a panel in its guard', function (): void {
    expect(Holler::context()->scope())->toBe(Filament::getPanel('admin')->getAuthGuard());

    $call = Holler::start($this->ion, $this->ana);
    expect($call->scope)->toBe(Filament::getPanel('admin')->getAuthGuard());
});

it('follows the core\'s pop_out config unless the plugin says otherwise', function (): void {
    expect(FilamentHollerPlugin::get()->isPopOut())->toBeTrue();

    config()->set('holler.pop_out', false);
    expect(FilamentHollerPlugin::get()->isPopOut())->toBeFalse();

    FilamentHollerPlugin::get()->popOut(true);
    expect(FilamentHollerPlugin::get()->isPopOut())->toBeTrue();
});

it('keeps the calls of a panel that requires a verified email to people who verified theirs', function (): void {
    $panel = Filament::getPanel('verified');
    Filament::setCurrentPanel($panel);
    $this->ana->markEmailAsVerified();

    // The routes ask what the panel's pages ask: an unverified caller never reaches them.
    $this->actingAs($this->ion)
        ->getJson($panel->route('filament-holler.poll'))
        ->assertForbidden();
    $this->actingAs($this->ion)
        ->postJson($panel->route('filament-holler.store'), ['callee' => $this->ana->getKey()])
        ->assertForbidden();
    expect(Holler::openCallsOf((string) $this->ana->getKey()))->toBeEmpty();

    // Nor rings someone who has not verified (a sign-up with somebody else's address).
    Filament::setCurrentPanel($panel);
    expect(FilamentHollerPlugin::get()->isCallAllowed($this->ana, $this->ion))->toBeFalse();
    $this->ion->markEmailAsVerified();
    expect(FilamentHollerPlugin::get()->isCallAllowed($this->ana, $this->ion))->toBeTrue();

    // The widget stays off the verify-your-email page.
    $this->actingAs(user('Mihai'))
        ->get($panel->getEmailVerificationPromptUrl())
        ->assertOk()
        ->assertDontSee('packstubHoller(', escape: false);

    // On the panel without the requirement nothing changes.
    expect(FilamentHollerPlugin::routeMiddleware(Filament::getPanel('admin')))->toBe([]);
});

it('asks for multi-factor authentication on the call routes where the panel requires it', function (): void {
    $panel = Panel::make()->id('mfa')->multiFactorAuthentication([], isRequired: true);

    expect(FilamentHollerPlugin::routeMiddleware($panel))->toBe([$panel->getMultiFactorAuthenticationRequiredMiddleware()]);
});

it('keeps the Call button hidden for someone the user may not call, whatever visible() the app adds', function (): void {
    $this->actingAs($this->ion);
    FilamentHollerPlugin::get()->canCall(fn (User $callee): bool => $callee->name !== 'Ana');

    expect(CallAction::make()->user($this->ana)->visible(true)->isVisible())->toBeFalse()
        ->and(CallAction::make()->user($this->ion)->visible(fn (): bool => true)->isVisible())->toBeFalse()
        ->and(CallAction::make()->user(user('Mihai'))->visible(true)->isVisible())->toBeTrue()
        ->and(CallAction::make()->user(user('Dana'))->visible(false)->isVisible())->toBeFalse()
        ->and(CallAction::make()->user(user('Radu'))->hidden()->isVisible())->toBeFalse();
});
