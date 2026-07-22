<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InvoicePaymentBreakdownTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_show_displays_line_balances_states_and_current_payment_allocations(): void
    {
        $invoice = $this->invoice('partially_paid', '180.00');
        $first = $this->line($invoice, 'Поддержка', '100.00', '2026-07-01', '2026-07-31');
        $second = $this->line($invoice, 'Разработка сайта', '80.00');
        $payment = $this->payment($invoice, 'confirmed', '130.00');
        $this->allocation($payment, $first, '100.00');
        $this->allocation($payment, $second, '30.00');

        $response = $this->get(route('invoices.show', $invoice));

        $response->assertOk()
            ->assertSee('Оплачено')
            ->assertSee('Остаток')
            ->assertSee('Статус')
            ->assertSee('100,00 ₼')
            ->assertSee('30,00 ₼')
            ->assertSee('50,00 ₼')
            ->assertSee('Частично')
            ->assertSee('Применено:')
            ->assertSee('Показать распределение')
            ->assertSee('Текущее распределение')
            ->assertSee('Поддержка')
            ->assertSee('Разработка сайта');
    }

    public function test_overpayment_pending_and_cancelled_payments_are_described_without_fake_allocations(): void
    {
        $invoice = $this->invoice('paid', '100.00');
        $line = $this->line($invoice, 'Работа', '100.00');
        $confirmed = $this->payment($invoice, 'confirmed', '130.00');
        $this->allocation($confirmed, $line, '100.00');
        $this->payment($invoice, 'pending', '20.00');
        $this->payment($invoice, 'cancelled', '15.00');

        $this->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Не распределено / Credit Balance:')
            ->assertSee('30,00 ₼')
            ->assertSee('Будет распределён после подтверждения.')
            ->assertSee('Отменён:');
    }

    public function test_credit_balance_badge_uses_applied_entry_and_not_comment(): void
    {
        $invoice = $this->invoice('paid', '20.00');
        $line = $this->line($invoice, 'Ручная работа', '20.00');
        $creditPayment = $this->payment($invoice, 'confirmed', '10.00');
        $commentPayment = $this->payment(
            $invoice,
            'confirmed',
            '10.00',
            'Автоматически применён Credit Balance'
        );
        $this->allocation($creditPayment, $line, '10.00');
        $this->allocation($commentPayment, $line, '10.00');
        $creditBalanceId = DB::table('credit_balances')->insertGetId([
            'company_id' => $invoice->company_id,
            'amount' => '0.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('credit_balance_entries')->insert([
            'credit_balance_id' => $creditBalanceId,
            'type' => 'applied',
            'amount' => '10.00',
            'payment_id' => $creditPayment->id,
            'invoice_id' => $invoice->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->get(route('invoices.show', $invoice));

        $response->assertOk();
        $this->assertSame(1, substr_count($response->getContent(), 'Оплата из Credit Balance'));
    }

    public function test_subscription_order_and_manual_lines_are_rendered_in_fifo_order(): void
    {
        $invoice = $this->invoice('draft', '30.00');
        $this->line($invoice, 'Ручная позиция', '10.00');
        $this->line($invoice, 'Разовая услуга', '10.00', orderId: $this->order($invoice));
        $this->line(
            $invoice,
            'Подписка июля',
            '10.00',
            '2026-07-01',
            '2026-07-31',
            $this->subscription($invoice)
        );

        $response = $this->get(route('invoices.show', $invoice));
        $content = $response->getContent();

        $response->assertOk()
            ->assertSee('Подписка')
            ->assertSee('Разовая услуга')
            ->assertSee('Ручная позиция')
            ->assertSee('0,00 ₼')
            ->assertSee('Не оплачено');
        $this->assertLessThan(strpos($content, 'Разовая услуга'), strpos($content, 'Подписка июля'));
        $this->assertLessThan(strpos($content, 'Ручная позиция'), strpos($content, 'Разовая услуга'));
    }

    public function test_show_eager_loads_breakdown_relations_and_keeps_payment_actions(): void
    {
        $invoice = $this->invoice('issued', '10.00');
        $this->line($invoice, 'Работа', '10.00');
        $this->payment($invoice, 'pending', '10.00');

        Model::preventLazyLoading();
        try {
            $this->get(route('invoices.show', $invoice))
                ->assertOk()
                ->assertSee(route('payments.confirm', 1), false)
                ->assertSee(route('payments.cancel', 1), false)
                ->assertSee('overflow-x-auto', false)
                ->assertSee('print:hidden', false);
        } finally {
            Model::preventLazyLoading(false);
        }
    }

    public function test_compact_summary_and_drawer_include_the_full_ordered_payment_history(): void
    {
        $invoice = $this->invoice('paid', '10.00');
        $line = $this->line($invoice, 'Работа', '10.00');

        for ($number = 1; $number <= 5; $number++) {
            $this->payment($invoice, 'cancelled', '1.00', "Отменённый {$number}");
        }
        $this->payment($invoice, 'pending', '2.00', 'Ожидающий');
        $confirmed = $this->payment($invoice, 'confirmed', '10.00', 'Подтверждённый');
        $this->allocation($confirmed, $line, '10.00');

        $response = $this->get(route('invoices.show', $invoice));
        $breakdown = $response->viewData('paymentBreakdown');
        $rows = collect($breakdown['paymentRows']);

        $response->assertOk()
            ->assertSee('Открыть историю')
            ->assertSee('Последний платёж:')
            ->assertSee('Подтверждён')
            ->assertSee('Ожидают подтверждения: 1')
            ->assertSee('Всего платежей: 7')
            ->assertSee('Отменённый 1')
            ->assertSee('Отменённый 5')
            ->assertSee('Ожидающий')
            ->assertSee('Подтверждённый');
        $this->assertSame(7, $breakdown['payments_count']);
        $this->assertSame(1, $breakdown['pending_payments_count']);
        $this->assertSame(1, $breakdown['confirmed_payments_count']);
        $this->assertSame(5, $breakdown['cancelled_payments_count']);
        $this->assertSame($confirmed->id, $breakdown['latest_payment']['id']);
        $this->assertFalse($rows->contains(fn(array $row): bool => array_key_exists('hidden_by_default', $row)));
        $this->assertSame(
            $rows->sortByDesc(fn(array $row): string => $row['payment_date'].':'.str_pad((string) $row['id'], 10, '0', STR_PAD_LEFT))
                ->pluck('id')->values()->all(),
            $rows->pluck('id')->all()
        );
    }

    public function test_empty_payment_history_has_no_drawer_trigger(): void
    {
        $invoice = $this->invoice('draft', '10.00');
        $this->line($invoice, 'Работа', '10.00');

        $this->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Платежей пока нет.')
            ->assertDontSee('Открыть историю');
    }

    private function invoice(string $status, string $total): Invoice
    {
        $suffix = uniqid();
        $companyId = DB::table('companies')->insertGetId(['name' => 'Company '.$suffix]);
        $contractId = DB::table('contracts')->insertGetId([
            'company_id' => $companyId,
            'contract_number' => 'CONTRACT-'.$suffix,
            'start_date' => '2026-01-01',
        ]);

        return Invoice::create([
            'company_id' => $companyId,
            'contract_id' => $contractId,
            'invoice_number' => 'INV-'.$suffix,
            'issue_date' => '2026-07-01',
            'due_date' => '2026-07-31',
            'total_amount' => $total,
            'status' => $status,
        ]);
    }

    private function line(
        Invoice $invoice,
        string $description,
        string $amount,
        ?string $periodStart = null,
        ?string $periodEnd = null,
        ?int $subscriptionId = null,
        ?int $orderId = null
    ): int {
        return DB::table('invoice_lines')->insertGetId([
            'invoice_id' => $invoice->id,
            'subscription_id' => $subscriptionId,
            'order_id' => $orderId,
            'description' => $description,
            'amount' => $amount,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function payment(
        Invoice $invoice,
        string $status,
        string $amount,
        ?string $comment = null
    ): Payment {
        return Payment::withoutEvents(fn() => Payment::create([
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'payment_date' => '2026-07-20',
            'amount' => $amount,
            'payment_method' => 'transfer',
            'status' => $status,
            'comment' => $comment,
            'cancelled_at' => $status === 'cancelled' ? now() : null,
            'cancel_reason' => $status === 'cancelled' ? 'Ошибка' : null,
        ]));
    }

    private function allocation(Payment $payment, int $lineId, string $amount): void
    {
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'invoice_line_id' => $lineId,
            'amount' => $amount,
        ]);
    }

    private function order(Invoice $invoice): int
    {
        $serviceType = DB::table('service_types')->insertGetId([
            'name' => 'Order type '.uniqid(),
            'base_price' => '10.00',
            'type' => 'one_time',
        ]);

        return DB::table('orders')->insertGetId([
            'contract_id' => $invoice->contract_id,
            'service_type_id' => $serviceType,
            'title' => 'Разовая услуга',
            'order_date' => '2026-07-01',
            'price' => '10.00',
            'payment_terms' => 10,
        ]);
    }

    private function subscription(Invoice $invoice): int
    {
        $serviceType = DB::table('service_types')->insertGetId([
            'name' => 'Subscription type '.uniqid(),
            'base_price' => '10.00',
            'type' => 'subscription',
        ]);

        return DB::table('subscriptions')->insertGetId([
            'contract_id' => $invoice->contract_id,
            'service_type_id' => $serviceType,
            'start_date' => '2026-01-01',
            'next_billing_date' => '2026-08-01',
            'billing_period' => 'monthly',
            'amount' => '10.00',
        ]);
    }
}
