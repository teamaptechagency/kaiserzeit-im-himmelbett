<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Amenity;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The "what you get" blocks on the home page. */
class AmenityController extends Controller
{
    /**
     * The glyphs the design knows how to draw.
     *
     * A free-text icon name would render as nothing at all, and an empty
     * square on the home page is the kind of fault that survives for months
     * because everyone assumes it is deliberate.
     */
    public const ICONS = ['wifi', 'pin', 'home', 'booking', 'key', 'coffee', 'parking', 'bed'];

    public function index()
    {
        return view('admin.amenities.index', ['amenities' => Amenity::orderBy('sort')->get()]);
    }

    public function create()
    {
        return view('admin.amenities.form', ['amenity' => new Amenity(['is_visible' => true, 'icon' => 'home'])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $amenity = new Amenity();
        $this->fill($amenity, $this->validated($request));
        $amenity->save();

        ActivityLogger::log('created', $amenity, 'Added the amenity ' . $amenity->t('title', 'de'));

        return redirect(admin_url('/amenities'))->with('status', 'Amenity created.');
    }

    public function edit(Amenity $amenity)
    {
        return view('admin.amenities.form', ['amenity' => $amenity]);
    }

    public function update(Request $request, Amenity $amenity): RedirectResponse
    {
        $this->fill($amenity, $this->validated($request, $amenity));

        ActivityLogger::saved($amenity, 'updated', 'Updated the amenity ' . $amenity->t('title', 'de'));

        return redirect(admin_url('/amenities'))->with('status', 'Amenity updated.');
    }

    public function destroy(Amenity $amenity): RedirectResponse
    {
        $label = $amenity->t('title', 'de');
        ActivityLogger::log('deleted', $amenity, 'Deleted the amenity ' . $label);
        $amenity->delete();

        return redirect(admin_url('/amenities'))->with('status', 'Deleted ' . $label . '.');
    }

    private function validated(Request $request, ?Amenity $amenity = null): array
    {
        return $request->validate([
            'key'           => ['required', 'string', 'max:60', 'regex:/^[a-z0-9-]+$/', Rule::unique('amenities')->ignore($amenity?->id)],
            'icon'          => ['required', Rule::in(self::ICONS)],
            'sort'          => ['nullable', 'integer', 'min:0'],
            'is_visible'    => ['nullable', 'boolean'],
            'title'         => ['required', 'array'],
            'title.*'       => ['nullable', 'string', 'max:190'],
            'description'   => ['nullable', 'array'],
            'description.*' => ['nullable', 'string', 'max:2000'],
        ], [
            'key.regex' => 'The key is used in URLs: lower case letters, numbers and hyphens only.',
        ]);
    }

    private function fill(Amenity $amenity, array $data): void
    {
        $amenity->fill([
            'key'         => $data['key'],
            'icon'        => $data['icon'],
            'sort'        => (int) ($data['sort'] ?? 0),
            'is_visible'  => (bool) ($data['is_visible'] ?? false),
            'title'       => array_filter($data['title'], fn ($v) => $v !== null),
            'description' => array_filter($data['description'] ?? [], fn ($v) => $v !== null),
        ]);
    }
}
