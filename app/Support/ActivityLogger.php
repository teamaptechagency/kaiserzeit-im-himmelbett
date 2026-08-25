<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Writes the audit trail.
 *
 * Only the fields that actually differed are stored, before and after. Keeping
 * the whole model on every edit would turn a year of ordinary work into a
 * table bigger than the site it describes, and would bury the one field
 * somebody is trying to find.
 *
 * Password hashes, two-factor secrets and recovery codes are never written
 * here, whatever changed: an audit trail that leaks credentials is worse than
 * no audit trail.
 */
class ActivityLogger
{
    private const NEVER_LOG = [
        'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes',
    ];

    public static function log(
        string $action,
        ?Model $subject = null,
        ?string $description = null,
        array $changes = [],
    ): void {
        $user = Auth::user();

        ActivityLog::create([
            'user_id'       => $user?->id,
            /* The name the account had at the time; the foreign key goes null
               if that person is later deleted. */
            'user_label'    => $user?->name ?? 'system',
            'action'        => $action,
            'subject_type'  => $subject ? $subject::class : null,
            'subject_id'    => $subject?->getKey(),
            'subject_label' => mb_substr(self::label($subject), 0, 190),
            'description'   => $description,
            'changes'       => $changes ?: null,
            'ip_hash'       => request()->ip() ? hash('sha256', request()->ip() . config('app.key')) : null,
            'user_agent'    => mb_substr((string) request()->userAgent(), 0, 255),
            'created_at'    => now(),
        ]);
    }

    /** What changed on a model that has been filled but not yet saved. */
    public static function diff(Model $model): array
    {
        $changes = [];

        foreach ($model->getDirty() as $field => $new) {
            if (in_array($field, self::NEVER_LOG, true)) {
                $changes[$field] = ['from' => '••••', 'to' => '••••'];
                continue;
            }

            $changes[$field] = [
                'from' => self::readable($model->getOriginal($field)),
                'to'   => self::readable($new),
            ];
        }

        return $changes;
    }

    /** Logs a save and what it altered, or nothing when nothing altered. */
    public static function saved(Model $model, string $action, ?string $description = null): void
    {
        $changes = self::diff($model);

        if ($action === 'updated' && $changes === []) {
            return;
        }

        $model->save();

        self::log($action, $model, $description, $changes);
    }

    /** Long values and arrays, cut down to something a table can show. */
    private static function readable(mixed $value): mixed
    {
        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        if (is_string($value) && mb_strlen($value) > 300) {
            return mb_substr($value, 0, 300) . '…';
        }

        return $value;
    }

    private static function label(?Model $subject): string
    {
        if (! $subject) {
            return '';
        }

        foreach (['name', 'title', 'reference', 'email', 'key'] as $field) {
            $value = $subject->getAttribute($field);

            if (is_array($value)) {
                $value = $value[config('app.fallback_locale', 'de')] ?? reset($value);
            }

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return class_basename($subject) . ' #' . $subject->getKey();
    }
}
