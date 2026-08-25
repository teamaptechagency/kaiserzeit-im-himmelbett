<?php

namespace App\Http\Controllers;

use App\Models\ContentEntry;
use App\Models\ImageSlot;
use App\Models\Note;
use App\Support\Installation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * GET /api/state — everything the client has changed.
 *
 * Public on purpose: the preview has to render the client's photos and copy
 * for anyone holding the link. Only writing needs a signed-in admin.
 *
 * `canEdit` is the authoritative answer to "should this page be editable".
 * The runtime builds its chrome from a cookie before this call has returned,
 * because it has to decide synchronously; this is what corrects it when that
 * cookie is stale or forged.
 *
 * `csrf` is here because signing out is a form POST from a page that is
 * served byte for byte from the design handoff and so has nowhere to carry a
 * token of its own. Handing it out publicly costs nothing: a CSRF token is
 * not a secret kept from the person holding the session, and no other origin
 * can read this response.
 *
 * Always answers 200, even when the site is not set up. The pages treat an
 * empty state as "use the design's own assets and copy", which is the right
 * thing to show while a database is still being connected — an error status
 * would leave the preview blank instead.
 */
class StateController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $empty = [
            'images' => (object) [], 'texts' => (object) [], 'styles' => (object) [],
            'notes'  => (object) [], 'slots' => (object) [], 'theme'  => (object) [],
        ];

        /* Not "is somebody signed in": guests hold accounts too, and a guest
           must see the same read-only preview a stranger does. Must agree
           with RequireEditor, or the editor appears and every save is
           refused. */
        $user    = Auth::user();
        $canEdit = $user !== null && $user->is_active && $user->isStaff();

        $problem = Installation::problem();
        if ($problem !== null) {
            return $this->json(['ok' => false, 'canEdit' => $canEdit] + $empty + ['error' => $problem]);
        }

        try {
            $images = [];
            foreach (ImageSlot::all() as $image) {
                $images[$image->slot] = $image->url();
            }

            $notes = [];
            foreach (Note::all() as $note) {
                $notes[$note->note_key] = $note->toWire();
            }

            return $this->json([
                'ok'      => true,
                'canEdit' => $canEdit,
                'csrf'    => csrf_token(),
                'images' => (object) $images,
                'texts'  => (object) ContentEntry::group('texts'),
                'styles' => (object) ContentEntry::group('styles'),
                'notes'  => (object) $notes,
                'slots'  => (object) ContentEntry::group('slots'),
                'theme'  => (object) self::theme(),
            ]);
        } catch (Throwable $error) {
            report($error);
            return $this->json(['ok' => false, 'canEdit' => $canEdit] + $empty + ['error' => $error->getMessage()]);
        }
    }

    /**
     * Corner rounding is a number in the browser and a string in the column,
     * so it is cast back on the way out. Anything non-numeric — the font set
     * — is passed through as it was stored.
     */
    public static function theme(): array
    {
        $theme = [];
        foreach (ContentEntry::group('theme') as $key => $value) {
            $theme[$key] = is_numeric($value) ? (float) $value : $value;
        }
        return $theme;
    }

    private function json(array $body): JsonResponse
    {
        return response()->json($body)
            ->header('content-type', 'application/json; charset=utf-8')
            ->header('cache-control', 'no-store');
    }
}
