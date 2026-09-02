<?php

namespace App\Support\Finance;

use App\Models\FinanceAccommodationTariffBinding;
use App\Models\FinanceAccommodationTariffBindingVersion;
use App\Models\FinanceCostComponent;
use App\Models\FinanceCostComponentGroup;
use App\Models\FinanceCostComponentVersion;
use App\Models\FinanceTariffItem;
use App\Models\FinanceTariffItemVersion;
use App\Models\InpatientBed;
use App\Models\InpatientBedVersion;
use App\Models\InpatientLocationEvent;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

final class FinanceAccommodationTariffProjection
{
    public function __construct(
        private readonly FinanceAccommodationTariffActorPolicy $policy,
        private readonly FinanceAccommodationTariffContentDigest $bindingDigests,
        private readonly FinanceTariffContentDigest $tariffDigests,
    ) {}

    /** @return list<array<string, mixed>> */
    public function overview(User $actor, ?string $asOfDate = null): array
    {
        $this->policy->view($actor);
        $date = $this->date($asOfDate ?? now()->toDateString());

        return array_values(FinanceAccommodationTariffBinding::query()
            ->with(['bed', 'bedVersion'])
            ->orderBy('bed_code')->get()
            ->map(fn (FinanceAccommodationTariffBinding $binding): array => $this->bindingView(
                $binding,
                $this->effectiveBindingVersion($binding, $date),
                $date,
            ))->values()->all());
    }

    /** @return array<string, mixed> */
    public function history(User $actor, string $publicId): array
    {
        $this->policy->view($actor);
        $binding = FinanceAccommodationTariffBinding::query()->where('public_id', $publicId)->firstOrFail();

        return [
            'public_id' => $binding->public_id,
            'inpatient_bed_version_public_id' => $binding->inpatient_bed_version_public_id,
            'inpatient_bed_content_digest' => $binding->inpatient_bed_content_digest,
            'bed_code' => $binding->bed_code,
            'care_setting' => $binding->care_setting,
            'versions' => $binding->versions()->orderBy('version')->get()->map(
                fn (FinanceAccommodationTariffBindingVersion $version): array => [
                    'public_id' => $version->public_id,
                    'version' => $version->version,
                    'tariff_item_public_id' => $version->tariff_item_public_id,
                    'tariff_item_code' => $version->tariff_item_code,
                    'state' => $version->state,
                    'effective_from' => $version->effective_from->format('Y-m-d'),
                    'reason' => $version->reason,
                    'content_digest' => $version->content_digest,
                ],
            )->all(),
        ];
    }

    public function resolve(InpatientLocationEvent $opening, CarbonInterface $anchorAt): FinanceAccommodationTariffResolution
    {
        return $this->resolveExact(
            $opening->to_inpatient_bed_version_public_id ?? '',
            $opening->to_inpatient_bed_after_digest ?? '',
            Carbon::instance($anchorAt->toDateTime())
                ->setTimezone((string) config('app.timezone', 'Asia/Jakarta'))
                ->format('Y-m-d'),
        );
    }

    public function resolveExact(string $bedVersionPublicId, string $bedContentDigest, string $serviceDate): FinanceAccommodationTariffResolution
    {
        $serviceDate = $this->date($serviceDate);
        $bedVersion = InpatientBedVersion::query()
            ->where('public_id', $bedVersionPublicId)->first();
        if (! $bedVersion instanceof InpatientBedVersion) {
            throw new FinanceDenied('source_binding_invalid', 'Ikatan versi master akomodasi tidak valid.');
        }
        $master = InpatientBed::query()->with('ward')->whereKey($bedVersion->bed_id)->first();
        if (! $master instanceof InpatientBed) {
            throw new FinanceDenied('source_binding_invalid', 'Master akomodasi tidak tersedia.');
        }
        $masterDigest = $this->bindingDigests->inpatientBedVersion(
            $master->code,
            $bedVersion->display_name,
            $bedVersion->room_label,
            $bedVersion->service_class,
            $bedVersion->state,
            $bedVersion->version,
        );
        if (! hash_equals($bedVersion->after_digest, $masterDigest)
            || ! hash_equals($bedContentDigest, $bedVersion->after_digest)) {
            throw new FinanceDenied('source_integrity_failure', 'Bukti versi master akomodasi tidak utuh.');
        }

        $binding = FinanceAccommodationTariffBinding::query()
            ->where('inpatient_bed_version_id', $bedVersion->id)
            ->where('inpatient_bed_content_digest', $bedContentDigest)->first();
        if (! $binding instanceof FinanceAccommodationTariffBinding) {
            throw new FinanceDenied('unresolved_accommodation_source', 'Tarif akomodasi tempat tidur belum dipetakan.');
        }
        if ($binding->inpatient_bed_version_public_id !== $bedVersionPublicId
            || $binding->bed_public_id !== $master->public_id
            || $binding->bed_code !== $master->code
            || $binding->ward_public_id !== $master->ward->public_id
            || $binding->ward_code !== $master->ward->code
            || $binding->service_class !== $bedVersion->service_class
            || $binding->care_setting !== 'INPATIENT' || $binding->pricing_unit !== 'OCCUPANCY_DAY') {
            throw new FinanceDenied('source_binding_invalid', 'Konteks pemetaan tarif akomodasi tidak cocok.');
        }

        $bindingVersion = $this->effectiveBindingVersion($binding, $serviceDate);
        if (! $bindingVersion instanceof FinanceAccommodationTariffBindingVersion
            || $bindingVersion->state !== FinanceAccommodationTariffBinding::ACTIVE) {
            throw new FinanceDenied('tariff_not_effective', 'Pemetaan tarif akomodasi tidak efektif pada tanggal layanan.');
        }
        $computedBindingDigest = $this->bindingDigests->bindingVersion($binding, $bindingVersion);
        if (! hash_equals($bindingVersion->content_digest, $computedBindingDigest)) {
            throw new FinanceDenied('source_integrity_failure', 'Digest pemetaan tarif akomodasi tidak valid.');
        }

        $tariffItem = FinanceTariffItem::query()->whereKey($bindingVersion->tariff_item_id)->first();
        if (! $tariffItem instanceof FinanceTariffItem
            || $tariffItem->public_id !== $bindingVersion->tariff_item_public_id
            || $tariffItem->tariff_code !== $bindingVersion->tariff_item_code) {
            throw new FinanceDenied('source_binding_invalid', 'Ikatan item tarif akomodasi tidak valid.');
        }
        $tariffVersion = FinanceTariffItemVersion::query()->where('tariff_item_id', $tariffItem->id)
            ->whereDate('effective_from', '<=', $serviceDate)
            ->orderByDesc('effective_from')->orderByDesc('version')->first();
        if (! $tariffVersion instanceof FinanceTariffItemVersion || $tariffVersion->state !== FinanceTariffItem::ACTIVE) {
            throw new FinanceDenied('tariff_not_effective', 'Tarif akomodasi tidak efektif pada tanggal layanan.');
        }
        if ($tariffVersion->service_domain !== 'ACCOMMODATION' || $tariffVersion->care_setting !== 'INPATIENT') {
            throw new FinanceDenied('source_binding_invalid', 'Domain atau care setting tarif akomodasi tidak cocok.');
        }
        if (! hash_equals($tariffVersion->content_digest, $this->tariffDigests->tariffVersion($tariffVersion, $tariffItem->tariff_code))) {
            throw new FinanceDenied('source_integrity_failure', 'Digest versi tarif akomodasi tidak valid.');
        }

        $component = FinanceCostComponent::query()->where('public_id', $tariffVersion->component_public_id)->first();
        $componentVersion = $component instanceof FinanceCostComponent
            ? FinanceCostComponentVersion::query()->where('component_id', $component->id)
                ->where('content_digest', $tariffVersion->component_content_digest)->first()
            : null;
        $group = $component instanceof FinanceCostComponent
            ? FinanceCostComponentGroup::query()->whereKey($component->group_id)->first()
            : null;
        if (! $component instanceof FinanceCostComponent
            || ! $componentVersion instanceof FinanceCostComponentVersion
            || ! $group instanceof FinanceCostComponentGroup
            || $component->component_code !== $tariffVersion->component_code) {
            throw new FinanceDenied('source_integrity_failure', 'Provenans komponen biaya tarif akomodasi tidak utuh.');
        }

        return new FinanceAccommodationTariffResolution(
            $binding,
            $bindingVersion,
            $tariffItem,
            $tariffVersion,
            $component,
            $componentVersion,
            $group,
            $serviceDate,
            $tariffVersion->amount_rupiah,
        );
    }

    private function effectiveBindingVersion(FinanceAccommodationTariffBinding $binding, string $serviceDate): ?FinanceAccommodationTariffBindingVersion
    {
        return FinanceAccommodationTariffBindingVersion::query()->where('binding_id', $binding->id)
            ->whereDate('effective_from', '<=', $serviceDate)
            ->orderByDesc('effective_from')->orderByDesc('version')->first();
    }

    /** @return array<string, mixed> */
    private function bindingView(FinanceAccommodationTariffBinding $binding, ?FinanceAccommodationTariffBindingVersion $version, string $asOfDate): array
    {
        return [
            'public_id' => $binding->public_id,
            'inpatient_bed_version_public_id' => $binding->inpatient_bed_version_public_id,
            'inpatient_bed_version' => $binding->inpatient_bed_version,
            'inpatient_bed_content_digest' => $binding->inpatient_bed_content_digest,
            'bed_code' => $binding->bed_code,
            'care_setting' => $binding->care_setting,
            'state' => $version?->state,
            'version' => $version?->version,
            'tariff_item_public_id' => $version?->tariff_item_public_id,
            'tariff_item_code' => $version?->tariff_item_code,
            'effective_from' => $version?->effective_from?->format('Y-m-d'),
            'is_effective' => $version?->state === FinanceAccommodationTariffBinding::ACTIVE
                && $version->effective_from->format('Y-m-d') <= $asOfDate,
            'latest_head_state' => $binding->state,
            'latest_head_version' => $binding->version,
            'latest_head_content_digest' => $binding->current_content_digest,
        ];
    }

    private function date(string $date): string
    {
        $date = trim($date);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = \DateTimeImmutable::getLastErrors();
        if (! $parsed || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $parsed->format('Y-m-d') !== $date) {
            throw new FinanceTariffDenied('validation_failed', 'Tanggal harus menggunakan format YYYY-MM-DD.');
        }

        return $date;
    }
}
