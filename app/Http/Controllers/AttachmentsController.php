<?php

namespace App\Http\Controllers;

use App\Http\Requests\AttachmentUploadRequest;
use App\Models\Attachment;
use App\Models\Order;
use App\Services\AttachmentService;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;

/**
 * Generic attachment upload + delete. Phase 4 uses it for pre-shipping
 * photos against orders; later phases add other related-record types.
 */
class AttachmentsController extends Controller
{
    public function __construct(
        private readonly AttachmentService $attachments,
    ) {}

    /**
     * Upload a file against an order. The form supplies the
     * attachment_type (e.g. "Pre Shipping Photo").
     */
    public function uploadForOrder(AttachmentUploadRequest $request, Order $order): RedirectResponse
    {
        $attachment = $this->attachments->attach(
            related: $order,
            file: $request->file('file'),
            attachmentType: $request->input('attachment_type'),
        );

        AuditLogService::log(
            action: 'uploaded',
            module: 'attachments',
            recordType: Attachment::class,
            recordId: $attachment->id,
            newValues: [
                'related_type' => Order::class,
                'related_id' => $order->id,
                'attachment_type' => $attachment->attachment_type,
                'file_name' => $attachment->file_name,
            ],
        );

        return back()->with('success', 'File uploaded.');
    }

    public function destroy(Attachment $attachment): RedirectResponse
    {
        $this->attachments->delete($attachment);

        AuditLogService::log(
            action: 'deleted',
            module: 'attachments',
            recordType: Attachment::class,
            recordId: $attachment->id,
        );

        return back()->with('success', 'Attachment deleted.');
    }
}
