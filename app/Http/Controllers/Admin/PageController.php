<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pages and what search engines read about them.
 *
 * The list flags a page whose meta description is missing or the wrong
 * length, because that is the field everyone forgets and the one Google
 * silently replaces with whatever sentence it happens to like. Under about
 * 120 characters wastes the space; over about 160 gets cut off mid-word.
 */
class PageController extends Controller
{
    public const DESCRIPTION_MIN = 120;
    public const DESCRIPTION_MAX = 160;

    public function index()
    {
        $pages = Page::orderBy('sort')->orderBy('id')->get();

        return view('admin.pages.index', [
            'pages'   => $pages,
            'notes'   => $pages->mapWithKeys(fn (Page $page) => [$page->id => $this->seoNotes($page)]),
            'baseUrl' => rtrim(config('app.url'), '/'),
        ]);
    }

    public function edit(Page $page)
    {
        return view('admin.pages.form', [
            'page'    => $page,
            'notes'   => $this->seoNotes($page),
            'baseUrl' => rtrim(config('app.url'), '/'),
        ]);
    }

    public function update(Request $request, Page $page): RedirectResponse
    {
        $data = $request->validate([
            'slug'              => ['nullable', 'string', 'max:160', Rule::unique('pages')->ignore($page->id)],
            'is_published'      => ['nullable', 'boolean'],
            'noindex'           => ['nullable', 'boolean'],
            'canonical'         => ['nullable', 'url', 'max:255'],
            'sitemap_priority'  => ['required', 'numeric', 'between:0,1'],
            'sitemap_frequency' => ['required', Rule::in(['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'])],
            'title'             => ['required', 'array'],
            'title.*'           => ['nullable', 'string', 'max:190'],
            'meta_title'        => ['nullable', 'array'],
            'meta_title.*'      => ['nullable', 'string', 'max:190'],
            'meta_description'  => ['nullable', 'array'],
            'meta_description.*' => ['nullable', 'string', 'max:300'],
            'og_title'          => ['nullable', 'array'],
            'og_title.*'        => ['nullable', 'string', 'max:190'],
            'og_description'    => ['nullable', 'array'],
            'og_description.*'  => ['nullable', 'string', 'max:300'],
        ]);

        $clean = fn (string $field) => array_filter($data[$field] ?? [], fn ($v) => $v !== null && $v !== '');

        $page->fill([
            'slug'              => $data['slug'] ?? '',
            'is_published'      => (bool) ($data['is_published'] ?? false),
            'noindex'           => (bool) ($data['noindex'] ?? false),
            'canonical'         => $data['canonical'] ?? null,
            'sitemap_priority'  => (float) $data['sitemap_priority'],
            'sitemap_frequency' => $data['sitemap_frequency'],
            'title'             => $clean('title'),
            'meta_title'        => $clean('meta_title'),
            'meta_description'  => $clean('meta_description'),
            'og_title'          => $clean('og_title'),
            'og_description'    => $clean('og_description'),
        ]);

        ActivityLogger::saved($page, 'updated', 'Updated the page ' . $page->key);

        return redirect(admin_url('/pages'))->with('status', 'Page saved.');
    }

    /**
     * What is wrong with this page, in the eyes of a search engine.
     *
     * @return array<int, string>
     */
    private function seoNotes(Page $page): array
    {
        $notes = [];

        foreach (array_keys(config('kaiserzeit.locales')) as $locale) {
            $language = strtoupper($locale);

            if (blank($page->t('meta_title', $locale)) && blank($page->t('title', $locale))) {
                $notes[] = $language . ': no title at all';
            }

            $description = (string) $page->t('meta_description', $locale);

            if (blank($description)) {
                if (! $page->noindex) {
                    $notes[] = $language . ': no meta description — Google will invent one';
                }
                continue;
            }

            $length = mb_strlen($description);

            if ($length < self::DESCRIPTION_MIN) {
                $notes[] = $language . ': description is short (' . $length . ' chars)';
            } elseif ($length > self::DESCRIPTION_MAX) {
                $notes[] = $language . ': description will be cut off (' . $length . ' chars)';
            }
        }

        return $notes;
    }
}
