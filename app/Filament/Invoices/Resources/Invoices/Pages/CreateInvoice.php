<?php

namespace App\Filament\Invoices\Resources\Invoices\Pages;

use App\Filament\Invoices\Concerns\HasCompanyBreadcrumb;
use App\Filament\Invoices\Resources\Invoices\InvoiceResource;
use App\Models\Invoices\Customer;
use App\Models\Invoices\InvoiceNumberSequence;
use App\Services\Invoices\InvoiceCalculationService;
use App\Services\Invoices\InvoiceNumberService;
use App\Services\Invoices\InvoicePdfService;
use Filament\Resources\Pages\CreateRecord;

class CreateInvoice extends CreateRecord
{
    use HasCompanyBreadcrumb;

    protected static string $resource = InvoiceResource::class;

    protected static ?string $title = 'Nová faktúra';

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $sequence = InvoiceNumberSequence::find($data['invoice_number_sequence_id']);
        $autoNumber = $data['_invoice_number_auto'] ?? null;
        unset($data['_invoice_number_auto']);

        if ($sequence && $autoNumber && $data['invoice_number'] === $autoNumber) {
            $data['invoice_number'] = app(InvoiceNumberService::class)->generateNextNumber($sequence);
        }

        $company = auth()->user()->activeCompany;

        if ($company) {
            $pdfService = app(InvoicePdfService::class);
            $data['seller_snapshot'] = $pdfService->buildSellerSnapshot($company);

            // The form carries the buyer data itself (prefilled from the customer, then
            // freely editable), so whatever was submitted wins — including fields the
            // user deliberately blanked out. Only fall back to building the snapshot
            // from the customer when the form supplied nothing at all.
            $submittedBuyerSnapshot = array_filter(
                $data['buyer_snapshot'] ?? [],
                fn ($value): bool => filled($value),
            );

            if (empty($submittedBuyerSnapshot) && ! empty($data['customer_id'])) {
                $customer = Customer::find($data['customer_id']);
                if ($customer) {
                    $data['buyer_snapshot'] = $pdfService->buildBuyerSnapshot($customer);
                }
            }

            if (! empty($data['exchange_rate']) && ($data['currency'] ?? '') !== $company->default_currency) {
                $data['exchange_rate_date'] = now()->toDateString();
            }
        }

        unset($data['due_days']);

        return $data;
    }

    protected function afterCreate(): void
    {
        app(InvoiceCalculationService::class)->recalculateInvoice($this->record);
    }
}
