<?php
declare(strict_types=1);

namespace Mollie\Shopware\Component\Router\Exception;

use Shopware\Core\Framework\HttpException;
use Symfony\Component\HttpFoundation\Response;

final class RedirectUrlException extends HttpException
{
    public const REDIRECT_URL_NOT_ALLOWED = 'MOLLIE__REDIRECT_URL_NOT_ALLOWED';

    public static function notAllowed(string $url, string $salesChannelId): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::REDIRECT_URL_NOT_ALLOWED,
            'The url {{url}} does not belong to a domain of the sales channel {{salesChannelId}}',
            [
                'url' => $url,
                'salesChannelId' => $salesChannelId,
            ]
        );
    }
}
