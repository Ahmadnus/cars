<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use App\Support\BranchContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(protected DashboardService $dashboard)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('dashboard.view');

        return view('admin.dashboard', [
            'data' => $this->dashboard->build($request->user()),
        ]);
    }

    /**
     * Switch the active branch.
     *
     * BranchContext refuses any branch the user has no grant for, so a forged
     * id simply leaves the selection unchanged.
     */
    public function switchBranch(Request $request, BranchContext $context): RedirectResponse
    {
        $branchId = $request->input('branch_id');
        $branchId = $branchId === '' || $branchId === null ? null : (int) $branchId;

        if (! $context->set($branchId, $request->user())) {
            return back()->with('toast', ['type' => 'error', 'message' => 'لا تملك صلاحية الوصول إلى هذا الفرع.']);
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'تم تغيير الفرع الحالي.']);
    }
}
