<?php

namespace App\Http\Controllers;

use App\Support\SimrsModuleCategories;
use App\Support\SimrsSahabatMenuCatalog;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ModulePlaceholderController extends Controller
{
    public function __invoke(string $category, ?string $item = null): RedirectResponse|Response
    {
        if (! SimrsModuleCategories::isValid($category)) {
            throw new NotFoundHttpException;
        }

        $selected = null;

        if ($item !== null) {
            $selected = SimrsSahabatMenuCatalog::find($category, $item);

            if ($selected === null) {
                throw new NotFoundHttpException;
            }

            if ($selected['status'] !== 'visual') {
                return redirect($selected['href']);
            }
        }

        $label = SimrsModuleCategories::label($category);

        return Inertia::render('modules/placeholder', [
            'category' => $category,
            'categoryLabel' => $label,
            'menus' => SimrsSahabatMenuCatalog::menusFor($category),
            'selected' => $selected,
        ]);
    }
}
