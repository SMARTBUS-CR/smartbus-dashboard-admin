<?php

namespace App\Filament\Support;

use BackedEnum;
use Illuminate\Support\HtmlString;

final class TableSectionHeader
{
    public static function heading(
        string $title,
        string|BackedEnum $icon,
    ): HtmlString {
        return new HtmlString(
            view('filament.tables.section-heading', [
                'heading' => $title,
                'icon' => $icon,
            ])->render(),
        );
    }

    public static function description(
        ?string $description,
    ): ?HtmlString {
        if ($description === null) {
            return null;
        }

        return new HtmlString(
            '<span style="display: block; padding-left: 2.23rem;">'
            .e($description)
            .'</span>',
        );
    }
}