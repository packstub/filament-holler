@php
    use Filament\Facades\Filament;
    use Filament\Support\Facades\FilamentAsset;
    use Filament\Support\Enums\IconSize;
    use Filament\Support\Icons\Heroicon;
    use Packstub\FilamentCalls\FilamentCallsPlugin;
    use Packstub\FilamentCalls\View\FilamentCallsIconAlias as Icon;

    $panel = Filament::getCurrentOrDefaultPanel();
    $plugin = FilamentCallsPlugin::get();
    $popup = request()->routeIs('*.filament-calls.window');

    // No ICE servers in here: the engine asks the `ice` route each time a call starts, so
    // short-lived TURN credentials are fresh and nothing is evaluated on every page load.
    // The transport policy is the same config `Calls::rtcConfiguration()` reads (without its servers).
    $config = [
        'routes' => [
            'poll' => $panel->route('filament-calls.poll'),
            'store' => $panel->route('filament-calls.store'),
            'answer' => $panel->route('filament-calls.answer', ['call' => '__ID__']),
            'decline' => $panel->route('filament-calls.decline', ['call' => '__ID__']),
            'hangup' => $panel->route('filament-calls.hangup', ['call' => '__ID__']),
            'signal' => $panel->route('filament-calls.signal', ['call' => '__ID__']),
            'leave' => $panel->route('filament-calls.leave', ['call' => '__ID__']),
            'invite' => $panel->route('filament-calls.invite', ['call' => '__ID__']),
            'ice' => $panel->route('filament-calls.ice'),
        ],
        // Only calls: another package's calls on the same tables (Session Replay's assist) never ring here.
        'kind' => 'call',
        'windowUrl' => $panel->route('filament-calls.window', ['call' => '__ID__', 'callee' => '__USER__']),
        'csrf' => csrf_token(),
        'iceTransportPolicy' => config('packstub-calls.ice_transport_policy') === 'relay' ? 'relay' : 'all',
        'video' => $plugin->hasVideo(),
        'popOut' => $plugin->isPopOut(),
        'popup' => $popup,
        'poll' => config('packstub-calls.poll'),
        'reconnectTimeout' => (int) config('packstub-calls.reconnect_timeout', 15) * 1000,
        'maxParticipants' => $plugin->getMaxParticipants(),
        'labels' => __('filament-calls::calls'),
    ];

    // How I appear in my own corner while my camera is off.
    $me = Filament::auth()->user();
    $myName = $me ? Filament::getUserName($me) : '';
    $myAvatar = $me ? Filament::getUserAvatarUrl($me) : null;

    // Heroicons has no microphone-slash: the outline microphone with a slash across it, like video-camera-slash.
    // The default of the UNMUTE and MUTED aliases; an app can still register its own icon for them.
    $micOff = new \Illuminate\Support\HtmlString('<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true" data-slot="icon" style="width: 100%; height: 100%"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 0 0 6-6v-1.5m-6 7.5a6 6 0 0 1-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 0 1-3-3V4.5a3 3 0 1 1 6 0v8.25a3 3 0 0 1-3 3Z"/><path stroke-linecap="round" d="m3 3 18 18"/></svg>');
@endphp

{{-- @persist keeps the call alive across wire:navigate page loads in SPA mode; one per panel, since
     the routes, the guard and the options are the panel's (a move to another panel starts its own). --}}
@persist('filament-calls.'.$panel->getId())
<div
    x-load
    x-load-src="{{ FilamentAsset::getAlpineComponentSrc('calls', 'packstub/filament-calls') }}"
    x-load-css="[@js(FilamentAsset::getStyleHref('calls', 'packstub/filament-calls'))]"
    x-data="packstubCalls(@js($config))"
    x-effect="popup && (document.title = windowTitle)"
    x-cloak
    @class(['fi-calls', 'fi-calls-popup' => $popup])
>
    {{-- Read out by screen readers when a call comes in: the card below does not take the focus. --}}
    <div class="fi-calls-sr-only" aria-live="assertive" aria-atomic="true" x-text="announcement"></div>

    {{-- Incoming call --}}
    <div
        x-show="state === 'ringing'"
        x-transition
        class="fi-calls-card fi-calls-incoming"
        role="alertdialog"
        aria-labelledby="fi-calls-incoming-name"
        aria-describedby="fi-calls-incoming-description"
    >
        <div class="fi-calls-person">
            <template x-if="call?.peer?.avatar">
                <x-filament::avatar x-bind:src="call.peer.avatar" size="lg" class="fi-calls-avatar" />
            </template>
            <template x-if="! call?.peer?.avatar">
                <span class="fi-calls-avatar fi-calls-avatar-initials" x-text="initials(call?.peer?.name)"></span>
            </template>
            <div class="fi-calls-person-text">
                <div class="fi-calls-name" id="fi-calls-incoming-name" x-text="call?.peer?.name"></div>
                <div class="fi-calls-muted" id="fi-calls-incoming-description">{{ __('filament-calls::calls.incoming') }}<span x-show="others > 0" x-cloak> · <span x-text="othersLabel"></span></span></div>
            </div>
        </div>
        <div class="fi-calls-actions">
            <x-filament::button color="danger" :icon="Heroicon::OutlinedPhoneXMark" :icon-alias="Icon::DECLINE" label-sr-only :tooltip="__('filament-calls::calls.decline')" x-on:click="decline" class="fi-calls-round">
                {{ __('filament-calls::calls.decline') }}
            </x-filament::button>
            <x-filament::button color="success" :icon="Heroicon::OutlinedPhone" :icon-alias="Icon::ACCEPT" label-sr-only :tooltip="__('filament-calls::calls.accept')" x-on:click="accept" class="fi-calls-round fi-calls-pulse">
                {{ __('filament-calls::calls.accept') }}
            </x-filament::button>
        </div>
    </div>

    {{-- The call lives in the pop-out window: a chip with the essentials --}}
    <div x-show="state === 'elsewhere'" x-transition class="fi-calls-card fi-calls-incoming fi-calls-chip" role="region" aria-labelledby="fi-calls-chip-name">
        <div class="fi-calls-person">
            <template x-if="call?.peer?.avatar">
                <x-filament::avatar x-bind:src="call.peer.avatar" size="lg" class="fi-calls-avatar" />
            </template>
            <template x-if="! call?.peer?.avatar">
                <span class="fi-calls-avatar fi-calls-avatar-initials" x-text="initials(call?.peer?.name)"></span>
            </template>
            <div class="fi-calls-person-text">
                <div class="fi-calls-name" id="fi-calls-chip-name" x-text="names"></div>
                <div class="fi-calls-muted" x-text="status"></div>
            </div>
        </div>
        <div class="fi-calls-actions">
            <x-filament::button color="gray" :icon="Heroicon::OutlinedArrowTopRightOnSquare" :icon-alias="Icon::OPEN_WINDOW" label-sr-only :tooltip="__('filament-calls::calls.open_window')" x-on:click="focusWindow" class="fi-calls-round">
                {{ __('filament-calls::calls.open_window') }}
            </x-filament::button>
            <x-filament::button color="danger" :icon="Heroicon::OutlinedPhoneXMark" :icon-alias="Icon::HANG_UP" label-sr-only :tooltip="__('filament-calls::calls.hang_up')" x-on:click="hangUp" class="fi-calls-round">
                {{ __('filament-calls::calls.hang_up') }}
            </x-filament::button>
        </div>
    </div>

    {{-- The call --}}
    <div x-show="inCall" x-transition class="fi-calls-card fi-calls-window" role="region" aria-labelledby="fi-calls-window-name">
        <div class="fi-calls-header">
            <div class="fi-calls-name" id="fi-calls-window-name" x-text="names"></div>
            <div class="fi-calls-muted" x-text="status"></div>
        </div>

        {{-- One tile per other participant; a 1-on-1 call is a grid of one. --}}
        {{-- A shared screen takes the stage (the spotlight), the cameras shrink into a strip on the
             side that leaves the screen more room: the top of a tall stage, the inline end of a wide
             one (data-strip="right": the right, or the left in a right-to-left panel). --}}
        <div class="fi-calls-stage" x-ref="stage" :data-spotlight="spotlight ?? null" :data-strip="spotlight ? (peers.length > 1 ? strip : 'none') : null">
            <div class="fi-calls-grid" :data-count="peers.length">
                <template x-for="peer in peers" :key="peer.id">
                    <div class="fi-calls-tile" :data-peer-id="peer.id" :data-peer-status="peer.status" :data-role="spotlight ? (peer.id === spotlight ? 'spotlight' : 'strip') : null">
                        <video autoplay playsinline class="fi-calls-remote" x-effect="attach($el, peer.stream)" :class="{ 'fi-calls-hidden': ! showRemote(peer) }"></video>
                        <div class="fi-calls-placeholder" x-show="! showRemote(peer)">
                            <template x-if="peer.avatar">
                                <x-filament::avatar x-bind:src="peer.avatar" size="fi-calls-avatar fi-calls-avatar-lg" />
                            </template>
                            <template x-if="! peer.avatar">
                                <span class="fi-calls-avatar fi-calls-avatar-lg fi-calls-avatar-initials" x-text="initials(peer.name)"></span>
                            </template>
                        </div>

                        <div class="fi-calls-tile-name" x-show="peers.length > 1 || peer.status !== 'joined'">
                            <span x-text="peer.name"></span>
                            <span class="fi-calls-tile-status" x-show="peer.status === 'ringing'" x-cloak>{{ __('filament-calls::calls.calling') }}</span>
                            <span class="fi-calls-tile-status" x-show="peer.status === 'joined' && ! peer.connected" x-cloak>{{ __('filament-calls::calls.connecting') }}</span>
                        </div>

                        {{-- What this person told us: muted, camera off, sharing --}}
                        <div class="fi-calls-badges">
                            <span class="fi-calls-badge" x-show="peer.muted" x-cloak data-peer="muted">
                                <x-filament::icon :icon="$micOff" :size="IconSize::Small" :alias="Icon::MUTED" class="fi-calls-badge-icon" />
                                {{ __('filament-calls::calls.peer_muted') }}
                            </span>
                            <span class="fi-calls-badge" x-show="peer.sharing" x-cloak data-peer="sharing">
                                <x-filament::icon :icon="Heroicon::ComputerDesktop" :size="IconSize::Small" :alias="Icon::SHARING" class="fi-calls-badge-icon" />
                                {{ __('filament-calls::calls.peer_sharing') }}
                            </span>
                            <span class="fi-calls-badge" x-show="peer.cameraOff && ! peer.sharing" x-cloak data-peer="camera-off">
                                <x-filament::icon :icon="Heroicon::VideoCameraSlash" :size="IconSize::Small" :alias="Icon::CAMERA_OFF_BADGE" class="fi-calls-badge-icon" />
                                {{ __('filament-calls::calls.peer_camera_off') }}
                            </span>
                        </div>
                    </div>
                </template>
            </div>
            {{-- My own picture, and my own mute where I can see it. --}}
            <div class="fi-calls-self" data-self-tile>
                <div class="fi-calls-self-placeholder" x-show="selfPlaceholder" x-cloak data-self="camera-off">
                    @if ($myAvatar)
                        <x-filament::avatar :src="$myAvatar" alt="" size="fi-calls-avatar fi-calls-avatar-sm" />
                    @else
                        <span class="fi-calls-avatar fi-calls-avatar-sm fi-calls-avatar-initials" x-text="initials(@js($myName))"></span>
                    @endif
                </div>
                <video x-ref="local" autoplay playsinline muted class="fi-calls-local" :class="{ 'fi-calls-mirror': ! sharing, 'fi-calls-hidden': selfPlaceholder }"></video>
                <span class="fi-calls-badge fi-calls-self-badge" x-show="muted" x-cloak data-self="muted" title="{{ __('filament-calls::calls.you_muted') }}">
                    <x-filament::icon :icon="$micOff" :size="IconSize::Small" :alias="Icon::MUTED" class="fi-calls-badge-icon" />
                    <span class="fi-calls-self-badge-text">{{ __('filament-calls::calls.you_muted') }}</span>
                </span>
            </div>
        </div>

        {{-- Toggles are Filament's own pressed buttons (aria-pressed), two per toggle, one per
             state, so each has its own icon and label; x-show switches between them. Off is grey,
             on stands out: a muted mic or a stopped camera turns red, a shared screen primary. --}}
        <div class="fi-calls-controls">
            <x-filament::button color="gray" :icon="Heroicon::OutlinedMicrophone" :icon-alias="Icon::MUTE" label-sr-only :tooltip="__('filament-calls::calls.mute')" data-calls-toggle="mute" aria-pressed="false" x-show="! muted" x-on:click="toggleMute" class="fi-calls-round">
                {{ __('filament-calls::calls.mute') }}
            </x-filament::button>
            <x-filament::button color="danger" :icon="$micOff" :icon-alias="Icon::UNMUTE" label-sr-only :tooltip="__('filament-calls::calls.unmute')" data-calls-toggle="mute" aria-pressed="true" x-show="muted" x-cloak x-on:click="toggleMute" class="fi-calls-round">
                {{ __('filament-calls::calls.unmute') }}
            </x-filament::button>

            <x-filament::button color="gray" :icon="Heroicon::OutlinedVideoCamera" :icon-alias="Icon::CAMERA_OFF" label-sr-only :tooltip="__('filament-calls::calls.camera_off')" data-calls-toggle="camera" aria-pressed="false" x-show="! cameraOff" x-on:click="toggleCamera" class="fi-calls-round">
                {{ __('filament-calls::calls.camera_off') }}
            </x-filament::button>
            <x-filament::button color="danger" :icon="Heroicon::OutlinedVideoCameraSlash" :icon-alias="Icon::CAMERA_ON" label-sr-only :tooltip="__('filament-calls::calls.camera_on')" data-calls-toggle="camera" aria-pressed="true" x-show="cameraOff" x-cloak x-on:click="toggleCamera" class="fi-calls-round">
                {{ __('filament-calls::calls.camera_on') }}
            </x-filament::button>

            <x-filament::button color="gray" :icon="Heroicon::OutlinedComputerDesktop" :icon-alias="Icon::SHARE_SCREEN" label-sr-only :tooltip="__('filament-calls::calls.share_screen')" data-calls-toggle="share" aria-pressed="false" x-show="state === 'active' && ! sharing" x-on:click="toggleShare" class="fi-calls-round">
                {{ __('filament-calls::calls.share_screen') }}
            </x-filament::button>
            <x-filament::button color="primary" :icon="Heroicon::OutlinedComputerDesktop" :icon-alias="Icon::SHARE_SCREEN" label-sr-only :tooltip="__('filament-calls::calls.stop_sharing')" data-calls-toggle="share" aria-pressed="true" x-show="state === 'active' && sharing" x-cloak x-on:click="toggleShare" class="fi-calls-round">
                {{ __('filament-calls::calls.stop_sharing') }}
            </x-filament::button>

            <x-filament::button color="danger" :icon="Heroicon::OutlinedPhoneXMark" :icon-alias="Icon::HANG_UP" label-sr-only :tooltip="__('filament-calls::calls.hang_up')" x-on:click="hangUp" class="fi-calls-round">
                {{ __('filament-calls::calls.hang_up') }}
            </x-filament::button>
        </div>
    </div>

    {{-- Only where the page has no Filament notifications; panel pages and the pop-out use those. --}}
    <div x-show="notice" x-transition class="fi-calls-card fi-calls-notice" x-text="notice" role="status"></div>
</div>
@endpersist
