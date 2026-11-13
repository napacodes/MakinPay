<?php

namespace App\Support;

use Illuminate\Translation\Translator;

class SafeTranslator extends Translator
{
    /**
     * Prevent sentence-style JSON keys from leaking a conflicting PHP group array.
     *
     * @param mixed $key
     * @param mixed $locale
     * @param mixed $fallback
     */
    public function get($key, array $replace = [], $locale = null, $fallback = true): string|array
    {
        $line = parent::get($key, $replace, $locale, $fallback);

        if (! is_array($line) || ! is_string($key) || str_contains($key, '.') || str_contains($key, '::')) {
            return $line;
        }

        $requestedLocale = is_string($locale) ? $locale : $this->getLocale();
        $fallbackLocale  = $this->getFallback();

        if (is_string($fallbackLocale) && $fallbackLocale !== $requestedLocale) {
            $fallbackLine = parent::get($key, $replace, $fallbackLocale, false);

            if (is_string($fallbackLine)) {
                return $fallbackLine;
            }
        }

        return $this->makeReplacements($key, $replace);
    }
}
