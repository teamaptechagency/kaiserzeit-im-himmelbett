<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Translation;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The interface strings — nav, form labels, the booking flow's wording.
 *
 * Edited a whole group at a time rather than one string per screen. Nobody
 * changes "Buchung" in isolation; they go through the booking flow's wording
 * once, and a form per string would make that thirty page loads.
 *
 * Only strings that actually changed are written, so the audit trail records
 * the two somebody edited rather than the hundred and forty-six they scrolled
 * past.
 */
class TranslationController extends Controller
{
    public function index(Request $request)
    {
        $groups = Translation::query()
            ->select('group')
            ->selectRaw('COUNT(*) total')
            ->groupBy('group')
            ->orderBy('group')
            ->get();

        $group = (string) $request->query('group', $groups->first()->group ?? '');

        $rows = Translation::where('group', $group)->orderBy('key')->get();

        return view('admin.translations.index', [
            'groups'  => $groups,
            'group'   => $group,
            'rows'    => $rows,
            /* Which languages are missing something, per group, so the gaps
               are visible before opening each one. */
            'missing' => $this->missingByGroup(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'group'    => ['required', 'string', 'max:40'],
            'values'   => ['required', 'array'],
            'values.*' => ['array'],
            'values.*.*' => ['nullable', 'string', 'max:2000'],
        ]);

        $changed = [];

        foreach (Translation::where('group', $data['group'])->get() as $row) {
            $submitted = $data['values'][$row->key] ?? null;

            if (! is_array($submitted)) {
                continue;
            }

            $next = array_map(fn ($v) => $v === null ? '' : $v, $submitted);

            if ($row->values !== $next) {
                $changed[$row->key] = ['from' => $row->values, 'to' => $next];
                $row->update(['values' => $next]);
            }
        }

        if ($changed !== []) {
            ActivityLogger::log(
                'translations-updated',
                null,
                'Changed ' . count($changed) . ' string(s) in ' . $data['group'],
                $changed,
            );
        }

        return back()->with('status', $changed === []
            ? 'Nothing changed.'
            : count($changed) . ' string(s) saved.');
    }

    /** @return array<string, int> group => how many values are empty */
    private function missingByGroup(): array
    {
        $locales = array_keys(config('kaiserzeit.locales'));
        $out     = [];

        foreach (Translation::all() as $row) {
            foreach ($locales as $locale) {
                if (blank($row->values[$locale] ?? null)) {
                    $out[$row->group] = ($out[$row->group] ?? 0) + 1;
                }
            }
        }

        return $out;
    }
}
