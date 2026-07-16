# Outpatient Visit Termination Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete `E2E-06` with an authorized, transactional, accessible cancellation/no-show workflow that preserves every completed synthetic outpatient action and its provenance.

**Architecture:** Add one patient-domain termination service behind a validated authenticated endpoint. The service reuses the append-only encounter transition/audit layer, synchronizes appointment/queue/open-task state under row locks, and exposes only server-derived actions to a focused registration-workspace dialog.

**Tech Stack:** PHP 8.3, Laravel 13, Eloquent transactions/row locks, Inertia 3, React 19, TypeScript, Radix Dialog, PHPUnit 12, Vitest/Testing Library, axe-core, Tailwind CSS 4.

## Global Constraints

- Simulation mode and synthetic data only; no real-patient or production path.
- Allowed source pairs are exactly `BOOKED`/`PLANNED` cancellation, `CHECKED_IN`/`ARRIVED` cancellation, and overdue `BOOKED`/`PLANNED` no-show.
- `reason` is trimmed, human-authored, required, and 10–500 characters.
- No-show eligibility is decided from persisted `scheduled_at` and the normalized Laravel server clock.
- Only an active exact-case or session-wide `PatientRegister` assignment may use the endpoint.
- The first committed terminal outcome/reason is immutable; an identical outcome is idempotent and a conflicting outcome is rejected.
- Preserve completed work tasks and `checked_in_at`; cancel only active queue entries and unfinished work tasks.
- Store free-text reason only in the existing protected transition/audit provenance, never in the public queue projection.
- Add no dependency and no database migration.
- Preserve untracked `deliverables/`; do not merge or deploy.

---

### Task 1: Transactional visit-termination domain and endpoint

**Files:**
- Create: `app/Modules/Patient/Enums/VisitTerminationOutcome.php`
- Create: `app/Modules/Patient/Services/AppointmentTerminationService.php`
- Create: `app/Http/Requests/Patient/TerminateAppointmentRequest.php`
- Create: `app/Http/Controllers/Patient/TerminateAppointmentController.php`
- Create: `tests/Feature/AppointmentTerminationTest.php`
- Modify: `routes/web.php:1-45`
- Generate: `resources/js/actions/App/Http/Controllers/Patient/TerminateAppointmentController.ts`
- Modify (generated): `resources/js/routes/appointments/index.ts`

**Interfaces:**
- Consumes: `AppointmentRegistration`, `EncounterTransitionService::transition(Encounter, EncounterStatus, Assignment, ?string): Encounter`, `AuditRecorder::record(...)`, and `AssignmentContextResolver::forEncounter(User, Encounter, Capability::PatientRegister)`.
- Produces: `VisitTerminationOutcome::{Cancelled,NoShow}`, `AppointmentTerminationService::terminate(AppointmentRegistration $appointment, Assignment $assignment, VisitTerminationOutcome $outcome, string $reason): AppointmentRegistration`, and named route `appointments.termination.store`.

- [ ] **Step 1: Write the failing backend feature tests**

Create `tests/Feature/AppointmentTerminationTest.php` with focused tests for arrived cancellation, planned no-show, idempotency/conflict, validation/state rejection, and authorization:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Audit\Models\AuditEvent;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Enums\QueueEventStatus;
use App\Modules\Encounter\Models\EncounterTransition;
use App\Modules\Encounter\Models\QueueEvent;
use App\Modules\Encounter\Services\EncounterTransitionService;
use App\Modules\Patient\Enums\AppointmentStatus;
use App\Modules\Patient\Models\AppointmentRegistration;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Enums\WorkTaskStatus;
use App\Modules\Teaching\Enums\WorkTaskType;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\WorkTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SeedsReferenceOutpatient;
use Tests\TestCase;

class AppointmentTerminationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceOutpatient;

    public function test_planned_cancellation_updates_appointment_and_encounter_atomically(): void
    {
        $this->seedReferenceOutpatient();
        $registrar = User::query()->where('email', 'mahasiswa.rmik@example.invalid')->firstOrFail();
        $appointment = AppointmentRegistration::query()->firstOrFail();
        $encounter = $appointment->encounter()->firstOrFail();

        $this->actingAs($registrar)->post(route('appointments.termination.store', $appointment), [
            'outcome' => 'CANCELLED',
            'reason' => 'Janji sintetis dibatalkan sebelum kedatangan.',
        ])->assertRedirect(route('sessions.registration', $appointment->session));

        $this->assertSame(AppointmentStatus::Cancelled, $appointment->refresh()->status);
        $this->assertSame(EncounterStatus::Cancelled, $encounter->refresh()->status);
        $this->assertNotNull($encounter->period_end);
        $this->assertDatabaseHas('work_tasks', [
            'encounter_id' => $encounter->getKey(),
            'task_type' => WorkTaskType::Registration->value,
            'status' => WorkTaskStatus::Cancelled->value,
        ]);
    }

    public function test_arrived_cancellation_preserves_check_in_and_cancels_only_open_work(): void
    {
        $this->seedReferenceOutpatient();
        $registrar = User::query()->where('email', 'mahasiswa.rmik@example.invalid')->firstOrFail();
        $appointment = AppointmentRegistration::query()->firstOrFail();
        $encounter = $appointment->encounter()->firstOrFail();
        $encounter = $appointment->encounter()->firstOrFail();

        $this->actingAs($registrar)->post(route('appointments.check-in', $appointment));
        $checkedInAt = $appointment->refresh()->checked_in_at;

        $this->actingAs($registrar)->post(route('appointments.termination.store', $appointment), [
            'outcome' => 'CANCELLED',
            'reason' => 'Kunjungan simulasi dibatalkan setelah check-in untuk latihan.',
        ])->assertRedirect(route('sessions.registration', $appointment->session));

        $this->assertSame(AppointmentStatus::Cancelled, $appointment->refresh()->status);
        $this->assertTrue($appointment->checked_in_at->equalTo($checkedInAt));
        $this->assertSame(EncounterStatus::Cancelled, $encounter->refresh()->status);
        $this->assertNotNull($encounter->period_end);
        $this->assertDatabaseHas('queue_events', [
            'encounter_id' => $encounter->getKey(),
            'status' => QueueEventStatus::Cancelled->value,
            'reason' => 'encounter_cancelled',
        ]);
        $this->assertDatabaseHas('work_tasks', [
            'encounter_id' => $encounter->getKey(),
            'task_type' => WorkTaskType::Registration->value,
            'status' => WorkTaskStatus::Complete->value,
        ]);
        $this->assertDatabaseHas('work_tasks', [
            'encounter_id' => $encounter->getKey(),
            'task_type' => WorkTaskType::NursingIntake->value,
            'status' => WorkTaskStatus::Cancelled->value,
        ]);
        $this->assertDatabaseHas('encounter_transitions', [
            'encounter_id' => $encounter->getKey(),
            'to_status' => EncounterStatus::Cancelled->value,
            'reason' => 'Kunjungan simulasi dibatalkan setelah check-in untuk latihan.',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'appointment.terminated',
            'resource_id' => $appointment->public_id,
            'encounter_id' => $encounter->getKey(),
        ]);

        $publicQueue = $this->getJson(route('queue-display', $appointment->session));
        $publicQueue->assertOk()->assertJsonCount(0, 'entries');
        $this->assertStringNotContainsString(
            'Kunjungan simulasi dibatalkan setelah check-in untuk latihan.',
            $publicQueue->getContent(),
        );
    }

    public function test_overdue_planned_appointment_can_be_marked_no_show_without_queue_creation(): void
    {
        Carbon::setTestNow('2026-07-16 10:00:00');
        $this->seedReferenceOutpatient();
        $registrar = User::query()->where('email', 'mahasiswa.rmik@example.invalid')->firstOrFail();
        $appointment = AppointmentRegistration::query()->firstOrFail();
        $appointment->forceFill(['scheduled_at' => now()->subHour()])->save();
        $encounter = $appointment->encounter()->firstOrFail();

        $this->actingAs($registrar)->post(route('appointments.termination.store', $appointment), [
            'outcome' => 'NO_SHOW',
            'reason' => 'Pasien sintetis tidak hadir pada waktu latihan terjadwal.',
        ])->assertRedirect(route('sessions.registration', $appointment->session));

        $this->assertSame(AppointmentStatus::NoShow, $appointment->refresh()->status);
        $this->assertSame(EncounterStatus::NoShow, $encounter->refresh()->status);
        $this->assertSame(0, QueueEvent::query()->where('encounter_id', $encounter->getKey())->count());
    }

    public function test_repeated_same_outcome_is_idempotent_and_conflicting_outcome_is_rejected(): void
    {
        $this->seedReferenceOutpatient();
        $registrar = User::query()->where('email', 'mahasiswa.rmik@example.invalid')->firstOrFail();
        $appointment = AppointmentRegistration::query()->firstOrFail();

        $payload = ['outcome' => 'CANCELLED', 'reason' => 'Pembatalan sintetis yang dapat diaudit.'];
        $this->actingAs($registrar)->post(route('appointments.termination.store', $appointment), $payload);
        $this->actingAs($registrar)->post(route('appointments.termination.store', $appointment), $payload);

        $this->assertSame(1, EncounterTransition::query()
            ->where('encounter_id', $encounter->getKey())
            ->where('to_status', EncounterStatus::Cancelled->value)
            ->count());
        $this->assertSame(1, AuditEvent::query()
            ->where('action', 'appointment.terminated')
            ->where('resource_id', $appointment->public_id)
            ->count());

        $this->actingAs($registrar)->post(route('appointments.termination.store', $appointment), [
            'outcome' => 'NO_SHOW',
            'reason' => 'Outcome terminal yang berbeda harus ditolak.',
        ])->assertStatus(409);
    }

    public function test_invalid_reason_time_state_and_actor_are_rejected_without_mutation(): void
    {
        Carbon::setTestNow('2026-07-16 08:00:00');
        $this->seedReferenceOutpatient();
        $registrar = User::query()->where('email', 'mahasiswa.rmik@example.invalid')->firstOrFail();
        $nurse = User::query()->where('email', 'mahasiswa.keperawatan@example.invalid')->firstOrFail();
        $appointment = AppointmentRegistration::query()->firstOrFail();
        $appointment->forceFill(['scheduled_at' => now()->addHour()])->save();

        $this->actingAs($registrar)->post(route('appointments.termination.store', $appointment), [
            'outcome' => 'NO_SHOW',
            'reason' => 'Belum waktunya menandai tidak hadir.',
        ])->assertStatus(409);
        $this->actingAs($registrar)->post(route('appointments.termination.store', $appointment), [
            'outcome' => 'CANCELLED',
            'reason' => 'pendek',
        ])->assertSessionHasErrors('reason');
        $this->actingAs($nurse)->post(route('appointments.termination.store', $appointment), [
            'outcome' => 'CANCELLED',
            'reason' => 'Disiplin yang salah tidak boleh membatalkan kunjungan.',
        ])->assertForbidden();

        $this->assertSame(AppointmentStatus::Booked, $appointment->refresh()->status);
        $this->assertSame(EncounterStatus::Planned, $appointment->encounter()->firstOrFail()->status);
    }

    public function test_registrar_endpoint_rejects_later_clinical_states(): void
    {
        $this->seedReferenceOutpatient();
        $registrar = User::query()->where('email', 'mahasiswa.rmik@example.invalid')->firstOrFail();
        $nurse = User::query()->where('email', 'mahasiswa.keperawatan@example.invalid')->firstOrFail();
        $appointment = AppointmentRegistration::query()->firstOrFail();
        $encounter = $appointment->encounter()->firstOrFail();

        $this->actingAs($registrar)->post(route('appointments.check-in', $appointment));
        $nursingAssignment = Assignment::query()
            ->where('user_id', $nurse->getKey())
            ->where('encounter_id', $encounter->getKey())
            ->firstOrFail();
        $this->app->make(EncounterTransitionService::class)->transition(
            $encounter->refresh(),
            EncounterStatus::InIntake,
            $nursingAssignment,
            'test_fixture_intake_started',
        );

        $this->actingAs($registrar)->post(route('appointments.termination.store', $appointment), [
            'outcome' => 'CANCELLED',
            'reason' => 'Registrasi tidak boleh mengakhiri tahap klinis aktif.',
        ])->assertStatus(409);

        $this->assertSame(AppointmentStatus::CheckedIn, $appointment->refresh()->status);
        $this->assertSame(EncounterStatus::InIntake, $encounter->refresh()->status);
    }

    public function test_unassigned_and_revoked_registrars_are_denied(): void
    {
        $this->seedReferenceOutpatient();
        $appointment = AppointmentRegistration::query()->firstOrFail();
        $registrar = User::query()->where('email', 'mahasiswa.rmik@example.invalid')->firstOrFail();
        $unassigned = User::factory()->create(['email_verified_at' => now()]);
        $payload = [
            'outcome' => 'CANCELLED',
            'reason' => 'Permintaan terminasi dengan konteks yang tidak sah.',
        ];

        $this->actingAs($unassigned)
            ->post(route('appointments.termination.store', $appointment), $payload)
            ->assertForbidden();

        Assignment::query()
            ->where('user_id', $registrar->getKey())
            ->whereJsonContains('capabilities', Capability::PatientRegister->value)
            ->update(['revoked_at' => now()]);

        $this->actingAs($registrar)
            ->post(route('appointments.termination.store', $appointment), $payload)
            ->assertForbidden();

        $this->assertSame(AppointmentStatus::Booked, $appointment->refresh()->status);
    }
}
```

- [ ] **Step 2: Run the focused tests and verify the RED state**

Run:

```bash
php artisan test tests/Feature/AppointmentTerminationTest.php
```

Expected: FAIL because `appointments.termination.store` and the termination classes do not exist. Fix only test syntax/bootstrap errors until the failure is specifically the missing feature.

- [ ] **Step 3: Add the outcome enum and validated request**

Create `VisitTerminationOutcome`:

```php
<?php

namespace App\Modules\Patient\Enums;

use App\Modules\Encounter\Enums\EncounterStatus;

enum VisitTerminationOutcome: string
{
    case Cancelled = 'CANCELLED';
    case NoShow = 'NO_SHOW';

    public function appointmentStatus(): AppointmentStatus
    {
        return match ($this) {
            self::Cancelled => AppointmentStatus::Cancelled,
            self::NoShow => AppointmentStatus::NoShow,
        };
    }

    public function encounterStatus(): EncounterStatus
    {
        return match ($this) {
            self::Cancelled => EncounterStatus::Cancelled,
            self::NoShow => EncounterStatus::NoShow,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Cancelled => 'Batalkan kunjungan',
            self::NoShow => 'Tandai tidak hadir',
        };
    }
}
```

Create `TerminateAppointmentRequest`:

```php
<?php

namespace App\Http\Requests\Patient;

use App\Modules\Patient\Enums\VisitTerminationOutcome;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TerminateAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'outcome' => ['required', Rule::enum(VisitTerminationOutcome::class)],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['reason' => trim((string) $this->input('reason', ''))]);
    }
}
```

- [ ] **Step 4: Implement the transactional service**

Create `AppointmentTerminationService` with this public method and private guards:

```php
public function terminate(
    AppointmentRegistration $appointment,
    Assignment $assignment,
    VisitTerminationOutcome $outcome,
    string $reason,
): AppointmentRegistration {
    return DB::transaction(function () use ($appointment, $assignment, $outcome, $reason): AppointmentRegistration {
        $locked = AppointmentRegistration::query()
            ->with(['encounter.session', 'encounter.patient', 'location'])
            ->whereKey($appointment->getKey())
            ->lockForUpdate()
            ->firstOrFail();
        $encounter = $locked->encounter;

        if (! $encounter) {
            throw new DomainException('Encounter terencana tidak tersedia untuk terminasi.');
        }

        $this->assertAssignmentContext($locked, $encounter, $assignment);
        $targetAppointment = $outcome->appointmentStatus();
        $targetEncounter = $outcome->encounterStatus();

        if ($locked->status === $targetAppointment && $encounter->status === $targetEncounter) {
            return $locked;
        }

        if (in_array($locked->status, [AppointmentStatus::Cancelled, AppointmentStatus::NoShow], true)) {
            throw new DomainException('Kunjungan sudah memiliki outcome terminal yang berbeda.');
        }

        $this->assertAllowedSource($locked, $encounter, $outcome);

        $encounter = $this->transitionService->transition(
            $encounter,
            $targetEncounter,
            $assignment,
            $reason,
        );

        $locked->forceFill(['status' => $targetAppointment])->save();

        $queueEventsEnded = QueueEvent::query()
            ->where('encounter_id', $encounter->getKey())
            ->whereIn('status', [
                QueueEventStatus::Waiting->value,
                QueueEventStatus::Called->value,
                QueueEventStatus::InService->value,
                QueueEventStatus::Held->value,
            ])
            ->update([
                'status' => QueueEventStatus::Cancelled->value,
                'reason' => $outcome === VisitTerminationOutcome::NoShow
                    ? 'appointment_no_show'
                    : 'encounter_cancelled',
                'ended_at' => now(),
            ]);

        $tasksCancelled = WorkTask::query()
            ->where('encounter_id', $encounter->getKey())
            ->whereNotIn('status', [WorkTaskStatus::Complete->value, WorkTaskStatus::Cancelled->value])
            ->update(['status' => WorkTaskStatus::Cancelled->value, 'completed_at' => now()]);

        $this->auditRecorder->record(
            action: 'appointment.terminated',
            resourceType: 'appointment_registration',
            resourceId: $locked->public_id,
            actor: $assignment->user,
            assignment: $assignment,
            session: $encounter->session,
            encounter: $encounter,
            metadata: [
                'appointment_status' => $targetAppointment->value,
                'encounter_status' => $targetEncounter->value,
                'queue_events_ended' => $queueEventsEnded,
                'tasks_cancelled' => $tasksCancelled,
            ],
        );

        return $locked->refresh()->load(['encounter.patient.identifiers', 'encounter.location']);
    });
}
```

Implement `assertAssignmentContext()` using the same exact-case/session-wide registrar checks as `AppointmentCheckInService`. Implement `assertAllowedSource()` with only these predicates:

```php
$planned = $appointment->status === AppointmentStatus::Booked
    && $encounter->status === EncounterStatus::Planned;
$arrived = $appointment->status === AppointmentStatus::CheckedIn
    && $encounter->status === EncounterStatus::Arrived;

if ($outcome === VisitTerminationOutcome::Cancelled && ($planned || $arrived)) {
    return;
}

if ($outcome === VisitTerminationOutcome::NoShow
    && $planned
    && $appointment->scheduled_at->lessThanOrEqualTo(now())) {
    return;
}

throw new DomainException('Kunjungan tidak dapat diterminasi dari status atau waktu saat ini.');
```

- [ ] **Step 5: Add the controller and route**

Create `TerminateAppointmentController`:

```php
public function __invoke(
    TerminateAppointmentRequest $request,
    AppointmentRegistration $appointment,
): RedirectResponse {
    $user = $request->user();

    if (! $user instanceof User) {
        abort(401);
    }

    $appointment->loadMissing(['encounter.session']);
    $encounter = $appointment->encounter;

    if (! $encounter) {
        abort(409, 'Encounter terencana tidak tersedia.');
    }

    $assignment = $this->assignmentResolver->forEncounter($user, $encounter, Capability::PatientRegister);
    /** @var array{outcome: string, reason: string} $payload */
    $payload = $request->validated();

    try {
        $this->terminationService->terminate(
            $appointment,
            $assignment,
            VisitTerminationOutcome::from($payload['outcome']),
            $payload['reason'],
        );
    } catch (DomainException) {
        abort(409, 'Kunjungan tidak dapat diterminasi dari status atau waktu saat ini.');
    }

    return to_route('sessions.registration', $appointment->session)
        ->with('success', 'Outcome kunjungan sintetis tercatat tanpa menghapus riwayat.');
}
```

Register it inside the existing authenticated simulation route group:

```php
Route::post('appointments/{appointment}/termination', TerminateAppointmentController::class)
    ->name('appointments.termination.store');
```

Generate Wayfinder bindings:

```bash
php artisan wayfinder:generate --with-form
```

Expected: generated action/route modules include the new POST endpoint and no unrelated route changes.

- [ ] **Step 6: Run focused backend tests and verify GREEN**

Run:

```bash
php artisan test tests/Feature/AppointmentTerminationTest.php tests/Feature/AppointmentCheckInTest.php tests/Feature/EncounterStateMachineTest.php
```

Expected: all focused tests pass; no duplicate transition/audit/queue/task mutations occur.

- [ ] **Step 7: Commit the backend increment**

```bash
git add app/Http/Controllers/Patient/TerminateAppointmentController.php app/Http/Requests/Patient/TerminateAppointmentRequest.php app/Modules/Patient/Enums/VisitTerminationOutcome.php app/Modules/Patient/Services/AppointmentTerminationService.php routes/web.php resources/js/actions/App/Http/Controllers/Patient/TerminateAppointmentController.ts resources/js/routes/appointments/index.ts tests/Feature/AppointmentTerminationTest.php
git commit -m "feat: terminate synthetic outpatient visits"
```

---

### Task 2: Server-derived registration action contract

**Files:**
- Modify: `app/Http/Controllers/Patient/RegistrationWorkspaceController.php:48-145`
- Modify: `tests/Feature/AppointmentTerminationTest.php`
- Modify: `resources/js/types/outpatient.ts:16-36`
- Modify: `resources/js/test/registration-workspace.test.tsx:20-95`

**Interfaces:**
- Consumes: current appointment/encounter statuses, persisted schedule, and named route `appointments.termination.store`.
- Produces: `RegistrationAppointment.termination` with `{ url: string; canCancel: boolean; canMarkNoShow: boolean }`.

- [ ] **Step 1: Add failing Inertia payload tests**

Add three assertions to `AppointmentTerminationTest` using the session registration route:

```php
public function test_registration_payload_derives_only_allowed_termination_actions(): void
{
    Carbon::setTestNow('2026-07-16 10:00:00');
    $this->seedReferenceOutpatient();
    $registrar = User::query()->where('email', 'mahasiswa.rmik@example.invalid')->firstOrFail();
    $appointment = AppointmentRegistration::query()->firstOrFail();
    $appointment->forceFill(['scheduled_at' => now()->subHour()])->save();
    $session = $appointment->session;

    $this->actingAs($registrar)
        ->get(route('sessions.registration', $session))
        ->assertInertia(fn (Assert $page) => $page
            ->where('appointments.0.termination.canCancel', true)
            ->where('appointments.0.termination.canMarkNoShow', true)
            ->where('appointments.0.termination.url', route('appointments.termination.store', $appointment)));

    $this->actingAs($registrar)->post(route('appointments.check-in', $appointment));

    $this->actingAs($registrar)
        ->get(route('sessions.registration', $session))
        ->assertInertia(fn (Assert $page) => $page
            ->where('appointments.0.termination.canCancel', true)
            ->where('appointments.0.termination.canMarkNoShow', false));
}
```

Add a terminal-state assertion after cancellation: both flags are false.

- [ ] **Step 2: Run the payload test and verify RED**

Run:

```bash
php artisan test tests/Feature/AppointmentTerminationTest.php --filter=registration_payload
```

Expected: FAIL because `appointments.0.termination` is missing.

- [ ] **Step 3: Derive the payload exclusively on the server**

In `RegistrationWorkspaceController::appointmentPayload()` calculate:

```php
$planned = $appointment->status === AppointmentStatus::Booked
    && $encounter?->status === EncounterStatus::Planned;
$arrived = $appointment->status === AppointmentStatus::CheckedIn
    && $encounter?->status === EncounterStatus::Arrived;

'termination' => [
    'url' => route('appointments.termination.store', $appointment),
    'canCancel' => $planned || $arrived,
    'canMarkNoShow' => $planned && $appointment->scheduled_at->lessThanOrEqualTo(now()),
],
```

Import `EncounterStatus`. Do not derive these flags in React.

Update `RegistrationAppointment`:

```ts
termination: {
    url: string;
    canCancel: boolean;
    canMarkNoShow: boolean;
};
```

Add the same `termination` object to the booked fixture in `registration-workspace.test.tsx` so the new required TypeScript contract remains green before the dialog task begins:

```ts
termination: {
    url: '/appointments/example/termination',
    canCancel: true,
    canMarkNoShow: false,
},
```

- [ ] **Step 4: Run backend tests and TypeScript to verify GREEN**

```bash
php artisan test tests/Feature/AppointmentTerminationTest.php tests/Feature/AppointmentCheckInTest.php
npm run types:check
```

Expected: backend payload tests and TypeScript both pass; every current `RegistrationAppointment` fixture includes the server-derived `termination` contract.

- [ ] **Step 5: Commit the read-model contract**

```bash
git add app/Http/Controllers/Patient/RegistrationWorkspaceController.php tests/Feature/AppointmentTerminationTest.php resources/js/types/outpatient.ts resources/js/test/registration-workspace.test.tsx
git commit -m "feat: expose visit termination actions"
```

---

### Task 3: Accessible termination dialog and frontend regression coverage

**Files:**
- Create: `resources/js/components/visit-termination-dialog.tsx`
- Create: `resources/js/components/visit-termination-dialog.test.tsx`
- Modify: `resources/js/pages/patient/registration.tsx:1-125,380-480`
- Modify: `resources/js/test/registration-workspace.test.tsx`

**Interfaces:**
- Consumes: `RegistrationAppointment.termination` and Inertia `router.post(url, { outcome, reason }, options)`.
- Produces: `VisitTerminationDialog({ appointment }: { appointment: RegistrationAppointment })` with accessible cancellation/no-show selection and reason submission.

- [ ] **Step 1: Write failing component tests**

Create `visit-termination-dialog.test.tsx` with an appointment fixture whose server flags allow both outcomes:

```tsx
import axe from 'axe-core';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import VisitTerminationDialog from '@/components/visit-termination-dialog';
import type { RegistrationAppointment } from '@/types';

const inertia = vi.hoisted(() => ({ post: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    router: { post: inertia.post },
}));

const appointment: RegistrationAppointment = {
    publicId: '01J00000000000000000000003',
    appointmentCode: 'APT-SIM-000001',
    scheduledAt: '2026-07-16T08:00:00+07:00',
    visitReason: 'Evaluasi keluhan sintetis.',
    status: { code: 'BOOKED', label: 'Terjadwal' },
    canCheckIn: true,
    checkInUrl: '/appointments/example/check-in',
    termination: {
        url: '/appointments/example/termination',
        canCancel: true,
        canMarkNoShow: true,
    },
    patient: {
        publicId: '01J00000000000000000000004',
        fullName: 'Pasien Sintetis Arunika',
        birthDate: '1992-04-18',
        mrn: 'MR-SIM-000001',
        synthetic: true,
    },
    encounter: {
        publicId: '01J00000000000000000000005',
        number: 'ENC-SIM-000001',
        status: { code: 'PLANNED', label: 'Terencana' },
        location: 'Poliklinik Umum Simulasi UEU',
        url: '/encounters/example',
    },
};

afterEach(() => vi.clearAllMocks());

describe('visit termination dialog', () => {
    it('submits the server-allowed outcome and attributed reason', async () => {
        const user = userEvent.setup();
        render(<VisitTerminationDialog appointment={appointment} />);

        await user.click(screen.getByRole('button', { name: 'Akhiri kunjungan' }));
        await user.selectOptions(screen.getByLabelText('Outcome kunjungan'), 'NO_SHOW');
        await user.type(
            screen.getByLabelText('Alasan terminasi'),
            'Pasien sintetis tidak hadir pada jadwal latihan.',
        );
        await user.click(screen.getByRole('button', { name: 'Konfirmasi terminasi' }));

        expect(inertia.post).toHaveBeenCalledWith(
            '/appointments/example/termination',
            {
                outcome: 'NO_SHOW',
                reason: 'Pasien sintetis tidak hadir pada jadwal latihan.',
            },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('is axe clean when open', async () => {
        const user = userEvent.setup();
        const { container } = render(<VisitTerminationDialog appointment={appointment} />);
        await user.click(screen.getByRole('button', { name: 'Akhiri kunjungan' }));
        expect((await axe.run(container)).violations).toHaveLength(0);
    });
});
```

Extend `registration-workspace.test.tsx` fixtures with `termination`, and assert that terminal/later-state cards render no `Akhiri kunjungan` button.

- [ ] **Step 2: Run frontend tests and verify RED**

```bash
npm run test:unit -- resources/js/components/visit-termination-dialog.test.tsx resources/js/test/registration-workspace.test.tsx
```

Expected: FAIL because `VisitTerminationDialog` does not exist and the registration page does not render it.

- [ ] **Step 3: Implement the focused dialog component**

Create a controlled Radix dialog with local request state:

```tsx
import { router } from '@inertiajs/react';
import { Ban } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import type { RegistrationAppointment } from '@/types';

type Outcome = 'CANCELLED' | 'NO_SHOW';

export default function VisitTerminationDialog({
    appointment,
}: {
    appointment: RegistrationAppointment;
}) {
    const [open, setOpen] = useState(false);
    const [outcome, setOutcome] = useState<Outcome>('CANCELLED');
    const [reason, setReason] = useState('');
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<{ reason?: string; workflow?: string }>({});
    const allowed = appointment.termination.canCancel || appointment.termination.canMarkNoShow;

    if (!allowed) return null;

    function submit() {
        setProcessing(true);
        setErrors({});
        router.post(
            appointment.termination.url,
            { outcome, reason },
            {
                preserveScroll: true,
                onError: (next) => setErrors({
                    reason: typeof next.reason === 'string' ? next.reason : undefined,
                    workflow: typeof next.workflow === 'string' ? next.workflow : undefined,
                }),
                onSuccess: () => {
                    setOpen(false);
                    setOutcome('CANCELLED');
                    setReason('');
                },
                onFinish: () => setProcessing(false),
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button type="button" size="sm" variant="outline">
                    <Ban className="size-4" aria-hidden="true" />
                    Akhiri kunjungan
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Akhiri kunjungan sintetis</DialogTitle>
                    <DialogDescription>
                        Outcome ini terminal dan tidak menghapus check-in, tugas selesai, atau riwayat sebelumnya.
                    </DialogDescription>
                </DialogHeader>
                <div className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor={`termination-outcome-${appointment.publicId}`}>Outcome kunjungan</Label>
                        <select
                            id={`termination-outcome-${appointment.publicId}`}
                            value={outcome}
                            onChange={(event) => setOutcome(event.target.value as Outcome)}
                            className="min-h-11 w-full rounded-md border border-input bg-background px-3"
                        >
                            {appointment.termination.canCancel && <option value="CANCELLED">Batalkan kunjungan</option>}
                            {appointment.termination.canMarkNoShow && <option value="NO_SHOW">Tandai tidak hadir</option>}
                        </select>
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor={`termination-reason-${appointment.publicId}`}>Alasan terminasi</Label>
                        <textarea
                            id={`termination-reason-${appointment.publicId}`}
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                            minLength={10}
                            maxLength={500}
                            required
                            aria-invalid={Boolean(errors.reason)}
                            aria-describedby={errors.reason ? `termination-reason-error-${appointment.publicId}` : undefined}
                            className="min-h-24 w-full rounded-md border border-input bg-background px-3 py-2"
                        />
                        <InputError id={`termination-reason-error-${appointment.publicId}`} message={errors.reason} />
                    </div>
                    <InputError message={errors.workflow} />
                </div>
                <DialogFooter>
                    <DialogClose asChild><Button type="button" variant="secondary">Kembali</Button></DialogClose>
                    <Button
                        type="button"
                        variant="destructive"
                        disabled={processing || reason.trim().length < 10}
                        onClick={submit}
                    >
                        {processing ? 'Mencatat…' : 'Konfirmasi terminasi'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
```

- [ ] **Step 4: Integrate the component into appointment cards**

Import and render it next to Check-in and encounter navigation:

```tsx
<VisitTerminationDialog appointment={appointment} />
```

Update `StatusBadge` so `CANCELLED` and `NO_SHOW` use neutral/slate terminal styling instead of the booked sky styling. Do not compute action eligibility in React.

- [ ] **Step 5: Run frontend verification and fix only implementation defects**

```bash
npm run test:unit -- resources/js/components/visit-termination-dialog.test.tsx resources/js/test/registration-workspace.test.tsx
npm run types:check
npm run lint:check
npm run format:check
```

Expected: all commands exit 0; the dialog test reports no axe violations.

- [ ] **Step 6: Commit the frontend increment**

```bash
git add resources/js/components/visit-termination-dialog.tsx resources/js/components/visit-termination-dialog.test.tsx resources/js/pages/patient/registration.tsx resources/js/test/registration-workspace.test.tsx
git commit -m "feat: add visit termination controls"
```

---

### Task 4: Traceability, UAT, and browser evidence

**Files:**
- Modify: `docs/adr/ADR-003-OUTPATIENT-DOMAIN-SPINE.md:55-70`
- Modify: `docs/product/OUTPATIENT_TRACEABILITY_MATRIX.md:20-65,100-110`
- Modify: `docs/operations/OUTPATIENT_DOMAIN_SPINE_VALIDATION.md`
- Modify: `docs/operations/OUTPATIENT_CHECKPOINT_2_UAT_GUIDE.md`
- Modify: `README.md:20-32`

**Interfaces:**
- Consumes: focused backend/frontend results and the actual supported-browser observation.
- Produces: explicit `E2E-06` evidence without claiming stakeholder acceptance, native keyboard completion, production suitability, merge, or deployment.

- [ ] **Step 1: Update authoritative documents**

Make these exact semantic changes:

- ADR-003: replace “cancellation/no-show UI … not implemented” with a statement that registrar cancellation is implemented only for `PLANNED`/`ARRIVED`, overdue no-show only for `PLANNED`, and later clinical termination remains outside the increment.
- Traceability matrix: add `AppointmentTerminationTest` and `VisitTerminationDialog` evidence to `EMR-001`, `EMR-002`, `OPD-001`, `AUD-01`, and `E2E-06`; retain `IN PROGRESS` while stakeholder gates remain open.
- Validation record: record exact focused/full test counts from the fresh run and explicitly state what the browser pass did or did not prove.
- UAT guide: add registrar steps for cancellation after check-in and overdue no-show, including checks that history remains and the public queue entry disappears.
- README: add the bounded visit-termination increment to the current reference-workflow summary.

- [ ] **Step 2: Run the supported-browser rehearsal on disposable synthetic fixtures**

Use a fresh SQLite database and the existing simulation accounts. Exercise two separate cloned/fresh fixtures:

1. Registrar checks in the appointment, opens `Akhiri kunjungan`, submits `CANCELLED` with a synthetic reason, and verifies appointment/encounter terminal labels, preserved check-in timeline, cancelled public queue entry, and no console warning/error.
2. Registrar opens an overdue booked appointment, submits `NO_SHOW`, and verifies there is no queue entry and the encounter timeline records the attributed terminal transition.

For each observed page, record one `main`, one visible `h1`, permanent simulation labelling, no duplicate IDs, no page-level horizontal overflow, and dialog focus return if the browser surface can dispatch it. Do not claim native sequential keyboard traversal or native print/PDF review from this rehearsal.

- [ ] **Step 3: Validate documentation**

Run the tracked Markdown link/fence validator used by `.github/workflows/documentation-checks.yml`, plus:

```bash
git diff --check
rg -n "cancellation/no-show UI.*not implemented|E2E-06.*incomplete" README.md docs
```

Expected: 0 Markdown failures, 0 whitespace errors, and no stale claim that the bounded workflow is wholly unimplemented.

- [ ] **Step 4: Commit documentation and evidence**

```bash
git add README.md docs/adr/ADR-003-OUTPATIENT-DOMAIN-SPINE.md docs/product/OUTPATIENT_TRACEABILITY_MATRIX.md docs/operations/OUTPATIENT_DOMAIN_SPINE_VALIDATION.md docs/operations/OUTPATIENT_CHECKPOINT_2_UAT_GUIDE.md
git commit -m "docs: validate outpatient visit termination"
```

---

### Task 5: Full verification, publication, and draft-PR monitoring

**Files:**
- Modify only if verification exposes a defect in an already scoped file.
- Preserve: `deliverables/`

**Interfaces:**
- Consumes: all commits from Tasks 1–4.
- Produces: a pushed `agent/outpatient-domain-spine` head and refreshed draft PR #10 with green checks; no merge/deployment.

- [ ] **Step 1: Run the full local application gate**

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=512M
php artisan test
php artisan wayfinder:generate --with-form
npm run lint:check
npm run format:check
npm run types:check
npm run test:unit
npm run build
composer validate --strict
composer audit --locked
npm audit --audit-level=moderate
git diff --check
```

Expected: every command exits 0. Record exact PHPUnit test/assertion counts and Vitest file/test counts rather than reusing an older snapshot.

- [ ] **Step 2: Inspect the final scope before publication**

```bash
git status --short
git diff origin/agent/outpatient-domain-spine...HEAD --stat
git diff origin/agent/outpatient-domain-spine...HEAD --name-only
```

Expected: only the design/plan, scoped backend/frontend/tests/docs, and deterministic Wayfinder outputs appear; `deliverables/` remains untracked and unstaged.

- [ ] **Step 3: Push the existing branch and refresh draft PR #10**

```bash
git push origin agent/outpatient-domain-spine
gh pr view 10 --repo danielhappyg/simrs-campus-ueu --json isDraft,state,mergedAt,headRefName,headRefOid,url
```

Expected: PR #10 remains `OPEN`, `isDraft: true`, `mergedAt: null`, on `agent/outpatient-domain-spine`.

Update the PR body with:

- `E2E-06` cancellation/no-show scope and non-goals;
- exact local backend/frontend counts;
- supported-browser evidence and limitations;
- unchanged synthetic-only/human-decision boundaries; and
- explicit “no merge or deployment” status.

- [ ] **Step 4: Monitor all remote gates**

```bash
gh pr checks 10 --repo danielhappyg/simrs-campus-ueu --watch --interval 10
```

Expected: documentation, application/security, MySQL 8.4, and `Build release candidate (no deploy)` all pass. If a check fails, inspect that job's logs, fix only the scoped regression, rerun local evidence, commit, push, and monitor again.

- [ ] **Step 5: Final handoff**

Report:

- final commit SHA and draft PR URL;
- exact local and remote verification evidence;
- browser evidence and remaining native keyboard/print limitations;
- confirmation that `deliverables/` was untouched; and
- confirmation that no merge or deployment occurred.
