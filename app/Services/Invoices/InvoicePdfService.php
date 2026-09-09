<?php

namespace App\Services\Invoices;

use App\Enums\Invoices\InvoiceThemeEnum;
use App\Enums\Invoices\PaymentMethodEnum;
use App\Enums\Invoices\VatTypeEnum;
use App\Models\Invoices\Invoice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use ZipArchive;

class InvoicePdfService
{
    /**
     * Customer fields baked into an invoice's `buyer_snapshot`.
     *
     * Single source of truth: the invoice form renders exactly these keys, so the
     * editable buyer fields on the form and the snapshot built from a customer
     * cannot drift apart.
     *
     * @var list<string>
     */
    public const BUYER_SNAPSHOT_KEYS = [
        'name',
        'company_name',
        'street',
        'city',
        'zip',
        'country_code',
        'vat_number',
        'tax_number',
        'business_number',
        'email',
        'phone',
        'web',
    ];

    public function __construct(
        public PayBySquareService $payBySquareService,
    ) {}

    public function generateHtml(Invoice $invoice, ?string $locale = null): string
    {
        $invoice->load(['items.translations', 'customer', 'company']);

        $locale = $locale ?? $invoice->company->default_locale ?? 'sk';
        $previousLocale = app()->getLocale();
        app()->setLocale($locale);

        try {
            $items = $invoice->items->map(fn ($item) => [
                'description' => $item->translated('description', $locale),
                'quantity' => $item->quantity,
                'unit' => $item->unit,
                'unit_price' => $item->unit_price,
                'vat_rate_value' => $item->vat_rate_value,
                'vat_amount' => $item->vat_amount,
                'total' => $item->total,
            ])->toArray();

            $logoBase64 = $invoice->company->getLogoBase64();
            $signatureBase64 = $invoice->company->getSignatureBase64();

            $qrBase64 = $invoice->payment_method === PaymentMethodEnum::BANK_TRANSFER
                ? $this->payBySquareService->generateQrBase64($invoice)
                : null;

            $theme = InvoiceThemeEnum::tryFrom($invoice->company->invoice_theme ?? '') ?? InvoiceThemeEnum::Emerald;

            $showVat = $invoice->items->contains(fn ($item) => $item->vat_type === VatTypeEnum::STANDARD && (float) $item->vat_rate_value > 0);

            $hasReverseCharge = $invoice->items->contains(fn ($item) => $item->vat_type === VatTypeEnum::REVERSE_CHARGE);

            $seller = $invoice->seller_snapshot ?? $this->buildSellerSnapshot($invoice->company);

            $textBeforeItems = $this->getTranslatedText($invoice->text_before_items, $locale);
            $textAfterItems = $this->getTranslatedText($invoice->text_after_items, $locale);

            return View::make('invoices.pdf', [
                'invoice' => $invoice,
                'seller' => $seller,
                'buyer' => $invoice->buyer_snapshot ?? $this->buildBuyerSnapshot($invoice->customer),
                'items' => $items,
                'locale' => $locale,
                'logoBase64' => $logoBase64,
                'signatureBase64' => $signatureBase64,
                'qrBase64' => $qrBase64,
                'theme' => $theme,
                'invoiceColor' => $invoice->company->invoice_color ?: null,
                'showVat' => $showVat,
                'hasReverseCharge' => $hasReverseCharge,
                'textBeforeItems' => $textBeforeItems,
                'textAfterItems' => $textAfterItems,
            ])->render();
        } finally {
            app()->setLocale($previousLocale);
        }
    }

    public function generatePdf(Invoice $invoice, ?string $locale = null): string
    {
        $locale = $locale ?? $invoice->company->default_locale ?? 'sk';
        $html = $this->generateHtml($invoice, $locale);
        $footerHtml = $this->generateFooterHtml($invoice, $locale);

        $response = Http::attach('files', $html, 'index.html', ['Content-Type' => 'text/html'])
            ->attach('files', $footerHtml, 'footer.html', ['Content-Type' => 'text/html'])
            ->post(config('services.gotenberg.url').'/forms/chromium/convert/html', [
                'paperWidth' => 8.27,
                'paperHeight' => 11.7,
                'marginTop' => 0.4,
                'marginBottom' => 0.8,
                'marginLeft' => 0.4,
                'marginRight' => 0.4,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('PDF generation failed: '.$response->body());
        }

        return $response->body();
    }

    public function generateFooterHtml(Invoice $invoice, string $locale): string
    {
        $previousLocale = app()->getLocale();
        app()->setLocale($locale);

        try {
            $seller = $invoice->seller_snapshot ?? $this->buildSellerSnapshot($invoice->company);
            $responsiblePerson = $seller['responsible_person'] ?? $invoice->company->responsible_person ?? '';

            return View::make('invoices.footer', [
                'responsiblePerson' => $responsiblePerson,
                'locale' => $locale,
            ])->render();
        } finally {
            app()->setLocale($previousLocale);
        }
    }

    /**
     * @param  array<Invoice>  $invoices
     */
    public function generateBulkZip(array $invoices, ?string $locale = null): string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'invoices_').'.zip';
        $zip = new ZipArchive;
        $zip->open($tmpPath, ZipArchive::CREATE);

        foreach ($invoices as $invoice) {
            $pdf = $this->generatePdf($invoice, $locale);
            $filename = $invoice->invoice_number.'.pdf';
            $zip->addFromString($filename, $pdf);
        }

        $zip->close();

        return $tmpPath;
    }

    /**
     * @param  array<string, string>|null  $translations
     */
    private function getTranslatedText(?array $translations, string $locale): ?string
    {
        if (empty($translations)) {
            return null;
        }

        return $translations[$locale] ?? collect($translations)->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function buildSellerSnapshot(mixed $company): array
    {
        return [
            'name' => $company->name,
            'street' => $company->street,
            'city' => $company->city,
            'zip' => $company->zip,
            'country_code' => $company->country_code,
            'vat_number' => $company->vat_number,
            'tax_number' => $company->tax_number,
            'business_number' => $company->business_number,
            'is_vat_payer' => $company->is_vat_payer,
            'bank_name' => $company->bank_name,
            'bank_account_number' => $company->bank_account_number,
            'bank_iban' => $company->bank_iban,
            'bank_swift' => $company->bank_swift,
            'email' => $company->email,
            'phone' => $company->phone,
            'responsible_person' => $company->responsible_person,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildBuyerSnapshot(mixed $customer): array
    {
        $snapshot = [];

        foreach (self::BUYER_SNAPSHOT_KEYS as $key) {
            $snapshot[$key] = $customer->{$key};
        }

        return $snapshot;
    }
}
