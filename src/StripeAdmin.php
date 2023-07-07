<?php

namespace Letsco\Stripe;

use SilverStripe\Admin\ModelAdmin;

/**
 * StripeAdmin
 *
 * @package stripe
 * @author Johann
 * @since 2023.07.07
 */
class StripeAdmin extends ModelAdmin
{
	private static $managed_models = [
		StripeTransfer::class,
	];	
	private static $url_segment = 'stripe';
	private static $menu_title = 'Stripe';
}
