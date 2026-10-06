<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\SiteSetting;
use App\Support\Email\TemplateDefinitions;

/**
 * The order confirmation, with the invoice PDF attached. The wording is the "Order confirmation" email template.
 */
class OrderConfirmationMail extends TransactionalMail
{
    public function __construct(Order $order, ?SiteSetting $siteSetting)
    {
        parent::__construct(TemplateDefinitions::CONFIRMATION, $order, $order->customer, $siteSetting);
    }
}
