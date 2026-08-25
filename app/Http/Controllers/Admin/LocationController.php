<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The addresses.
 *
 * A location owns its street and postcode; the flats point at it. That is why
 * correcting a house number is one edit here rather than three identical ones
 * across the flats that share it.
 */
class LocationController extends Controller
{
    public function index()
    {
        return view('admin.locations.index', [
            'locations' => Location::withCount('apartments')->orderBy('sort')->get(),
        ]);
    }

    public function create()
    {
        return view('admin.locations.form', [
            'location' => new Location(['is_published' => true, 'city' => 'Leipzig']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $location = new Location();
        $this->fill($location, $this->validated($request));
        $location->save();

        ActivityLogger::log('created', $location, 'Added the location ' . $location->t('name', 'de'));

        return redirect(admin_url('/locations'))->with('status', 'Location created.');
    }

    public function edit(Location $location)
    {
        return view('admin.locations.form', ['location' => $location]);
    }

    public function update(Request $request, Location $location): RedirectResponse
    {
        $this->fill($location, $this->validated($request, $location));

        ActivityLogger::saved($location, 'updated', 'Updated the location ' . $location->t('name', 'de'));

        return redirect(admin_url('/locations'))->with('status', 'Location updated.');
    }

    /**
     * A location with flats in it cannot be deleted.
     *
     * Their foreign key would go null and those flats would quietly lose their
     * address — the sort of damage nobody notices until a guest turns up at
     * the wrong building.
     */
    public function destroy(Location $location): RedirectResponse
    {
        if ($location->apartments()->exists()) {
            return back()->withErrors([
                'location' => 'Flats still point at this address. Move them first.',
            ]);
        }

        $label = $location->t('name', 'de');
        ActivityLogger::log('deleted', $location, 'Deleted the location ' . $label);
        $location->delete();

        return redirect(admin_url('/locations'))->with('status', 'Deleted ' . $label . '.');
    }

    private function validated(Request $request, ?Location $location = null): array
    {
        return $request->validate([
            'key'           => ['required', 'string', 'max:60', 'regex:/^[a-z0-9-]+$/', Rule::unique('locations')->ignore($location?->id)],
            'street'        => ['required', 'string', 'max:160'],
            'postcode'      => ['required', 'string', 'max:16'],
            'city'          => ['required', 'string', 'max:80'],
            'latitude'      => ['nullable', 'numeric', 'between:-90,90'],
            'longitude'     => ['nullable', 'numeric', 'between:-180,180'],
            'sort'          => ['nullable', 'integer', 'min:0'],
            'is_published'  => ['nullable', 'boolean'],
            'name'          => ['required', 'array'],
            'name.*'        => ['nullable', 'string', 'max:190'],
            'description'   => ['nullable', 'array'],
            'description.*' => ['nullable', 'string', 'max:2000'],
        ], [
            'key.regex' => 'The key is used in URLs: lower case letters, numbers and hyphens only.',
        ]);
    }

    private function fill(Location $location, array $data): void
    {
        $location->fill([
            'key'          => $data['key'],
            'slug'         => Str::slug($data['key']),
            'street'       => $data['street'],
            'postcode'     => $data['postcode'],
            'city'         => $data['city'],
            'latitude'     => $data['latitude'] ?? null,
            'longitude'    => $data['longitude'] ?? null,
            'sort'         => (int) ($data['sort'] ?? 0),
            'is_published' => (bool) ($data['is_published'] ?? false),
            'name'         => array_filter($data['name'], fn ($v) => $v !== null),
            'description'  => array_filter($data['description'] ?? [], fn ($v) => $v !== null),
        ]);
    }
}
