<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One override: a piece of copy, a section's background, a photo's framing,
 * or a theme setting. Which of those it is comes from `group_name`.
 */
class ContentEntry extends Model
{
    public const GROUPS = ['texts', 'styles', 'slots', 'theme'];

    protected $fillable = ['group_name', 'entry_key', 'value'];

    /**
     * Reads one group back as the plain key => value map the browser expects.
     *
     * texts and theme were stored as they came in; styles and slots were
     * stored as JSON and are decoded here. A row whose JSON has been
     * corrupted is skipped rather than returned half-formed — the page then
     * falls back to the design's own value, which is the same thing that
     * happened when content.json was unreadable.
     */
    public static function group(string $group): array
    {
        $decode = in_array($group, ['styles', 'slots'], true);
        $out    = [];

        foreach (static::where('group_name', $group)->get() as $row) {
            if (! $decode) {
                $out[$row->entry_key] = $row->value;
                continue;
            }
            $value = json_decode($row->value, true);
            if (is_array($value)) {
                $out[$row->entry_key] = $value;
            }
        }

        return $out;
    }

    /** Writes one override, or removes it when $value is null. */
    public static function put(string $group, string $key, mixed $value): void
    {
        if ($value === null) {
            static::where('group_name', $group)->where('entry_key', $key)->delete();
            return;
        }

        static::updateOrCreate(
            ['group_name' => $group, 'entry_key' => $key],
            ['value' => is_array($value) ? json_encode($value) : (string) $value],
        );
    }
}
