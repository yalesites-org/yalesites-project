<?php

namespace Drupal\Tests\ys_core\Unit\EventSubscriber;

use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\cas\CasRedirectData;
use Drupal\cas\CasServerConfig;
use Drupal\cas\Event\CasPreRedirectEvent;
use Drupal\ys_core\EventSubscriber\CasUser1BypassSubscriber;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests CasUser1BypassSubscriber's forced-login bypass for user 1.
 *
 * @coversDefaultClass \Drupal\ys_core\EventSubscriber\CasUser1BypassSubscriber
 *
 * @group ys_core
 * @group yalesites
 */
class CasUser1BypassSubscriberTest extends UnitTestCase {

  /**
   * Builds a real CasPreRedirectEvent with a real CasRedirectData payload.
   */
  protected function buildEvent(): CasPreRedirectEvent {
    $redirectData = new CasRedirectData();
    $serverConfig = $this->createMock(CasServerConfig::class);
    return new CasPreRedirectEvent($redirectData, $serverConfig);
  }

  /**
   * Builds a subscriber wired to a given user id, request path, and session.
   *
   * @param int $uid
   *   The current user's uid.
   * @param string $path
   *   The current request path.
   * @param int|null $sessionUid
   *   The '_drupal_uid' session value, or NULL to leave it unset.
   */
  protected function subscriberFor(int $uid, string $path, ?int $sessionUid = NULL): CasUser1BypassSubscriber {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($uid);

    $request = Request::create($path);
    $session = new Session(new MockArraySessionStorage());
    if ($sessionUid !== NULL) {
      $session->set('_drupal_uid', $sessionUid);
    }
    $request->setSession($session);

    $requestStack = new RequestStack();
    $requestStack->push($request);

    return new CasUser1BypassSubscriber($account, $requestStack);
  }

  /**
   * @covers ::getSubscribedEvents
   */
  public function testGetSubscribedEvents(): void {
    $events = CasUser1BypassSubscriber::getSubscribedEvents();
    $this->assertSame([['onPreRedirect', 100]], $events[CasPreRedirectEvent::class]);
  }

  /**
   * @covers ::onPreRedirect
   */
  public function testUser1IsPreventedFromRedirect(): void {
    $subscriber = $this->subscriberFor(1, '/some/page');
    $event = $this->buildEvent();

    $subscriber->onPreRedirect($event);

    $this->assertFalse($event->getCasRedirectData()->willRedirect());
    $this->assertTrue($event->isPropagationStopped());
  }

  /**
   * @covers ::onPreRedirect
   */
  public function testUser1ResetLinkIsPreventedFromRedirect(): void {
    $subscriber = $this->subscriberFor(2, '/user/reset/1/abc123/def456');
    $event = $this->buildEvent();

    $subscriber->onPreRedirect($event);

    $this->assertFalse($event->getCasRedirectData()->willRedirect());
  }

  /**
   * @covers ::onPreRedirect
   */
  public function testUser1SessionIsPreventedFromRedirect(): void {
    $subscriber = $this->subscriberFor(2, '/some/page', 1);
    $event = $this->buildEvent();

    $subscriber->onPreRedirect($event);

    $this->assertFalse($event->getCasRedirectData()->willRedirect());
  }

  /**
   * @covers ::onPreRedirect
   */
  public function testOtherUserIsNotPrevented(): void {
    $subscriber = $this->subscriberFor(2, '/some/page');
    $event = $this->buildEvent();

    $subscriber->onPreRedirect($event);

    $this->assertTrue($event->getCasRedirectData()->willRedirect());
    $this->assertFalse($event->isPropagationStopped());
  }

}
