<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Trainer;
use App\Models\TrainerCompensationRule;
use App\Models\TrainingSession;
use App\Services\AuditLogger;
use App\Services\NumberGenerator;
use App\Services\TrainerCompensationService;
use App\Support\BranchContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TrainerController extends Controller
{
    public function __construct(
        protected BranchContext $branchContext,
        protected NumberGenerator $numbers,
        protected AuditLogger $audit,
    ) {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Trainer::class);

        $trainers = Trainer::query()
            ->visibleTo($request->user())
            ->with('branch:id,uuid,name')
            ->withCount([
                'trainees',
                'trainingSessions as completed_sessions_count' => fn (Builder $q) => $q->where('status', 'completed'),
            ])
            ->when($request->filled('search'), function (Builder $q) use ($request) {
                $term = trim($request->string('search'));

                $q->where(fn (Builder $i) => $i
                    ->where('full_name', 'like', "%{$term}%")
                    ->orWhere('trainer_number', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%"));
            })
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->input('status')))
            ->orderBy('full_name')
            ->paginate(20)
            ->withQueryString();

        return view('admin.trainers.index', [
            'trainers' => $trainers,
            'statuses' => self::statuses(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Trainer::class);

        return view('admin.trainers.create', [
            'branches' => $this->branchOptions($request),
            'statuses' => self::statuses(),
            'compensationModels' => TrainerCompensationRule::MODELS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Trainer::class);

        $data = $this->validateTrainer($request);

        $trainer = DB::transaction(function () use ($data, $request) {
            $trainer = Trainer::create(array_merge($data, [
                'trainer_number' => $this->numbers->trainerNumber(),
                'branch_id' => $data['branch_id'] ?? $this->branchContext->defaultForWrite($request->user()),
            ]));

            $this->audit->logCreate('trainer.created', $trainer, 'إضافة مدرب جديد');

            return $trainer;
        });

        return redirect()
            ->route('admin.trainers.show', $trainer)
            ->with('toast', ['type' => 'success', 'message' => 'تم إضافة المدرب بنجاح.']);
    }

    public function show(Request $request, Trainer $trainer, TrainerCompensationService $compensation): View
    {
        $this->authorize('view', $trainer);

        $trainer->load(['branch', 'user', 'vehicles']);

        $data = [
            'trainer' => $trainer,
            'trainees' => $trainer->trainees()->active()->orderBy('full_name')->get(),
            'upcoming' => $trainer->trainingSessions()
                ->with('trainee:id,uuid,full_name')
                ->scheduled()
                ->where('scheduled_date', '>=', now()->toDateString())
                ->orderBy('scheduled_date')->orderBy('start_time')
                ->limit(10)->get(),
            'stats' => $this->monthlyStats($trainer),
        ];

        // Pay figures are only prepared for users allowed to see them.
        if ($request->user()->hasPermission('trainer_compensation.view')) {
            $data['currentRule'] = $trainer->ruleOn(now());
            $data['rules'] = $trainer->compensationRules()->orderByDesc('effective_from')->get();
            $data['records'] = $trainer->compensationRecords()->orderByDesc('period')->limit(12)->get();
        }

        return view('admin.trainers.show', $data);
    }

    public function edit(Request $request, Trainer $trainer): View
    {
        $this->authorize('update', $trainer);

        return view('admin.trainers.edit', [
            'trainer' => $trainer,
            'branches' => $this->branchOptions($request),
            'statuses' => self::statuses(),
        ]);
    }

    public function update(Request $request, Trainer $trainer): RedirectResponse
    {
        $this->authorize('update', $trainer);

        $data = $this->validateTrainer($request, $trainer);
        $original = $trainer->getOriginal();

        DB::transaction(function () use ($trainer, $data, $original) {
            $trainer->update($data);
            $this->audit->logUpdate('trainer.updated', $trainer, $original);
        });

        return redirect()
            ->route('admin.trainers.show', $trainer)
            ->with('toast', ['type' => 'success', 'message' => 'تم تحديث بيانات المدرب.']);
    }

    public function destroy(Trainer $trainer): RedirectResponse
    {
        $this->authorize('delete', $trainer);

        if ($trainer->trainingSessions()->scheduled()->exists()) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => 'لا يمكن أرشفة مدرب لديه حصص مجدولة. يجب إعادة جدولتها أولاً.',
            ]);
        }

        DB::transaction(function () use ($trainer) {
            $trainer->update(['status' => 'terminated']);
            $this->audit->logDelete('trainer.archived', $trainer);
            $trainer->delete();
        });

        return redirect()
            ->route('admin.trainers.index')
            ->with('toast', ['type' => 'success', 'message' => 'تم أرشفة المدرب.']);
    }

    /** Weekly schedule for one trainer. */
    public function schedule(Request $request, Trainer $trainer): View
    {
        $this->authorize('view', $trainer);

        $start = $request->filled('date')
            ? Carbon::parse($request->input('date'))->startOfWeek(Carbon::SUNDAY)
            : now()->startOfWeek(Carbon::SUNDAY);

        $end = $start->copy()->endOfWeek(Carbon::SATURDAY);

        return view('admin.trainers.schedule', [
            'trainer' => $trainer,
            'start' => $start,
            'end' => $end,
            'sessions' => TrainingSession::query()
                ->where('trainer_id', $trainer->id)
                ->with(['trainee:id,uuid,full_name', 'vehicle:id,uuid,name'])
                ->whereBetween('scheduled_date', [$start->toDateString(), $end->toDateString()])
                ->orderBy('scheduled_date')->orderBy('start_time')
                ->get()
                ->groupBy(fn ($s) => $s->scheduled_date->toDateString()),
        ]);
    }

    // ------------------------------------------------------------------

    protected function validateTrainer(Request $request, ?Trainer $trainer = null): array
    {
        return $request->validate([
            'full_name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9\s\-]{7,20}$/'],
            'national_id' => [
                'nullable', 'string', 'max:30', 'regex:/^[0-9]{6,20}$/',
                Rule::unique('trainers', 'national_id')->ignore($trainer?->id)->whereNull('deleted_at'),
            ],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'address' => ['nullable', 'string', 'max:255'],
            'employment_date' => ['required', 'date', 'before_or_equal:today'],
            'license_types' => ['nullable', 'array'],
            'license_types.*' => ['string', 'max:40'],
            'working_days' => ['nullable', 'array'],
            'working_days.*' => ['integer', 'between:0,6'],
            'work_start_time' => ['nullable', 'date_format:H:i'],
            'work_end_time' => ['nullable', 'date_format:H:i', 'after:work_start_time'],
            'branch_id' => ['nullable', 'integer', Rule::in($request->user()->accessibleBranchIds())],
            'status' => ['required', Rule::in(array_keys(self::statuses()))],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'full_name' => 'الاسم الكامل',
            'phone' => 'رقم الهاتف',
            'national_id' => 'الرقم الوطني',
            'employment_date' => 'تاريخ التعيين',
            'work_start_time' => 'بداية الدوام',
            'work_end_time' => 'نهاية الدوام',
            'branch_id' => 'الفرع',
            'status' => 'الحالة',
        ]);
    }

    /** Completed lessons and training minutes for the current month. */
    protected function monthlyStats(Trainer $trainer): array
    {
        $row = TrainingSession::query()
            ->where('trainer_id', $trainer->id)
            ->whereBetween('scheduled_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->selectRaw("SUM(status = 'completed') as completed")
            ->selectRaw("SUM(status = 'scheduled') as scheduled")
            ->selectRaw("SUM(status = 'cancelled') as cancelled")
            ->selectRaw("SUM(CASE WHEN status = 'completed' THEN duration_minutes ELSE 0 END) as minutes")
            ->first();

        return [
            'completed' => (int) ($row->completed ?? 0),
            'scheduled' => (int) ($row->scheduled ?? 0),
            'cancelled' => (int) ($row->cancelled ?? 0),
            'hours' => round((int) ($row->minutes ?? 0) / 60, 1),
        ];
    }

    protected function branchOptions(Request $request): array
    {
        return Branch::query()
            ->whereIn('id', $request->user()->accessibleBranchIds())
            ->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return [
            'active' => 'نشط',
            'on_leave' => 'في إجازة',
            'terminated' => 'منتهي الخدمة',
        ];
    }
}
