<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\ShippingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response as HttpResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Print 4×6 shipping labels.
 *
 * Two endpoints:
 *   - GET /shipping/labels                — list of recently printed labels
 *   - GET /shipping/labels/{order}/print  — generate + download the PDF
 */
class ShippingLabelsController extends Controller
{
    public function __construct(
        private readonly ShippingService $shipping,
    ) {}

    public function index(): Response
    {
        $labels = \App\Models\ShippingLabel::query()
            ->with(['order:id,order_number,customer_name', 'shipment:id,tracking_number', 'printedBy:id,name'])
            ->latest('printed_at')
            ->paginate(30);

        return Inertia::render('Shipping/Labels/Index', [
            'labels' => $labels,
        ]);
    }

    /**
     * Generate (or re-generate) the 4×6 label and stream the PDF.
     */
    public function print(Order $order): HttpResponse|RedirectResponse|BinaryFileResponse
    {
        try {
            $label = $this->shipping->generateLabelPdf($order);
        } catch (\Throwable $e) {
            return redirect()->route('orders.show', $order)
                ->with('error', $e->getMessage());
        }

        // Stream the PDF directly from disk so the download is instant.
        $relative = ltrim(parse_url($label->label_pdf_url, PHP_URL_PATH) ?? '', '/');
        $relative = preg_replace('#^storage/#', '', $relative);
        $disk = \Illuminate\Support\Facades\Storage::disk('public');

        if (! $disk->exists($relative)) {
            return redirect()->route('orders.show', $order)
                ->with('error', 'Generated label file not found on disk.');
        }

        return response($disk->get($relative), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="label-%s.pdf"', $order->order_number),
        ]);
    }
}
