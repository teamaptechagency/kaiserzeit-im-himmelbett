<?php

namespace App\Http\Controllers;

use App\Models\ContentEntry;
use App\Models\Note;
use App\Support\ContentSanitizer as Clean;
use App\Support\Installation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\NoteRejected;
use Throwable;

/**
 * POST /api/content — merge copy, background, framing, comment and theme
 * overrides.
 *
 * Body: { texts?, styles?, notes?, slots?, theme? } where each is
 * { key: value|null }. A null value deletes that entry and restores the
 * design's original.
 *
 * The Node build read, merged and rewrote one JSON document, so two people
 * editing at the same moment could drop one change. Here each key is its own
 * row inside a transaction, so concurrent edits to different keys both land.
 */
class ContentController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $patch = json_decode($request->getContent(), true);

        if (! is_array($patch)) {
            return $this->json(['error' => 'body must be JSON'], 400);
        }

        $pick = fn (string $name) => is_array($patch[$name] ?? null) ? $patch[$name] : [];

        $texts  = $pick('texts');
        $styles = $pick('styles');
        $notes  = $pick('notes');
        $slots  = $pick('slots');
        $theme  = $pick('theme');

        $total = count($texts) + count($styles) + count($notes) + count($slots) + count($theme);

        /* An empty patch is the sign-in check. It has already passed the key
           middleware, so answering here lets the login button verify a key
           without a fourth route and without a pointless write — and it is
           answered before the storage check, so a key can still be verified
           while the database is being connected. */
        if ($total === 0) {
            return $this->json(['ok' => true, 'verified' => true, 'storage' => Installation::problem()]);
        }

        $problem = Installation::problem();
        if ($problem !== null) {
            return $this->json(['error' => $problem], 503);
        }

        if ($total > Clean::MAX_KEYS) {
            return $this->json(['error' => 'too many keys in one request'], 400);
        }

        foreach ($texts as $value) {
            if ($value !== null && (! is_string($value) || mb_strlen($value) > Clean::MAX_TEXT)) {
                return $this->json(['error' => 'text values must be strings under 5000 characters'], 400);
            }
        }

        try {
            $rejected = null;

            DB::transaction(function () use ($texts, $styles, $notes, $slots, $theme, &$rejected) {
                foreach ($texts as $key => $value) {
                    ContentEntry::put('texts', (string) $key, $value);
                }

                foreach ($styles as $key => $value) {
                    /* An empty object means "back to the design default", so
                       the key is dropped rather than stored as a rule with no
                       declarations. */
                    ContentEntry::put('styles', (string) $key, Clean::style($value));
                }

                foreach ($slots as $key => $value) {
                    ContentEntry::put('slots', (string) $key, Clean::slot($value));
                }

                foreach ($notes as $key => $value) {
                    $key = (string) $key;

                    if ($value === null) {
                        Note::where('note_key', $key)->delete();
                        continue;
                    }

                    $note = Clean::note($value);
                    if ($note === null) {
                        $rejected = 'a note needs text';
                        throw new NoteRejected();
                    }

                    $exists = Note::where('note_key', $key)->exists();
                    if (! $exists && Note::count() >= Clean::MAX_NOTES) {
                        $rejected = 'note limit reached';
                        throw new NoteRejected();
                    }

                    Note::updateOrCreate(['note_key' => $key], [
                        'page'     => $note['page'],
                        'el'       => $note['el'],
                        'x'        => $note['x'],
                        'y'        => $note['y'],
                        'author'   => $note['author'],
                        'body'     => $note['text'],
                        'noted_at' => $note['at'],
                        'resolved' => $note['resolved'],
                        'replies'  => $note['replies'],
                    ]);
                }

                if (array_key_exists('fonts', $theme)) {
                    /* "original" is the design's own, so it is stored as the
                       absence of an override rather than as a value. */
                    $fonts = $theme['fonts'];
                    ContentEntry::put(
                        'theme',
                        'fonts',
                        in_array($fonts, Clean::FONT_SETS, true) && $fonts !== 'original' ? $fonts : null,
                    );
                }

                foreach (Clean::RADIUS_KEYS as $key) {
                    if (! array_key_exists($key, $theme)) {
                        continue;
                    }
                    $value = $theme[$key];
                    if ($value === null || $value === '') {
                        ContentEntry::put('theme', $key, null);
                        continue;
                    }
                    ContentEntry::put('theme', $key, (string) min(40, max(0, is_numeric($value) ? (float) $value : 0)));
                }
            });
        } catch (NoteRejected) {
            return $this->json(['error' => $rejected], 400);
        } catch (Throwable $error) {
            report($error);
            return $this->json(['error' => $error->getMessage()], 500);
        }

        $wire = [];
        foreach (Note::all() as $note) {
            $wire[$note->note_key] = $note->toWire();
        }

        return $this->json([
            'ok'     => true,
            'texts'  => (object) ContentEntry::group('texts'),
            'styles' => (object) ContentEntry::group('styles'),
            'notes'  => (object) $wire,
            'slots'  => (object) ContentEntry::group('slots'),
            'theme'  => (object) StateController::theme(),
        ]);
    }

    private function json(array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status)
            ->header('content-type', 'application/json; charset=utf-8')
            ->header('cache-control', 'no-store');
    }
}

