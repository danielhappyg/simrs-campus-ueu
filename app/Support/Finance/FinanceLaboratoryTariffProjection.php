<?php

namespace App\Support\Finance;

use App\Models\FinanceCostComponent;
use App\Models\FinanceCostComponentGroup;
use App\Models\FinanceCostComponentVersion;
use App\Models\FinanceLaboratoryTariffBinding;
use App\Models\FinanceLaboratoryTariffBindingVersion;
use App\Models\FinanceTariffItem;
use App\Models\FinanceTariffItemVersion;
use App\Models\LaboratoryExaminationMaster;
use App\Models\LaboratoryExaminationMasterVersion;
use App\Models\LaboratoryOrder;
use App\Models\User;
use App\Support\Laboratory\LaboratoryCanonicalJson;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

final class FinanceLaboratoryTariffProjection
{
    public function __construct(
        private readonly FinanceLaboratoryTariffActorPolicy $policy,
        private readonly FinanceLaboratoryTariffContentDigest $bindingDigests,
        private readonly FinanceTariffContentDigest $tariffDigests,
    ) {}

    /** @return list<array<string, mixed>> */
    public function overview(User $actor, ?string $asOfDate = null): array
    {
        $this->policy->view($actor);
        $date = $this->date($asOfDate ?? now()->toDateString());

        return array_values(FinanceLaboratoryTariffBinding::query()
            ->with(['laboratoryMaster', 'laboratoryMasterVersion'])
            ->orderBy('laboratory_master_code')->orderBy('care_setting')->get()
            ->map(fn (FinanceLaboratoryTariffBinding $binding): array => $this->bindingView(
                $binding,
                $this->effectiveBindingVersion($binding, $date),
                $date,
            ))->values()->all());
    }

    /** @return array<string, mixed> */
    public function history(User $actor, string $publicId): array
    {
        $this->policy->view($actor);
        $binding = FinanceLaboratoryTariffBinding::query()->where('public_id', $publicId)->firstOrFail();

        return [
            'public_id' => $binding->public_id,
            'laboratory_master_version_public_id' => $binding->laboratory_master_version_public_id,
            'laboratory_master_content_digest' => $binding->laboratory_master_content_digest,
            'laboratory_master_code' => $binding->laboratory_master_code,
            'care_setting' => $binding->care_setting,
            'versions' => $binding->versions()->orderBy('version')->get()->map(
                fn (FinanceLaboratoryTariffBindingVersion $version): array => [
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

    public function resolve(LaboratoryOrder $order, CarbonInterface $verifiedAt): FinanceLaboratoryTariffResolution
    {
        $serviceDate = Carbon::instance($verifiedAt->toDateTime())
            ->setTimezone((string) config('app.timezone', 'Asia/Jakarta'))
            ->format('Y-m-d');
        $masterVersion = LaboratoryExaminationMasterVersion::query()
            ->where('public_id', $order->master_version_public_id)->first();
        if (! $masterVersion instanceof LaboratoryExaminationMasterVersion
            || $masterVersion->laboratory_examination_master_id !== $order->master_id
            || $masterVersion->version !== $order->master_version) {
            throw new FinanceDenied('source_binding_invalid', 'Ikatan versi master laboratorium tidak valid.');
        }
        $order->loadMissing('master');
        $master = $order->getRelation('master');
        if (! $master instanceof LaboratoryExaminationMaster) {
            throw new FinanceDenied('source_binding_invalid', 'Master laboratorium pesanan tidak tersedia.');
        }
        $masterDigest = $this->bindingDigests->laboratoryMasterVersion(
            $masterVersion->display_name,
            $masterVersion->specimen_type,
            $masterVersion->collection_instruction,
            $masterVersion->components,
            $masterVersion->state,
        );
        if (! hash_equals($masterVersion->content_digest, $masterDigest)
            || ! hash_equals($order->master_content_digest, $masterVersion->content_digest)
            || $order->master_code !== $master->examination_code
            || $order->master_display_name !== $masterVersion->display_name
            || $order->specimen_type_snapshot !== $masterVersion->specimen_type
            || $order->collection_instruction_snapshot !== $masterVersion->collection_instruction
            || ! LaboratoryCanonicalJson::equivalent($order->components_snapshot, $masterVersion->components)) {
            throw new FinanceDenied('source_integrity_failure', 'Bukti versi master laboratorium tidak utuh.');
        }

        $binding = FinanceLaboratoryTariffBinding::query()
            ->where('laboratory_master_version_id', $masterVersion->id)
            ->where('care_setting', $order->care_setting)->first();
        if (! $binding instanceof FinanceLaboratoryTariffBinding) {
            throw new FinanceDenied('unresolved_laboratory_source', 'Tarif pemeriksaan laboratorium belum dipetakan.');
        }
        if (! hash_equals($binding->laboratory_master_content_digest, $order->master_content_digest)
            || $binding->laboratory_master_version_public_id !== $order->master_version_public_id
            || $binding->laboratory_master_code !== $order->master_code) {
            throw new FinanceDenied('source_binding_invalid', 'Konteks pemetaan tarif laboratorium tidak cocok.');
        }

        $bindingVersion = $this->effectiveBindingVersion($binding, $serviceDate);
        if (! $bindingVersion instanceof FinanceLaboratoryTariffBindingVersion
            || $bindingVersion->state !== FinanceLaboratoryTariffBinding::ACTIVE) {
            throw new FinanceDenied('tariff_not_effective', 'Pemetaan tarif laboratorium tidak efektif pada tanggal layanan.');
        }
        $computedBindingDigest = $this->bindingDigests->bindingVersion($binding->setRelation('laboratoryMaster', $master), $bindingVersion);
        if (! hash_equals($bindingVersion->content_digest, $computedBindingDigest)) {
            throw new FinanceDenied('source_integrity_failure', 'Digest pemetaan tarif laboratorium tidak valid.');
        }

        $tariffItem = FinanceTariffItem::query()->whereKey($bindingVersion->tariff_item_id)->first();
        if (! $tariffItem instanceof FinanceTariffItem
            || $tariffItem->public_id !== $bindingVersion->tariff_item_public_id
            || $tariffItem->tariff_code !== $bindingVersion->tariff_item_code) {
            throw new FinanceDenied('source_binding_invalid', 'Ikatan item tarif laboratorium tidak valid.');
        }
        $tariffVersion = FinanceTariffItemVersion::query()->where('tariff_item_id', $tariffItem->id)
            ->whereDate('effective_from', '<=', $serviceDate)
            ->orderByDesc('effective_from')->orderByDesc('version')->first();
        if (! $tariffVersion instanceof FinanceTariffItemVersion || $tariffVersion->state !== FinanceTariffItem::ACTIVE) {
            throw new FinanceDenied('tariff_not_effective', 'Tarif laboratorium tidak efektif pada tanggal layanan.');
        }
        if ($tariffVersion->service_domain !== 'LABORATORY' || $tariffVersion->care_setting !== $order->care_setting) {
            throw new FinanceDenied('source_binding_invalid', 'Domain atau care setting tarif laboratorium tidak cocok.');
        }
        if (! hash_equals($tariffVersion->content_digest, $this->tariffDigests->tariffVersion($tariffVersion, $tariffItem->tariff_code))) {
            throw new FinanceDenied('source_integrity_failure', 'Digest versi tarif laboratorium tidak valid.');
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
            throw new FinanceDenied('source_integrity_failure', 'Provenans komponen biaya tarif laboratorium tidak utuh.');
        }

        return new FinanceLaboratoryTariffResolution(
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

    private function effectiveBindingVersion(FinanceLaboratoryTariffBinding $binding, string $serviceDate): ?FinanceLaboratoryTariffBindingVersion
    {
        return FinanceLaboratoryTariffBindingVersion::query()->where('binding_id', $binding->id)
            ->whereDate('effective_from', '<=', $serviceDate)
            ->orderByDesc('effective_from')->orderByDesc('version')->first();
    }

    /** @return array<string, mixed> */
    private function bindingView(FinanceLaboratoryTariffBinding $binding, ?FinanceLaboratoryTariffBindingVersion $version, string $asOfDate): array
    {
        return [
            'public_id' => $binding->public_id,
            'laboratory_master_version_public_id' => $binding->laboratory_master_version_public_id,
            'laboratory_master_version' => $binding->laboratory_master_version,
            'laboratory_master_content_digest' => $binding->laboratory_master_content_digest,
            'laboratory_master_code' => $binding->laboratory_master_code,
            'care_setting' => $binding->care_setting,
            'state' => $version?->state,
            'version' => $version?->version,
            'tariff_item_public_id' => $version?->tariff_item_public_id,
            'tariff_item_code' => $version?->tariff_item_code,
            'effective_from' => $version?->effective_from?->format('Y-m-d'),
            'is_effective' => $version?->state === FinanceLaboratoryTariffBinding::ACTIVE
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
