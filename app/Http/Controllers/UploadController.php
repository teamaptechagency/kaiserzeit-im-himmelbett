<?php

namespace App\Http\Controllers;

use App\Models\ImageSlot;
use App\Support\ContentSanitizer as Clean;
use App\Support\Installation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * POST /api/upload?slot=<id> — replace one photo.
 *
 * The body is the raw image and content-type says which format, exactly as
 * image-slot.js sends it. Not a multipart form: keeping the shape means the
 * editor did not have to change.
 *
 * A photo is stored at slots/<slotId>.<ext>, so the slot id alone identifies
 * it and no filename has to be remembered. Replacing a photo with one in a
 * different format deletes the old file, or a slot would own two.
 */
class UploadController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $problem = Installation::problem();
        if ($problem !== null) {
            return $this->json(['error' => $problem], 503);
        }

        $slot = $request->query('slot');
        if (! Clean::validSlot($slot)) {
            return $this->json(['error' => 'invalid slot id'], 400);
        }

        $type      = trim(explode(';', (string) $request->header('content-type'))[0]);
        $extension = config('kaiserzeit.image_types')[$type] ?? null;
        if ($extension === null) {
            return $this->json(['error' => 'unsupported image type: ' . ($type ?: 'none')], 415);
        }

        $body = $request->getContent();
        $size = strlen($body);

        if ($size === 0) {
            /* An empty body usually means PHP dropped it: post_max_size is
               below the 12 MB the editor allows. Saying so beats "empty
               upload", which reads as the client's fault. */
            $limit = ini_get('post_max_size');
            return $this->json([
                'error' => 'empty upload' . ($limit ? " (post_max_size is {$limit}; it must be at least 12M)" : ''),
            ], 400);
        }

        if ($size > config('kaiserzeit.max_upload_bytes')) {
            return $this->json(['error' => 'image is larger than 12 MB'], 413);
        }

        try {
            $directory = Installation::slotsPath();
            $target    = $directory . DIRECTORY_SEPARATOR . $slot . '.' . $extension;

            if (@file_put_contents($target, $body) === false) {
                return $this->json(['error' => 'could not write to storage/app/public/slots'], 500);
            }

            /* Clear any copy of this slot left behind under another
               extension, so the listing never shows a slot twice. */
            foreach (glob($directory . DIRECTORY_SEPARATOR . $slot . '.*') ?: [] as $existing) {
                if ($existing !== $target) {
                    @unlink($existing);
                }
            }

            $image = ImageSlot::updateOrCreate(
                ['slot' => $slot],
                ['extension' => $extension, 'mime' => $type, 'bytes' => $size],
            );
            /* updateOrCreate leaves updated_at alone when nothing changed —
               a re-upload of the same format and size — and that timestamp is
               the editor's cache buster, so it is bumped explicitly. */
            $image->touch();

            return $this->json(['ok' => true, 'slot' => $slot, 'url' => $image->url()]);
        } catch (Throwable $error) {
            report($error);
            return $this->json(['error' => $error->getMessage()], 500);
        }
    }

    private function json(array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status)
            ->header('content-type', 'application/json; charset=utf-8')
            ->header('cache-control', 'no-store');
    }
}
