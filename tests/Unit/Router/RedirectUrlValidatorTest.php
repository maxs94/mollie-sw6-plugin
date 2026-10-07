<?php
declare(strict_types=1);

namespace Mollie\Shopware\Unit\Router;

use Mollie\Shopware\Component\Router\Exception\RedirectUrlException;
use Mollie\Shopware\Component\Router\RedirectUrlValidator;
use Mollie\Shopware\Unit\Fake\FakeSalesChannelContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RedirectUrlValidator::class)]
#[CoversClass(RedirectUrlException::class)]
final class RedirectUrlValidatorTest extends TestCase
{
    private FakeSalesChannelContext $salesChannelContext;

    protected function setUp(): void
    {
        $this->salesChannelContext = new FakeSalesChannelContext('sales-channel-id');
        $this->salesChannelContext->setDomainUrls('https://shop.example/de', 'http://localhost:3100');
    }

    public function testAnEmptyUrlIsLeftToTheDefaultOfTheRoute(): void
    {
        $url = (new RedirectUrlValidator())->validate('', $this->salesChannelContext);

        $this->assertSame('', $url);
    }

    #[DataProvider('urlsOfTheSalesChannel')]
    public function testAUrlOnADomainOfTheSalesChannelPasses(string $url): void
    {
        $validated = (new RedirectUrlValidator())->validate($url, $this->salesChannelContext);

        $this->assertSame($url, $validated);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function urlsOfTheSalesChannel(): array
    {
        return [
            'any path on a domain' => ['https://shop.example/checkout/finish/{orderId}'],
            'the default port spelled out' => ['https://shop.example:443/finish'],
            'host in upper case' => ['https://SHOP.example/finish'],
            'host with a trailing dot' => ['https://shop.example./finish'],
            'a domain with its own port' => ['http://localhost:3100/finish?order=1'],
        ];
    }

    #[DataProvider('foreignUrls')]
    public function testAUrlOutsideTheSalesChannelIsRejected(string $url): void
    {
        $this->expectException(RedirectUrlException::class);

        (new RedirectUrlValidator())->validate($url, $this->salesChannelContext);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function foreignUrls(): array
    {
        return [
            'other host' => ['https://evil.example/finish'],
            'subdomain of a domain' => ['https://evil.shop.example/finish'],
            'other port' => ['https://shop.example:8443/finish'],
            'other scheme' => ['http://shop.example/finish'],
            'protocol relative' => ['//evil.example/finish'],
            'relative path' => ['/finish'],
            'javascript' => ['javascript:alert(1)'],
            'user info hiding the host' => ['https://shop.example@evil.example/'],
            'placeholder as user info' => ['https://{orderId}@shop.example/'],
            'backslash read as a slash by browsers' => ['https://evil.example\@shop.example/'],
            'whitespace in the host' => ["https://shop.example\t.evil.example/"],
        ];
    }

    public function testTheRejectionIsABadRequestWithItsOwnCode(): void
    {
        try {
            (new RedirectUrlValidator())->validate('https://evil.example/', $this->salesChannelContext);
            $this->fail('The foreign url was accepted');
        } catch (RedirectUrlException $exception) {
            $this->assertSame(400, $exception->getStatusCode());
            $this->assertSame(RedirectUrlException::REDIRECT_URL_NOT_ALLOWED, $exception->getErrorCode());
        }
    }

    #[DataProvider('unparseableUrls')]
    public function testAnUnparseableDomainMatchesNothing(string $url): void
    {
        $salesChannelContext = new FakeSalesChannelContext();
        $salesChannelContext->setDomainUrls('not-a-url');

        $this->expectException(RedirectUrlException::class);

        (new RedirectUrlValidator())->validate($url, $salesChannelContext);
    }

    /**
     * A domain that does not parse must not turn every url that does not parse either into a match.
     *
     * @return array<string, array{string}>
     */
    public static function unparseableUrls(): array
    {
        return [
            'relative path' => ['/finish'],
            'javascript' => ['javascript:alert(1)'],
        ];
    }

    public function testASalesChannelWithoutDomainsRejectsEveryUrl(): void
    {
        $this->expectException(RedirectUrlException::class);

        (new RedirectUrlValidator())->validate('https://shop.example/finish', new FakeSalesChannelContext());
    }
}
