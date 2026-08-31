<?php

namespace Tests\Feature;

use App\Models\Invoices\Company;
use App\Models\Invoices\Customer;
use App\Models\Invoices\Invoice;
use App\Services\Invoices\InvoicePdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoicePdfOrderNumberTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create([
            'default_currency' => 'EUR',
            'default_locale' => 'sk',
        ]);
        $this->customer = Customer::factory()->create(['company_id' => $this->company->id]);
    }

    private function makeInvoice(?string $orderNumber): Invoice
    {
        return Invoice::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => '2026-0001',
            'order_number' => $orderNumber,
            'total' => 100,
        ]);
    }

    public function test_order_number_is_shown_when_filled(): void
    {
        $invoice = $this->makeInvoice('OBJ-2026-42');

        $html = app(InvoicePdfService::class)->generateHtml($invoice);

        $this->assertStringContainsString('Číslo objednávky', $html);
        $this->assertStringContainsString('OBJ-2026-42', $html);
    }

    public function test_order_number_is_hidden_when_empty(): void
    {
        $invoice = $this->makeInvoice(null);

        $html = app(InvoicePdfService::class)->generateHtml($invoice);

        $this->assertStringNotContainsString('Číslo objednávky', $html);
    }
}
