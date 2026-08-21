<?php

namespace App\Http\Controllers\Rebuild;

use App\Http\Controllers\Controller;
use App\Models\Encounter;
use App\Models\Patient;
use Inertia\Inertia;
use Inertia\Response;

class RebuildHomeController extends Controller
{
    public function __invoke(): Response
    {
        $today = today();

        try {
            $counts = [
                'kunjungan_hari_ini' => Encounter::query()
                    ->where('care_setting', Encounter::CARE_SETTING_OUTPATIENT)
                    ->whereDate('registered_at', $today)
                    ->count(),
                'pasien_baru_hari_ini' => Patient::query()
                    ->where('is_synthetic', true)
                    ->whereDate('created_at', $today)
                    ->count(),
                'in_examination' => Encounter::query()
                    ->where('care_setting', Encounter::CARE_SETTING_OUTPATIENT)
                    ->where('status', Encounter::STATUS_IN_EXAMINATION)
                    ->count(),
                'ready_for_rm' => Encounter::query()
                    ->where('care_setting', Encounter::CARE_SETTING_OUTPATIENT)
                    ->where('status', Encounter::STATUS_READY_FOR_RM)
                    ->count(),
            ];
        } catch (\Throwable) {
            $counts = [
                'kunjungan_hari_ini' => 0,
                'pasien_baru_hari_ini' => 0,
                'in_examination' => 0,
                'ready_for_rm' => 0,
            ];
        }

        return Inertia::render('rebuild/home', [
            'counts' => $counts,
        ]);
    }
}
