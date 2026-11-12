# Changelog

All notable changes to `packstub/filament-holler` are documented in this file.

## Unreleased

First release of Filament Holler: audio, video and screen-share calls between the users of a Filament panel, 1-on-1 or in groups of up to four, peer to peer over WebRTC. Works with Filament 4.13.3+ or 5.8.3+ and Laravel 12.69+ or 13.30+, built on `packstub/holler` and `@packstub/holler`.

- Register `FilamentHollerPlugin` and a call widget appears on every page for signed-in users
- A `CallAction` button to ring someone, or add them to the call you are on
- `canCall()` decides who may call whom, on the server; with tenancy, by default only people who share a tenant, and the panel's email verification and two-factor rules apply
- Drawn with Filament's own components, in the panel's theme, light or dark, usable with the keyboard and a screen reader, in English, German, Romanian and Russian
- Calls open in a pop-out window by default, so you can keep using the panel, and survive a reload
- STUN and TURN per panel, with TURN credentials that expire per call; voice-only panels with `video(false)`
- People choose their microphone, camera and speaker from the call's settings, a call ringing in a background tab shows in the tab title and as a system notification, and a weak connection is marked on the person's picture
