@php
    use Filament\Facades\Filament;
    use Filament\Support\Facades\FilamentAsset;
    use Filament\Support\Enums\IconSize;
    use Filament\Support\Icons\Heroicon;
    use Packstub\FilamentHoller\FilamentHollerPlugin;
    use Packstub\FilamentHoller\View\FilamentHollerIconAlias as Icon;

    $panel = Filament::getCurrentOrDefaultPanel();
    $plugin = FilamentHollerPlugin::get();
    $popup = request()->routeIs('*.filament-holler.window');

    // No ICE servers in here: the engine asks the `ice` route each time a call starts, so
    // short-lived TURN credentials are fresh and nothing is evaluated on every page load.
    // The transport policy is the same config `Holler::rtcConfiguration()` reads (without its servers).
    $config = [
        'routes' => [
            'poll' => $panel->route('filament-holler.poll'),
            'store' => $panel->route('filament-holler.store'),
            'answer' => $panel->route('filament-holler.answer', ['call' => '__ID__']),
            'decline' => $panel->route('filament-holler.decline', ['call' => '__ID__']),
            'hangup' => $panel->route('filament-holler.hangup', ['call' => '__ID__']),
            'signal' => $panel->route('filament-holler.signal', ['call' => '__ID__']),
            'leave' => $panel->route('filament-holler.leave', ['call' => '__ID__']),
            'invite' => $panel->route('filament-holler.invite', ['call' => '__ID__']),
            'hold' => $panel->route('filament-holler.hold', ['call' => '__ID__']),
            'resume' => $panel->route('filament-holler.resume', ['call' => '__ID__']),
            // No route, no "Add someone" button.
            'people' => config('holler.people_search', true) ? $panel->route('filament-holler.people') : null,
            'ice' => $panel->route('filament-holler.ice'),
        ],
        // Only calls: another package's calls on the same tables (Session Replay's assist) never ring here.
        'kind' => 'call',
        'windowUrl' => $panel->route('filament-holler.window', ['call' => '__ID__', 'callee' => '__USER__']),
        'csrf' => csrf_token(),
        'iceTransportPolicy' => config('holler.ice_transport_policy') === 'relay' ? 'relay' : 'all',
        'video' => $plugin->hasVideo(),
        'popOut' => $plugin->isPopOut(),
        'popup' => $popup,
        'poll' => config('holler.poll'),
        'reconnectTimeout' => (int) config('holler.reconnect_timeout', 15) * 1000,
        'maxParticipants' => $plugin->getMaxParticipants(),
        'devices' => $plugin->hasDeviceSettings(),
        'notifications' => $plugin->hasNotifications(),
        'labels' => __('filament-holler::calls'),
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
@persist('filament-holler.'.$panel->getId())
<div
    x-load
    x-load-src="{{ FilamentAsset::getAlpineComponentSrc('holler', 'packstub/filament-holler') }}"
    x-load-css="[@js(FilamentAsset::getStyleHref('holler', 'packstub/filament-holler'))]"
    x-data="packstubHoller(@js($config))"
    x-effect="popup && (document.title = windowTitle)"
    x-cloak
    @class(['fi-holler', 'fi-holler-popup' => $popup])
>
    {{-- Read out by screen readers when a call comes in: the card below does not take the focus. --}}
    <div class="fi-holler-sr-only" aria-live="assertive" aria-atomic="true" x-text="announcement"></div>

    {{-- Incoming call --}}
    <div
        x-show="state === 'ringing'"
        x-transition
        class="fi-holler-card fi-holler-incoming"
        role="alertdialog"
        aria-labelledby="fi-holler-incoming-name"
        aria-describedby="fi-holler-incoming-description"
    >
        <div class="fi-holler-person">
            <template x-if="call?.peer?.avatar">
                <x-filament::avatar x-bind:src="call.peer.avatar" size="lg" class="fi-holler-avatar" />
            </template>
            <template x-if="! call?.peer?.avatar">
                <span class="fi-holler-avatar fi-holler-avatar-initials" x-text="initials(call?.peer?.name)"></span>
            </template>
            <div class="fi-holler-person-text">
                <div class="fi-holler-name" id="fi-holler-incoming-name" x-text="call?.peer?.name"></div>
                <div class="fi-holler-muted" id="fi-holler-incoming-description">{{ __('filament-holler::calls.incoming') }}<span x-show="others > 0" x-cloak> · <span x-text="othersLabel"></span></span></div>
            </div>
        </div>
        <div class="fi-holler-actions">
            <x-filament::button color="danger" :icon="Heroicon::OutlinedPhoneXMark" :icon-alias="Icon::DECLINE" label-sr-only :tooltip="__('filament-holler::calls.decline')" x-on:click="decline" class="fi-holler-round">
                {{ __('filament-holler::calls.decline') }}
            </x-filament::button>
            <x-filament::button color="success" :icon="Heroicon::OutlinedPhone" :icon-alias="Icon::ACCEPT" label-sr-only :tooltip="__('filament-holler::calls.accept')" x-on:click="accept" class="fi-holler-round fi-holler-pulse">
                {{ __('filament-holler::calls.accept') }}
            </x-filament::button>
        </div>
    </div>

    {{-- Call pressed during a call: what to do with the call I am on --}}
    <div
        x-show="prompt"
        x-cloak
        x-transition
        x-on:keydown.escape.window="prompt && promptCancel()"
        class="fi-holler-card fi-holler-prompt"
        role="dialog"
        aria-labelledby="fi-holler-prompt-title"
        data-holler-prompt
    >
        <div class="fi-holler-prompt-title">
            <div class="fi-holler-name" id="fi-holler-prompt-title">{{ __('filament-holler::calls.on_call_title') }}</div>
            <div class="fi-holler-muted" x-show="prompt?.name" x-text="prompt?.name"></div>
        </div>
        <x-filament::button color="gray" :icon="Heroicon::OutlinedUserPlus" :icon-alias="Icon::ADD_PERSON" x-show="canInvite" x-on:click="promptAdd" data-holler-prompt-option="add" class="fi-holler-option">
            {{ __('filament-holler::calls.add_to_call') }}
        </x-filament::button>
        <x-filament::button color="gray" :icon="Heroicon::OutlinedPause" :icon-alias="Icon::HOLD" x-show="canHold" x-on:click="promptHold" data-holler-prompt-option="hold" class="fi-holler-option">
            {{ __('filament-holler::calls.hold_and_call') }}
        </x-filament::button>
        <x-filament::button color="gray" :icon="Heroicon::OutlinedPhoneXMark" :icon-alias="Icon::HANG_UP" x-on:click="promptEnd" data-holler-prompt-option="end" class="fi-holler-option">
            {{ __('filament-holler::calls.end_and_call') }}
        </x-filament::button>
        <x-filament::link tag="button" color="gray" size="sm" x-on:click="promptCancel" data-holler-prompt-option="cancel" class="fi-holler-option-cancel">
            {{ __('filament-holler::calls.cancel') }}
        </x-filament::link>
    </div>

    {{-- The call I put on hold: who it is with, resume, end --}}
    <div x-show="heldCall" x-cloak x-transition class="fi-holler-card fi-holler-incoming fi-holler-held" role="region" aria-labelledby="fi-holler-held-name" data-holler-held>
        <div class="fi-holler-person">
            <span class="fi-holler-avatar fi-holler-avatar-initials fi-holler-avatar-held">
                <x-filament::icon :icon="Heroicon::OutlinedPause" :alias="Icon::ON_HOLD" />
            </span>
            <div class="fi-holler-person-text">
                <div class="fi-holler-name" id="fi-holler-held-name" x-text="heldNames"></div>
                <div class="fi-holler-muted">{{ __('filament-holler::calls.on_hold') }}</div>
            </div>
        </div>
        <div class="fi-holler-actions">
            <x-filament::button color="success" :icon="Heroicon::OutlinedPlay" :icon-alias="Icon::RESUME" label-sr-only :tooltip="__('filament-holler::calls.resume')" x-on:click="resumeHeld" data-holler-resume class="fi-holler-round">
                {{ __('filament-holler::calls.resume') }}
            </x-filament::button>
            <x-filament::button color="danger" :icon="Heroicon::OutlinedPhoneXMark" :icon-alias="Icon::HANG_UP" label-sr-only :tooltip="__('filament-holler::calls.hang_up')" x-on:click="endHeld" data-holler-end-held class="fi-holler-round">
                {{ __('filament-holler::calls.hang_up') }}
            </x-filament::button>
        </div>
    </div>

    {{-- The call lives in the pop-out window: a chip with the essentials --}}
    <div x-show="state === 'elsewhere'" x-transition class="fi-holler-card fi-holler-incoming fi-holler-chip" role="region" aria-labelledby="fi-holler-chip-name">
        <div class="fi-holler-person">
            <template x-if="call?.peer?.avatar">
                <x-filament::avatar x-bind:src="call.peer.avatar" size="lg" class="fi-holler-avatar" />
            </template>
            <template x-if="! call?.peer?.avatar">
                <span class="fi-holler-avatar fi-holler-avatar-initials" x-text="initials(call?.peer?.name)"></span>
            </template>
            <div class="fi-holler-person-text">
                <div class="fi-holler-name" id="fi-holler-chip-name" x-text="names"></div>
                <div class="fi-holler-muted" x-text="status"></div>
            </div>
        </div>
        <div class="fi-holler-actions">
            <x-filament::button color="gray" :icon="Heroicon::OutlinedArrowTopRightOnSquare" :icon-alias="Icon::OPEN_WINDOW" label-sr-only :tooltip="__('filament-holler::calls.open_window')" x-on:click="focusWindow" class="fi-holler-round">
                {{ __('filament-holler::calls.open_window') }}
            </x-filament::button>
            <x-filament::button color="danger" :icon="Heroicon::OutlinedPhoneXMark" :icon-alias="Icon::HANG_UP" label-sr-only :tooltip="__('filament-holler::calls.hang_up')" x-on:click="hangUp" class="fi-holler-round">
                {{ __('filament-holler::calls.hang_up') }}
            </x-filament::button>
        </div>
    </div>

    {{-- The call --}}
    <div x-show="inCall" x-transition class="fi-holler-card fi-holler-window" role="region" aria-labelledby="fi-holler-window-name">
        <div class="fi-holler-header">
            <div class="fi-holler-name" id="fi-holler-window-name" x-text="names"></div>
            <div class="fi-holler-status" x-show="status" x-text="status"></div>
        </div>

        {{-- One tile per other participant; a 1-on-1 call is a grid of one. --}}
        {{-- A shared screen takes the stage (the spotlight), the cameras shrink into a strip on the
             side that leaves the screen more room: the top of a tall stage, the inline end of a wide
             one (data-strip="right": the right, or the left in a right-to-left panel). --}}
        {{-- The stage, and beside it (over it in a narrow window) the People or Settings panel. --}}
        <div class="fi-holler-body">
        <div class="fi-holler-stage" x-ref="stage" :data-spotlight="spotlight ?? null" :data-strip="spotlight ? (stagePeers.length > 1 ? strip : 'none') : null">
            {{-- Someone added to the call: rung, and on the stage once they answer. --}}
            <div class="fi-holler-ringing" x-show="ringingPeers.length > 0" x-cloak role="status" data-holler-ringing>
                <span class="fi-holler-ringing-dot"></span>
                <span x-text="ringingLabel"></span>
            </div>
            <div class="fi-holler-grid" :data-count="stagePeers.length">
                <template x-for="peer in stagePeers" :key="peer.id">
                    <div class="fi-holler-tile" :data-peer-id="peer.id" :data-peer-status="peer.status" :data-quality="peer.quality" :data-role="spotlight ? (peer.id === spotlight ? 'spotlight' : 'strip') : null">
                        <video autoplay playsinline class="fi-holler-remote" x-effect="attach($el, peer.stream)" :class="{ 'fi-holler-hidden': ! showRemote(peer) }"></video>
                        <div class="fi-holler-placeholder" x-show="! showRemote(peer)">
                            <template x-if="peer.avatar">
                                <x-filament::avatar x-bind:src="peer.avatar" size="fi-holler-avatar fi-holler-avatar-lg" />
                            </template>
                            <template x-if="! peer.avatar">
                                <span class="fi-holler-avatar fi-holler-avatar-lg fi-holler-avatar-initials" x-text="initials(peer.name)"></span>
                            </template>
                        </div>

                        <div class="fi-holler-tile-name" x-show="stagePeers.length > 1 || peer.status !== 'joined'">
                            <span x-text="peer.name"></span>
                            <span class="fi-holler-tile-status" x-show="peer.status === 'ringing'" x-cloak>{{ __('filament-holler::calls.calling') }}</span>
                            <span class="fi-holler-tile-status" x-show="peer.status === 'joined' && ! peer.connected" x-cloak>{{ __('filament-holler::calls.connecting') }}</span>
                            <span class="fi-holler-tile-status" x-show="peer.status === 'on_hold'" x-cloak data-peer="on-hold">{{ __('filament-holler::calls.on_hold') }}</span>
                        </div>

                        {{-- What this person told us: muted, camera off, sharing --}}
                        <div class="fi-holler-badges">
                            <span class="fi-holler-badge fi-holler-badge-icon-only" x-show="peer.muted" x-cloak data-peer="muted" title="{{ __('filament-holler::calls.peer_muted') }}">
                                <x-filament::icon :icon="$micOff" :size="IconSize::Small" :alias="Icon::MUTED" class="fi-holler-badge-icon" />
                                <span class="fi-holler-sr-only">{{ __('filament-holler::calls.peer_muted') }}</span>
                            </span>
                            <span class="fi-holler-badge" x-show="peer.sharing" x-cloak data-peer="sharing">
                                <x-filament::icon :icon="Heroicon::ComputerDesktop" :size="IconSize::Small" :alias="Icon::SHARING" class="fi-holler-badge-icon" />
                                {{ __('filament-holler::calls.peer_sharing') }}
                            </span>
                            <span class="fi-holler-badge" x-show="peer.cameraOff && ! peer.sharing" x-cloak data-peer="camera-off">
                                <x-filament::icon :icon="Heroicon::VideoCameraSlash" :size="IconSize::Small" :alias="Icon::CAMERA_OFF_BADGE" class="fi-holler-badge-icon" />
                                {{ __('filament-holler::calls.peer_camera_off') }}
                            </span>
                            <span class="fi-holler-badge fi-holler-badge-warning" x-show="peer.connected && peer.quality === 'poor'" x-cloak data-peer="quality">
                                <x-filament::icon :icon="Heroicon::Signal" :size="IconSize::Small" :alias="Icon::WEAK_CONNECTION" class="fi-holler-badge-icon" />
                                {{ __('filament-holler::calls.quality_poor') }}
                            </span>
                        </div>
                    </div>
                </template>
            </div>
            {{-- My own picture, and my own mute where I can see it. --}}
            {{-- Drag the corner (or use the arrow keys on it) to make it bigger or smaller; remembered in this browser. --}}
            <div class="fi-holler-self" data-self-tile x-bind:style="selfSize ? { width: (selfSize * 100) + '%' } : {}">
                <div class="fi-holler-self-placeholder" x-show="selfPlaceholder" x-cloak data-self="camera-off">
                    @if ($myAvatar)
                        <x-filament::avatar :src="$myAvatar" alt="" size="fi-holler-avatar fi-holler-avatar-sm" />
                    @else
                        <span class="fi-holler-avatar fi-holler-avatar-sm fi-holler-avatar-initials" x-text="initials(@js($myName))"></span>
                    @endif
                </div>
                <video x-ref="local" autoplay playsinline muted class="fi-holler-local" :class="{ 'fi-holler-mirror': ! sharing, 'fi-holler-hidden': selfPlaceholder }"></video>
                <span class="fi-holler-badge fi-holler-self-badge" x-show="muted" x-cloak data-self="muted" title="{{ __('filament-holler::calls.you_muted') }}">
                    <x-filament::icon :icon="$micOff" :size="IconSize::Small" :alias="Icon::MUTED" class="fi-holler-badge-icon" />
                    <span class="fi-holler-sr-only">{{ __('filament-holler::calls.you_muted') }}</span>
                </span>
                <button type="button" class="fi-holler-self-resize" title="{{ __('filament-holler::calls.resize_self') }}" aria-label="{{ __('filament-holler::calls.resize_self') }}" x-on:pointerdown="startSelfResize($event)" x-on:keydown.arrow-up.prevent="resizeSelf(0.04)" x-on:keydown.arrow-down.prevent="resizeSelf(-0.04)" x-on:keydown.arrow-left.prevent="resizeSelf(0.04)" x-on:keydown.arrow-right.prevent="resizeSelf(-0.04)" data-holler-resize-self>
                    <x-filament::icon :icon="Heroicon::OutlinedArrowsPointingOut" :alias="Icon::RESIZE_SELF" class="fi-holler-self-resize-icon" />
                </button>
            </div>
        </div>

        <aside class="fi-holler-side" x-show="peopleOpen || settingsOpen" x-cloak x-on:keydown.escape.stop="closePanels()" aria-labelledby="fi-holler-side-title" data-holler-side>
            <div class="fi-holler-side-header">
                <div class="fi-holler-name" id="fi-holler-side-title" x-text="peopleOpen ? @js(__('filament-holler::calls.people')) : @js(__('filament-holler::calls.settings'))"></div>
                <x-filament::icon-button color="gray" :icon="Heroicon::OutlinedXMark" :icon-alias="Icon::CLOSE_PANEL" :label="__('filament-holler::calls.close')" x-on:click="closePanels()" data-holler-close-panel />
            </div>

        {{-- The microphone, the camera and the speaker; a choice applies at once and is remembered in this browser. --}}
        <div class="fi-holler-settings" id="fi-holler-settings" x-show="settingsOpen" x-cloak>
            @foreach (['microphone' => 'microphones', 'camera' => 'cameras', 'speaker' => 'speakers'] as $kind => $list)
                <label class="fi-holler-field" x-show="config.devices && devices.{{ $list }}.length > 0{{ $kind === 'speaker' ? ' && canPickSpeaker' : '' }}" x-cloak>
                    <span class="fi-holler-field-label">{{ __('filament-holler::calls.'.$kind) }}</span>
                    <x-filament::input.wrapper>
                        {{-- The value is set once the options are drawn: an option added later does not select itself. --}}
                        <x-filament::input.select data-holler-device="{{ $kind }}" x-on:change="setDevice('{{ $kind }}', $event.target.value)" x-effect="const value = chosen('{{ $kind }}'); $nextTick(() => { $el.value = value })">
                            <option value="">{{ __('filament-holler::calls.default_device') }}</option>
                            <template x-for="(device, index) in devices.{{ $list }}" :key="device.id">
                                <option :value="device.id" x-text="device.label || (@js(__('filament-holler::calls.'.$kind)) + ' ' + (index + 1))"></option>
                            </template>
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>
            @endforeach
            <x-filament::link tag="button" size="sm" x-show="canNotify" x-cloak x-on:click="enableNotifications" data-holler-notify class="fi-holler-notify">
                {{ __('filament-holler::calls.notify') }}
            </x-filament::link>
        </div>

        {{-- Who is on the call, then a search box to ring someone into it. --}}
        <div class="fi-holler-people" id="fi-holler-people" x-show="peopleOpen" x-cloak x-on:keydown.escape.stop="closePanels()">
            <div class="fi-holler-section-title">{{ __('filament-holler::calls.in_call') }}</div>
            <ul class="fi-holler-members" data-holler-members>
                <li class="fi-holler-person fi-holler-member">
                    @if ($myAvatar)
                        <x-filament::avatar :src="$myAvatar" alt="" size="fi-holler-avatar fi-holler-avatar-sm" />
                    @else
                        <span class="fi-holler-avatar fi-holler-avatar-sm fi-holler-avatar-initials" x-text="initials(@js($myName))"></span>
                    @endif
                    <span class="fi-holler-member-text">
                        <span class="fi-holler-name">{{ $myName }}</span>
                        <span class="fi-holler-muted">{{ __('filament-holler::calls.you') }}</span>
                    </span>
                    <span class="fi-holler-member-icon" x-show="muted" x-cloak title="{{ __('filament-holler::calls.you_muted') }}">
                        <x-filament::icon :icon="$micOff" :size="IconSize::Small" :alias="Icon::MUTED" />
                    </span>
                </li>
                <template x-for="peer in peers" :key="peer.id">
                    <li class="fi-holler-person fi-holler-member" :data-member="peer.id">
                        <template x-if="peer.avatar">
                            <x-filament::avatar x-bind:src="peer.avatar" size="fi-holler-avatar fi-holler-avatar-sm" />
                        </template>
                        <template x-if="! peer.avatar">
                            <span class="fi-holler-avatar fi-holler-avatar-sm fi-holler-avatar-initials" x-text="initials(peer.name)"></span>
                        </template>
                        <span class="fi-holler-member-text">
                            <span class="fi-holler-name" x-text="peer.name"></span>
                            <span class="fi-holler-muted" x-show="peer.status === 'ringing'" x-cloak>{{ __('filament-holler::calls.calling') }}</span>
                            <span class="fi-holler-muted" x-show="peer.status === 'joined' && ! peer.connected" x-cloak>{{ __('filament-holler::calls.connecting') }}</span>
                            <span class="fi-holler-muted" x-show="peer.status === 'on_hold'" x-cloak>{{ __('filament-holler::calls.on_hold') }}</span>
                        </span>
                        <span class="fi-holler-member-icon" x-show="peer.muted" x-cloak title="{{ __('filament-holler::calls.peer_muted') }}">
                            <x-filament::icon :icon="$micOff" :size="IconSize::Small" :alias="Icon::MUTED" />
                        </span>
                    </li>
                </template>
            </ul>

            <div class="fi-holler-invite" x-show="canAdd" x-cloak>
                <div class="fi-holler-section-title">{{ __('filament-holler::calls.add_person') }}</div>
                <x-filament::input.wrapper :prefix-icon="Heroicon::OutlinedMagnifyingGlass" :prefix-icon-alias="Icon::SEARCH">
                    <x-filament::input type="search" autocomplete="off" x-ref="peopleSearch" x-on:input.debounce.200ms="loadPeople($event.target.value)" :placeholder="__('filament-holler::calls.search_people')" :aria-label="__('filament-holler::calls.search_people')" data-holler-people-search />
                </x-filament::input.wrapper>
                <div class="fi-holler-people-list">
                    <template x-for="person in people" :key="person.id">
                        <button type="button" class="fi-holler-person fi-holler-person-option" x-on:click="pick(person)" :data-holler-add="person.id">
                            <template x-if="person.avatar">
                                <x-filament::avatar x-bind:src="person.avatar" size="fi-holler-avatar fi-holler-avatar-sm" />
                            </template>
                            <template x-if="! person.avatar">
                                <span class="fi-holler-avatar fi-holler-avatar-sm fi-holler-avatar-initials" x-text="initials(person.name)"></span>
                            </template>
                            <span class="fi-holler-name" x-text="person.name"></span>
                            <span class="fi-holler-person-call" aria-hidden="true">
                                <x-filament::icon :icon="Heroicon::OutlinedPhone" :size="IconSize::Small" :alias="Icon::ACCEPT" />
                            </span>
                        </button>
                    </template>
                </div>
                <div class="fi-holler-muted" x-show="peopleLoaded && people.length === 0" x-cloak data-holler-no-people>{{ __('filament-holler::calls.no_people') }}</div>
            </div>
        </div>
        </aside>
        </div>

        {{-- Toggles are Filament's own pressed buttons (aria-pressed), two per toggle, one per
             state, so each has its own icon and label; x-show switches between them. Off is grey,
             on stands out: a muted mic or a stopped camera turns red, a shared screen primary. --}}
        <div class="fi-holler-controls">
            <x-filament::button color="gray" :icon="Heroicon::OutlinedMicrophone" :icon-alias="Icon::MUTE" label-sr-only :tooltip="__('filament-holler::calls.mute')" data-holler-toggle="mute" aria-pressed="false" x-show="! muted" x-on:click="toggleMute" class="fi-holler-round">
                {{ __('filament-holler::calls.mute') }}
            </x-filament::button>
            <x-filament::button color="danger" :icon="$micOff" :icon-alias="Icon::UNMUTE" label-sr-only :tooltip="__('filament-holler::calls.unmute')" data-holler-toggle="mute" aria-pressed="true" x-show="muted" x-cloak x-on:click="toggleMute" class="fi-holler-round">
                {{ __('filament-holler::calls.unmute') }}
            </x-filament::button>

            <x-filament::button color="gray" :icon="Heroicon::OutlinedVideoCamera" :icon-alias="Icon::CAMERA_OFF" label-sr-only :tooltip="__('filament-holler::calls.camera_off')" data-holler-toggle="camera" aria-pressed="false" x-show="! cameraOff" x-on:click="toggleCamera" class="fi-holler-round">
                {{ __('filament-holler::calls.camera_off') }}
            </x-filament::button>
            <x-filament::button color="danger" :icon="Heroicon::OutlinedVideoCameraSlash" :icon-alias="Icon::CAMERA_ON" label-sr-only :tooltip="__('filament-holler::calls.camera_on')" data-holler-toggle="camera" aria-pressed="true" x-show="cameraOff" x-cloak x-on:click="toggleCamera" class="fi-holler-round">
                {{ __('filament-holler::calls.camera_on') }}
            </x-filament::button>

            <x-filament::button color="gray" :icon="Heroicon::OutlinedComputerDesktop" :icon-alias="Icon::SHARE_SCREEN" label-sr-only :tooltip="__('filament-holler::calls.share_screen')" data-holler-toggle="share" aria-pressed="false" x-show="state === 'active' && ! sharing" x-on:click="toggleShare" class="fi-holler-round">
                {{ __('filament-holler::calls.share_screen') }}
            </x-filament::button>
            <x-filament::button color="primary" :icon="Heroicon::OutlinedComputerDesktop" :icon-alias="Icon::SHARE_SCREEN" label-sr-only :tooltip="__('filament-holler::calls.stop_sharing')" data-holler-toggle="share" aria-pressed="true" x-show="state === 'active' && sharing" x-cloak x-on:click="toggleShare" class="fi-holler-round">
                {{ __('filament-holler::calls.stop_sharing') }}
            </x-filament::button>

            <x-filament::button x-bind:class="peopleOpen && 'fi-holler-pressed'" color="gray" :icon="Heroicon::OutlinedUsers" :icon-alias="Icon::PEOPLE" label-sr-only :tooltip="__('filament-holler::calls.people')" data-holler-add-person aria-controls="fi-holler-people" x-bind:aria-expanded="peopleOpen.toString()" x-show="inCall" x-cloak x-on:click="togglePeople" class="fi-holler-round">
                {{ __('filament-holler::calls.people') }}
            </x-filament::button>

            <x-filament::button color="gray" :icon="Heroicon::OutlinedPause" :icon-alias="Icon::HOLD" label-sr-only :tooltip="__('filament-holler::calls.hold')" data-holler-hold x-show="canHold" x-cloak x-on:click="hold" class="fi-holler-round">
                {{ __('filament-holler::calls.hold') }}
            </x-filament::button>

            <x-filament::button color="gray" :icon="Heroicon::OutlinedCog6Tooth" :icon-alias="Icon::SETTINGS" label-sr-only :tooltip="__('filament-holler::calls.settings')" data-holler-settings aria-controls="fi-holler-settings" x-bind:aria-expanded="settingsOpen.toString()" x-bind:class="settingsOpen && 'fi-holler-pressed'" x-show="config.devices || canNotify" x-on:click="toggleSettings" class="fi-holler-round">
                {{ __('filament-holler::calls.settings') }}
            </x-filament::button>

            <x-filament::button color="danger" :icon="Heroicon::OutlinedPhoneXMark" :icon-alias="Icon::HANG_UP" label-sr-only :tooltip="__('filament-holler::calls.hang_up')" x-on:click="hangUp" class="fi-holler-round fi-holler-hang-up">
                {{ __('filament-holler::calls.hang_up') }}
            </x-filament::button>
        </div>
    </div>

    {{-- Only where the page has no Filament notifications; panel pages and the pop-out use those. --}}
    <div x-show="notice" x-transition class="fi-holler-card fi-holler-notice" x-text="notice" role="status"></div>
</div>
@endpersist
