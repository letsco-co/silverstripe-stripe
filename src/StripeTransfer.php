<?php

namespace Letsco\Stripe;

use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBCurrency;

/**
 * StripeTransfer
 *
 * @package stripe
 * @author Johann
 * @since 2023.07.07
 */
class StripeTransfer extends DataObject
{
    const TYPE_IN  = 'IN';
    const TYPE_OUT = 'OUT';

    const STATUS_WAITING    = 'Waiting';
    const STATUS_PAID       = 'Paid';
    const STATUS_CANCELLED  = 'Cancelled';
    const STATUS_REIMBURSED = 'Reimbursed';

    private static $table_name = 'StripeTransfer';
    private static $db = [
        'Type' 		  => 'Enum(",IN,OUT")',
        'Status'	  => 'Enum("Waiting,Paid,Cancelled,Reimbursed","Waiting")',
        'PaymentId'   => 'Varchar(50)',
        'AccountId'   => 'Varchar(50)',
        'TransferId'  => 'Varchar(50)',
        'Description' => 'Varchar(50)',
        'Amount' 	  => 'Currency',
    ];
    private static $default_sort = 'Created DESC';

    public function summaryFields()
    {
        $fields = [
            'Created'     => 'Date',
            'Type'        => 'Type',
            'Status'      => 'Statut',
            'Description' => 'Description',
            'Amount.Nice' => 'Montant', // @TODO May override Nice function to divide amount by 100 (currently in cents)
        ];

        $this->extend("updateSummaryFields", $fields);

        return $fields;
    }
}
