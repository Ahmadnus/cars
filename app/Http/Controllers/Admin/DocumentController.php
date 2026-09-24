<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Trainee;
use App\Models\Trainer;
use App\Models\Vehicle;
use App\Services\DocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Upload and retrieval of private documents.
 *
 * Files live on a disk the web server does not expose; every download comes
 * through this controller, which checks the policy on the owning record first.
 */
class DocumentController extends Controller
{
    /** Uploadable owner types, keyed by the value the form posts. */
    protected const OWNERS = [
        'trainee' => Trainee::class,
        'trainer' => Trainer::class,
        'employee' => Employee::class,
        'vehicle' => Vehicle::class,
    ];

    public function __construct(protected DocumentService $documents)
    {
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'owner_type' => ['required', Rule::in(array_keys(self::OWNERS))],
            'owner_id' => ['required', 'integer'],
            'category' => ['required', 'string', 'max:60'],
            'title' => ['nullable', 'string', 'max:150'],
            'expires_on' => ['nullable', 'date'],
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
        ], [], [
            'category' => 'نوع المستند',
            'title' => 'عنوان المستند',
            'expires_on' => 'تاريخ الانتهاء',
            'file' => 'الملف',
        ]);

        $owner = self::OWNERS[$data['owner_type']]::findOrFail($data['owner_id']);

        // Authorize against the owning record, not against the document.
        $this->authorize('update', $owner);

        $this->documents->store(
            $request->file('file'),
            $owner,
            $data['category'],
            $data['title'] ?? null,
            $data['expires_on'] ?? null,
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'تم رفع المستند بنجاح.']);
    }

    public function download(Document $document): StreamedResponse
    {
        $this->authorize('download', $document);

        return $this->documents->download($document);
    }

    public function view(Document $document): StreamedResponse
    {
        $this->authorize('download', $document);

        return $this->documents->download($document, inline: true);
    }

    public function destroy(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('delete', $document);

        $this->documents->delete($document, $request->input('reason'));

        return back()->with('toast', ['type' => 'success', 'message' => 'تم حذف المستند.']);
    }

    /** @return array<string, string> */
    public static function categories(): array
    {
        return [
            'identity' => 'إثبات هوية',
            'photo' => 'صورة شخصية',
            'medical' => 'تقرير طبي',
            'license' => 'رخصة قيادة',
            'invoice' => 'فاتورة',
            'receipt' => 'إيصال',
            'contract' => 'عقد',
            'other' => 'أخرى',
        ];
    }
}
