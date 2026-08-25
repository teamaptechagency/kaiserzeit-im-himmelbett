<?php

namespace App\Models\Concerns;

/**
 * Columns that hold {"de": "…", "en": "…"} instead of one string.
 *
 * A model lists them in $translatable and reads them through t(). Reading the
 * attribute directly still gives the whole map, which is what the admin forms
 * want — they edit both languages side by side.
 *
 * Falling back rather than returning null matters: a half-translated site
 * should show German where English is missing, not a gap in the middle of a
 * paragraph.
 */
trait HasTranslations
{
    public function initializeHasTranslations(): void
    {
        foreach ($this->translatable ?? [] as $attribute) {
            $this->casts[$attribute] = 'array';
        }
    }

    /** One locale's value, falling back to the site's default. */
    public function t(string $attribute, ?string $locale = null): mixed
    {
        $values = $this->getAttribute($attribute);

        if (! is_array($values)) {
            return $values;
        }

        $locale ??= app()->getLocale();
        $fallback = config('app.fallback_locale', 'de');

        return $values[$locale]
            ?? $values[$fallback]
            ?? (reset($values) ?: null);
    }

    /** Writes one locale without disturbing the others. */
    public function setTranslation(string $attribute, string $locale, mixed $value): static
    {
        $values = $this->getAttribute($attribute);
        $values = is_array($values) ? $values : [];
        $values[$locale] = $value;
        $this->setAttribute($attribute, $values);

        return $this;
    }

    /** True when every configured locale has something in it. */
    public function isFullyTranslated(string $attribute): bool
    {
        $values = $this->getAttribute($attribute);

        foreach (array_keys(config('kaiserzeit.locales', ['de' => 'Deutsch'])) as $locale) {
            if (! is_array($values) || blank($values[$locale] ?? null)) {
                return false;
            }
        }

        return true;
    }
}
