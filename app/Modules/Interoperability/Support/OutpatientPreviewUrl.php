<?php

namespace App\Modules\Interoperability\Support;

final class OutpatientPreviewUrl
{
    public const BASE = 'https://simrs-campus-ueu.example.invalid/fhir';

    public static function resource(string $type, string $id): string
    {
        return self::BASE.'/'.$type.'/'.$id;
    }
}
