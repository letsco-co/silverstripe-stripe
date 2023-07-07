<?php

namespace Letsco\Stripe;

use SilverStripe\ORM\DataExtension;
use SilverStripe\Forms\FieldList;

/**
 * StripeSiteConfig
 *
 * @package stripe
 * @author Johann
 * @since 2023.07.05
 * @extends SiteConfig
 */
class StripeSiteConfig extends DataExtension
{
    private static $db = array(
    );

    public function updateCMSFields(FieldList $fields)
    {
    }

    public function onBeforeWrite()
    {
    }
}
