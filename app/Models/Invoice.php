<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $fillable = [
        'client_name', 'consultant_name', 'process',
        'basic_amount', 'gst_amount', 'cgst', 'sgst', 'igst', 'total_amount',
        'invoice_number', 'invoice_date', 'client_address', 'state',
        'contact_no', 'email_address', 'branch_code', 'payment_mode',
        'refund_invoice_no', 'pdf_path',
    ];

    protected $casts = [
        'invoice_date' => 'date:Y-m-d',
        'basic_amount' => 'float',
        'gst_amount'   => 'float',
        'cgst'         => 'float',
        'sgst'         => 'float',
        'igst'         => 'float',
        'total_amount' => 'float',
    ];
}
