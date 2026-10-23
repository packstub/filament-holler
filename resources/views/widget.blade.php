@php
    use Filament\Facades\Filament;
    use Filament\Support\Facades\FilamentAsset;
    use Packstub\Calls\FilamentCallsPlugin;

    $panel = Filament::getCurrentOrDefaultPanel();
    $plugin = FilamentCallsPlugin::get();
    $popup = request()->routeIs('*.filament-calls.window');

    $config = [
        'routes' => [
            'poll' => $panel->route('filament-calls.poll'),
            'store' => $panel->route('filament-calls.store'),
            'answer' => $panel->route('filament-calls.answer', ['call' => '__ID__']),
            'decline' => $panel->route('filament-calls.decline', ['call' => '__ID__']),
            'hangup' => $panel->route('filament-calls.hangup', ['call' => '__ID__']),
            'signal' => $panel->route('filament-calls.signal', ['call' => '__ID__']),
            'leave' => $panel->route('filament-calls.leave', ['call' => '__ID__']),
        ],
        'windowUrl' => $panel->route('filament-calls.window', ['call' => '__ID__', 'callee' => '__USER__']),
        'csrf' => csrf_token(),
        'iceServers' => $plugin->getIceServers(),
        'video' => $plugin->hasVideo(),
        'popOut' => $plugin->isPopOut(),
        'popup' => $popup,
        'autoStart' => $popup ? array_filter([
            'call' => (int) request()->query('call') ?: null,
            'callee' => (string) request()->query('callee') ?: null,
        ]) : null,
        'poll' => config('packstub-calls.poll'),
        'reconnectTimeout' => (int) config('packstub-calls.reconnect_timeout', 15) * 1000,
        'labels' => __('filament-calls::calls'),
    ];
@endphp

{{-- @persist keeps the call alive across wire:navigate page loads in SPA mode. --}}
@persist('filament-calls')
<div
    x-load
    x-load-src="{{ FilamentAsset::getAlpineComponentSrc('calls', 'packstub/filament-calls') }}"
    x-load-css="[@js(FilamentAsset::getStyleHref('calls', 'packstub/filament-calls'))]"
    x-data="packstubCalls(@js($config))"
    x-cloak
    @class(['fi-calls', 'fi-calls-popup' => $popup])
>
    {{-- Incoming call --}}
    <div x-show="state === 'ringing'" x-transition class="fi-calls-card fi-calls-incoming" role="alertdialog">
        <div class="fi-calls-person">
            <template x-if="call?.peer?.avatar">
                <img class="fi-calls-avatar" :src="call.peer.avatar" alt="">
            </template>
            <template x-if="! call?.peer?.avatar">
                <span class="fi-calls-avatar fi-calls-avatar-initials" x-text="initials(call?.peer?.name)"></span>
            </template>
            <div>
                <div class="fi-calls-name" x-text="call?.peer?.name"></div>
                <div class="fi-calls-muted">{{ __('filament-calls::calls.incoming') }}</div>
            </div>
        </div>
        <div class="fi-calls-actions">
            <button type="button" class="fi-calls-btn fi-calls-btn-danger" x-on:click="decline" title="{{ __('filament-calls::calls.decline') }}">
                <x-filament::icon icon="heroicon-o-phone-x-mark" class="fi-calls-icon" />
            </button>
            <button type="button" class="fi-calls-btn fi-calls-btn-success fi-calls-pulse" x-on:click="accept" title="{{ __('filament-calls::calls.accept') }}">
                <x-filament::icon icon="heroicon-o-phone" class="fi-calls-icon" />
            </button>
        </div>
    </div>

    {{-- The call lives in the pop-out window: a chip with the essentials --}}
    <div x-show="state === 'elsewhere'" x-transition class="fi-calls-card fi-calls-incoming fi-calls-chip">
        <div class="fi-calls-person">
            <template x-if="call?.peer?.avatar">
                <img class="fi-calls-avatar" :src="call.peer.avatar" alt="">
            </template>
            <template x-if="! call?.peer?.avatar">
                <span class="fi-calls-avatar fi-calls-avatar-initials" x-text="initials(call?.peer?.name)"></span>
            </template>
            <div>
                <div class="fi-calls-name" x-text="call?.peer?.name"></div>
                <div class="fi-calls-muted" x-text="status"></div>
            </div>
        </div>
        <div class="fi-calls-actions">
            <button type="button" class="fi-calls-btn" x-on:click="focusWindow" title="{{ __('filament-calls::calls.open_window') }}">
                <x-filament::icon icon="heroicon-o-arrow-top-right-on-square" class="fi-calls-icon" />
            </button>
            <button type="button" class="fi-calls-btn fi-calls-btn-danger" x-on:click="hangUp" title="{{ __('filament-calls::calls.hang_up') }}">
                <x-filament::icon icon="heroicon-o-phone-x-mark" class="fi-calls-icon" />
            </button>
        </div>
    </div>

    {{-- The call --}}
    <div x-show="inCall" x-transition class="fi-calls-card fi-calls-window">
        <div class="fi-calls-header">
            <div class="fi-calls-name" x-text="call?.peer?.name"></div>
            <div class="fi-calls-muted" x-text="status"></div>
        </div>

        <div class="fi-calls-stage">
            <video x-ref="remote" autoplay playsinline class="fi-calls-remote" :class="{ 'fi-calls-hidden': ! remoteVideo }"></video>
            <div class="fi-calls-placeholder" x-show="! remoteVideo">
                <template x-if="call?.peer?.avatar">
                    <img class="fi-calls-avatar fi-calls-avatar-lg" :src="call.peer.avatar" alt="">
                </template>
                <template x-if="! call?.peer?.avatar">
                    <span class="fi-calls-avatar fi-calls-avatar-lg fi-calls-avatar-initials" x-text="initials(call?.peer?.name)"></span>
                </template>
            </div>
            <video x-ref="local" autoplay playsinline muted class="fi-calls-local" :class="{ 'fi-calls-mirror': ! sharing, 'fi-calls-hidden': cameraOff && ! sharing }"></video>
        </div>

        <div class="fi-calls-controls">
            <button type="button" class="fi-calls-btn" :class="{ 'fi-calls-btn-off': muted }" x-on:click="toggleMute" :title="muted ? @js(__('filament-calls::calls.unmute')) : @js(__('filament-calls::calls.mute'))">
                <x-filament::icon icon="heroicon-o-microphone" class="fi-calls-icon" />
            </button>
            <button type="button" class="fi-calls-btn" :class="{ 'fi-calls-btn-off': cameraOff }" x-on:click="toggleCamera" :title="cameraOff ? @js(__('filament-calls::calls.camera_on')) : @js(__('filament-calls::calls.camera_off'))">
                <x-filament::icon icon="heroicon-o-video-camera" class="fi-calls-icon" x-show="! cameraOff" />
                <x-filament::icon icon="heroicon-o-video-camera-slash" class="fi-calls-icon" x-show="cameraOff" x-cloak />
            </button>
            <button type="button" class="fi-calls-btn" :class="{ 'fi-calls-btn-on': sharing }" x-on:click="toggleShare" x-show="state === 'active'" :title="sharing ? @js(__('filament-calls::calls.stop_sharing')) : @js(__('filament-calls::calls.share_screen'))">
                <x-filament::icon icon="heroicon-o-computer-desktop" class="fi-calls-icon" />
            </button>
            <button type="button" class="fi-calls-btn fi-calls-btn-danger" x-on:click="hangUp" title="{{ __('filament-calls::calls.hang_up') }}">
                <x-filament::icon icon="heroicon-o-phone-x-mark" class="fi-calls-icon" />
            </button>
        </div>
    </div>

    <div x-show="notice" x-transition class="fi-calls-card fi-calls-notice" x-text="notice" role="status"></div>
</div>
@endpersist
