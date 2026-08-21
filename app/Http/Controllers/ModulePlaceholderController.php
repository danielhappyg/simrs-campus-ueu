<?php

namespace App\Http\Controllers;

use App\Support\SimrsModuleCategories;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ModulePlaceholderController extends Controller
{
    public function __invoke(string $category): Response
    {
        if (! SimrsModuleCategories::isValid($category)) {
            throw new NotFoundHttpException;
        }

        $label = SimrsModuleCategories::label($category);

        return Inertia::render('modules/placeholder', [
            'category' => $category,
            'categoryLabel' => $label,
        ]);
    }
}
