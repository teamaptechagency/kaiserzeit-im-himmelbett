<?php

namespace App\Support;

/**
 * The validation from api/content.js, port for port.
 *
 * This is not defensive tidying that could be dropped. Style values are
 * interpolated straight into a stylesheet in the browser, so a saved value
 * like "red;} body{display:none" would become CSS for every later viewer;
 * pinning each field to an exact shape is what stops that. Notes are the one
 * place a client types free-form content that is stored and replayed to
 * others, so their shape is fixed here rather than trusted from the browser.
 *
 * Lengths use mb_substr where the JS used String.slice. The JS counted UTF-16
 * code units; this counts characters. The copy is largely German, so the
 * difference only ever cuts later, never mid-character.
 */
class ContentSanitizer
{
    public const MAX_KEYS    = 40;
    public const MAX_TEXT    = 5000;
    public const MAX_NOTE    = 2000;
    public const MAX_NOTES   = 500;
    public const MAX_REPLIES = 60;

    public const FONT_SETS   = ['original', 'modern', 'classic'];
    public const RADIUS_KEYS = ['radiusButton', 'radiusCard', 'radiusField'];

    /** Slot ids come from the templates and from background keys. */
    public static function validSlot(mixed $slot): bool
    {
        return is_string($slot) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-]{0,80}$/', $slot) === 1;
    }

    /** A section's background: colour, photo, overlay and its strength. */
    public static function style(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $out = [];

        if (isset($value['color']) && is_string($value['color']) && self::isHex($value['color'])) {
            $out['color'] = trim($value['color']);
        }

        if (isset($value['image']) && is_string($value['image'])) {
            $url = trim($value['image']);
            /* Uploads only: an absolute https URL, or a path inside the site. */
            if ($url === ''
                || preg_match('#^https://[\w.\-]+/[\w./%\-]*(\?[\w=&.%\-]*)?$#i', $url) === 1
                || preg_match('#^[\w./\-]+$#', $url) === 1) {
                $out['image'] = $url;
            }
        }

        if (isset($value['overlay']) && in_array($value['overlay'], ['dark', 'light', 'custom'], true)) {
            $out['overlay'] = $value['overlay'];
        }

        if (isset($value['text']) && in_array($value['text'], ['light', 'dark'], true)) {
            $out['text'] = $value['text'];
        }

        if (isset($value['overlayColor']) && is_string($value['overlayColor']) && self::isHex($value['overlayColor'])) {
            $out['overlayColor'] = trim($value['overlayColor']);
        }

        if (array_key_exists('strength', $value) && $value['strength'] !== null) {
            $out['strength'] = self::clamp(self::num($value['strength']), 0, 0.95);
        }

        if (array_key_exists('photo', $value) && $value['photo'] !== null) {
            $out['photo'] = self::clamp(self::num($value['photo']), 0, 1);
        }

        return $out === [] ? null : $out;
    }

    /**
     * How a photo sits in its slot: whether it fills or fits, how far it is
     * zoomed, and which edge is kept when it is cropped.
     */
    public static function slot(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $out = [];

        if (isset($value['fit']) && in_array($value['fit'], ['cover', 'contain'], true)) {
            $out['fit'] = $value['fit'];
        }

        if (isset($value['position']) && in_array($value['position'], ['top', 'center', 'bottom'], true)) {
            $out['position'] = $value['position'];
        }

        if (array_key_exists('zoom', $value) && $value['zoom'] !== null) {
            $zoom = self::num($value['zoom']);
            $out['zoom'] = self::clamp($zoom === 0.0 ? 1.0 : $zoom, 1, 3);
        }

        return $out === [] ? null : $out;
    }

    /** A pinned comment. Returns null when there is nothing to store. */
    public static function note(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $text = is_string($value['text'] ?? null) ? mb_substr($value['text'], 0, self::MAX_NOTE) : '';
        if (trim($text) === '') {
            return null;
        }

        $replies = [];
        if (is_array($value['replies'] ?? null)) {
            foreach (array_slice(array_values($value['replies']), 0, self::MAX_REPLIES) as $reply) {
                if (! is_array($reply)) {
                    continue;
                }
                $body = is_string($reply['text'] ?? null) ? mb_substr($reply['text'], 0, self::MAX_NOTE) : '';
                if (trim($body) === '') {
                    continue;
                }
                $replies[] = [
                    'author' => is_string($reply['author'] ?? null) ? mb_substr($reply['author'], 0, 80) : '',
                    'text'   => $body,
                    'at'     => (int) self::num($reply['at'] ?? 0),
                ];
            }
        }

        return [
            'page'     => is_string($value['page'] ?? null) ? mb_substr($value['page'], 0, 120) : '',
            'el'       => is_string($value['el'] ?? null) ? mb_substr($value['el'], 0, 40) : 'root',
            'x'        => self::clamp(self::num($value['x'] ?? 0), 0, 1),
            'y'        => self::clamp(self::num($value['y'] ?? 0), 0, 1),
            'author'   => is_string($value['author'] ?? null) ? mb_substr($value['author'], 0, 80) : '',
            'text'     => $text,
            'at'       => (int) self::num($value['at'] ?? 0),
            'resolved' => (bool) ($value['resolved'] ?? false),
            'replies'  => $replies,
        ];
    }

    private static function isHex(string $value): bool
    {
        return preg_match('/^#[0-9a-f]{3,8}$/i', trim($value)) === 1;
    }

    /** JS `Number(x) || 0`: anything that is not a number becomes zero. */
    private static function num(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private static function clamp(float $value, float $min, float $max): float
    {
        return min($max, max($min, $value));
    }
}
