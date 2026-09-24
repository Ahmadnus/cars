<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Document;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Secure storage for identity papers, receipts and vehicle documents.
 *
 * Files go to a private disk that is not served by the web server; they are
 * only ever returned through an authenticated controller that checks the policy
 * on the owning record first. The stored name is randomised so a guessed URL
 * cannot reach a file even if the disk were exposed.
 */
class DocumentService
{
    /** Extensions we are willing to store, mapped to allowed MIME types. */
    public const ALLOWED = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
        'pdf' => ['application/pdf'],
    ];

    public const MAX_BYTES = 8 * 1024 * 1024;

    public function __construct(protected AuditLogger $audit)
    {
    }

    public function store(
        UploadedFile $file,
        Model $owner,
        string $category = 'other',
        ?string $title = null,
        ?string $expiresOn = null,
    ): Document {
        $this->assertAcceptable($file);

        $disk = config('filesystems.private_disk', 'private');
        $folder = $this->folderFor($owner);
        $name = Str::uuid().'.'.strtolower($file->getClientOriginalExtension());

        $path = $file->storeAs($folder, $name, $disk);

        if ($path === false) {
            throw BusinessRuleException::make('تعذر حفظ الملف. يرجى المحاولة مرة أخرى.');
        }

        $document = Document::create([
            'branch_id' => $owner->branch_id ?? null,
            'documentable_type' => $owner::class,
            'documentable_id' => $owner->getKey(),
            'category' => $category,
            'title' => $title ?: $file->getClientOriginalName(),
            'original_name' => $file->getClientOriginalName(),
            'disk' => $disk,
            'path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
            'checksum' => hash_file('sha256', $file->getRealPath()),
            'expires_on' => $expiresOn,
            'uploaded_by' => auth()->id(),
        ]);

        $this->audit->log(
            action: 'document.uploaded',
            subject: $document,
            after: ['category' => $category, 'original_name' => $document->original_name],
            description: 'رفع مستند',
        );

        return $document;
    }

    /** Stream a private document to an already-authorized caller. */
    public function download(Document $document, bool $inline = false): StreamedResponse
    {
        $disk = Storage::disk($document->disk);

        if (! $disk->exists($document->path)) {
            throw BusinessRuleException::make('الملف غير موجود على الخادم.', [], 404);
        }

        $disposition = $inline ? 'inline' : 'attachment';

        return $disk->response($document->path, $document->original_name, [
            'Content-Type' => $document->mime_type,
            'Content-Disposition' => $disposition.'; filename="'.addslashes($document->original_name).'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Soft-delete the record and remove the bytes.
     *
     * The audit entry keeps the metadata, so a deletion is still traceable
     * after the file itself is gone.
     */
    public function delete(Document $document, ?string $reason = null): void
    {
        $this->audit->logDelete('document.deleted', $document, $reason);

        Storage::disk($document->disk)->delete($document->path);

        $document->delete();
    }

    protected function assertAcceptable(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw BusinessRuleException::make('الملف المرفوع غير صالح.');
        }

        if ($file->getSize() > self::MAX_BYTES) {
            throw BusinessRuleException::make('حجم الملف يتجاوز الحد المسموح (8 ميجابايت).');
        }

        $extension = strtolower($file->getClientOriginalExtension());

        if (! isset(self::ALLOWED[$extension])) {
            throw BusinessRuleException::make('نوع الملف غير مسموح. المسموح: صور أو PDF.');
        }

        // Trust the sniffed type, not the extension the client sent.
        $mime = $file->getMimeType();

        if (! in_array($mime, self::ALLOWED[$extension], true)) {
            throw BusinessRuleException::make('محتوى الملف لا يطابق امتداده.');
        }
    }

    protected function folderFor(Model $owner): string
    {
        $type = Str::snake(class_basename($owner));

        return "documents/{$type}/".$owner->getKey();
    }
}
