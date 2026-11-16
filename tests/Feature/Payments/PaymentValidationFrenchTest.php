<?php

namespace Tests\Feature\Payments;

use Illuminate\Validation\ValidationException;
use Tests\Support\PaymentTestCase;

/** BUG-04: payment validation messages are French; the error KEYS (stable codes) are unchanged. */
class PaymentValidationFrenchTest extends PaymentTestCase
{
    public function test_overpayment_is_rejected_with_a_french_message_under_the_same_error_key(): void
    {
        [$owner, , , $order, $account] = $this->paymentFixture('100.0000');

        try {
            $this->recordPayment($owner, $order, $account, '999999.0000');
            $this->fail('Expected an overpayment rejection.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount', $exception->errors());
            $this->assertSame('Le montant du paiement dépasse le reste à payer de la commande.', $exception->errors()['amount'][0]);
        }
    }
}
