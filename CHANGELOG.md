# Changelog

All notable changes to `packstub/filament-holler` are documented in this file.

## 1.0.0 — 2026-11-18

First release of Filament Holler: audio, video and screen-share calls between the users of a Filament panel, 1-on-1 or in groups of up to four, peer to peer over WebRTC. Works with Filament 4.13.3+ or 5.8.3+ and Laravel 12.69+ or 13.30+, built on `packstub/holler` and `@packstub/holler`.

- Register `FilamentHollerPlugin` and a call widget appears on every page; `CallAction` rings someone from any table or page
- `canCall()` decides who may call whom, on the server; with tenancy, by default only people who share a tenant
- A People panel beside the video lists who is on the call and adds someone from a search, who joins the stage once they answer; Settings picks the microphone, camera and speaker
- Put a call on hold to ring someone else, then swap back with Resume
- Calls open in a pop-out window by default and survive a reload; in SPA mode they stay on the page while you navigate
- A dark call window like any call app, drawn with Filament's own components (the cards and the side panel in the panel's theme), usable with the keyboard and a screen reader, in English, German, Romanian and Russian
