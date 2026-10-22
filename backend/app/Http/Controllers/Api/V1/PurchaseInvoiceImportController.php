<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PurchaseInvoiceImportController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(DB::table('purchase_invoice_imports as d')
            ->leftJoin('purchase_invoices as p', function ($join): void {
                $join->on('p.id', '=', 'd.purchase_invoice_id')->on('p.organization_id', '=', 'd.organization_id');
            })
            ->where('d.organization_id', $request->user()->organization_id)
            ->orderByDesc('d.created_at')
            ->limit(100)
            ->get([
                'd.id', 'd.original_name', 'd.mime_type', 'd.file_size', 'd.sha256', 'd.status',
                'd.extracted_text', 'd.extraction_json', 'd.ocr_engine', 'd.ocr_confidence',
                'd.purchase_invoice_id', 'p.document_number as posted_document_number', 'd.created_at',
            ]));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'documents' => ['required', 'array', 'min:1', 'max:10'],
            'documents.*' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:15360'],
        ]);

        $organizationId = $request->user()->organization_id;
        $disk = Storage::build(['driver' => 'local', 'root' => storage_path('app'), 'throw' => true]);
        $created = [];

        foreach ($data['documents'] as $file) {
            $sha256 = hash_file('sha256', $file->getRealPath());
            $existing = DB::table('purchase_invoice_imports')
                ->where('organization_id', $organizationId)
                ->where('sha256', $sha256)
                ->first();

            if ($existing) {
                $created[] = $this->publicRecord($existing, true);
                continue;
            }

            $id = (string) Str::ulid();
            $mimeType = (string) $file->getMimeType();
            $extension = match ($mimeType) {
                'application/pdf' => 'pdf',
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => throw ValidationException::withMessages(['documents' => ['صيغة الملف غير مدعومة.']]),
            };
            $path = "private/purchase-invoice-imports/{$organizationId}/{$id}.{$extension}";
            $disk->putFileAs(dirname($path), $file, basename($path));

            DB::table('purchase_invoice_imports')->insert([
                'id' => $id,
                'organization_id' => $organizationId,
                'uploaded_by' => $request->user()->id,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'storage_path' => $path,
                'mime_type' => $mimeType,
                'file_size' => $file->getSize(),
                'sha256' => $sha256,
                'status' => 'uploaded',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $created[] = $this->publicRecord(DB::table('purchase_invoice_imports')->where('id', $id)->first(), false);
        }

        return response()->json(['documents' => $created], 201);
    }

    public function saveExtraction(Request $request, string $documentId)
    {
        $data = $request->validate([
            'extracted_text' => ['required', 'string', 'max:150000'],
            'extraction_json' => ['nullable', 'array'],
            'ocr_engine' => ['required', 'string', 'max:64'],
            'ocr_confidence' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $organizationId = $request->user()->organization_id;
        return DB::transaction(function () use ($data, $organizationId, $documentId) {
            // The row lock only protects the status check when it is held inside
            // a transaction. Purchase posting locks this same row; keeping the
            // check and update atomic prevents a late OCR save from changing a
            // posted import back to needs_review.
            $document = DB::table('purchase_invoice_imports')
                ->where('organization_id', $organizationId)
                ->where('id', $documentId)
                ->lockForUpdate()
                ->first();

            abort_unless($document, 404);
            if ($document->status === 'posted' || $document->purchase_invoice_id) {
                throw ValidationException::withMessages(['document' => ['المستند مرحّل بالفعل ولا يمكن تغيير قراءة OCR الخاصة به.']]);
            }

            DB::table('purchase_invoice_imports')
                ->where('organization_id', $organizationId)
                ->where('id', $documentId)
                ->update([
                    'status' => 'needs_review',
                    'extracted_text' => $data['extracted_text'],
                    'extraction_json' => isset($data['extraction_json']) ? json_encode($data['extraction_json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                    'ocr_engine' => $data['ocr_engine'],
                    'ocr_confidence' => $data['ocr_confidence'] ?? null,
                    'error_message' => null,
                    'updated_at' => now(),
                ]);

            return response()->json(['status' => 'needs_review', 'id' => $documentId]);
        });
    }

    public function file(Request $request, string $documentId)
    {
        $organizationId = $request->user()->organization_id;
        $document = DB::table('purchase_invoice_imports')
            ->where('organization_id', $organizationId)
            ->where('id', $documentId)
            ->first();
        abort_unless($document, 404);

        $disk = Storage::build(['driver' => 'local', 'root' => storage_path('app'), 'throw' => true]);
        abort_unless($disk->exists($document->storage_path), 404);

        return response()->file($disk->path($document->storage_path), [
            'Content-Type' => $document->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => 'sandbox',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function publicRecord(object $record, bool $duplicate): array
    {
        return [
            'id' => $record->id,
            'original_name' => $record->original_name,
            'mime_type' => $record->mime_type,
            'file_size' => (int) $record->file_size,
            'status' => $record->status,
            'extracted_text' => $record->extracted_text ?? null,
            'extraction_json' => isset($record->extraction_json) ? (is_string($record->extraction_json) ? json_decode($record->extraction_json, true) : $record->extraction_json) : null,
            'purchase_invoice_id' => $record->purchase_invoice_id ?? null,
            'duplicate' => $duplicate,
        ];
    }
}
