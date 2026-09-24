<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\Vehicle;
use App\Models\VehicleMaintenance;
use App\Services\AuditLogger;
use App\Services\ExpenseService;
use App\Support\BranchContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Vehicle maintenance, optionally posted straight to the expense ledger so a
 * repair shows up in both the vehicle history and the P&L without double entry.
 */
class VehicleMaintenanceController extends Controller
{
    public function __construct(
        protected ExpenseService $expenses,
        protected BranchContext $branchContext,
        protected AuditLogger $audit,
    ) {
    }

    public function index(Request $request): View
    {
        $this->authorize('vehicles.view');

        $query = VehicleMaintenance::query()
            ->visibleTo($request->user())
            ->with(['vehicle:id,uuid,name,plate_number', 'creator:id,name', 'expense:id,uuid,reference'])
            ->when($request->filled('vehicle_id'), fn ($q) => $q->where('vehicle_id', $request->integer('vehicle_id')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('service_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('service_date', '<=', $request->date('to')));

        return view('admin.maintenance.index', [
            'maintenances' => (clone $query)->orderByDesc('service_date')->paginate(20)->withQueryString(),
            'total' => round((float) (clone $query)->sum('cost'), 2),
            'vehicles' => Vehicle::query()->visibleTo($request->user())
                ->orderBy('name')->pluck('name', 'id')->all(),
            'types' => self::types(),
            'methods' => PaymentMethod::active()->pluck('label_ar', 'id')->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('vehicles.manage');

        $data = $request->validate([
            'vehicle_id' => ['required', 'integer', Rule::exists('vehicles', 'id')->whereNull('deleted_at')],
            'type' => ['required', Rule::in(array_keys(self::types()))],
            'title' => ['required', 'string', 'max:200'],
            'service_date' => ['required', 'date', 'before_or_equal:today'],
            'cost' => ['required', 'numeric', 'min:0', 'max:999999'],
            'provider' => ['nullable', 'string', 'max:150'],
            'odometer_km' => ['nullable', 'integer', 'min:0'],
            'next_service_on' => ['nullable', 'date', 'after:service_date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'post_to_expenses' => ['nullable', 'boolean'],
            'payment_method_id' => ['nullable', 'required_if:post_to_expenses,1', 'integer', 'exists:payment_methods,id'],
        ], [], [
            'vehicle_id' => 'المركبة',
            'type' => 'نوع الصيانة',
            'title' => 'البيان',
            'service_date' => 'تاريخ الصيانة',
            'cost' => 'التكلفة',
            'provider' => 'الجهة المنفذة',
            'odometer_km' => 'قراءة العداد',
            'next_service_on' => 'موعد الصيانة القادمة',
            'payment_method_id' => 'طريقة الدفع',
        ]);

        $vehicle = Vehicle::findOrFail($data['vehicle_id']);
        abort_unless($request->user()->canAccessBranch($vehicle->branch_id), 403);

        DB::transaction(function () use ($data, $vehicle, $request) {
            $maintenance = VehicleMaintenance::create([
                'vehicle_id' => $vehicle->id,
                'branch_id' => $vehicle->branch_id,
                'type' => $data['type'],
                'title' => $data['title'],
                'service_date' => $data['service_date'],
                'cost' => $data['cost'],
                'provider' => $data['provider'] ?? null,
                'odometer_km' => $data['odometer_km'] ?? null,
                'next_service_on' => $data['next_service_on'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            if (! empty($data['post_to_expenses']) && (float) $data['cost'] > 0) {
                $expense = $this->expenses->record([
                    'expense_category_id' => $this->maintenanceCategoryId($data['type']),
                    'payment_method_id' => $data['payment_method_id'],
                    'title' => $vehicle->name.' — '.$data['title'],
                    'amount' => (float) $data['cost'],
                    'spent_on' => $data['service_date'],
                    'beneficiary' => $data['provider'] ?? null,
                ], $vehicle->branch_id, $maintenance);

                $maintenance->forceFill(['expense_id' => $expense->id])->save();
            }

            if (! empty($data['odometer_km'])) {
                $vehicle->update(['odometer_km' => $data['odometer_km']]);
            }

            $this->audit->logCreate('vehicle_maintenance.recorded', $maintenance, 'تسجيل صيانة مركبة');
        });

        return back()->with('toast', ['type' => 'success', 'message' => 'تم تسجيل الصيانة.']);
    }

    protected function maintenanceCategoryId(string $type): int
    {
        $code = $type === 'parts' ? 'vehicle_parts' : 'vehicle_maintenance';

        return (int) ExpenseCategory::where('code', $code)->value('id')
            ?: (int) ExpenseCategory::where('profit_bucket', 'vehicles')->value('id');
    }

    /** @return array<string, string> */
    public static function types(): array
    {
        return [
            'routine' => 'صيانة دورية',
            'repair' => 'إصلاح',
            'parts' => 'قطع غيار',
            'inspection' => 'فحص',
            'other' => 'أخرى',
        ];
    }
}
