<?php
declare(strict_types=1);

namespace Mollie\Shopware\Component\Router;

use Shopware\Core\System\SalesChannel\SalesChannelContext;

interface RedirectUrlValidatorInterface
{
    public function validate(string $url, SalesChannelContext $salesChannelContext): string;
}
