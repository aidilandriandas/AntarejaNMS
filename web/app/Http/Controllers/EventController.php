<?php

namespace App\Http\Controllers;

use App\Models\DeviceEvent;
use App\Models\Site;
use Illuminate\Http\Request;

class EventController extends Controller
{
    public function index(Request $request)
    {
        $q = DeviceEvent::with('device.site')->orderByDesc('at');
        if ($k = $request->query('kind')) {
            $q->where('kind', $k);
        }
        if ($s = $request->query('site')) {
            $q->whereHas('device', fn ($d) => $d->where('site_id', $s));
        }
        if ($term = $request->query('q')) {
            $q->whereHas('device', fn ($d) => $d->search($term));
        }

        return view('events.index', ['events' => $q->paginate(50)->withQueryString(), 'sites' => Site::orderBy('code')->get()]);
    }
}
