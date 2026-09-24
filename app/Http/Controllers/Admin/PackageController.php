<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Package;
use App\Services\AuditLogger;
use App\Services\PackageService;
use App\Support\BranchContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PackageController extends Controller
{
    public function __construct(
        protected PackageService $packages,
        protected BranchContext $branchContext,
        protected AuditLogger $audit,
    ) {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Package::class);

        return view('admin.packages.index', [
            'packages' => Package::query()
                ->availableAt($this->branchContext->currentId())
                ->with('branch:id,uuid,name')
                ->withCount('enrolments')
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
                ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.trim($request->string('search')).'%'))
                ->orderBy('name')
                ->paginate(20)
                ->withQueryString(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Package::class);

        return view('admin.packages.create', ['branches' => $this->branchOptions($request)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Package::class);

        $data = $this->validatePackage($request);

        $package = DB::transaction(function () use ($data) {
            $package = Package::create($data);
            $this->audit->logCreate('package.created', $package, 'إنشاء باقة تدريبية');

            return $package;
        });

        return redirect()
            ->route('admin.packages.index')
            ->with('toast', ['type' => 'success', 'message' => "تم إنشاء الباقة «{$package->name}»."]);
    }

    public function edit(Request $request, Package $package): View
    {
        $this->authorize('update', $package);

        return view('admin.packages.edit', [
            'package' => $package,
            'branches' => $this->branchOptions($request),
        ]);
    }

    /**
     * Update a package.
     *
     * A price change goes through PackageService so it is audited separately —
     * pricing is a commercial decision the center may need to justify later.
     */
    public function update(Request $request, Package $package): RedirectResponse
    {
        $this->authorize('update', $package);

        $data = $this->validatePackage($request, $package);

        $priceChanged = (float) $data['price'] !== (float) $package->price
            || (float) $data['extra_lesson_price'] !== (float) $package->extra_lesson_price;

        if ($priceChanged) {
            $request->validate(
                ['price_change_reason' => ['required', 'string', 'max:255']],
                [],
                ['price_change_reason' => 'سبب تغيير السعر'],
            );
        }

        DB::transaction(function () use ($package, $data, $request, $priceChanged) {
            $original = $package->getOriginal();

            if ($priceChanged) {
                $this->packages->updateCatalogPrice(
                    $package,
                    (float) $data['price'],
                    (float) $data['extra_lesson_price'],
                    $request->input('price_change_reason'),
                );
            }

            $package->update(collect($data)->except(['price', 'extra_lesson_price'])->all());

            if (! $priceChanged) {
                $this->audit->logUpdate('package.updated', $package, $original);
            }
        });

        return redirect()
            ->route('admin.packages.index')
            ->with('toast', ['type' => 'success', 'message' => 'تم تحديث الباقة.']);
    }

    public function destroy(Package $package): RedirectResponse
    {
        $this->authorize('delete', $package);

        if ($package->enrolments()->exists()) {
            // Deleting would orphan signed contracts, so it is deactivated.
            $package->update(['status' => 'inactive']);
            $this->audit->log('package.deactivated', $package, description: 'تعطيل باقة مستخدمة');

            return back()->with('toast', [
                'type' => 'warning',
                'message' => 'الباقة مستخدمة من متدربين، تم تعطيلها بدلاً من حذفها.',
            ]);
        }

        $this->audit->logDelete('package.deleted', $package);
        $package->delete();

        return back()->with('toast', ['type' => 'success', 'message' => 'تم حذف الباقة.']);
    }

    protected function validatePackage(Request $request, ?Package $package = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'license_type' => ['required', 'string', 'max:40'],
            'lessons_count' => ['required', 'integer', 'min:1', 'max:200'],
            'lesson_duration_minutes' => ['required', 'integer', 'min:15', 'max:300'],
            'price' => ['required', 'numeric', 'min:0', 'max:999999'],
            'extra_lesson_price' => ['required', 'numeric', 'min:0', 'max:99999'],
            'max_discount_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'validity_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'description' => ['nullable', 'string', 'max:1000'],
            'branch_id' => ['nullable', 'integer', Rule::in($request->user()->accessibleBranchIds())],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ], [], [
            'name' => 'اسم الباقة',
            'license_type' => 'نوع الرخصة',
            'lessons_count' => 'عدد الحصص',
            'lesson_duration_minutes' => 'مدة الحصة',
            'price' => 'سعر الباقة',
            'extra_lesson_price' => 'سعر الحصة الإضافية',
            'max_discount_percent' => 'أقصى نسبة خصم',
            'validity_days' => 'مدة الصلاحية',
            'branch_id' => 'الفرع',
            'status' => 'الحالة',
        ]);
    }

    protected function branchOptions(Request $request): array
    {
        return Branch::query()
            ->whereIn('id', $request->user()->accessibleBranchIds())
            ->orderBy('name')->pluck('name', 'id')->all();
    }
}
