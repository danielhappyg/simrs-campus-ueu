<?php

namespace App\Support;

/** English presentation only. Never apply this to clinical text or stored snapshots. */
final class ScreenVocabulary
{
    public static function label(string $label): string
    {
        /** @var array<string, string>|null $labels */
        static $labels = null;
        $labels ??= require __DIR__.'/../../resources/i18n/screen-en.php';

        return $labels[$label] ?? $label;
    }
}
