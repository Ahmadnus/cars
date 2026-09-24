<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Trainee;
use App\Models\TraineeNote;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Internal notes on a trainee's file. */
class TraineeNoteController extends Controller
{
    public function store(Request $request, Trainee $trainee): RedirectResponse
    {
        $this->authorize('update', $trainee);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'is_pinned' => ['nullable', 'boolean'],
        ], [], ['body' => 'الملاحظة']);

        $trainee->notes()->create([
            'user_id' => $request->user()->id,
            'body' => $data['body'],
            'is_pinned' => (bool) ($data['is_pinned'] ?? false),
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'تمت إضافة الملاحظة.']);
    }

    public function destroy(Request $request, TraineeNote $note): RedirectResponse
    {
        $this->authorize('update', $note->trainee);

        // Only the author, or someone who can archive trainees, may remove a note.
        abort_unless(
            $note->user_id === $request->user()->id || $request->user()->hasPermission('trainees.delete'),
            403,
        );

        $note->delete();

        return back()->with('toast', ['type' => 'success', 'message' => 'تم حذف الملاحظة.']);
    }
}
