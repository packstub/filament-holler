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

While on a call, there are two ways to add someone. The Add someone button in the call window opens a search box over the panel's users and rings whoever is picked. Clicking Call on another person asks what to do with the call you are on: add them to it, put it on hold and ring them, or end it and ring them. Someone added gets the usual toast, with how many people are already on, and once they accept everyone connects to them. The window shows a tile per other participant, two by two. Someone who hangs up leaves the call for the others; the call ends once one person is left.

Four people is the default limit. The browsers connect to each other directly, so each uploads its media once per other participant, and four is where that stops being comfortable on ordinary connections:

```php
FilamentHollerPlugin::make()->maxParticipants(3)   // or HOLLER_MAX_PARTICIPANTS in .env
```

To go further, give the engine a `topology` for a media server (an SFU) through the [engine options](customizing.md#engine-options).

The search finds the panel's users by `name` (the `search_columns` key of `config/holler.php`; add `email` there to find people by address too, where colleagues may know each other's address) and only shows people `canCall()` allows, who are not on the call yet. It looks through up to 200 matches for the 8 it shows, so with tenancy colleagues are found even when other tenants' users match too. In a panel whose users are found another way, or to keep the search quick on a large users table, answer it yourself with a query narrowed to the tenant:

```php
FilamentHollerPlugin::make()->searchUsersUsing(
    fn (User $caller, string $query, int $limit) => Filament::getTenant()->users()->where('name', 'like', "%{$query}%")->limit($limit)->get()
)
```

`HOLLER_PEOPLE_SEARCH=false` leaves the button out.

## Calls on hold

Put on hold in the call window steps you out of a call without leaving it. Your camera and microphone close and your window is free for another call; the others stay on the first one, see that you put it on hold, and wait or carry on among themselves.

- The call on hold shows in a small bar on every page of the panel, with who is on it, Resume and End.
- Resume brings you back with a fresh connection. If you are on another call by then, that one goes on hold in its place, so Resume swaps the two.
- One call on hold at a time. With one on hold already, Call on another person offers to add them or to end the current call.
- Close every window and the call lets go of you after `HOLLER_HOLD_TIMEOUT` seconds (120).

## Voice calls

```php
FilamentHollerPlugin::make()->video(false)
```

Calls then start with the microphone only; either side can still share their screen. Someone without a camera joins a video call with their voice, and the others see their avatar. `HOLLER_VIDEO=false` makes every call a voice call.

## Page events

- `filament-holler-call` on `window` with `{ user, name }` rings that user; during a call it opens the question above, with the name in it. It is what `CallAction` dispatches, so any button or script can do the same: `$dispatch('filament-holler-call', { user: 5 })` in Alpine.
- `filament-holler-sharing` bubbles from the widget with `{ peer, sharing, spotlight }` when someone's screen starts or stops taking the stage.
- `filament-holler-quality` bubbles from the widget with `{ peer, quality, rtt, loss, relayed }` when the connection to someone changes between `good`, `fair` and `poor`.
