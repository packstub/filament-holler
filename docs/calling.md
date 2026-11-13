# Calling

## The Call action

Put a `CallAction` where the record is a user (an `Authenticatable`):

```php
use Packstub\FilamentHoller\Actions\CallAction;

// A table of users
->recordActions([
    CallAction::make(),
])

// A page header, calling a related user
protected function getHeaderActions(): array
{
    return [
        CallAction::make()->user(fn () => $this->record->assignee),
    ];
}
```

Clicking it rings the other person: their open panel tabs show a toast with the caller's name and avatar (the panel's own `getUserName()` and `getUserAvatarUrl()`), they accept, and the two browsers connect.

The button only shows for someone the signed-in user may call (never for themselves), so a table of users gets a Call button on every row but one. Your own `->visible()` or `->hidden()` narrows that further; it never shows the button for someone the rules below refuse.

## Who may call whom

By default any two users who can open the panel can call each other, and on a panel with tenancy only two who share a tenant (the call routes carry no tenant, so the plugin compares the two people's tenants). Narrow it:

```php
FilamentHollerPlugin::make()
    ->canCall(fn (User $caller, User $callee): bool => $caller->team_id === $callee->team_id)
```

A closure replaces the tenant rule; take `bool $sharesTenant` to keep it and add your own:

```php
FilamentHollerPlugin::make()
    ->canCall(fn (User $callee, bool $sharesTenant): bool => $sharesTenant && $callee->is_active)
```

Whatever the closure says:

- nobody calls themselves;
- someone who cannot open the panel is never callable: the user model's `canAccessPanel()` decides, and a model without `FilamentUser` counts only in the `local` environment, as in Filament itself;
- on a panel that requires a verified email, someone who has not verified theirs is never callable.

The check runs on the server when a call starts and when someone is added to one; the Call button follows the same rule. `FilamentHollerPlugin::sharesTenant($caller, $callee, $panel)` is the tenant check on its own, for code of your own. The caller's tenants are read once per request; `FilamentHollerPlugin::flushTenantCache()` forgets them after you change someone's tenants mid-request. In a table of Call buttons on a panel with tenancy, eager-load the users' tenants:

```php
->modifyQueryUsing(fn ($query) => $query->with('teams'))
```

## Group calls

While on a call, clicking Call on another person adds them to it: they get the usual toast, with how many people are already on, and once they accept everyone connects to them. The window shows a tile per other participant, two by two. Someone who hangs up leaves the call for the others; the call ends once one person is left.

Four people is the default limit. The browsers connect to each other directly, so each uploads its media once per other participant, and four is where that stops being comfortable on ordinary connections:

```php
FilamentHollerPlugin::make()->maxParticipants(3)   // or HOLLER_MAX_PARTICIPANTS in .env
```

To go further, give the engine a `topology` for a media server (an SFU) through the [engine options](customizing.md#engine-options).

## Voice calls

```php
FilamentHollerPlugin::make()->video(false)
```

Calls then start with the microphone only; either side can still share their screen. Someone without a camera joins a video call with their voice, and the others see their avatar. `HOLLER_VIDEO=false` makes every call a voice call.

## Page events

- `filament-holler-call` on `window` with `{ user }` rings that user, or adds them to the call you are on. It is what `CallAction` dispatches, so any button or script can do the same: `$dispatch('filament-holler-call', { user: 5 })` in Alpine.
- `filament-holler-sharing` bubbles from the widget with `{ peer, sharing, spotlight }` when someone's screen starts or stops taking the stage.
- `filament-holler-quality` bubbles from the widget with `{ peer, quality, rtt, loss, relayed }` when the connection to someone changes between `good`, `fair` and `poor`.
