<?php

namespace App\Support\Finance;

use App\Models\Encounter;
use App\Models\FinanceChargeEvent;
use App\Models\FinanceLaboratorySourceEvent;
use App\Models\FinanceRadiologySourceEvent;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryResultVersion;
use App\Models\RadiologyOrder;
use App\Models\RadiologyPerformance;
use Illuminate\Support\Facades\Lang;

final class FinanceSourceReadinessProjection
{
    public function __construct(
        private readonly FinanceRadiologyTariffProjection $radiologyTariffs,
        private readonly FinanceRadiologySourceAdapter $radiology,
        private readonly FinanceLaboratoryTariffProjection $laboratoryTariffs,
        private readonly FinanceLaboratorySourceAdapter $laboratory,
        private readonly FinanceAccommodationSourceAdapter $accommodation,
    ) {}

    /** @return list<array<string, mixed>> */
    public function items(Encounter $encounter): array
    {
        $encounter->loadMissing('patient');
        $orders = RadiologyOrder::query()->with(['master', 'performance'])
            ->where('encounter_id', $encounter->id)
            ->whereHas('performance')
            ->orderBy('id')->get();

        $radiologyItems = $orders->map(function (RadiologyOrder $order) use ($encounter): array {
            $performance = $order->performance;
            if (! $performance instanceof RadiologyPerformance) {
                return $this->radiologyItem($order, null, 'BUKTI_TIDAK_KONSISTEN');
            }

            try {
                $this->radiology->validatePerformance($encounter, $order, $performance);
                $source = FinanceRadiologySourceEvent::query()
                    ->where('radiology_performance_id', $performance->id)->first();
                if ($source instanceof FinanceRadiologySourceEvent) {
                    $charge = FinanceChargeEvent::query()
                        ->where('finance_radiology_source_event_id', $source->id)->first();
                    if (! $charge instanceof FinanceChargeEvent) {
                        return $this->radiologyItem($order, $performance, 'BUKTI_TIDAK_KONSISTEN');
                    }
                    $this->radiology->verifyRetained($encounter, collect([$charge]));

                    return $this->radiologyItem($order, $performance, 'TERSINKRONISASI');
                }

                $resolution = $this->radiologyTariffs->resolve($order, $performance->performed_at);

                return $this->radiologyItem(
                    $order,
                    $performance,
                    'SIAP_DISINKRONKAN',
                    $resolution->amountRupiah,
                );
            } catch (FinanceDenied $denied) {
                return $this->radiologyItem($order, $performance, match ($denied->reason) {
                    'unresolved_radiology_source' => 'TARIF_BELUM_DIPETAKAN',
                    'tariff_not_effective' => 'TARIF_TIDAK_EFEKTIF',
                    'source_binding_invalid' => 'KONTEKS_TIDAK_COCOK',
                    default => 'BUKTI_TIDAK_KONSISTEN',
                });
            }
        });

        $laboratoryResults = LaboratoryResultVersion::query()
            ->with(['order.master', 'specimenAttempt', 'criticalCommunication'])
            ->where('state', LaboratoryResultVersion::VERIFIED)
            ->whereNull('base_verified_version_id')
            ->whereHas('order', fn ($query) => $query->where('encounter_id', $encounter->id))
            ->orderBy('verified_at')->orderBy('id')->get();

        $laboratoryItems = $laboratoryResults->map(function (LaboratoryResultVersion $result) use ($encounter): array {
            $order = $result->order;
            $specimen = $result->specimenAttempt;
            $communication = $result->criticalCommunication;
            if ($result->verified_at === null) {
                return $this->laboratoryItem($order, $result, 'BUKTI_TIDAK_KONSISTEN');
            }

            try {
                $this->laboratory->validateOriginalVerified($encounter, $order, $result, $specimen, $communication);
                $source = FinanceLaboratorySourceEvent::query()
                    ->where('laboratory_result_version_id', $result->id)->first();
                if ($source instanceof FinanceLaboratorySourceEvent) {
                    $charge = FinanceChargeEvent::query()
                        ->where('finance_laboratory_source_event_id', $source->id)->first();
                    if (! $charge instanceof FinanceChargeEvent) {
                        return $this->laboratoryItem($order, $result, 'BUKTI_TIDAK_KONSISTEN');
                    }
                    $this->laboratory->verifyRetained($encounter, collect([$charge]));

                    return $this->laboratoryItem($order, $result, 'TERSINKRONISASI');
                }

                $resolution = $this->laboratoryTariffs->resolve($order, $result->verified_at);

                return $this->laboratoryItem(
                    $order,
                    $result,
                    'SIAP_DISINKRONKAN',
                    $resolution->amountRupiah,
                );
            } catch (FinanceDenied $denied) {
                return $this->laboratoryItem($order, $result, match ($denied->reason) {
                    'unresolved_laboratory_source' => 'TARIF_BELUM_DIPETAKAN',
                    'tariff_not_effective' => 'TARIF_TIDAK_EFEKTIF',
                    'source_binding_invalid' => 'KONTEKS_TIDAK_COCOK',
                    default => 'BUKTI_TIDAK_KONSISTEN',
                });
            }
        });

        $accommodationItems = collect($this->accommodation->readiness($encounter))
            ->map(fn (array $item): array => $this->accommodationItem($encounter, $item));

        return array_values($radiologyItems->concat($laboratoryItems)->concat($accommodationItems)
            ->sortBy([['service_at', 'asc'], ['public_id', 'asc']])
            ->values()->all());
    }

    /** @return array{resolved_count:int,unresolved_count:int,issue_blocked:bool,issue_blocker:?string,items:list<array<string,mixed>>} */
    public function summary(Encounter $encounter): array
    {
        $items = $this->items($encounter);
        $resolved = count(array_filter($items, static fn (array $item): bool => in_array(
            $item['state'], ['SIAP_DISINKRONKAN', 'TERSINKRONISASI'], true,
        )));
        $unresolved = count($items) - $resolved;

        return [
            'resolved_count' => $resolved,
            'unresolved_count' => $unresolved,
            'issue_blocked' => $unresolved > 0,
            'issue_blocker' => $unresolved > 0
                ? Lang::string(':count sumber layanan belum memiliki biaya yang dapat diterbitkan.', ['count' => $unresolved])
                : null,
            'items' => $items,
        ];
    }

    /** @return array<string, mixed> */
    private function radiologyItem(
        RadiologyOrder $order,
        ?RadiologyPerformance $performance,
        string $state,
        ?int $previewSignedAmount = null,
    ): array {
        [$label, $detail] = match ($state) {
            'SIAP_DISINKRONKAN' => [__('Siap disinkronkan'), __('Pemetaan dan tarif efektif telah ditemukan.')],
            'TERSINKRONISASI' => [__('Tersinkronisasi'), __('Sumber dan baris biaya telah direkonsiliasi.')],
            'TARIF_BELUM_DIPETAKAN' => [__('Tarif belum dipetakan'), __('Pemetaan tepat belum tersedia.')],
            'TARIF_TIDAK_EFEKTIF' => [__('Tarif tidak efektif'), __('Tarif belum aktif pada tanggal layanan.')],
            'KONTEKS_TIDAK_COCOK' => [__('Konteks tidak cocok'), __('Care setting atau identitas master tidak cocok.')],
            default => [__('Bukti tidak konsisten'), __('Bukti sumber tidak dapat direkonsiliasi.')],
        };

        $publicId = $performance instanceof RadiologyPerformance ? $performance->public_id : $order->public_id;
        $serviceAt = $performance instanceof RadiologyPerformance
            ? $performance->performed_at->toIso8601String()
            : $order->ordered_at->toIso8601String();

        return [
            'public_id' => $publicId,
            'source_domain' => FinanceChargeEvent::SOURCE_RADIOLOGY,
            'source_reference' => $order->public_id,
            'description' => $order->master_display_name,
            'service_at' => $serviceAt,
            'preview_signed_amount' => $previewSignedAmount,
            'state' => $state,
            'state_label' => $label,
            'detail' => $detail,
        ];
    }

    /** @return array<string, mixed> */
    private function laboratoryItem(
        LaboratoryOrder $order,
        LaboratoryResultVersion $result,
        string $state,
        ?int $previewSignedAmount = null,
    ): array {
        [$label, $detail] = $this->statePresentation($state);

        return [
            'public_id' => $result->public_id,
            'source_domain' => FinanceChargeEvent::SOURCE_LABORATORY,
            'source_reference' => $order->public_id,
            'description' => $order->master_display_name,
            'service_at' => $result->verified_at?->toIso8601String()
                ?? $order->ordered_at->toIso8601String(),
            'preview_signed_amount' => $previewSignedAmount,
            'state' => $state,
            'state_label' => $label,
            'detail' => $detail,
        ];
    }

    /** @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function accommodationItem(Encounter $encounter, array $item): array
    {
        $state = is_string($item['state'] ?? null) ? $item['state'] : 'BUKTI_TIDAK_KONSISTEN';
        $serviceDate = is_string($item['service_date'] ?? null) ? $item['service_date'] : null;
        $serviceAt = is_string($item['service_at'] ?? null) ? $item['service_at'] : null;
        $previewSignedAmount = is_int($item['preview_signed_amount'] ?? null)
            ? $item['preview_signed_amount']
            : null;
        [$label, $detail] = $this->statePresentation($state);

        return [
            'public_id' => $serviceDate === null
                ? $encounter->public_id.'-ACCOMMODATION'
                : $encounter->public_id.'-ACCOMMODATION-'.$serviceDate,
            'source_domain' => FinanceChargeEvent::SOURCE_ACCOMMODATION,
            'source_reference' => $encounter->public_id,
            'description' => $serviceDate === null
                ? __('Akomodasi rawat inap')
                : __('Hari akomodasi :date', ['date' => $serviceDate]),
            'service_at' => $serviceAt ?? ($serviceDate === null
                ? $encounter->registered_at->toIso8601String()
                : $serviceDate.'T00:00:00'.now()->format('P')),
            'service_date' => $serviceDate,
            'preview_signed_amount' => $previewSignedAmount,
            'state' => $state,
            'state_label' => $label,
            'detail' => $detail,
        ];
    }

    /** @return array{string, string} */
    private function statePresentation(string $state): array
    {
        return match ($state) {
            'SIAP_DISINKRONKAN' => [__('Siap disinkronkan'), __('Pemetaan dan tarif efektif telah ditemukan.')],
            'TERSINKRONISASI' => [__('Tersinkronisasi'), __('Sumber dan baris biaya telah direkonsiliasi.')],
            'TARIF_BELUM_DIPETAKAN' => [__('Tarif belum dipetakan'), __('Pemetaan tepat belum tersedia.')],
            'TARIF_TIDAK_EFEKTIF' => [__('Tarif tidak efektif'), __('Tarif belum aktif pada tanggal layanan.')],
            'KONTEKS_TIDAK_COCOK' => [__('Konteks tidak cocok'), __('Care setting atau identitas master tidak cocok.')],
            'INTERVAL_MASIH_TERBUKA' => [__('Interval masih terbuka'), __('Akomodasi belum lengkap sampai pemulangan rutin menutup interval terakhir.')],
            'RIWAYAT_LOKASI_TIDAK_LENGKAP' => [__('Riwayat lokasi tidak lengkap'), __('Bukti versi tempat tidur prospektif belum lengkap dan tidak boleh diinferensikan.')],
            default => [__('Bukti tidak konsisten'), __('Bukti sumber tidak dapat direkonsiliasi.')],
        };
    }
}
