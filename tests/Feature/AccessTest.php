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

    $share = (new ReflectionMethod(FilamentCallsPlugin::class, 'shareTenant'))->getClosure();
    $panel = Filament::getPanel('admin');

    expect($share($member([$tenantOf(1), $tenantOf(2)]), $member([$tenantOf(2)]), $panel))->toBeTrue()
        ->and($share($member([$tenantOf(1)]), $member([$tenantOf(3)]), $panel))->toBeFalse()
        ->and($share($this->ion, $this->ana, $panel))->toBeFalse();
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
