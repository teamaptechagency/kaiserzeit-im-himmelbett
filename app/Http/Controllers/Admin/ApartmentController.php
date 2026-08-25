<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Apartment;
use App\Models\Location;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The flats.
 *
 * Every text field is edited in both languages side by side, because a flat
 * described in German and not in English is worse than one described in
 * neither: the English page then shows German copy to someone who cannot read
 * it, and nobody notices until a guest says so.
 *
 * Rates are typed in euros and stored in cents. That conversion happens here,
 * once, rather than being remembered at each of the places a rate is read.
 */
class ApartmentController extends Controller
{
    public function index()
    {
        return view('admin.apartments.index', [
            'apartments' => Apartment::with('location')
                ->withCount(['bookings' => fn ($q) => $q->whereIn('status', ['requested', 'confirmed'])])
                ->orderBy('sort')
                ->get(),
        ]);
    }

    public function create()
    {
        return view('admin.apartments.form', [
            'apartment' => new Apartment(['is_published' => false, 'max_guests' => 2, 'bedrooms' => 1]),
            'locations' => Location::orderBy('sort')->get(),
            'activity'  => collect(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $apartment = new Apartment();
        $this->fill($apartment, $data);
        $apartment->save();

        ActivityLogger::log('created', $apartment, 'Added the flat ' . $apartment->t('name', 'de'));

        return redirect(admin_url('/apartments'))->with('status', 'Flat created.');
    }

    public function edit(Apartment $apartment)
    {
        return view('admin.apartments.form', [
            'apartment' => $apartment,
            'locations' => Location::orderBy('sort')->get(),
            'activity'  => ActivityLog::for(Apartment::class, $apartment->id)
                ->latest('created_at')->limit(15)->get(),
        ]);
    }

    public function update(Request $request, Apartment $apartment): RedirectResponse
    {
        $data = $this->validated($request, $apartment);

        $this->fill($apartment, $data);

        ActivityLogger::saved($apartment, 'updated', 'Updated the flat ' . $apartment->t('name', 'de'));

        return redirect(admin_url('/apartments'))->with('status', 'Flat updated.');
    }

    /**
     * Deleting a flat with bookings against it is refused.
     *
     * The booking's foreign key would go null and a guest would be left with
     * a reservation for nothing in particular. Unpublishing is what somebody
     * wanting to take a flat off the site actually means, so the message says
     * so.
     */
    public function destroy(Apartment $apartment): RedirectResponse
    {
        $live = $apartment->bookings()->whereIn('status', ['requested', 'confirmed'])->count();

        if ($live > 0) {
            return back()->withErrors([
                'apartment' => "This flat has {$live} live booking(s). Unpublish it instead, or deal with those first.",
            ]);
        }

        $label = $apartment->t('name', 'de');
        ActivityLogger::log('deleted', $apartment, "Deleted the flat {$label}");
        $apartment->delete();

        return redirect(admin_url('/apartments'))->with('status', "Deleted {$label}.");
    }

    /** Drag-to-reorder posts the whole order at once. */
    public function reorder(Request $request): RedirectResponse
    {
        $data = $request->validate(['order' => ['required', 'array'], 'order.*' => ['integer']]);

        foreach (array_values($data['order']) as $position => $id) {
            Apartment::where('id', $id)->update(['sort' => $position]);
        }

        ActivityLogger::log('reordered', null, 'Reordered the flats');

        return back()->with('status', 'Order saved.');
    }

    /* -------------------------------------------------------------- pieces */

    private function validated(Request $request, ?Apartment $apartment = null): array
    {
        return $request->validate([
            'key'             => ['required', 'string', 'max:60', 'regex:/^[a-z0-9-]+$/', Rule::unique('apartments')->ignore($apartment?->id)],
            'slug'            => ['nullable', 'string', 'max:120', Rule::unique('apartments')->ignore($apartment?->id)],
            'location_id'     => ['nullable', 'exists:locations,id'],
            'price_euros'     => ['required', 'numeric', 'min:0', 'max:100000'],
            'max_guests'      => ['required', 'integer', 'min:1', 'max:20'],
            'bedrooms'        => ['required', 'integer', 'min:0', 'max:20'],
            'size_sqm'        => ['nullable', 'integer', 'min:1', 'max:2000'],
            'sort'            => ['nullable', 'integer', 'min:0'],
            'is_published'    => ['nullable', 'boolean'],

            'name'            => ['required', 'array'],
            'name.*'          => ['nullable', 'string', 'max:190'],
            'brief'           => ['nullable', 'array'],
            'brief.*'         => ['nullable', 'string', 'max:500'],
            'description'     => ['nullable', 'array'],
            'description.*'   => ['nullable', 'string', 'max:5000'],
            /* One feature per line in the form; split below. */
            'features'        => ['nullable', 'array'],
            'features.*'      => ['nullable', 'string', 'max:2000'],
        ], [
            'key.regex' => 'The key is used in URLs: lower case letters, numbers and hyphens only.',
            'name.required' => 'A name in at least one language is needed.',
        ]);
    }

    private function fill(Apartment $apartment, array $data): void
    {
        $apartment->fill([
            'key'             => $data['key'],
            'slug'            => $data['slug'] ?: Str::slug($data['key']),
            'location_id'     => $data['location_id'] ?? null,
            /* Euros in, cents stored. round() before the cast, or 129.10
               arrives as 12909. */
            'price_per_night' => (int) round(((float) $data['price_euros']) * 100),
            'max_guests'      => (int) $data['max_guests'],
            'bedrooms'        => (int) $data['bedrooms'],
            'size_sqm'        => $data['size_sqm'] ?? null,
            'sort'            => (int) ($data['sort'] ?? 0),
            'is_published'    => (bool) ($data['is_published'] ?? false),
            'name'            => array_filter($data['name'], fn ($v) => $v !== null),
            'brief'           => array_filter($data['brief'] ?? [], fn ($v) => $v !== null),
            'description'     => array_filter($data['description'] ?? [], fn ($v) => $v !== null),
            'features'        => $this->splitFeatures($data['features'] ?? []),
        ]);
    }

    /** The form takes one feature per line; the column holds a list. */
    private function splitFeatures(array $byLocale): array
    {
        $out = [];

        foreach ($byLocale as $locale => $text) {
            $lines = preg_split('/\r\n|\r|\n/', (string) $text) ?: [];
            $lines = array_values(array_filter(array_map('trim', $lines), fn ($line) => $line !== ''));

            if ($lines !== []) {
                $out[$locale] = $lines;
            }
        }

        return $out;
    }
}
