<?php

namespace App\Filament\Invoices\Resources\Invoices\Pages;

use App\Enums\Common\LocaleEnum;
use App\Enums\Invoices\InvoiceStatusEnum;
use App\Filament\Invoices\Actions\SendInvoiceEmailAction;
use App\Filament\Invoices\Concerns\HasCompanyBreadcrumb;
use App\Filament\Invoices\Resources\Invoices\InvoiceResource;
use App\Models\Invoices\InvoiceNumberSequence;
use App\Services\Invoices\InvoiceCalculationService;
use App\Services\Invoices\InvoiceNumberService;
use App\Services\Invoices\InvoicePdfService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;

class EditInvoice extends EditRecord
{
    use HasCompanyBreadcrumb;

    protected static string $resource = InvoiceResource::class;

    public function getTitle(): string|Htmlable
    {
        return 'Faktúra '.$this->getRecord()->invoice_number;
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('previewHtml')
                    ->label('Náhľad')
                    ->icon('heroicon-o-eye')
                    ->url(fn () => route('invoices.preview', $this->getRecord()))
                    ->openUrlInNewTab(),

                Action::make('downloadPdf')
                    ->label('Stiahnuť PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->form([
                        Select::make('locale')
                            ->label('Jazyk')
                            ->options(LocaleEnum::translations())
                            ->default(fn () => $this->getRecord()->company->default_locale ?? 'sk')
                            ->required(),
                    ])
                    ->action(function (array $data) {
                        $record = $this->getRecord();
                        $pdf = app(InvoicePdfService::class)->generatePdf($record, $data['locale']);

                        return response()->streamDownload(function () use ($pdf) {
                            echo $pdf;
                        }, $record->invoice_number.'.pdf', [
                            'Content-Type' => 'application/pdf',
                        ]);
                    }),

                SendInvoiceEmailAction::make(),
            ])->label('Viac')->icon('heroicon-o-ellipsis-vertical'),

            Action::make('duplicate')
                ->label('Duplikovať')
                ->icon('heroicon-o-document-duplicate')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Duplikovať faktúru')
                ->modalDescription('Vytvorí sa kópia faktúry s novým číslom a stavom "Nová".')
                ->action(function () {
                    $record = $this->getRecord();
                    $company = auth()->user()->activeCompany;
                    $sequence = InvoiceNumberSequence::query()->where('is_default', true)->first()
                        ?? $record->invoiceNumberSequence;

                    $newInvoice = $record->replicate([
                        'invoice_number',
                        'invoice_number_sequence_id',
                        'status',
                        'sent_at',
                        'cancelled_at',
                        'deleted_at',
                        'subtotal',
                        'vat_total',
                        'total',
                        'subtotal_base',
                        'vat_total_base',
                        'total_base',
                    ]);

                    $newInvoice->status = InvoiceStatusEnum::NEW;
                    $newInvoice->issue_date = now();
                    $newInvoice->due_date = now()->addDays(14);
                    $newInvoice->delivery_date = now();

                    if ($sequence) {
                        $newInvoice->invoice_number_sequence_id = $sequence->id;
                        $newInvoice->invoice_number = app(InvoiceNumberService::class)->generateNextNumber($sequence);
                    }

                    $pdfService = app(InvoicePdfService::class);
                    if ($company) {
                        $newInvoice->seller_snapshot = $pdfService->buildSellerSnapshot($company);
                    }
                    // The replicated invoice already carries its own buyer data, which may
                    // have been hand-edited; only rebuild it for invoices that have none.
                    if (blank($newInvoice->buyer_snapshot) && $record->customer) {
                        $newInvoice->buyer_snapshot = $pdfService->buildBuyerSnapshot($record->customer);
                    }

                    $newInvoice->save();

                    foreach ($record->items as $item) {
                        $newItem = $item->replicate(['invoice_id']);
                        $newItem->invoice_id = $newInvoice->id;
                        $newItem->save();

                        foreach ($item->translations as $translation) {
                            $newTranslation = $translation->replicate(['parent_id']);
                            $newTranslation->parent_id = $newItem->id;
                            $newTranslation->save();
                        }
                    }

                    app(InvoiceCalculationService::class)->recalculateInvoice($newInvoice);

                    return redirect(InvoiceResource::getUrl('edit', ['record' => $newInvoice]));
                }),

            DeleteAction::make(),
        ];
    }

    /**
     * Invoices created before the buyer fields lived on the invoice itself have an
     * empty snapshot; seed the form from the customer so the fields aren't blank.
     * Anything already stored on the invoice is left untouched.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $storedBuyerSnapshot = array_filter(
            $data['buyer_snapshot'] ?? [],
            fn ($value): bool => filled($value),
        );

        if (empty($storedBuyerSnapshot) && $this->getRecord()->customer) {
            $data['buyer_snapshot'] = app(InvoicePdfService::class)
                ->buildBuyerSnapshot($this->getRecord()->customer);
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $company = auth()->user()->activeCompany;

        if ($company && ! empty($data['exchange_rate']) && ($data['currency'] ?? '') !== $company->default_currency) {
            $data['exchange_rate_date'] = now()->toDateString();
        }

        unset($data['due_days']);

        return $data;
    }

    protected function afterSave(): void
    {
        app(InvoiceCalculationService::class)->recalculateInvoice($this->record);
    }
}
