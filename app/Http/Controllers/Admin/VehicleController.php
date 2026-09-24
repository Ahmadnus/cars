<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Trainer;
use App\Models\Vehicle;
use App\Services\AuditLogger;
use App\Support\BranchContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class VehicleController extends Controller
{
    public function __construct(
        protected BranchContext $branchContext,
        protected AuditLogger $audit,
    ) {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Vehicle::class);

        $vehicles = Vehicle::query()
            ->visibleTo($request->user())
            ->with(['assignedTrainer:id,uuid,full_name', 'branch:id,uuid,name'])
            ->withCount(['trainingSessions as completed_lessons_count' => fn (Builder $q) => $q->where('status', 'completed')])
            ->when($request->filled('search'), function (Builder $q) use ($request) {
                $term = trim($request->string('search'));

                $q->where(fn (Builder $i) => $i
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('plate_number', 'like', "%{$term}%")
                    ->orWhere('model', 'like', "%{$term}%"));
            })
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->input('status')))
            ->when($request->filled('transmission'), fn (Builder $q) => $q->where('transmission', $request->input('transmission')))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('admin.vehicles.index', [
            'vehicles' => $vehicles,
            'statuses' => self::statuses(),
            // Papers expiring soon deserve a visible warning, not a buried field.
            'expiring' => Vehicle::query()->visibleTo($request->user())->get()
                ->filter(fn (Vehicle $v) => ! empty($v->expiringPapers(30))),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Vehicle::class);

        return view('admin.vehicles.create', [
            'branches' => $this->branchOptions($request),
            'trainers' => $this->trainerOptions($request),
            'statuses' => self::statuses(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Vehicle::class);

        $data = $this->validateVehicle($request);

        $vehicle = DB::transaction(function () use ($data, $request) {
            $vehicle = Vehicle::create(array_merge($data, [
                'branch_id' => $data['branch_id'] ?? $this->branchContext->defaultForWrite($request->user()),
            ]));

            $this->audit->logCreate('vehicle.created', $vehicle, 'إضافة مركبة');

            return $vehicle;
        });

        return redirect()
            ->route('admin.vehicles.show', $vehicle)
            ->with('toast', ['type' => 'success', 'message' => 'تم إضافة المركبة بنجاح.']);
    }

    public function show(Vehicle $vehicle): View
    {
        $this->authorize('view', $vehicle);

        return view('admin.vehicles.show', [
            'vehicle' => $vehicle->load(['assignedTrainer', 'branch', 'documents.uploader']),
            'maintenances' => $vehicle->maintenances()->with('creator:id,name')->orderByDesc('service_date')->limit(20)->get(),
            'maintenanceCost' => round((float) $vehicle->maintenances()->sum('cost'), 2),
            'recentSessions' => $vehicle->trainingSessions()
                ->with(['trainee:id,uuid,full_name', 'trainer:id,uuid,full_name'])
                ->orderByDesc('scheduled_date')->limit(10)->get(),
        ]);
    }

    public function edit(Request $request, Vehicle $vehicle): View
    {
        $this->authorize('update', $vehicle);

        return view('admin.vehicles.edit', [
            'vehicle' => $vehicle,
            'branches' => $this->branchOptions($request),
            'trainers' => $this->trainerOptions($request),
            'statuses' => self::statuses(),
        ]);
    }

    public function update(Request $request, Vehicle $vehicle): RedirectResponse
    {
        $this->authorize('update', $vehicle);

        $data = $this->validateVehicle($request, $vehicle);
        $original = $vehicle->getOriginal();

        DB::transaction(function () use ($vehicle, $data, $original) {
            $vehicle->update($data);
            $this->audit->logUpdate('vehicle.updated', $vehicle, $original);
        });

        return redirect()
            ->route('admin.vehicles.show', $vehicle)
            ->with('toast', ['type' => 'success', 'message' => 'تم تحديث بيانات المركبة.']);
    }

    public function destroy(Vehicle $vehicle): RedirectResponse
    {
        $this->authorize('delete', $vehicle);

        if ($vehicle->trainingSessions()->scheduled()->exists()) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => 'لا يمكن أرشفة مركبة مرتبطة بحصص مجدولة.',
            ]);
        }

        DB::transaction(function () use ($vehicle) {
            $vehicle->update(['status' => 'inactive']);
            $this->audit->logDelete('vehicle.archived', $vehicle);
            $vehicle->delete();
        });

        return redirect()
            ->route('admin.vehicles.index')
            ->with('toast', ['type' => 'success', 'message' => 'تم أرشفة المركبة.']);
    }

    protected function validateVehicle(Request $request, ?Vehicle $vehicle = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'plate_number' => [
                'required', 'string', 'max:30',
                Rule::unique('vehicles', 'plate_number')->ignore($vehicle?->id)->whereNull('deleted_at'),
            ],
            'model' => ['nullable', 'string', 'max:120'],
            'year' => ['nullable', 'integer', 'min:1970', 'max:'.(date('Y') + 1)],
            'transmission' => ['required', Rule::in(['manual', 'automatic'])],
            'license_type' => ['nullable', 'string', 'max:40'],
            'assigned_trainer_id' => ['nullable', 'integer', Rule::exists('trainers', 'id')->whereNull('deleted_at')],
            'status' => ['required', Rule::in(array_keys(self::statuses()))],
            'insurance_number' => ['nullable', 'string', 'max:60'],
            'insurance_expires_on' => ['nullable', 'date'],
            'registration_number' => ['nullable', 'string', 'max:60'],
            'registration_expires_on' => ['nullable', 'date'],
            'odometer_km' => ['nullable', 'integer', 'min:0'],
            'branch_id' => ['nullable', 'integer', Rule::in($request->user()->accessibleBranchIds())],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'name' => 'اسم المركبة',
            'plate_number' => 'رقم اللوحة',
            'model' => 'الطراز',
            'year' => 'سنة الصنع',
            'transmission' => 'ناقل الحركة',
            'assigned_trainer_id' => 'المدرب المسؤول',
            'status' => 'الحالة',
            'insurance_expires_on' => 'انتهاء التأمين',
            'registration_expires_on' => 'انتهاء الترخيص',
            'odometer_km' => 'قراءة العداد',
            'branch_id' => 'الفرع',
        ]);
    }

    protected function trainerOptions(Request $request): array
    {
        return Trainer::query()->visibleTo($request->user())->active()
            ->orderBy('full_name')->pluck('full_name', 'id')->all();
    }

    protected function branchOptions(Request $request): array
    {
        return Branch::query()->whereIn('id', $request->user()->accessibleBranchIds())
            ->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return [
            'available' => 'متاحة',
            'in_use' => 'قيد الاستخدام',
            'maintenance' => 'في الصيانة',
            'inactive' => 'غير نشطة',
        ];
    }
}
