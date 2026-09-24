<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TraineeSkillEvaluation;
use App\Models\TrainingSkill;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The training syllabus. Skills are configurable so a center can add its own
 * without a code change; existing evaluations keep pointing at their skill.
 */
class TrainingSkillController extends Controller
{
    public function __construct(protected AuditLogger $audit)
    {
    }

    public function index(): View
    {
        $this->authorize('evaluations.view');

        return view('admin.skills.index', [
            'skills' => TrainingSkill::withCount('evaluations')->orderBy('sort_order')->get(),
            'levels' => TrainingSessionController::ratings(),
            // How the whole cohort is doing on each skill, for the supervisor.
            'distribution' => TraineeSkillEvaluation::query()
                ->selectRaw('training_skill_id, level, COUNT(*) as total')
                ->groupBy('training_skill_id', 'level')
                ->get()
                ->groupBy('training_skill_id'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('skills.manage');

        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/', Rule::unique('training_skills', 'code')],
            'description' => ['nullable', 'string', 'max:255'],
        ], [
            'code.regex' => 'المعرّف يجب أن يحتوي على حروف إنجليزية صغيرة وأرقام وشرطة سفلية فقط.',
        ], [
            'name_ar' => 'اسم المهارة',
            'code' => 'المعرّف',
        ]);

        $skill = TrainingSkill::create(array_merge($data, [
            'sort_order' => (int) TrainingSkill::max('sort_order') + 1,
            'status' => 'active',
        ]));

        $this->audit->logCreate('training_skill.created', $skill, 'إضافة مهارة تدريب');

        return back()->with('toast', ['type' => 'success', 'message' => 'تمت إضافة المهارة.']);
    }

    public function update(Request $request, TrainingSkill $skill): RedirectResponse
    {
        $this->authorize('skills.manage');

        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:999'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ], [], [
            'name_ar' => 'اسم المهارة',
            'sort_order' => 'الترتيب',
            'status' => 'الحالة',
        ]);

        $original = $skill->getOriginal();
        $skill->update($data);
        $this->audit->logUpdate('training_skill.updated', $skill, $original);

        return back()->with('toast', ['type' => 'success', 'message' => 'تم تحديث المهارة.']);
    }

    /**
     * Retire a skill.
     *
     * Deactivated rather than deleted once it has been used, so historical
     * evaluations keep their meaning.
     */
    public function destroy(TrainingSkill $skill): RedirectResponse
    {
        $this->authorize('skills.manage');

        if ($skill->evaluations()->exists() || $skill->sessionSkills()->exists()) {
            $skill->update(['status' => 'inactive']);
            $this->audit->log('training_skill.deactivated', $skill, description: 'تعطيل مهارة مستخدمة');

            return back()->with('toast', [
                'type' => 'warning',
                'message' => 'المهارة مستخدمة في تقييمات سابقة، تم تعطيلها بدلاً من حذفها.',
            ]);
        }

        $this->audit->logDelete('training_skill.deleted', $skill);
        $skill->delete();

        return back()->with('toast', ['type' => 'success', 'message' => 'تم حذف المهارة.']);
    }
}
