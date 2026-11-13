<?php

namespace Packstub\FilamentHoller\Http;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Packstub\Holler\Facades\Holler;
use Packstub\Holler\Models\Call;

/**
 * The pop-out: a page with nothing on it but the call widget, opened in its own
 * browser window so the call outlives whatever the main tab does. `?callee=`
 * rings someone from here, `?call=` answers (or continues) that call.
 */
class WindowController
{
    public function __invoke(Request $request): View
    {
        $callId = (int) $request->query('call', 0);

        if ($callId) {
            $call = Call::query()->findOrFail($callId);

            $me = (string) Holler::currentUserId();

            abort_unless($call->involves($me) && Holler::inScope($call, $me), 403);
        }

        return view('filament-holler::window');
    }
}
