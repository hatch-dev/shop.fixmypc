<?php

namespace App\Http\Controllers;

use App\Models\Helper\MailHelper;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;

/**
 * The customer's own invoice, as a PDF.
 *
 * "Invoice" on My Orders was a dead href="#": the only invoice the site could
 * produce was the one attached to the order confirmation email, and only when
 * attach_pdf is switched on. This serves that same document - the same blade
 * (mail_templates.order_pdf), fed by the same MailHelper::sendingOrderEmail,
 * so every figure comes from OrderSummary like the email copy - on demand.
 *
 * Only for the signed-in owner: an invoice carries the name, address and
 * contact details, so an order id alone must never be enough.
 */
class InvoiceController extends Controller
{
    public function show(Request $request, $id)
    {
        $user = Auth::user();

        $order = Order::where('id', (int)$id)->first();

        // One answer for "no such order" and "not yours", so ids cannot be probed.
        if (!$user || !$order || (int)$order->user_id !== (int)$user->id) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        try {
            $lang = $request->header('language');

            $mailRequest = new Request();
            $mailRequest->merge(['token' => null]);

            if ($request->time_zone) {
                $mailRequest->merge(['time_zone' => $request->time_zone]);
            }

            $data = MailHelper::sendingOrderEmail($mailRequest, $order->id, $lang)
                ?: MailHelper::sendingOrderEmail($mailRequest, $order->id);

            if (!$data) {
                return response()->json(['message' => 'Invoice not available for this order.'], 404);
            }

            $html = View::make('mail_templates.order_pdf', $data)->render();

            $pdf = new \Mpdf\Mpdf(['tempDir' => storage_path('app/mpdf')]);
            $pdf->autoScriptToLang = true;
            $pdf->autoLangToFont = true;
            $pdf->SetTitle('Invoice ' . $order->order);
            $pdf->WriteHTML($html);

            $filename = 'Invoice-' . preg_replace('/[^A-Za-z0-9_-]/', '', $order->order) . '.pdf';

            return response($pdf->Output('', 'S'), 200, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . $filename . '"',
                'Cache-Control'       => 'private, no-store',
            ]);

        } catch (\Throwable $e) {
            Log::error('Customer invoice failed', ['order_id' => $order->id, 'message' => $e->getMessage()]);

            return response()->json(['message' => 'We could not create the invoice just now.'], 500);
        }
    }
}
