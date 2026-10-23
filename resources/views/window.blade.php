{{-- The pop-out window: Filament's bare page shell (its head, scripts and render hooks) with
     nothing in the body. The widget arrives through the BODY_END hook like on every page and
     sees from the route that it is the pop-out. --}}
<x-filament-panels::layout.base>
    <div class="fi-calls-popup-page"></div>
</x-filament-panels::layout.base>
