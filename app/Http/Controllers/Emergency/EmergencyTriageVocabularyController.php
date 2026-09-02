<?php

namespace App\Http\Controllers\Emergency;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Emergency\Concerns\RespondsToEmergencyMutation;
use App\Models\EmergencyTriageVocabulary;
use App\Models\User;
use App\Support\Emergency\EmergencyActorPolicy;
use App\Support\Emergency\EmergencyTriageVocabularyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class EmergencyTriageVocabularyController extends Controller
{
    use RespondsToEmergencyMutation;

    public function __construct(
        private readonly EmergencyTriageVocabularyService $vocabularies,
        private readonly EmergencyActorPolicy $policy,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $this->policy->master($actor);

        $items = [];
        $readError = null;
        try {
            $items = EmergencyTriageVocabulary::query()
                ->with(['versions' => fn ($query) => $query->with('actor')->orderBy('version')])
                ->orderBy('vocabulary_code')
                ->get()
                ->map(function (EmergencyTriageVocabulary $vocabulary): array {
                    $currentVersion = $vocabulary->versions->firstWhere('version', $vocabulary->version);

                    return [
                        'public_id' => $vocabulary->public_id,
                        'vocabulary_code' => $vocabulary->vocabulary_code,
                        'display_name' => $vocabulary->display_name,
                        'state' => $vocabulary->state,
                        'version' => $vocabulary->version,
                        'categories' => $currentVersion ? $currentVersion->categories : [],
                        'versions' => $vocabulary->versions->map(fn ($version): array => [
                            'public_id' => $version->public_id,
                            'version' => $version->version,
                            'display_name' => $version->display_name,
                            'state' => $version->state,
                            'categories' => $version->categories,
                            'actor_name' => $version->actor->name,
                            'created_at' => $version->created_at->toIso8601String(),
                            'content_digest' => $version->content_digest,
                        ])->all(),
                        'actions' => [
                            'revise_url' => $vocabulary->state === EmergencyTriageVocabulary::ACTIVE
                                ? route('emergency.triage-vocabulary.revise', $vocabulary, false)
                                : null,
                        ],
                    ];
                })->all();
        } catch (\Throwable $exception) {
            report($exception);
            $readError = 'Data kosakata triage belum dapat dibaca. Coba muat ulang halaman.';
        }

        return Inertia::render('manajemen-data/triage/index', [
            'vocabularies' => $items,
            'permissions' => ['can_manage' => true],
            'commands' => ['create_url' => route('emergency.triage-vocabulary.create', absolute: false)],
            'read_error' => $readError,
        ]);
    }

    public function create(Request $request): RedirectResponse
    {
        $validated = $this->validated($request, revising: false);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $this->emergencyMutation(
            fn () => $this->vocabularies->create(
                $actor,
                $validated['code'],
                $validated['display_name'],
                $validated['categories'],
                $validated['idempotency_key'],
            ),
            'Kosakata triage telah dibuat.',
        );
    }

    public function revise(Request $request, string $vocabulary): RedirectResponse
    {
        $validated = $this->validated($request, revising: true);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $this->emergencyMutation(
            fn () => $this->vocabularies->revise(
                $vocabulary,
                $actor,
                $validated['expected_version'],
                $validated['display_name'],
                $validated['categories'],
                $validated['retire'],
                $validated['idempotency_key'],
            ),
            $validated['retire'] ? 'Kosakata triage telah dihentikan.' : 'Kosakata triage telah direvisi.',
        );
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $revising): array
    {
        return $request->validate([
            'code' => [$revising ? 'prohibited' : 'required', 'string', 'regex:/\A[A-Z0-9_]{3,64}\z/'],
            'expected_version' => [$revising ? 'required' : 'prohibited', 'integer', 'min:1'],
            'display_name' => ['required', 'string', 'min:3', 'max:160'],
            'categories' => ['required', 'array', 'size:4'],
            'categories.*.code' => ['required', Rule::in(EmergencyTriageVocabularyService::FIXED_CODES)],
            'categories.*.rank' => ['required', 'integer', 'between:1,4'],
            'categories.*.display_name' => ['required', 'string', 'min:2', 'max:120'],
            'categories.*.text_cue' => ['required', 'string', 'min:2', 'max:160'],
            'categories.*.colour_token' => ['required', 'string', 'regex:/\A[a-z][a-z0-9-]{1,31}\z/'],
            'categories.*.guidance_text' => ['nullable', 'string', 'max:1000'],
            'retire' => [$revising ? 'required' : 'prohibited', 'boolean'],
            'idempotency_key' => ['required', 'string', 'max:255'],
        ]);
    }
}
