<?php

namespace Tests\Unit\Finance;

use App\Models\PharmacyFinancialSourceEvent;
use App\Support\Finance\FinanceCandidatePreviewTotals;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class FinanceCandidatePreviewTotalsTest extends TestCase
{
    public function test_ready_multi_domain_sources_extend_pharmacy_totals_without_counting_unresolved_sources(): void
    {
        $pharmacyEvents = collect([
            $this->pharmacyEvent(
                PharmacyFinancialSourceEvent::CHARGE,
                18000,
                '2026-09-02T08:00:00+07:00',
            ),
            $this->pharmacyEvent(
                PharmacyFinancialSourceEvent::REVERSAL,
                -3000,
                '2026-09-02T09:00:00+07:00',
            ),
        ]);
        $readiness = [
            'items' => [
                $this->ready('RADIOLOGY', 125000, '2026-09-02T10:00:00+07:00'),
                $this->ready('LABORATORY', 90000, '2026-09-02T11:00:00+07:00'),
                $this->ready('ACCOMMODATION', 75000, '2026-09-03T00:00:00+07:00'),
                [
                    'source_domain' => 'RADIOLOGY',
                    'state' => 'TARIF_BELUM_DIPETAKAN',
                    'preview_signed_amount' => 999999,
                    'service_at' => '2026-09-04T00:00:00+07:00',
                ],
                [
                    'source_domain' => 'LABORATORY',
                    'state' => 'TERSINKRONISASI',
                    'preview_signed_amount' => 888888,
                    'service_at' => '2026-09-05T00:00:00+07:00',
                ],
            ],
        ];

        $totals = (new FinanceCandidatePreviewTotals)->summarize($pharmacyEvents, $readiness);

        $this->assertSame(5, $totals['source_event_count']);
        $this->assertSame(308000, $totals['gross_amount']);
        $this->assertSame(-3000, $totals['reversal_amount']);
        $this->assertSame(305000, $totals['net_amount']);
        $this->assertSame('2026-09-03T00:00:00+07:00', $totals['latest_source_at']);
    }

    public function test_empty_candidate_sources_return_zero_control_totals_without_inventing_a_time(): void
    {
        $totals = (new FinanceCandidatePreviewTotals)->summarize(collect(), [
            'items' => [[
                'source_domain' => 'ACCOMMODATION',
                'state' => 'INTERVAL_MASIH_TERBUKA',
                'preview_signed_amount' => null,
                'service_at' => null,
            ]],
        ]);

        $this->assertSame([
            'source_event_count' => 0,
            'gross_amount' => 0,
            'reversal_amount' => 0,
            'net_amount' => 0,
            'latest_source_at' => null,
        ], $totals);
    }

    private function pharmacyEvent(string $eventType, int $amount, string $occurredAt): PharmacyFinancialSourceEvent
    {
        $event = new PharmacyFinancialSourceEvent;
        $event->forceFill([
            'event_type' => $eventType,
            'amount' => $amount,
            'occurred_at' => Carbon::parse($occurredAt),
        ]);

        return $event;
    }

    /** @return array{source_domain:string,state:string,preview_signed_amount:int,service_at:string} */
    private function ready(string $domain, int $amount, string $serviceAt): array
    {
        return [
            'source_domain' => $domain,
            'state' => 'SIAP_DISINKRONKAN',
            'preview_signed_amount' => $amount,
            'service_at' => $serviceAt,
        ];
    }
}
