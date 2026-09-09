<?php

namespace App\Filament\Invoices\Actions;

use App\Enums\Common\LocaleEnum;
use App\Models\Invoices\Invoice;
use App\Services\Invoices\InvoiceEmailService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

/**
 * The single "send invoice by email" modal.
 *
 * Used by the invoice list row action, the invoice detail page and the edit page,
 * so all three offer the same fields (CC/BCC and a rich-text body used to be
 * missing from the list version).
 */
class SendInvoiceEmailAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'sendEmail';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Odoslať emailom')
            ->icon('heroicon-o-envelope')
            ->modalHeading(fn (Invoice $record): string => 'Odoslať faktúru '.$record->invoice_number)
            ->schema([
                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->required()
                    ->default(fn (Invoice $record) => $record->buyer_snapshot['email'] ?? $record->customer?->email),
                TagsInput::make('cc')
                    ->label('CC')
                    ->nestedRecursiveRules(['email:rfc'])
                    ->splitKeys(['Tab', ',', ' '])
                    ->placeholder('Pridať email'),
                TagsInput::make('bcc')
                    ->label('BCC')
                    ->nestedRecursiveRules(['email:rfc'])
                    ->splitKeys(['Tab', ',', ' '])
                    ->placeholder('Pridať email'),
                TextInput::make('subject')
                    ->label('Predmet')
                    ->required()
                    ->default(fn (Invoice $record): string => 'Faktúra '.$record->invoice_number),
                RichEditor::make('body')
                    ->label('Správa')
                    ->required()
                    ->default('<p>V prílohe posielame faktúru. Ďakujeme za spoluprácu.</p>'),
                Select::make('locale')
                    ->label('Jazyk PDF')
                    ->options(LocaleEnum::translations())
                    ->default(fn (Invoice $record) => $record->company->default_locale ?? 'sk')
                    ->required(),
                FileUpload::make('attachments')
                    ->label('Prílohy')
                    ->multiple()
                    ->storeFiles(false)
                    ->acceptedFileTypes([
                        'application/pdf',
                        'application/msword',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'image/jpeg',
                        'image/png',
                        'application/zip',
                    ])
                    ->maxSize(10240),
            ])
            ->action(function (Invoice $record, array $data): void {
                app(InvoiceEmailService::class)->sendInvoice(
                    $record,
                    $data['email'],
                    $data['subject'],
                    $data['body'],
                    $data['locale'],
                    $data['attachments'] ?? [],
                    $data['cc'] ?? [],
                    $data['bcc'] ?? [],
                );

                Notification::make()
                    ->title('Faktúra bola odoslaná')
                    ->success()
                    ->send();
            });
    }
}
