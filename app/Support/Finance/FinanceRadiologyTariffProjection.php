<?php

namespace App\Support\Finance;

use App\Models\FinanceCostComponent;
use App\Models\FinanceCostComponentGroup;
use App\Models\FinanceCostComponentVersion;
use App\Models\FinanceRadiologyTariffBinding;
use App\Models\FinanceRadiologyTariffBindingVersion;
use App\Models\FinanceTariffItem;
use App\Models\FinanceTariffItemVersion;
use App\Models\RadiologyExaminationMaster;
use App\Models\RadiologyExaminationMasterVersion;
use App\Models\RadiologyOrder;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

final class FinanceRadiologyTariffProjection
{
    public function __construct(
        private readonly FinanceRadiologyTariffActorPolicy $policy,
        private readonly FinanceRadiologyTariffContentDigest $bindingDigests,
        private readonly FinanceTariffContentDigest $tariffDigests,
    ) {}

    /** @return list<array<string, mixed>> */
    public function overview(User $actor, ?string $asOfDate = null): array
    {
        $this->policy->view($actor);
        $date = $this->date($asOfDate ?? now()->toDateString());

        return array_values(FinanceRadiologyTariffBinding::query()
            ->with(['radiologyMaster', 'radiologyMasterVersion'])
            ->orderBy('radiology_master_code')->orderBy('care_setting')->get()
            ->map(fn (FinanceRadiologyTariffBinding $binding): array => $this->bindingView(
                $binding,
                $this->effectiveBindingVersion($binding, $date),
                $date,
            ))->values()->all());
    }

    /** @return array<string, mixed> */
    public function history(User $actor, string $publicId): array
    {
        $this->policy->view($actor);
        $binding = FinanceRadiologyTariffBinding::query()->where('public_id', $publicId)->firstOrFail();

        return [
            'public_id' => $binding->public_id,
            'radiology_master_version_public_id' => $binding->radiology_master_version_public_id,
            'radiology_master_content_digest' => $binding->radiology_master_content_digest,
            'radiology_master_code' => $binding->radiology_master_code,
            'care_setting' => $binding->care_setting,
            'versions' => $binding->versions()->orderBy('version')->get()->map(
                fn (FinanceRadiologyTariffBindingVersion $version): array => [
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

    public function resolve(RadiologyOrder $order, CarbonInterface $performedAt): FinanceRadiologyTariffResolution
    {
        $serviceDate = Carbon::instance($performedAt->toDateTime())
            ->setTimezone((string) config('app.timezone', 'Asia/Jakarta'))
            ->format('Y-m-d');
        $masterVersion = RadiologyExaminationMasterVersion::query()
            ->where('public_id', $order->master_version_public_id)->first();
        if (! $masterVersion instanceof RadiologyExaminationMasterVersion
            || $masterVersion->radiology_examination_master_id !== $order->master_id
            || $masterVersion->version !== $order->master_version) {
            throw new FinanceDenied('source_binding_invalid', 'Ikatan versi master radiologi tidak valid.');
        }
        $order->loadMissing('master');
        $master = $order->getRelation('master');
        if (! $master instanceof RadiologyExaminationMaster) {
            throw new FinanceDenied('source_binding_invalid', 'Master radiologi pesanan tidak tersedia.');
        }
        $masterDigest = $this->bindingDigests->radiologyMasterVersion(
            $masterVersion->display_name,
            $masterVersion->preparation_instruction,
            $masterVersion->state,
        );
        if (! hash_equals($masterVersion->content_digest, $masterDigest)
            || ! hash_equals($order->master_content_digest, $masterVersion->content_digest)
            || $order->master_code !== $master->examination_code) {
            throw new FinanceDenied('source_integrity_failure', 'Bukti versi master radiologi tidak utuh.');
        }

        $binding = FinanceRadiologyTariffBinding::query()
            ->where('radiology_master_version_id', $masterVersion->id)
            ->where('care_setting', $order->care_setting)->first();
        if (! $binding instanceof FinanceRadiologyTariffBinding) {
            throw new FinanceDenied('unresolved_radiology_source', 'Tarif pemeriksaan radiologi belum dipetakan.');
        }
        if (! hash_equals($binding->radiology_master_content_digest, $order->master_content_digest)
            || $binding->radiology_master_version_public_id !== $order->master_version_public_id
            || $binding->radiology_master_code !== $order->master_code) {
            throw new FinanceDenied('source_binding_invalid', 'Konteks pemetaan tarif radiologi tidak cocok.');
        }

        $bindingVersion = $this->effectiveBindingVersion($binding, $serviceDate);
        if (! $bindingVersion instanceof FinanceRadiologyTariffBindingVersion
            || $bindingVersion->state !== FinanceRadiologyTariffBinding::ACTIVE) {
            throw new FinanceDenied('tariff_not_effective', 'Pemetaan tarif radiologi tidak efektif pada tanggal layanan.');
        }
        $computedBindingDigest = $this->bindingDigests->bindingVersion($binding->setRelation('radiologyMaster', $master), $bindingVersion);
        if (! hash_equals($bindingVersion->content_digest, $computedBindingDigest)) {
            throw new FinanceDenied('source_integrity_failure', 'Digest pemetaan tarif radiologi tidak valid.');
        }

        $tariffItem = FinanceTariffItem::query()->whereKey($bindingVersion->tariff_item_id)->first();
        if (! $tariffItem instanceof FinanceTariffItem
            || $tariffItem->public_id !== $bindingVersion->tariff_item_public_id
            || $tariffItem->tariff_code !== $bindingVersion->tariff_item_code) {
            throw new FinanceDenied('source_binding_invalid', 'Ikatan item tarif radiologi tidak valid.');
        }
        $tariffVersion = FinanceTariffItemVersion::query()->where('tariff_item_id', $tariffItem->id)
            ->whereDate('effective_from', '<=', $serviceDate)
            ->orderByDesc('effective_from')->orderByDesc('version')->first();
        if (! $tariffVersion instanceof FinanceTariffItemVersion || $tariffVersion->state !== FinanceTariffItem::ACTIVE) {
            throw new FinanceDenied('tariff_not_effective', 'Tarif radiologi tidak efektif pada tanggal layanan.');
        }
        if ($tariffVersion->service_domain !== 'RADIOLOGY' || $tariffVersion->care_setting !== $order->care_setting) {
            throw new FinanceDenied('source_binding_invalid', 'Domain atau care setting tarif radiologi tidak cocok.');
        }
        if (! hash_equals($tariffVersion->content_digest, $this->tariffDigests->tariffVersion($tariffVersion, $tariffItem->tariff_code))) {
            throw new FinanceDenied('source_integrity_failure', 'Digest versi tarif radiologi tidak valid.');
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
            throw new FinanceDenied('source_integrity_failure', 'Provenans komponen biaya tarif radiologi tidak utuh.');
        }

        return new FinanceRadiologyTariffResolution(
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

    private function effectiveBindingVersion(FinanceRadiologyTariffBinding $binding, string $serviceDate): ?FinanceRadiologyTariffBindingVersion
    {
        return FinanceRadiologyTariffBindingVersion::query()->where('binding_id', $binding->id)
            ->whereDate('effective_from', '<=', $serviceDate)
            ->orderByDesc('effective_from')->orderByDesc('version')->first();
    }

    /** @return array<string, mixed> */
    private function bindingView(FinanceRadiologyTariffBinding $binding, ?FinanceRadiologyTariffBindingVersion $version, string $asOfDate): array
    {
        return [
            'public_id' => $binding->public_id,
            'radiology_master_version_public_id' => $binding->radiology_master_version_public_id,
            'radiology_master_version' => $binding->radiology_master_version,
            'radiology_master_content_digest' => $binding->radiology_master_content_digest,
            'radiology_master_code' => $binding->radiology_master_code,
            'care_setting' => $binding->care_setting,
            'state' => $version?->state,
            'version' => $version?->version,
            'tariff_item_public_id' => $version?->tariff_item_public_id,
            'tariff_item_code' => $version?->tariff_item_code,
            'effective_from' => $version?->effective_from?->format('Y-m-d'),
            'is_effective' => $version?->state === FinanceRadiologyTariffBinding::ACTIVE
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
