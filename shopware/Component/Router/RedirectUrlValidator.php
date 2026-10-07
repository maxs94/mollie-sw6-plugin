<?php
declare(strict_types=1);

namespace Mollie\Shopware\Component\Router;

use Mollie\Shopware\Component\Router\Exception\RedirectUrlException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

final class RedirectUrlValidator implements RedirectUrlValidatorInterface
{
    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    /**
     * Browsers read a backslash as a slash and drop control characters, PHP's url parser does neither,
     * so `https://evil.example\@shop.example` would pass as the shop's host.
     */
    private const AMBIGUOUS_CHARACTERS = '/[\\\\\x00-\x20\x7f]/';

    public function validate(string $url, SalesChannelContext $salesChannelContext): string
    {
        if (strlen($url) === 0) {
            return $url;
        }

        $origin = $this->getOrigin($url);
        if ($origin !== '' && in_array($origin, $this->getSalesChannelOrigins($salesChannelContext), true)) {
            return $url;
        }

        throw RedirectUrlException::notAllowed($url, $salesChannelContext->getSalesChannelId());
    }

    private function getOrigin(string $url): string
    {
        if (preg_match(self::AMBIGUOUS_CHARACTERS, $url) === 1) {
            return '';
        }

        $parts = parse_url($url);
        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $lowercaseHost = strtolower($parts['host'] ?? '');
        $host = rtrim($lowercaseHost, '.');
        if ($host === '' || ! isset(self::DEFAULT_PORTS[$scheme])) {
            return '';
        }

        $port = $parts['port'] ?? self::DEFAULT_PORTS[$scheme];

        return sprintf('%s://%s:%d', $scheme, $host, $port);
    }

    /**
     * @return list<string>
     */
    private function getSalesChannelOrigins(SalesChannelContext $salesChannelContext): array
    {
        $origins = [];
        $domains = $salesChannelContext->getSalesChannel()->getDomains() ?? [];
        foreach ($domains as $domain) {
            $origins[] = $this->getOrigin($domain->getUrl());
        }

        return $origins;
    }
}
