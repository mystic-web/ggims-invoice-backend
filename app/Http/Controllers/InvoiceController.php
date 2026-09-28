<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;

class InvoiceController extends Controller
{
    // GET /api/invoices
    public function index(Request $request)
    {
        $query = Invoice::query();

        if ($request->search) {
            $s = $request->search;
            $query->where(function($q) use ($s) {
                $q->where('client_name',    'like', "%$s%")
                  ->orWhere('consultant_name', 'like', "%$s%")
                  ->orWhere('invoice_number', 'like', "%$s%")
                  ->orWhere('process',        'like', "%$s%")
                  ->orWhere('state',          'like', "%$s%");
            });
        }

        if ($request->consultant) {
            $query->where('consultant_name', $request->consultant);
        }

        if ($request->process) {
            $query->where('process', $request->process);
        }

        if ($request->branch) {
            $query->where('branch_code', $request->branch);
        }

        if ($request->date_from) {
            $query->whereDate('invoice_date', '>=', $request->date_from);
        }

        if ($request->date_to) {
            $query->whereDate('invoice_date', '<=', $request->date_to);
        }

        $sort  = $request->sort  ?? 'invoice_date';
        $order = $request->order ?? 'desc';
        $allowedSorts = ['client_name','consultant_name','invoice_date','total_amount','invoice_number','process','state'];
        if (!in_array($sort, $allowedSorts)) $sort = 'invoice_date';

        $invoices = $query->orderBy($sort, $order)->get();

        return response()->json($invoices);
    }

    // POST /api/invoices/upload
    public function upload(Request $request)
    {
        $request->validate([
            'pdf' => 'required|file|mimes:pdf|max:10240'
        ]);

        $file = $request->file('pdf');
        $path = $file->store('invoices', 'local');

        $result = $this->processStoredPdf($path);

        if (isset($result['error'])) {
            return response()->json([
                'error'          => $result['error'],
                'code'           => $result['code'] ?? 'error',
                'invoice_number' => $result['invoice_number'] ?? null,
            ], $result['status']);
        }

        return response()->json($result['invoice'], 201);
    }

    /**
     * Shared logic: parse an already-stored PDF (relative "local" disk path)
     * and create an Invoice record from it. Used by both the manual upload
     * endpoint and the email-sync flow.
     *
     * Returns either ['invoice' => Invoice] or ['error' => string, 'status' => int]
     */
    public function processStoredPdf(string $path): array
    {
        try {
            $parser = new Parser();
            $pdf    = $parser->parseFile(Storage::disk('local')->path($path));
            $text   = $pdf->getText();
        } catch (\Exception $e) {
            Storage::delete($path);
            return ['error' => 'PDF parse failed: ' . $e->getMessage(), 'status' => 422];
        }

        // Validate it's a GGIMS invoice
        if (!str_contains($text, 'GGIMS') || !str_contains($text, 'TAX INVOICE')) {
            Storage::delete($path);
            return ['error' => 'Not a valid GGIMS TAX INVOICE', 'status' => 422, 'code' => 'not_invoice'];
        }

        $data = $this->parseInvoiceText($text);

        // If the invoice number could not be read, do NOT treat it as a duplicate
        // of other blank ones — report it clearly so nothing disappears silently.
        if (empty($data['invoice_number'])) {
            Storage::delete($path);
            return ['error' => 'Could not read invoice number from this PDF', 'status' => 422, 'code' => 'no_invoice_number'];
        }

        // Duplicate check
        if (Invoice::where('invoice_number', $data['invoice_number'])->exists()) {
            Storage::delete($path);
            return [
                'error'          => "Invoice {$data['invoice_number']} already exists",
                'status'         => 409,
                'code'           => 'duplicate',
                'invoice_number' => $data['invoice_number'],
            ];
        }

        try {
            $invoice = Invoice::create([
                ...$data,
                'pdf_path' => $path,
            ]);
        } catch (\Throwable $e) {
            Storage::delete($path);
            // Race with another request inserting the same number
            if (str_contains(strtolower($e->getMessage()), 'unique') || str_contains(strtolower($e->getMessage()), 'duplicate')) {
                return [
                    'error'          => "Invoice {$data['invoice_number']} already exists",
                    'status'         => 409,
                    'code'           => 'duplicate',
                    'invoice_number' => $data['invoice_number'],
                ];
            }
            return ['error' => 'Could not save invoice: ' . substr($e->getMessage(), 0, 160), 'status' => 500, 'code' => 'save_failed'];
        }

        return ['invoice' => $invoice];
    }

    // PUT /api/invoices/{id}
    public function update(Request $request, $id)
    {
        $invoice = Invoice::findOrFail($id);
        $invoice->update($request->only([
            'refund_invoice_no', 'payment_mode', 'consultant_name',
            'process', 'branch_code', 'notes'
        ]));
        return response()->json($invoice);
    }

    // DELETE /api/invoices/{id}
    public function destroy($id)
    {
        $invoice = Invoice::findOrFail($id);
        if ($invoice->pdf_path) Storage::delete($invoice->pdf_path);
        $invoice->delete();
        return response()->json(['ok' => true]);
    }

    // GET /api/invoices/export
    public function export(Request $request)
    {
        $invoices = Invoice::orderBy('invoice_date', 'desc')->get();

        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="ggims-invoices-' . now()->format('Y-m-d') . '.csv"',
        ];

        $callback = function() use ($invoices) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'Client Name', 'Consultant', 'Process', 'Basic Amount', 'GST Amount',
                'CGST', 'SGST', 'IGST', 'Total Amount', 'Invoice Number', 'Invoice Date',
                'Client Address', 'State', 'Contact No', 'Email', 'Branch Code',
                'Payment Mode', 'Refund Invoice No'
            ]);
            foreach ($invoices as $inv) {
                fputcsv($handle, [
                    $inv->client_name, $inv->consultant_name, $inv->process,
                    $inv->basic_amount, $inv->gst_amount, $inv->cgst, $inv->sgst, $inv->igst,
                    $inv->total_amount, $inv->invoice_number, $inv->invoice_date,
                    $inv->client_address, $inv->state, $inv->contact_no,
                    $inv->email_address, $inv->branch_code, $inv->payment_mode,
                    $inv->refund_invoice_no
                ]);
            }
            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    // GET /api/invoices/stats
    public function stats()
    {
        return response()->json([
            'total'        => Invoice::count(),
            'total_amount' => Invoice::sum('total_amount'),
            'total_gst'    => Invoice::sum('gst_amount'),
            'consultants'  => Invoice::distinct('consultant_name')->count('consultant_name'),
            'by_consultant'=> Invoice::selectRaw('consultant_name, COUNT(*) as count, SUM(total_amount) as total')
                                ->groupBy('consultant_name')->orderByDesc('total')->get(),
            'by_process'   => Invoice::selectRaw('process, COUNT(*) as count, SUM(total_amount) as total')
                                ->groupBy('process')->orderByDesc('total')->get(),
            'by_branch'    => Invoice::selectRaw('COALESCE(NULLIF(branch_code, \'\'), \'Unassigned\') as branch, COUNT(*) as count, SUM(total_amount) as total, SUM(gst_amount) as gst')
                                ->groupBy('branch')->orderByDesc('total')->get(),
        ]);
    }

    // -------------------------------------------------------
    // PDF TEXT PARSER
    // -------------------------------------------------------
    private function parseInvoiceText(string $text): array
    {
        $extract = function(string $pattern, string $fallback = '') use ($text): string {
            if (preg_match($pattern, $text, $m)) return trim($m[1]);
            return $fallback;
        };

        // Client name
        $clientName = $extract('/BUYER[\s\S]*?Name:\s*([^\n]+)/i')
                   ?: $extract('/Name:\s*([^\n]+)/i', 'Unknown');

        // Consultant name
        $consultantName = $extract('/CONSULTANT\s*DETAILS[\s\S]*?Name:\s*([^\n]+)/i');

        // Process
        $process = $this->extractProcess($text);

        // Basic amount (taxable value)
        $basicRaw   = $extract('/9985\s+([\d,]+\.?\d*)\s+(?:18%|NIL)/i');
        $basicAmount = $basicRaw ? (float) str_replace(',', '', $basicRaw) : null;

        // GST
        $gstInfo = $this->extractGST($text);

        // Total amount
        $totalRaw   = $extract('/Total\s+INR\s*([\d,]+\.?\d*)/i')
                   ?: $extract('/Total\s*\n\s*INR\s*([\d,]+\.?\d*)/i');
        $totalAmount = $totalRaw ? (float) str_replace(',', '', $totalRaw) : null;

        // Invoice number
        $invRaw = $extract('/(GGIMS\/\d{2}-\d{2}\/[ \t]*\d+)/i')
               ?: $extract('/Invoice\s*No\.?[\s\S]{0,40}?(GGIMS\/[\d\-\/ \t]+\d)/i');
        $invoiceNumber = preg_replace('/\s+/', '', (string) $invRaw);

        // Invoice date
        $invoiceDate = $extract('/INVOICE\s*DATE[\n\r\s]*([\d]{4}-[\d]{2}-[\d]{2})/i')
                    ?: $extract('/INVOICE\s*DATE[^\d]*([\d]{2}[\/\-][\d]{2}[\/\-][\d]{4})/i');
        // Normalise dd/mm/yyyy or dd-mm-yyyy -> yyyy-mm-dd so the DB never rejects it
        if ($invoiceDate && preg_match('/^(\d{2})[\/\-](\d{2})[\/\-](\d{4})$/', $invoiceDate, $dm)) {
            $invoiceDate = checkdate((int) $dm[2], (int) $dm[1], (int) $dm[3])
                ? "{$dm[3]}-{$dm[2]}-{$dm[1]}"
                : null;
        }

        // Buyer block for email/address
        preg_match('/BUYER[\s\S]*?(?=CONSULTANT\s*DETAILS)/i', $text, $buyerMatch);
        $buyerBlock = $buyerMatch[0] ?? '';

        $clientAddress = $extract('/Recipient\s*Address:\s*([^\n]+)/i');
        $state         = $this->extractState($buyerBlock ?: $text);
        $contactNo     = $extract('/Contact(?:\s*No\.?)?:\s*([\d\s\-\+]{8,15})/i');

        // Client email (not ggims.com)
        $emailAddress = '';
        if (preg_match('/Email:\s*([a-zA-Z0-9._%+\-]+@(?!ggims)[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})/i', $buyerBlock, $em)) {
            $emailAddress = trim($em[1]);
        }

        $branchCode  = $extract('/BRANCH\s*CODE\s*:\s*([\w\-]+)/i')
                    ?: $extract('/BRANCH\s*:\s*([A-Za-z0-9 \-]+?)(?:\n|$)/i')
                    ?: $extract('/OFFICE\s*:\s*([A-Za-z0-9 \-]+?)(?:\n|$)/i')
                    ?: $extract('/LOCATION\s*:\s*([A-Za-z0-9 \-]+?)(?:\n|$)/i');

        // Consultant-based branch override.
        // The PDF's "BRANCH CODE" field is a static head-office code and doesn't
        // actually vary per document, so specific consultants are manually mapped
        // to their real working branch here. Add more names as needed.
        $consultantBranchMap = [
            'aryan'   => 'Bangalore',
            'kanchan' => 'Bangalore',
            'swetha'  => 'Bangalore',
        ];
        $consultantKey = strtolower(trim($this->cleanText($consultantName)));
        if (isset($consultantBranchMap[$consultantKey])) {
            $branchCode = $consultantBranchMap[$consultantKey];
        }

        $paymentMode = $this->extractPaymentMode($text);

        return [
            'client_name'     => $this->cleanText($clientName),
            'consultant_name' => $this->cleanText($consultantName),
            'process'         => $process,
            'basic_amount'    => $basicAmount,
            'gst_amount'      => $gstInfo['total'],
            'cgst'            => $gstInfo['cgst'] ?: null,
            'sgst'            => $gstInfo['sgst'] ?: null,
            'igst'            => $gstInfo['igst'] ?: null,
            'total_amount'    => $totalAmount,
            'invoice_number'  => $invoiceNumber,
            'invoice_date'    => $invoiceDate ?: null,
            'client_address'  => $this->cleanText($clientAddress),
            'state'           => $state,
            'contact_no'      => trim($contactNo),
            'email_address'   => $emailAddress,
            'branch_code'     => $branchCode,
            'payment_mode'    => $paymentMode,
            'refund_invoice_no' => '',
        ];
    }

    private function extractProcess(string $text): string
    {
        $countries    = ['Ireland','Europe','Canada','UK','Australia','Germany','New Zealand','Portugal','Malta','Dubai','UAE','USA','Denmark','Netherlands','Sweden','Norway','Finland','Singapore','Luxembourg'];
        $serviceTypes = ['Job Assistance','Study Visa','Work Permit','PR','Permanent Residency','Tourist Visa','Business Visa','Spouse Visa','Family Visa','Immigration'];
        $evalTypes    = ['Technical Evaluation','Signup','Registration','Documentation','File Processing','Consultation'];

        $line = '';
        if (preg_match('/(?:Consultation\s*)?Fee\s*for\s*([^\n\d]+?)(?:\s*\d|HSN|$)/i', $text, $m)) $line = $m[1];
        elseif (preg_match('/1\s+((?:Fee|Consultation)[^\n]+?)(?:\s*9985|\s*\d{4})/i', $text, $m)) $line = $m[1];

        $country = $service = $eval = '';
        foreach ($countries as $c) if (stripos($line, $c) !== false) { $country = $c; break; }
        foreach ($serviceTypes as $s) if (stripos($line, $s) !== false) { $service = $s; break; }
        foreach ($evalTypes as $e) if (stripos($line, $e) !== false) { $eval = $e; break; }

        $parts = array_filter([$country, $service, $eval]);
        return $parts ? implode(' - ', $parts) : trim($line);
    }

    private function extractGST(string $text): array
    {
        $r = ['total' => null, 'cgst' => null, 'sgst' => null, 'igst' => null];

        // NIL rate case
        if (preg_match('/9985\s+[\d,]+\.?\d*\s+NIL\s+([\d,]+\.?\d*)/i', $text, $m)) {
            $r['igst'] = $r['total'] = (float) str_replace(',', '', $m[1]);
            return $r;
        }
        // 18% IGST
        if (preg_match('/9985\s+[\d,]+\.?\d*\s+18%\s+([\d,]+\.?\d*)\s+([\d,]+\.?\d*)/i', $text, $m)) {
            $r['igst'] = $r['total'] = (float) str_replace(',', '', $m[1]);
            return $r;
        }
        // CGST + SGST
        if (preg_match('/9985\s+[\d,]+\.?\d*\s+9%\s+([\d,]+\.?\d*)\s+9%\s+([\d,]+\.?\d*)/i', $text, $m)) {
            $r['cgst']  = (float) str_replace(',', '', $m[1]);
            $r['sgst']  = (float) str_replace(',', '', $m[2]);
            $r['total'] = $r['cgst'] + $r['sgst'];
            return $r;
        }
        // Fallback: Total Tax Amount
        if (preg_match('/Total\s*Tax\s*Amount[^\d]*([\d,]+\.?\d*)/i', $text, $m)) {
            $r['igst'] = $r['total'] = (float) str_replace(',', '', $m[1]);
        }
        return $r;
    }

    private function extractState(string $text): string
    {
        $states = ['Delhi','Maharashtra','Karnataka','Tamil Nadu','Uttar Pradesh','Gujarat','Rajasthan','West Bengal','Bihar','Madhya Pradesh','Punjab','Haryana','Kerala','Andhra Pradesh','Telangana','Odisha','Jharkhand','Uttarakhand','Himachal Pradesh','Assam','Chandigarh','Goa','Jammu','Chhattisgarh','Rajasthan'];
        foreach ($states as $state) {
            if (preg_match('/\b' . preg_quote($state, '/') . '\b/i', $text)) return $state;
        }
        return '';
    }

    private function extractPaymentMode(string $text): string
    {
        if (stripos($text, 'razorpay') !== false) return 'Razorpay';
        if (stripos($text, 'paytm') !== false)    return 'Paytm';
        if (preg_match('/MODE\s*OF\s*PAYMENT:\s*([^\n]+)/i', $text, $m)) return trim($m[1]);
        if (stripos($text, 'ICICI') !== false) return 'ICICI Bank';
        if (stripos($text, 'HDFC') !== false)  return 'HDFC Bank';
        if (stripos($text, 'Axis') !== false)  return 'Axis Bank';
        if (stripos($text, 'SBI') !== false)   return 'SBI';
        return '';
    }

    private function cleanText(string $str): string
    {
        return trim(preg_replace('/\n.*/s', '', $str));
    }
}
