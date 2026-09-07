<?php

namespace App\Http\Controllers;

use App\Models\ProcessedEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Webklex\PHPIMAP\ClientManager;

class EmailSyncController extends Controller
{
    // POST /api/invoices/sync-email
    // Scans the "Sent" folder of the configured mailbox for PDF attachments
    // that look like GGIMS invoices, and imports any not already processed.
    public function sync(InvoiceController $invoiceController)
    {
        $host       = env('IMAP_HOST');
        $port       = env('IMAP_PORT', 993);
        $encryption = env('IMAP_ENCRYPTION', 'ssl');
        $username   = env('IMAP_USERNAME');
        $password   = env('IMAP_PASSWORD');

        if (!$host || !$username || !$password) {
            return response()->json([
                'error' => 'Email sync is not configured. Set IMAP_HOST, IMAP_USERNAME, IMAP_PASSWORD in .env',
            ], 422);
        }

        $cm = new ClientManager();
        $client = $cm->make([
            'host'          => $host,
            'port'          => $port,
            'encryption'    => $encryption,
            'validate_cert' => true,
            'username'      => $username,
            'password'      => $password,
            'protocol'      => 'imap',
        ]);

        try {
            $client->connect();
        } catch (\Throwable $e) {
            return response()->json(['error' => 'IMAP connection failed: ' . $e->getMessage()], 422);
        }

        // Try common "Sent" folder names across providers
        $folder = null;
        foreach (['Sent', 'Sent Items', 'INBOX.Sent', '[Gmail]/Sent Mail'] as $name) {
            try {
                $folder = $client->getFolder($name);
                if ($folder) break;
            } catch (\Throwable $e) {
                continue;
            }
        }

        if (!$folder) {
            return response()->json(['error' => 'Could not find a Sent folder on this mailbox'], 422);
        }

        // Only look at recent messages to keep this fast (last 90 days)
        $messages = $folder->messages()->since(now()->subDays(90))->get();

        $added = 0; $skipped = 0; $failed = 0; $errors = [];

        foreach ($messages as $message) {
            $messageId = (string) $message->getMessageId();
            if (!$messageId) continue;

            if (ProcessedEmail::where('message_id', $messageId)->exists()) {
                continue; // already handled in a previous sync
            }

            $attachments = $message->getAttachments();
            $handledAny = false;

            foreach ($attachments as $attachment) {
                $name = strtolower($attachment->name ?? '');
                if (!str_ends_with($name, '.pdf')) continue;

                $handledAny = true;
                $tmpName = 'invoices/' . uniqid('mail_') . '.pdf';
                Storage::disk('local')->put($tmpName, $attachment->getContent());

                $result = $invoiceController->processStoredPdf($tmpName);

                if (isset($result['error'])) {
                    // "already exists" / "not a valid invoice" are not real failures,
                    // just skip them quietly; genuine parse errors count as failed.
                    if (str_contains($result['error'], 'already exists')
                        || str_contains($result['error'], 'Not a valid')) {
                        $skipped++;
                    } else {
                        $failed++;
                        $errors[] = $result['error'];
                    }
                } else {
                    $added++;
                }
            }

            if ($handledAny) {
                ProcessedEmail::create(['message_id' => $messageId]);
            }
        }

        $client->disconnect();

        return response()->json([
            'added'   => $added,
            'skipped' => $skipped,
            'failed'  => $failed,
            'errors'  => $errors,
        ]);
    }
}
