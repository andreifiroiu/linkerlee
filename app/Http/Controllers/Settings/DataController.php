<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\Link;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DataController extends Controller
{
    /**
     * The export and import screen.
     *
     * The counts are here so the page can say what an export would actually
     * contain before the user commits to downloading it.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/data', [
            /**
             * The export is a streamed download, which Inertia's own router
             * cannot receive — the browser has to submit a real form. That form
             * needs a token of its own, since it never goes through Inertia.
             */
            'csrfToken' => csrf_token(),
            'counts' => [
                'links' => Link::where('user_id', $user->id)->count(),
                'archivedLinks' => Link::onlyTrashed()->where('user_id', $user->id)->count(),
                'groups' => Group::where('user_id', $user->id)->count(),
            ],
        ]);
    }
}
