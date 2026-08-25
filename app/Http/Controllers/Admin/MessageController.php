<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The contact form's inbox. */
class MessageController extends Controller
{
    public function index(Request $request)
    {
        $show = $request->query('show', 'open');

        return view('admin.messages.index', [
            'messages' => ContactMessage::query()
                ->when($show === 'open', fn ($q) => $q->where('is_handled', false))
                ->when($show === 'done', fn ($q) => $q->where('is_handled', true))
                ->latest()
                ->paginate(25)
                ->withQueryString(),
            'show'  => $show,
            'tally' => [
                'open' => ContactMessage::where('is_handled', false)->count(),
                'done' => ContactMessage::where('is_handled', true)->count(),
            ],
        ]);
    }

    public function show(ContactMessage $message)
    {
        /* Opening it is reading it; nobody should have to say so twice. */
        if (! $message->read_at) {
            $message->forceFill(['read_at' => now()])->save();
        }

        return view('admin.messages.show', ['message' => $message]);
    }

    public function update(Request $request, ContactMessage $message): RedirectResponse
    {
        $message->is_handled = $request->boolean('is_handled');
        ActivityLogger::saved($message, 'updated', ($message->is_handled ? 'Closed' : 'Reopened') . " the message from {$message->email}");

        return back()->with('status', $message->is_handled ? 'Marked as handled.' : 'Reopened.');
    }

    public function destroy(ContactMessage $message): RedirectResponse
    {
        $label = $message->email;
        ActivityLogger::log('deleted', $message, "Deleted the message from {$label}");
        $message->delete();

        return redirect(admin_url('/messages'))->with('status', "Deleted the message from {$label}.");
    }
}
