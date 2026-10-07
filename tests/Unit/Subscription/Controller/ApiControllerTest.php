<?php
declare(strict_types=1);

namespace Mollie\Shopware\Unit\Subscription\Controller;

use Mollie\Shopware\Component\Mollie\SubscriptionStatus;
use Mollie\Shopware\Component\Subscription\Controller\ApiController;
use Mollie\Shopware\Unit\Fake\FakeOrderRepository;
use Mollie\Shopware\Unit\Subscription\Builder\MollieSubscriptionBuilder;
use Mollie\Shopware\Unit\Subscription\Builder\SubscriptionEntityBuilder;
use Mollie\Shopware\Unit\Subscription\Fake\FakeSubscriptionActionHandler;
use Mollie\Shopware\Unit\Subscription\Fake\FakeSubscriptionRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(ApiController::class)]
final class ApiControllerTest extends TestCase
{
    private const SUBSCRIPTION_ID = 'subscription-id';
    private const UPDATE_PRIVILEGE = 'mollie_subscription:update';
    private const CANCEL_PRIVILEGE = 'mollie_subscription_custom:cancel';

    public function testChangeStateReturnsSubscriptionDataOnSuccess(): void
    {
        $mollieSubscription = MollieSubscriptionBuilder::create()->withId('sub_test123')->build();

        $handler = new FakeSubscriptionActionHandler();
        $handler->setResponse($mollieSubscription);

        $controller = new ApiController($handler, new FakeSubscriptionRepository(), new FakeOrderRepository());

        $request = $this->buildRequest('subscription-id-42', 'pause');
        $response = $controller->changeState($request, Context::createDefaultContext());

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('subscription', $body);
        $this->assertSame(1, $handler->getCallCount());
        $this->assertSame('pause', $handler->getCalls()[0]['action']);
        $this->assertSame('subscription-id-42', $handler->getCalls()[0]['subscriptionId']);
    }

    public function testChangeStateReturnsErrorMessageOnException(): void
    {
        $handler = new FakeSubscriptionActionHandler();
        $handler->setException(new \RuntimeException('something broke'));

        $controller = new ApiController($handler, new FakeSubscriptionRepository(), new FakeOrderRepository());

        $request = $this->buildRequest('subscription-id-42', 'cancel');
        $response = $controller->changeState($request, Context::createDefaultContext());

        $this->assertSame(500, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        $this->assertFalse($body['success']);
        $this->assertSame('something broke', $body['error']);
        $this->assertArrayNotHasKey('subscription', $body);
    }

    public function testChangeStateReadsSubscriptionIdFromRequestBody(): void
    {
        $handler = new FakeSubscriptionActionHandler();
        $handler->setResponse(MollieSubscriptionBuilder::create()->build());

        $controller = new ApiController($handler, new FakeSubscriptionRepository(), new FakeOrderRepository());

        $request = $this->buildRequest('explicit-id', 'skip');
        $controller->changeState($request, Context::createDefaultContext());

        $this->assertSame('explicit-id', $handler->getCalls()[0]['subscriptionId']);
        $this->assertSame('skip', $handler->getCalls()[0]['action']);
    }

    /**
     * The edit only changes the shop's record and Mollie keeps charging, so it must not let a user
     * without the cancel privilege mark a subscription canceled.
     */
    public function testMarkingASubscriptionCanceledWithoutTheCancelPrivilegeIsForbidden(): void
    {
        $repository = $this->repositoryWithActiveSubscription();

        $response = $this->controller($repository)->edit(self::SUBSCRIPTION_ID, new Request(request: ['status' => 'canceled']), $this->userContext(self::UPDATE_PRIVILEGE));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(0, $repository->getUpsertCount());
    }

    public function testMarkingASubscriptionCanceledWithTheCancelPrivilegeIsSaved(): void
    {
        $repository = $this->repositoryWithActiveSubscription();

        $response = $this->controller($repository)->edit(self::SUBSCRIPTION_ID, new Request(request: ['status' => 'canceled']), $this->userContext(self::UPDATE_PRIVILEGE, self::CANCEL_PRIVILEGE));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('canceled', $repository->getLastUpsert()['status']);
    }

    public function testOtherStatusChangesNeedOnlyTheUpdatePrivilege(): void
    {
        $repository = $this->repositoryWithActiveSubscription();

        $response = $this->controller($repository)->edit(self::SUBSCRIPTION_ID, new Request(request: ['status' => 'paused']), $this->userContext(self::UPDATE_PRIVILEGE));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('paused', $repository->getLastUpsert()['status']);
    }

    public function testASubscriptionThatIsAlreadyCanceledCanBeCorrectedWithoutTheCancelPrivilege(): void
    {
        $repository = new FakeSubscriptionRepository();
        $repository->add(SubscriptionEntityBuilder::create()->withId(self::SUBSCRIPTION_ID)->withStatus(SubscriptionStatus::CANCELED)->build());

        $response = $this->controller($repository)->edit(self::SUBSCRIPTION_ID, new Request(request: ['status' => 'canceled', 'mollieId' => 'sub_corrected']), $this->userContext(self::UPDATE_PRIVILEGE));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('sub_corrected', $repository->getLastUpsert()['mollieId']);
    }

    /**
     * Mollie's own admin sends every field it was not given as null. Taken as a value, a null wiped
     * the Mollie id - renewals and webhooks then no longer find the subscription.
     *
     * @param array<string, null|string> $fields
     */
    #[DataProvider('fieldsSentEmpty')]
    public function testAFieldSentEmptyKeepsItsValue(array $fields): void
    {
        $repository = $this->repositoryWithActiveSubscription();

        $response = $this->controller($repository)->edit(self::SUBSCRIPTION_ID, new Request(request: $fields), $this->userContext(self::UPDATE_PRIVILEGE));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $repository->getUpsertCount());
    }

    /**
     * @return array<string, array{array<string, null|string>}>
     */
    public static function fieldsSentEmpty(): array
    {
        return [
            'mollie id null' => [['mollieId' => null]],
            'mollie id empty' => [['mollieId' => '']],
            'mollie id blank' => [['mollieId' => '  ']],
            'order id null' => [['orderId' => null]],
            'status null' => [['status' => null]],
            'everything null' => [['status' => null, 'mollieId' => null, 'orderId' => null]],
        ];
    }

    public function testAFieldSentEmptyNextToARealChangeIsLeftOutOfTheWrite(): void
    {
        $repository = $this->repositoryWithActiveSubscription();

        $this->controller($repository)->edit(self::SUBSCRIPTION_ID, new Request(request: ['status' => ' paused ', 'mollieId' => null]), $this->userContext(self::UPDATE_PRIVILEGE));

        $upsert = $repository->getLastUpsert();
        $this->assertSame('paused', $upsert['status']);
        $this->assertArrayNotHasKey('mollieId', $upsert);
    }

    private function repositoryWithActiveSubscription(): FakeSubscriptionRepository
    {
        $repository = new FakeSubscriptionRepository();
        $repository->add(SubscriptionEntityBuilder::create()->withId(self::SUBSCRIPTION_ID)->withStatus(SubscriptionStatus::ACTIVE)->build());

        return $repository;
    }

    private function controller(FakeSubscriptionRepository $repository): ApiController
    {
        return new ApiController(new FakeSubscriptionActionHandler(), $repository, new FakeOrderRepository());
    }

    private function userContext(string ...$privileges): Context
    {
        $source = new AdminApiSource('user-id');
        $source->setIsAdmin(false);
        $source->setPermissions(array_values($privileges));

        return new Context($source);
    }

    private function buildRequest(string $subscriptionId, string $action): Request
    {
        $request = new Request(request: ['id' => $subscriptionId]);
        $request->attributes->set('action', $action);

        return $request;
    }
}
