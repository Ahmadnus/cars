<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Cashbox;
use App\Models\Organization;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BranchController extends Controller
{
    public function __construct(protected AuditLogger $audit)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Branch::class);

        return view('admin.branches.index', [
            'branches' => Branch::query()
                ->whereIn('id', $request->user()->accessibleBranchIds())
                ->withCount(['trainees', 'trainers', 'employees', 'vehicles'])
                ->with('organization:id,name')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Branch::class);

        return view('admin.branches.create', [
            'organizations' => Organization::orderBy('name')->pluck('name', 'id')->all(),
            'days' => self::days(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Branch::class);

        $data = $this->validateBranch($request);

        $branch = DB::transaction(function () use ($data) {
            $branch = Branch::create(array_merge($data, [
                'working_hours' => $this->normaliseHours($data['working_hours'] ?? []),
            ]));

            // Every branch needs a cashbox before any cash can move through it.
            Cashbox::create([
                'branch_id' => $branch->id,
                'name' => 'الصندوق الرئيسي',
                'opening_balance' => 0,
                'current_balance' => 0,
                'status' => 'active',
            ]);

            $this->audit->logCreate('branch.created', $branch, 'إنشاء فرع جديد');

            return $branch;
        });

        return redirect()
            ->route('admin.branches.index')
            ->with('toast', ['type' => 'success', 'message' => "تم إنشاء الفرع «{$branch->name}» وصندوقه."]);
    }

    public function edit(Branch $branch): View
    {
        $this->authorize('update', $branch);

        return view('admin.branches.edit', [
            'branch' => $branch,
            'organizations' => Organization::orderBy('name')->pluck('name', 'id')->all(),
            'days' => self::days(),
        ]);
    }

    public function update(Request $request, Branch $branch): RedirectResponse
    {
        $this->authorize('update', $branch);

        $data = $this->validateBranch($request, $branch);
        $original = $branch->getOriginal();

        DB::transaction(function () use ($branch, $data, $original) {
            $branch->update(array_merge($data, [
                'working_hours' => $this->normaliseHours($data['working_hours'] ?? []),
            ]));

            $this->audit->logUpdate('branch.updated', $branch, $original);
        });

        return redirect()
            ->route('admin.branches.index')
            ->with('toast', ['type' => 'success', 'message' => 'تم تحديث بيانات الفرع.']);
    }

    protected function validateBranch(Request $request, ?Branch $branch = null): array
    {
        return $request->validate([
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'name' => ['required', 'string', 'max:150'],
            'code' => [
                'required', 'string', 'max:20', 'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('branches', 'code')->ignore($branch?->id)->whereNull('deleted_at'),
            ],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'working_hours' => ['nullable', 'array'],
            'working_hours.*.open' => ['nullable', 'date_format:H:i'],
            'working_hours.*.close' => ['nullable', 'date_format:H:i'],
            'working_hours.*.closed' => ['nullable', 'boolean'],
        ], [
            'code.regex' => 'رمز الفرع يجب أن يحتوي على حروف إنجليزية كبيرة وأرقام فقط.',
        ], [
            'organization_id' => 'المؤسسة',
            'name' => 'اسم الفرع',
            'code' => 'رمز الفرع',
            'address' => 'العنوان',
            'phone' => 'الهاتف',
            'email' => 'البريد الإلكتروني',
            'status' => 'الحالة',
        ]);
    }

    /**
     * Turn the form's day-keyed input into the stored shape, defaulting any
     * missing day to closed so a partially filled form cannot silently leave a
     * day open with no hours.
     */
    protected function normaliseHours(array $input): array
    {
        $hours = [];

        foreach (array_keys(self::days()) as $day) {
            $entry = $input[$day] ?? [];
            $closed = (bool) ($entry['closed'] ?? false);

            $hours[] = [
                'day' => (int) $day,
                'open' => $closed ? '00:00' : ($entry['open'] ?? '08:00'),
                'close' => $closed ? '00:00' : ($entry['close'] ?? '18:00'),
                'closed' => $closed,
            ];
        }

        return $hours;
    }

    /** @return array<int, string> Carbon day numbers, 0 = Sunday */
    public static function days(): array
    {
        return [
            0 => 'الأحد',
            1 => 'الاثنين',
            2 => 'الثلاثاء',
            3 => 'الأربعاء',
            4 => 'الخميس',
            5 => 'الجمعة',
            6 => 'السبت',
        ];
    }
}
