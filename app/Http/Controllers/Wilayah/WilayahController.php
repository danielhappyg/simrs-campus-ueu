<?php

namespace App\Http\Controllers\Wilayah;

use App\Http\Controllers\Controller;
use App\Services\Wilayah\WilayahRepository;
use App\Support\Authorization\Capability;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class WilayahController extends Controller
{
    public function __construct(
        private readonly WilayahRepository $wilayah,
    ) {}

    public function provinces(): JsonResponse
    {
        Gate::authorize(Capability::PATIENT_SEARCH);

        return response()->json([
            'options' => $this->wilayah->provinces(),
        ]);
    }

    public function regencies(string $provinceCode): JsonResponse
    {
        Gate::authorize(Capability::PATIENT_SEARCH);

        return response()->json([
            'options' => $this->wilayah->regencies($provinceCode),
        ]);
    }

    public function districts(string $regencyCode): JsonResponse
    {
        Gate::authorize(Capability::PATIENT_SEARCH);

        return response()->json([
            'options' => $this->wilayah->districts($regencyCode),
        ]);
    }

    public function villages(string $districtCode): JsonResponse
    {
        Gate::authorize(Capability::PATIENT_SEARCH);

        return response()->json([
            'options' => $this->wilayah->villages($districtCode),
        ]);
    }
}
