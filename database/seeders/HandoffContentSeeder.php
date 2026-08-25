<?php

namespace Database\Seeders;

use App\Models\Amenity;
use App\Models\Apartment;
use App\Models\Location;
use App\Models\Page;
use App\Models\Section;
use App\Models\Setting;
use App\Models\Testimonial;
use App\Models\Translation;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Loads the site's own content into the database.
 *
 * The design handoff kept everything — German and English — inside each
 * .dc.html page as two JavaScript objects the browser read: CONTENT for the
 * copy and APARTMENTS for the apartments themselves. Those were lifted out
 * verbatim into database/content/*.json (see the extract scripts beside them)
 * and are read here rather than retyped, so what the CMS holds is exactly
 * what the client signed off.
 *
 * Everything is written with updateOrCreate keyed on a stable string, so
 * re-running this repairs missing rows without flattening edits made since.
 * Re-running it does not restore something an editor deleted on purpose,
 * which is the behaviour you want from a seeder that ships with the app.
 */
class HandoffContentSeeder extends Seeder
{
    private array $content;
    private array $apartments;

    public function run(): void
    {
        $this->content    = $this->read('handoff-content.json');
        $this->apartments = $this->read('handoff-apartments.json')['Apartment.dc.html'] ?? [];

        $this->seedSettings();
        $this->seedTranslations();
        $this->seedLocations();
        $this->seedApartments();
        $this->seedAmenities();
        $this->seedTestimonials();
        $this->seedPages();

        $this->command?->info('Handoff content loaded.');
    }

    private function read(string $file): array
    {
        $path = database_path('content/' . $file);

        if (! is_file($path)) {
            throw new RuntimeException("database/content/{$file} is missing. Run the extract script beside it.");
        }

        return json_decode((string) file_get_contents($path), true) ?? [];
    }

    /**
     * One page's extracted content.
     *
     * Indexed directly rather than through data_get: the keys are filenames
     * like "Home.dc.html", and data_get treats every dot as a level, so it
     * would look for a "Home" holding a "dc" holding an "html" and silently
     * find nothing.
     */
    private function page(string $file): array
    {
        return $this->content[$file] ?? [];
    }

    /** One value out of a page, in one language. */
    private function at(string $file, string $locale, string $path): mixed
    {
        return data_get($this->page($file)[$locale] ?? [], $path);
    }

    /** Both languages of one value, as the translatable columns want it. */
    private function both(string $page, string $path): array
    {
        $de = $this->at($page, 'de', $path);

        return [
            'de' => $de,
            'en' => $this->at($page, 'en', $path) ?? $de,
        ];
    }

    /* ------------------------------------------------------------ settings */

    private function seedSettings(): void
    {
        $footer = fn (string $key) => $this->both('Home.dc.html', "footer.{$key}");

        $values = [
            'site_name'      => ['KAISERZEIT im Himmelbett', 'general'],
            'tagline'        => [$footer('tagline'), 'general'],
            'footer_rights'  => [$footer('rights'), 'general'],
            'footer_legal'   => [$footer('legal'), 'general'],
            'contact_email'  => ['info@kaiserzeit-im-himmelbett.de', 'contact'],
            'contact_phone'  => ['', 'contact'],
            'whatsapp'       => ['', 'contact'],
            /* Read straight into the JSON-LD block and the sitemap. */
            'business_type'  => ['LodgingBusiness', 'seo'],
            'default_locale' => ['de', 'general'],
        ];

        foreach ($values as $key => [$value, $group]) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value, 'group' => $group]);
        }
    }

    /* -------------------------------------------------------- translations */

    /**
     * Every interface string, flattened to group.key.
     *
     * The handoff repeated nav and footer on all seven pages. They are
     * identical, so the first page that carries a group wins and the rest are
     * skipped — one row per string, not seven.
     */
    private function seedTranslations(): void
    {
        $groups = [
            'nav', 'page', 'form', 'auth', 'payment', 'summary',
            'trust', 'layoutToggle', 'footer', 'direct', 'addresses',
            'hero', 'intro', 'map', 'story', 'hosts',
        ];

        $seen = [];

        foreach ($this->content as $file => $data) {
            $prefix = $this->pageKey($file);

            foreach ($groups as $group) {
                $de = data_get($data, "de.{$group}");
                if (! is_array($de)) {
                    continue;
                }

                /* nav and footer are the same everywhere; page and form are
                   not, so those are namespaced by the page they belong to. */
                $shared = in_array($group, ['nav', 'footer'], true);
                $name   = $shared ? $group : $prefix . '.' . $group;

                if ($shared && isset($seen[$group])) {
                    continue;
                }
                $seen[$group] = true;

                foreach ($de as $key => $value) {
                    /* Nested objects and lists are content, not labels — they
                       become sections or entities further down. */
                    if (is_array($value) && ! array_is_list($value)) {
                        continue;
                    }

                    Translation::updateOrCreate(
                        ['group' => $name, 'key' => (string) $key],
                        ['values' => [
                            'de' => $value,
                            'en' => data_get($data, "en.{$group}.{$key}") ?? $value,
                        ]],
                    );
                }
            }
        }
    }

    /* ----------------------------------------------------------- locations */

    private function seedLocations(): void
    {
        $items = $this->at('Home.dc.html', 'de', 'groups.items') ?? [];

        foreach ($items as $index => $item) {
            $key = str_replace('group-', '', $item['slotId'] ?? 'location-' . $index);
            $en  = $this->at('Home.dc.html', 'en', "groups.items.{$index}") ?? [];

            [$street, $rest] = array_pad(explode(',', (string) ($item['address'] ?? ''), 2), 2, '');
            [$postcode, $city] = array_pad(preg_split('/\s+/', trim($rest), 2) ?: [], 2, '');

            Location::updateOrCreate(['key' => $key], [
                'slug'         => \Illuminate\Support\Str::slug($key),
                'street'       => trim($street),
                'postcode'     => trim($postcode),
                'city'         => trim($city) ?: 'Leipzig',
                'sort'         => $index,
                'is_published' => true,
                'name'         => ['de' => $item['name'] ?? '', 'en' => $en['name'] ?? ($item['name'] ?? '')],
                'description'  => ['de' => $item['desc'] ?? '', 'en' => $en['desc'] ?? ($item['desc'] ?? '')],
            ]);
        }
    }

    /* ---------------------------------------------------------- apartments */

    private function seedApartments(): void
    {
        $blurbs = collect($this->at('Apartments.dc.html', 'de', 'individualApts') ?? [])
            ->keyBy('key');
        $blurbsEn = collect($this->at('Apartments.dc.html', 'en', 'individualApts') ?? [])
            ->keyBy('key');

        $locations = Location::pluck('id', 'key');
        $sort = 0;

        foreach ($this->apartments as $key => $apartment) {
            /* The handoff wrote the address on the apartment; the CMS keeps
               it on the location and points at it, so changing "Blumenstraße"
               once changes it in all three apartments that share it. */
            $address    = (string) ($apartment['address'] ?? '');
            $locationId = str_contains($address, 'Helmholtz')
                ? ($locations['kanal'] ?? null)
                : ($locations['blumenstrasse'] ?? null);

            Apartment::updateOrCreate(['key' => $key], [
                'slug'            => \Illuminate\Support\Str::slug($key),
                'location_id'     => $locationId,
                /* The handoff wrote whole euros; money is stored in cents. */
                'price_per_night' => (int) round(((float) ($apartment['rate'] ?? 0)) * 100),
                'max_guests'      => 2,
                'sort'            => $sort++,
                'is_published'    => true,
                'name'            => $apartment['name'] ?? ['de' => $key, 'en' => $key],
                'brief'           => [
                    'de' => data_get($blurbs, "{$key}.desc", ''),
                    'en' => data_get($blurbsEn, "{$key}.desc", data_get($blurbs, "{$key}.desc", '')),
                ],
                'description'     => $apartment['desc'] ?? null,
                'features'        => $apartment['features'] ?? null,
            ]);
        }
    }

    /* ----------------------------------------------------- amenities, etc. */

    private function seedAmenities(): void
    {
        $items = $this->at('Home.dc.html', 'de', 'amenities.items') ?? [];

        foreach ($items as $index => $item) {
            $en = $this->at('Home.dc.html', 'en', "amenities.items.{$index}") ?? [];

            Amenity::updateOrCreate(['key' => $item['icon'] ?? 'amenity-' . $index], [
                'icon'        => $item['icon'] ?? '',
                'sort'        => $index,
                'is_visible'  => true,
                'title'       => ['de' => $item['title'] ?? '', 'en' => $en['title'] ?? ($item['title'] ?? '')],
                'description' => ['de' => $item['desc'] ?? '', 'en' => $en['desc'] ?? ($item['desc'] ?? '')],
            ]);
        }
    }

    private function seedTestimonials(): void
    {
        $items = $this->at('Home.dc.html', 'de', 'testimonials.items') ?? [];

        foreach ($items as $index => $item) {
            $en = $this->at('Home.dc.html', 'en', "testimonials.items.{$index}") ?? [];

            Testimonial::updateOrCreate(
                ['author' => $item['name'] ?? 'Gast ' . $index],
                [
                    'sort'       => $index,
                    'is_visible' => true,
                    'quote'      => ['de' => $item['text'] ?? '', 'en' => $en['text'] ?? ($item['text'] ?? '')],
                ],
            );
        }
    }

    /* ------------------------------------------------------ pages, blocks */

    /**
     * The seven pages, their SEO defaults, and the blocks Home is made of.
     *
     * meta_description falls back to the section copy the page already has
     * rather than being left empty: a page with no description gets whatever
     * Google decides to quote, and that is rarely the sentence you would have
     * chosen.
     */
    private function seedPages(): void
    {
        $pages = [
            'home'       => ['', 'home', 1.0, 'weekly'],
            'apartments' => ['apartments', 'apartments', 0.9, 'weekly'],
            'apartment'  => ['apartment', 'apartment', 0.8, 'weekly'],
            'booking'    => ['booking', 'booking', 0.9, 'monthly'],
            'about'      => ['about', 'about', 0.6, 'monthly'],
            'contact'    => ['contact', 'contact', 0.6, 'monthly'],
            'profile'    => ['profile', 'profile', 0.1, 'yearly'],
        ];

        $titles = [
            'home'       => ['de' => 'KAISERZEIT im Himmelbett — Apartments in Leipzig', 'en' => 'KAISERZEIT im Himmelbett — Apartments in Leipzig'],
            'apartments' => ['de' => 'Unsere Apartments', 'en' => 'Our Apartments'],
            'apartment'  => ['de' => 'Apartment', 'en' => 'Apartment'],
            'booking'    => ['de' => 'Buchung', 'en' => 'Booking'],
            'about'      => ['de' => 'Über uns', 'en' => 'About us'],
            'contact'    => ['de' => 'Kontakt', 'en' => 'Contact'],
            'profile'    => ['de' => 'Mein Konto', 'en' => 'My account'],
        ];

        $descriptions = [
            'home'       => $this->both('Home.dc.html', 'intro.body'),
            'apartments' => $this->both('Apartments.dc.html', 'page.subtitle'),
            'booking'    => $this->both('Booking.dc.html', 'page.subtitle'),
            'about'      => $this->both('About Us.dc.html', 'story.body'),
            'contact'    => $this->both('Contact.dc.html', 'page.subtitle'),
        ];

        foreach ($pages as $key => [$slug, $template, $priority, $frequency]) {
            $page = Page::updateOrCreate(['key' => $key], [
                'slug'              => $slug,
                'template'          => $template,
                'is_published'      => true,
                'title'             => $titles[$key],
                'meta_title'        => $titles[$key],
                'meta_description'  => $this->trim($descriptions[$key] ?? null),
                /* Nobody should find a guest's account page in a search result. */
                'noindex'           => $key === 'profile',
                'sitemap_priority'  => $priority,
                'sitemap_frequency' => $frequency,
            ]);

            if ($key === 'home') {
                $this->seedHomeSections($page);
            }
        }
    }

    /** Meta descriptions are cut off around 160 characters, so cut them here. */
    private function trim(?array $values): ?array
    {
        if (! is_array($values)) {
            return null;
        }

        return array_map(
            fn ($value) => is_string($value) ? \Illuminate\Support\Str::limit(strip_tags($value), 155) : $value,
            $values,
        );
    }

    private function seedHomeSections(Page $page): void
    {
        /* section key => [type, which CONTENT keys are its own fields] */
        $blocks = [
            'hero'         => ['hero',    ['eyebrow', 'titleLine1', 'titleLine2', 'subtitle', 'ctaPrimary', 'ctaSecondary', 'scroll']],
            'intro'        => ['rich',    ['label', 'title', 'body', 'cta']],
            'groups'       => ['locations', ['label', 'title']],
            'map'          => ['map',     ['label', 'title', 'subtitle']],
            'amenities'    => ['amenities', ['title', 'subtitle']],
            'hosts'        => ['hosts',   ['label', 'title', 'quote', 'body1', 'body2', 'cta', 'replyNote']],
            'testimonials' => ['testimonials', ['label', 'title']],
        ];

        $sort = 0;

        foreach ($blocks as $key => [$type, $fields]) {
            $content = [];

            foreach (['de', 'en'] as $locale) {
                foreach ($fields as $field) {
                    $value = $this->at('Home.dc.html', $locale, "{$key}.{$field}");
                    if ($value !== null) {
                        $content[$locale][$field] = $value;
                    }
                }
            }

            Section::updateOrCreate(
                ['page_id' => $page->id, 'key' => $key],
                ['type' => $type, 'sort' => $sort++, 'is_visible' => true, 'content' => $content],
            );
        }
    }

    /** 'Home.dc.html' => 'home', 'About Us.dc.html' => 'about-us'. */
    private function pageKey(string $file): string
    {
        return \Illuminate\Support\Str::slug(str_replace('.dc.html', '', $file));
    }
}
