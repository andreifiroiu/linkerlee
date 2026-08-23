<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeleteUserDataRequest;
use App\Services\DeleteUserDataService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class DeleteUserDataController extends Controller
{
    public function delete(DeleteUserDataRequest $request, DeleteUserDataService $deleteUserDataService): RedirectResponse
    {
        $deleteUserDataService->deleteUserData($request->validated('deleteOptions'), Auth::user());

        return back();
    }
}
