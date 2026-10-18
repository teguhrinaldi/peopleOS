<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\NotificationBell;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The signed-in user's own notifications. Another user's id answers 404; nothing here takes a user id from the client. */
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationBell $bell) {}

    public function index(Request $request): View
    {
        return view('admin.notifications', ['notifications' => $this->bell->paginate($request->user())]);
    }

    /** Mark read, then open the related page when there is one the user may see. */
    public function read(Request $request, int $notification): RedirectResponse
    {
        $row = $this->bell->read($request->user(), $notification) ?? abort(404);
        $url = $this->bell->url($row, $request->user());

        return $url ? redirect($url) : back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $count = $this->bell->readAll($request->user());

        return back()->with('success', $count > 0 ? "{$count} notification(s) marked as read." : 'You are all caught up.');
    }
}
