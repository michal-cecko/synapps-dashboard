<?php

namespace App\Filament\Invoices\Resources\Invoices\Schemas;

use App\Enums\Common\CountryEnum;
use App\Enums\Common\CurrencyEnum;
use App\Enums\Common\LocaleEnum;
use App\Enums\Invoices\InvoiceStatusEnum;
use App\Enums\Invoices\PaymentMethodEnum;
use App\Enums\Invoices\VatTypeEnum;
use App\Models\Invoices\Customer;
use App\Models\Invoices\Invoice;
use App\Models\Invoices\InvoiceNumberSequence;
use App\Models\Invoices\ServiceCatalogItem;
use App\Models\Invoices\VatRate;
use App\Services\Invoices\ExchangeRateService;
use App\Services\Invoices\InvoiceNumberService;
use App\Services\Invoices\InvoicePdfService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class InvoiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Základné údaje')
                    ->schema([
                        Hidden::make('_invoice_number_auto')
                            ->default(function () {
                                $sequence = InvoiceNumberSequence::query()->where('is_default', true)->first();

                                return $sequence
                                    ? app(InvoiceNumberService::class)->previewNumber($sequence)
                                    : '';
                            }),

                        Select::make('invoice_number_sequence_id')
                            ->label('Číselná rada')
                            ->options(fn () => InvoiceNumberSequence::query()->pluck('name', 'id'))
                            ->required()
                            ->default(fn () => InvoiceNumberSequence::query()->where('is_default', true)->value('id'))
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set, $record): void {
                                if ($record) {
                                    return;
                                }

                                if ($state) {
                                    $sequence = InvoiceNumberSequence::find($state);
                                    if ($sequence) {
                                        $preview = app(InvoiceNumberService::class)->previewNumber($sequence);
                                        $set('invoice_number', $preview);
                                        $set('_invoice_number_auto', $preview);
                                    }
                                } else {
                                    $set('invoice_number', '');
                                    $set('_invoice_number_auto', '');
                                }
                            }),

                        TextInput::make('invoice_number')
                            ->label('Číslo faktúry')
                            ->required()
                            ->default(function () {
                                $sequence = InvoiceNumberSequence::query()->where('is_default', true)->first();

                                return $sequence
                                    ? app(InvoiceNumberService::class)->previewNumber($sequence)
                                    : '';
                            })
                            ->hintAction(
                                Action::make('refetchSequenceNumber')
                                    ->label('Obnoviť číslo')
                                    ->link()
                                    ->action(function (Get $get, Set $set, ?Invoice $record): void {
                                        $sequenceId = $get('invoice_number_sequence_id');
                                        if ($sequenceId) {
                                            $sequence = InvoiceNumberSequence::find($sequenceId);
                                            if ($sequence) {
                                                $preview = app(InvoiceNumberService::class)->previewNumber($sequence, $record?->getKey());
                                                $set('invoice_number', $preview);
                                                $set('_invoice_number_auto', $preview);
                                            }
                                        }
                                    })
                            )
                            ->helperText('Náhľad ďalšieho čísla — vygenerované pri uložení')
                            ->maxLength(100),

                        Select::make('customer_id')
                            ->label('Odberateľ')
                            ->options(fn () => Customer::query()->pluck('name', 'id'))
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set): void {
                                self::fillBuyerSnapshotFromCustomer($state, $set);
                            })
                            ->createOptionForm([
                                TextInput::make('name')
                                    ->label('Meno / Názov')
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('company_name')
                                    ->label('Názov firmy')
                                    ->maxLength(255),
                                TextInput::make('email')
                                    ->label('Email')
                                    ->email()
                                    ->maxLength(255),
                                TextInput::make('phone')
                                    ->label('Telefón')
                                    ->maxLength(255),
                                TextInput::make('web')
                                    ->label('Web')
                                    ->url()
                                    ->maxLength(255),
                                TextInput::make('business_number')
                                    ->label('IČO')
                                    ->maxLength(255),
                                TextInput::make('tax_number')
                                    ->label('DIČ')
                                    ->maxLength(255),
                                TextInput::make('vat_number')
                                    ->label('IČ DPH')
                                    ->maxLength(255),
                            ])
                            ->createOptionUsing(function (array $data): int {
                                return Customer::create($data)->id;
                            }),

                        Select::make('status')
                            ->label('Stav')
                            ->options(InvoiceStatusEnum::translations())
                            ->default(InvoiceStatusEnum::NEW->value)
                            ->required(),

                        Select::make('currency')
                            ->label('Mena')
                            ->options(CurrencyEnum::translations())
                            ->default(fn () => auth()->user()->activeCompany?->default_currency ?? 'EUR')
                            ->required()
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set): void {
                                $baseCurrency = auth()->user()->activeCompany?->default_currency ?? 'EUR';
                                if ($state && $state !== $baseCurrency) {
                                    $rate = app(ExchangeRateService::class)->getRate($state, $baseCurrency);
                                    if ($rate) {
                                        $set('exchange_rate', round($rate, 6));
                                    }
                                } else {
                                    $set('exchange_rate', null);
                                }
                            }),

                        TextInput::make('exchange_rate')
                            ->label(fn () => 'Kurz k '.(auth()->user()->activeCompany?->default_currency ?? 'EUR'))
                            ->numeric()
                            ->helperText(fn (Get $get) => $get('currency') && $get('exchange_rate')
                                ? '1 '.$get('currency').' = '.$get('exchange_rate').' '.(auth()->user()->activeCompany?->default_currency ?? 'EUR')
                                : null)
                            ->live(onBlur: true)
                            ->visible(fn (Get $get): bool => $get('currency') !== (auth()->user()->activeCompany?->default_currency ?? 'EUR')),

                        Select::make('payment_method')
                            ->label('Spôsob platby')
                            ->options(PaymentMethodEnum::translations()),

                        Textarea::make('description')
                            ->label('Popis')
                            ->rows(2)
                            ->columnSpanFull(),

                        TextInput::make('order_number')
                            ->label('Číslo objednávky')
                            ->maxLength(100),

                    ])->columns(2),

                Section::make('Údaje odberateľa na faktúre')
                    ->description('Predvyplnia sa z karty odberateľa pri jeho výbere a uložia sa priamo na faktúru. Úpravy tu platia len pre túto faktúru — kartu odberateľa nezmenia, a neskoršia zmena karty túto faktúru neovplyvní.')
                    ->collapsible()
                    ->schema([
                        TextInput::make('buyer_snapshot.name')
                            ->label('Meno / Názov')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('buyer_snapshot.company_name')
                            ->label('Názov firmy')
                            ->maxLength(255),
                        TextInput::make('buyer_snapshot.email')
                            ->label('Email')
                            ->email()
                            ->maxLength(255),
                        TextInput::make('buyer_snapshot.phone')
                            ->label('Telefón')
                            ->maxLength(255),
                        TextInput::make('buyer_snapshot.business_number')
                            ->label('IČO')
                            ->maxLength(255),
                        TextInput::make('buyer_snapshot.tax_number')
                            ->label('DIČ')
                            ->maxLength(255),
                        TextInput::make('buyer_snapshot.vat_number')
                            ->label('IČ DPH')
                            ->maxLength(255),
                        TextInput::make('buyer_snapshot.web')
                            ->label('Web')
                            ->maxLength(255),
                        TextInput::make('buyer_snapshot.street')
                            ->label('Ulica')
                            ->maxLength(255),
                        TextInput::make('buyer_snapshot.city')
                            ->label('Mesto')
                            ->maxLength(255),
                        TextInput::make('buyer_snapshot.zip')
                            ->label('PSČ')
                            ->maxLength(255),
                        Select::make('buyer_snapshot.country_code')
                            ->label('Krajina')
                            ->options(CountryEnum::translations()),
                    ])->columns(4),

                Section::make('Dátumy')
                    ->schema([
                        DatePicker::make('issue_date')
                            ->label('Dátum vystavenia')
                            ->default(now())
                            ->required()
                            ->live()
                            ->columnSpanFull()
                            ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                                $dueDays = (int) $get('due_days');
                                if ($state && $dueDays > 0) {
                                    $set('due_date', Carbon::parse($state)->addDays($dueDays)->format('Y-m-d'));
                                }
                            }),

                        Grid::make(20)
                            ->schema([
                                TextInput::make('due_days')
                                    ->label('Splatnosť')
                                    ->numeric()
                                    ->default(14)
                                    ->minValue(0)
                                    ->dehydrated(false)
                                    ->live(onBlur: true)
                                    ->columnSpan(3)
                                    ->afterStateHydrated(function (TextInput $component, Get $get): void {
                                        $issueDate = $get('issue_date');
                                        $dueDate = $get('due_date');
                                        if ($issueDate && $dueDate) {
                                            $days = Carbon::parse($issueDate)->diffInDays(Carbon::parse($dueDate), false);
                                            $component->state(max(0, (int) $days));
                                        }
                                    })
                                    ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                                        $issueDate = $get('issue_date');
                                        if ($issueDate && (int) $state >= 0) {
                                            $set('due_date', Carbon::parse($issueDate)->addDays((int) $state)->format('Y-m-d'));
                                        }
                                    }),

                                DatePicker::make('due_date')
                                    ->label('Dátum splatnosti')
                                    ->default(now()->addDays(14))
                                    ->required()
                                    ->live()
                                    ->columnSpan(17)
                                    ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                                        $issueDate = $get('issue_date');
                                        if ($state && $issueDate) {
                                            $days = Carbon::parse($issueDate)->diffInDays(Carbon::parse($state), false);
                                            $set('due_days', max(0, (int) $days));
                                        }
                                    }),
                            ]),

                        DatePicker::make('delivery_date')
                            ->label('Dátum dodania')
                            ->default(now())
                            ->columnSpanFull(),
                    ]),

                Section::make('Položky')
                    ->compact()
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Tabs::make('text_before_items_tabs')
                                    ->label('Text pred položkami')
                                    ->schema([
                                        Tab::make('SK')
                                            ->schema([
                                                RichEditor::make('text_before_items.sk')
                                                    ->hiddenLabel(),
                                            ]),
                                        Tab::make('CZ')
                                            ->schema([
                                                RichEditor::make('text_before_items.cs')
                                                    ->hiddenLabel(),
                                            ]),
                                        Tab::make('EN')
                                            ->schema([
                                                RichEditor::make('text_before_items.en')
                                                    ->hiddenLabel(),
                                            ]),
                                    ]),

                                Tabs::make('text_after_items_tabs')
                                    ->label('Text za položkami')
                                    ->schema([
                                        Tab::make('SK')
                                            ->schema([
                                                RichEditor::make('text_after_items.sk')
                                                    ->hiddenLabel(),
                                            ]),
                                        Tab::make('CZ')
                                            ->schema([
                                                RichEditor::make('text_after_items.cs')
                                                    ->hiddenLabel(),
                                            ]),
                                        Tab::make('EN')
                                            ->schema([
                                                RichEditor::make('text_after_items.en')
                                                    ->hiddenLabel(),
                                            ]),
                                    ]),
                            ]),

                        Repeater::make('items')
                            ->label('Položky')
                            ->relationship()
                            ->schema([
                                Select::make('service_catalog_item_id')
                                    ->label('Z katalógu')
                                    ->options(fn () => ServiceCatalogItem::with('translations')
                                        ->get()
                                        ->mapWithKeys(fn ($item) => [$item->id => $item->translated('name', app()->getLocale()) ?? '—']))
                                    ->searchable()
                                    ->live()
                                    ->afterStateUpdated(function ($state, Get $get, Set $set) {
                                        if ($state) {
                                            $item = ServiceCatalogItem::with(['translations', 'defaultVatRate'])->find($state);
                                            if ($item) {
                                                $invoiceCurrency = $get('../../currency') ?? auth()->user()->activeCompany?->default_currency ?? 'EUR';
                                                $set('unit_price', $item->getPriceForCurrency($invoiceCurrency) ?? collect($item->prices)->first());
                                                $set('quantity', $item->default_quantity ?? 1);
                                                $set('unit', $item->unit);
                                                $set('vat_rate_id', $item->default_vat_rate_id);
                                                if ($item->defaultVatRate) {
                                                    $set('vat_rate_value', $item->defaultVatRate->rate);
                                                }

                                                $translations = $item->translations->map(fn ($t) => [
                                                    'locale' => $t->locale,
                                                    'description' => $t->description ?? $t->name,
                                                ])->toArray();
                                                $set('translations', $translations);
                                            }
                                        }
                                    })
                                    ->columnSpan(2),

                                TextInput::make('quantity')
                                    ->label('Množstvo')
                                    ->numeric()
                                    ->default(1)
                                    ->required()
                                    ->live(onBlur: true),

                                Select::make('unit')
                                    ->label('Jednotka')
                                    ->options([
                                        'ks' => 'ks', 'hod' => 'hod', 'mes' => 'mes',
                                        'km' => 'km', 'kg' => 'kg', 'm2' => 'm²',
                                    ]),

                                TextInput::make('unit_price')
                                    ->label('Cena za jednotku')
                                    ->numeric()
                                    ->required()
                                    ->live(onBlur: true),

                                Select::make('vat_rate_id')
                                    ->label('Sadzba DPH')
                                    ->options(fn () => VatRate::query()
                                        ->whereIn('country_code', [auth()->user()->activeCompany?->country_code ?? 'SK', 'XX'])
                                        ->get()
                                        ->mapWithKeys(fn ($r) => [$r->id => "{$r->name} ({$r->rate}%)"])
                                        ->toArray())
                                    ->live()
                                    ->afterStateUpdated(function ($state, Set $set) {
                                        if ($state) {
                                            $rate = VatRate::find($state);
                                            if ($rate) {
                                                $set('vat_rate_value', $rate->rate);
                                            }
                                        }
                                    }),

                                TextInput::make('vat_rate_value')
                                    ->label('% DPH')
                                    ->numeric()
                                    ->default(20),

                                Select::make('vat_type')
                                    ->label('Typ DPH')
                                    ->options(VatTypeEnum::translations())
                                    ->default(VatTypeEnum::STANDARD->value),

                                Repeater::make('translations')
                                    ->label('Popis podľa jazyka')
                                    ->relationship()
                                    ->schema([
                                        Select::make('locale')
                                            ->label('Jazyk')
                                            ->options(LocaleEnum::translations())
                                            ->required(),
                                        TextInput::make('description')
                                            ->label('Popis')
                                            ->required(),
                                    ])
                                    ->columns(2)
                                    ->defaultItems(1)
                                    ->collapsible()
                                    ->collapsed()
                                    ->required()
                                    ->columnSpanFull(),
                            ])
                            ->columns(7)
                            ->defaultItems(1)
                            ->reorderable()
                            ->reorderableWithButtons()
                            ->collapsible()
                            ->columnSpanFull(),
                    ])->columnSpanFull(),

                Section::make('Súhrn')
                    ->schema([
                        Placeholder::make('computed_subtotal')
                            ->label('Základ')
                            ->content(function (Get $get): string {
                                $currency = $get('currency') ?? auth()->user()->activeCompany?->default_currency ?? 'EUR';

                                return number_format(self::computeSubtotal($get), 2, ',', ' ').' '.$currency;
                            }),

                        Placeholder::make('computed_vat')
                            ->label('DPH')
                            ->content(function (Get $get): string {
                                $currency = $get('currency') ?? auth()->user()->activeCompany?->default_currency ?? 'EUR';

                                return number_format(self::computeVat($get), 2, ',', ' ').' '.$currency;
                            }),

                        Placeholder::make('computed_total')
                            ->label('Celkom')
                            ->content(function (Get $get): string {
                                $currency = $get('currency') ?? auth()->user()->activeCompany?->default_currency ?? 'EUR';
                                $total = self::computeSubtotal($get) + self::computeVat($get);

                                return number_format($total, 2, ',', ' ').' '.$currency;
                            }),

                        Placeholder::make('computed_total_base')
                            ->label(fn () => 'Celkom v '.(auth()->user()->activeCompany?->default_currency ?? 'EUR'))
                            ->content(function (Get $get): string {
                                $baseCurrency = auth()->user()->activeCompany?->default_currency ?? 'EUR';
                                $exchangeRate = (float) ($get('exchange_rate') ?? 0);
                                $total = self::computeSubtotal($get) + self::computeVat($get);
                                $totalBase = $total * $exchangeRate;

                                return number_format($totalBase, 2, ',', ' ').' '.$baseCurrency;
                            })
                            ->visible(fn (Get $get): bool => $get('currency') !== (auth()->user()->activeCompany?->default_currency ?? 'EUR') && (float) ($get('exchange_rate') ?? 0) > 0),
                    ])->columns(4),

                Section::make('Poznámky')
                    ->schema([
                        Textarea::make('notes')
                            ->label('Poznámky')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * Copy the selected customer's data into the invoice's own buyer fields.
     *
     * Deliberately only called from the customer select's change event: the fields
     * stay hand-editable afterwards, and re-rendering the form never overwrites an
     * edit. Clearing the select leaves the already-filled data alone.
     */
    private static function fillBuyerSnapshotFromCustomer(mixed $customerId, Set $set): void
    {
        if (blank($customerId)) {
            return;
        }

        $customer = Customer::find($customerId);

        if (! $customer) {
            return;
        }

        foreach (app(InvoicePdfService::class)->buildBuyerSnapshot($customer) as $key => $value) {
            $set("buyer_snapshot.{$key}", $value);
        }
    }

    private static function computeSubtotal(Get $get): float
    {
        $items = $get('items') ?? [];
        $subtotal = 0;

        foreach ($items as $item) {
            $subtotal += (float) ($item['quantity'] ?? 0) * (float) ($item['unit_price'] ?? 0);
        }

        return $subtotal;
    }

    private static function computeVat(Get $get): float
    {
        $items = $get('items') ?? [];
        $vatTotal = 0;

        foreach ($items as $item) {
            $vatType = $item['vat_type'] ?? 'standard';
            if ($vatType === 'standard' || $vatType === VatTypeEnum::STANDARD->value) {
                $lineSubtotal = (float) ($item['quantity'] ?? 0) * (float) ($item['unit_price'] ?? 0);
                $vatRate = (float) ($item['vat_rate_value'] ?? 0);
                $vatTotal += $lineSubtotal * ($vatRate / 100);
            }
        }

        return $vatTotal;
    }
}
