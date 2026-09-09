<?php

namespace Tests\Feature;

use App\Enums\Common\UserCapabilityEnum;
use App\Enums\Invoices\InvoiceStatusEnum;
use App\Filament\Invoices\Actions\SendInvoiceEmailAction;
use App\Filament\Invoices\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\Invoices\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Invoices\Resources\Invoices\Pages\ViewInvoice;
use App\Models\Common\User;
use App\Models\Invoices\Company;
use App\Models\Invoices\Customer;
use App\Models\Invoices\Invoice;
use App\Models\Invoices\InvoiceNumberSequence;
use App\Services\Invoices\InvoicePdfService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InvoiceBuyerSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private Customer $customer;

    private InvoiceNumberSequence $sequence;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('invoices'));

        $this->user = User::factory()->create([
            'capabilities' => [
                UserCapabilityEnum::VIEW_INVOICES,
                UserCapabilityEnum::MANAGE_INVOICES,
            ],
        ]);
        $this->company = Company::factory()->create([
            'user_id' => $this->user->id,
            'default_currency' => 'EUR',
        ]);
        $this->customer = Customer::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Testovací odberateľ',
            'company_name' => 'Testovacia s.r.o.',
            'street' => 'Hlavná 1',
            'city' => 'Bratislava',
            'zip' => '81101',
            'country_code' => 'SK',
            'business_number' => '12345678',
            'tax_number' => '2023456789',
            'vat_number' => 'SK2023456789',
            'email' => 'odberatel@example.com',
            'phone' => '+421900000000',
            'web' => 'https://example.com',
        ]);
        $this->sequence = InvoiceNumberSequence::factory()->create([
            'company_id' => $this->company->id,
            'is_default' => true,
        ]);

        $this->user->update(['active_company_id' => $this->company->id]);
        $this->actingAs($this->user);
    }

    public function test_selecting_a_customer_prefills_the_buyer_fields_on_the_invoice_form(): void
    {
        Livewire::test(CreateInvoice::class)
            ->fillForm(['customer_id' => $this->customer->id])
            ->assertFormSet([
                'buyer_snapshot.name' => 'Testovací odberateľ',
                'buyer_snapshot.company_name' => 'Testovacia s.r.o.',
                'buyer_snapshot.street' => 'Hlavná 1',
                'buyer_snapshot.city' => 'Bratislava',
                'buyer_snapshot.zip' => '81101',
                'buyer_snapshot.country_code' => 'SK',
                'buyer_snapshot.business_number' => '12345678',
                'buyer_snapshot.tax_number' => '2023456789',
                'buyer_snapshot.vat_number' => 'SK2023456789',
                'buyer_snapshot.email' => 'odberatel@example.com',
                'buyer_snapshot.phone' => '+421900000000',
                'buyer_snapshot.web' => 'https://example.com',
            ]);
    }

    public function test_hand_edited_buyer_fields_survive_and_are_stored_on_the_invoice(): void
    {
        Livewire::test(CreateInvoice::class)
            ->fillForm([
                'invoice_number_sequence_id' => $this->sequence->id,
                'invoice_number' => 'SNAP-0001',
                'customer_id' => $this->customer->id,
                'status' => InvoiceStatusEnum::NEW->value,
                'currency' => 'EUR',
                'issue_date' => '2026-09-01',
                'due_date' => '2026-09-15',
                'items' => [
                    [
                        'quantity' => 1,
                        'unit_price' => 100,
                        'vat_rate_value' => 20,
                        'vat_type' => 'standard',
                        'translations' => [
                            ['locale' => 'sk', 'description' => 'Testovacia položka'],
                        ],
                    ],
                ],
            ])
            // Overriding after the customer was picked: these edits must win.
            ->fillForm([
                'buyer_snapshot.name' => 'Ručne prepísaný názov',
                'buyer_snapshot.street' => 'Iná ulica 42',
                'buyer_snapshot.vat_number' => '',
            ])
            ->assertFormSet([
                'buyer_snapshot.name' => 'Ručne prepísaný názov',
                'buyer_snapshot.street' => 'Iná ulica 42',
                // untouched fields keep what the customer prefilled
                'buyer_snapshot.city' => 'Bratislava',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $invoice = Invoice::query()->where('invoice_number', 'SNAP-0001')->firstOrFail();

        $this->assertSame('Ručne prepísaný názov', $invoice->buyer_snapshot['name']);
        $this->assertSame('Iná ulica 42', $invoice->buyer_snapshot['street']);
        $this->assertSame('Bratislava', $invoice->buyer_snapshot['city']);
        // a deliberately cleared field stays cleared, it is not restored from the customer
        $this->assertEmpty($invoice->buyer_snapshot['vat_number']);
        // the customer record itself is untouched
        $this->assertSame('Testovací odberateľ', $this->customer->fresh()->name);
        $this->assertSame('SK2023456789', $this->customer->fresh()->vat_number);
    }

    public function test_buyer_snapshot_keys_match_the_form_and_the_pdf(): void
    {
        $snapshot = app(InvoicePdfService::class)->buildBuyerSnapshot($this->customer);

        $this->assertSame(InvoicePdfService::BUYER_SNAPSHOT_KEYS, array_keys($snapshot));
        $this->assertSame('Testovací odberateľ', $snapshot['name']);
        $this->assertSame('SK2023456789', $snapshot['vat_number']);
    }

    public function test_list_and_detail_pages_share_one_send_email_action(): void
    {
        $invoice = Invoice::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'invoice_number_sequence_id' => $this->sequence->id,
            'status' => InvoiceStatusEnum::NEW,
        ]);

        $isSharedAction = fn ($action): bool => $action instanceof SendInvoiceEmailAction;

        Livewire::test(ListInvoices::class)
            ->assertActionExists(
                TestAction::make('sendEmail')->table($invoice),
                $isSharedAction,
            );

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getKey()])
            ->assertActionExists('sendEmail', $isSharedAction);
    }

    public function test_send_email_modal_opens_with_the_same_fields_from_the_list_and_the_detail_page(): void
    {
        $invoice = Invoice::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'invoice_number_sequence_id' => $this->sequence->id,
            'invoice_number' => 'SEND-0001',
            'status' => InvoiceStatusEnum::NEW,
            'buyer_snapshot' => app(InvoicePdfService::class)->buildBuyerSnapshot($this->customer),
        ]);

        $expected = [
            'email' => 'odberatel@example.com',
            'subject' => 'Faktúra SEND-0001',
            'cc' => [],
            'bcc' => [],
        ];

        Livewire::test(ListInvoices::class)
            ->mountAction(TestAction::make('sendEmail')->table($invoice))
            ->assertActionMounted(TestAction::make('sendEmail')->table($invoice))
            ->assertActionDataSet($expected);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getKey()])
            ->mountAction('sendEmail')
            ->assertActionMounted('sendEmail')
            ->assertActionDataSet($expected);
    }
}
