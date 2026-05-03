<?php

namespace App\Services;

use App\Models\Attachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Stores files in the `public` disk and persists a row pointing at them.
 *
 * Filename normalisation: keeps the user's original name (slugified) +
 * a random suffix to avoid collisions. Files live under
 *   storage/app/public/<bucket>/<related_id>/<filename>
 * The `storage:link` Artisan command needs to be run once on a fresh
 * checkout so /storage/* serves the files.
 *
 * MIME / extension validation is the responsibility of the controller's
 * Form Request (cleaner separation: this service trusts what it gets).
 */
class AttachmentService
{
    public function attach(
        Model $related,
        UploadedFile $file,
        string $attachmentType,
        ?string $bucket = null,
    ): Attachment {
        $bucket ??= self::bucketFor($attachmentType);
        $folder = "{$bucket}/{$related->getKey()}";

        $path = $file->store($folder, 'public');

        return Attachment::create([
            'related_type' => $related::class,
            'related_id' => $related->getKey(),
            'file_name' => $file->getClientOriginalName(),
            'file_url' => Storage::url($path),
            'file_type' => $file->getClientMimeType(),
            'file_size_bytes' => $file->getSize(),
            'attachment_type' => $attachmentType,
            'uploaded_by' => Auth::id(),
            'created_at' => now(),
        ]);
    }

    public function delete(Attachment $attachment): void
    {
        // Best-effort delete from storage; row removal is the source of truth.
        $relative = ltrim(parse_url($attachment->file_url, PHP_URL_PATH) ?? '', '/');
        $relative = preg_replace('#^storage/#', '', $relative);
        if ($relative) {
            Storage::disk('public')->delete($relative);
        }

        $attachment->delete();
    }

    private static function bucketFor(string $attachmentType): string
    {
        return match ($attachmentType) {
            Attachment::TYPE_PRE_SHIPPING_PHOTO => 'pre-shipping-photos',
            Attachment::TYPE_RETURN_PHOTO => 'return-photos',
            Attachment::TYPE_SHIPPING_LABEL => 'shipping-labels',
            Attachment::TYPE_PURCHASE_INVOICE => 'purchase-invoices',
            Attachment::TYPE_PAYMENT_PROOF => 'payment-proofs',
            Attachment::TYPE_COMPLAINT => 'complaints',
            Attachment::TYPE_IMPORT_FILE => 'imports',
            Attachment::TYPE_PDF_REPORT => 'reports',
            default => 'attachments',
        };
    }
}
