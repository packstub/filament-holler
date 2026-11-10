# Changelog

All notable changes to `packstub/filament-calls` are documented in this file.

## Unreleased

First release of Filament Calls: audio, video and screen-share calls between the users of a Filament panel, 1-on-1 or in groups of up to four, peer to peer over WebRTC. Works with Filament 4.13.3+ or 5.8.3+ and Laravel 12.69+ or 13.30+, built on `packstub/calls` and `@packstub/calls`.

- Register `FilamentCallsPlugin` and a call widget appears on every page for signed-in users
- A `CallAction` button to ring someone, or add them to the call you are on
- `canCall()` decides who may call whom; with tenancy, by default only people who share a tenant
- Drawn with Filament's own components, in the panel's theme, light or dark
- Calls open in a pop-out window by default, so you can keep using the panel, and survive a reload
- STUN and TURN per panel, voice-only panels with `video(false)`
