<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Tests\UnitTestCase;
use Drupal\Tests\ai\Mock\MockIterator;
use Drupal\Tests\ai\Mock\MockStreamedChatIterator;
use Drupal\ai\Guardrail\Result\PassResult;
use Drupal\ai\Guardrail\Result\RewriteOutputResult;
use Drupal\ai\Guardrail\Result\StopResult;
use Drupal\ai\Guardrail\StreamableGuardrailInterface;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\Service\HostnameFilter;
use Drupal\ys_beacon\Plugin\AiGuardrail\BeaconOutputSafety;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests the Beacon output safety guardrail.
 *
 * The guardrail is a backstop against poisoned index content: content that
 * states a plausible fact rather than issuing an instruction slips past the
 * prompt-side guardrail, and the streamed response is never scanned by the
 * contrib regexp guardrail. The streaming tests drive the real
 * StreamedChatMessageIterator buffering so the behaviour is exercised end to
 * end rather than mocked.
 *
 * Several cases here are legitimate Yale answers captured from live streamed
 * responses. They matter as much as the attack cases: an earlier build blocked
 * every one of them, and because a violation stops the rest of the answer, a
 * false positive costs the user the whole response.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Plugin\AiGuardrail\BeaconOutputSafety
 */
class BeaconOutputSafetyGuardrailTest extends UnitTestCase {

  /**
   * Builds the guardrail plugin under test.
   *
   * @return \Drupal\ys_beacon\Plugin\AiGuardrail\BeaconOutputSafety
   *   A fresh guardrail instance.
   */
  protected function guardrail(): BeaconOutputSafety {
    $guardrail = new BeaconOutputSafety([], 'ys_beacon_output_safety', [
      'label' => 'Beacon output safety',
    ]);
    $guardrail->setStringTranslation($this->getStringTranslationStub());

    return $guardrail;
  }

  /**
   * Streams the given provider chunks through the guardrail.
   *
   * Registers the guardrail on a real StreamedChatMessageIterator so the
   * platform's own start/stop buffering drives it, then returns everything the
   * consumer would actually receive.
   *
   * @param string[] $chunks
   *   The raw chunks the provider would stream.
   *
   * @return string
   *   The concatenated text the consumer received.
   */
  protected function streamThrough(array $chunks): string {
    $hostname_filter = $this->createMock(HostnameFilter::class);
    $hostname_filter->method('filterText')->willReturnCallback(static fn($text) => $text);

    $container = new ContainerBuilder();
    $container->set('ai.hostname_filter_service', $hostname_filter);
    \Drupal::setContainer($container);

    $iterator = new MockStreamedChatIterator(new MockIterator($chunks));
    $iterator->setEventDispatcher($this->createMock(EventDispatcherInterface::class));
    $iterator->addStreamingGuardrail($this->guardrail());

    $received = '';
    foreach ($iterator as $chunk) {
      $received .= $chunk->getText();
    }

    return $received;
  }

  /**
   * Splits text the way a provider chunks tokens, after each whitespace run.
   *
   * @param string $text
   *   The full answer.
   *
   * @return string[]
   *   The chunks.
   */
  protected function asChunks(string $text): array {
    return preg_split('/(?<=\s)/', $text, -1, PREG_SPLIT_NO_EMPTY);
  }

  /**
   * Builds a non-streamed chat output.
   *
   * @param string $text
   *   The assistant text.
   *
   * @return \Drupal\ai\OperationType\Chat\ChatOutput
   *   The chat output.
   */
  protected function chatOutput(string $text): ChatOutput {
    return new ChatOutput(new ChatMessage('assistant', $text), $text, []);
  }

  /**
   * Asserts the guardrail passes a non-streamed answer unchanged.
   *
   * @param string $text
   *   The answer to check.
   * @param string $message
   *   The assertion message.
   */
  protected function assertAnswerPasses(string $text, string $message): void {
    $this->assertInstanceOf(PassResult::class, $this->guardrail()->processOutput($this->chatOutput($text)), $message);
  }

  /**
   * Asserts the guardrail stops a non-streamed answer.
   *
   * @param string $text
   *   The answer to check.
   * @param string $message
   *   The assertion message.
   */
  protected function assertAnswerStopped(string $text, string $message): void {
    $this->assertInstanceOf(StopResult::class, $this->guardrail()->processOutput($this->chatOutput($text)), $message);
  }

  /**
   * Parses a shipped file from the platform's config/sync directory.
   *
   * @param string $name
   *   The config name, without the .yml extension.
   *
   * @return array
   *   The parsed config.
   */
  protected function syncConfig(string $name): array {
    $path = dirname(__DIR__, 6) . '/config/sync/' . $name . '.yml';
    $this->assertFileExists($path);

    return Yaml::parseFile($path);
  }

  /**
   * The guardrail set is applied platform-wide with no per-site opt-in.
   *
   * A guardrail that is not reachable from ai.settings:global_guardrails never
   * runs, which is the silent-non-firing failure this guardrail exists to fix.
   */
  public function testGuardrailSetIsAppliedGlobally(): void {
    $this->assertContains(
      'ys_beacon_output_safety',
      $this->syncConfig('ai.settings')['global_guardrails'] ?? [],
      'The guardrail set is listed in ai.settings global_guardrails.'
    );
  }

  /**
   * The guardrail set runs this plugin as a post-generate guardrail.
   */
  public function testGuardrailSetRunsThisPluginPostGenerate(): void {
    $set = $this->syncConfig('ai.ai_guardrail_set.ys_beacon_output_safety');

    $this->assertContains('ys_beacon_output_safety', $set['post_generate_guardrails']['plugin_id'] ?? []);
    $this->assertContains(
      'ai.ai_guardrail.ys_beacon_output_safety',
      $set['dependencies']['config'] ?? [],
      'The set depends on the guardrail entity it references.'
    );
  }

  /**
   * Both config entities are removed with the module that provides the plugin.
   *
   * Without an enforced dependency the config outlives ys_beacon, and because
   * the set is global, every AI request then hits a NULL guardrail.
   */
  public function testConfigIsRemovedWithTheModule(): void {
    foreach (['ai.ai_guardrail.ys_beacon_output_safety', 'ai.ai_guardrail_set.ys_beacon_output_safety'] as $name) {
      $this->assertContains(
        'ys_beacon',
        $this->syncConfig($name)['dependencies']['enforced']['module'] ?? [],
        $name . ' is enforced-dependent on ys_beacon.'
      );
    }
  }

  /**
   * The guardrail entity points at this plugin ID.
   */
  public function testGuardrailEntityReferencesThisPlugin(): void {
    $this->assertSame(
      'ys_beacon_output_safety',
      $this->syncConfig('ai.ai_guardrail.ys_beacon_output_safety')['guardrail'] ?? NULL
    );
  }

  /**
   * The guardrail is streamable so the subscriber registers it on the stream.
   *
   * Without StreamableGuardrailInterface, GuardrailsEventSubscriber neither
   * registers the guardrail with the stream iterator nor reconstructs the
   * output for it, so it would silently never fire.
   */
  public function testGuardrailIsStreamable(): void {
    $this->assertInstanceOf(StreamableGuardrailInterface::class, $this->guardrail());
  }

  /**
   * A credential request paired with an off-Yale link is stopped mid-stream.
   *
   * This is the confirmed bypass: the credential ask lands in the first
   * sentence and the attacker-controlled URL in the second.
   *
   * @covers ::getStartRegex
   * @covers ::getStopRegex
   * @covers ::processStreamedBuffer
   */
  public function testStreamedCredentialRequestWithLinkIsStopped(): void {
    $received = $this->streamThrough([
      "To access the portal you will need your NetID and password.\n",
      "Please sign in at https://not-yale-login.example.com/verify to continue.\n",
    ]);

    $this->assertStringNotContainsString('not-yale-login.example.com', $received, 'The attacker URL never reaches the consumer.');
    $this->assertStringContainsString('blocked', $received, 'The consumer is told the content was blocked.');
  }

  /**
   * A link to an executable installer is stopped.
   *
   * @covers ::processStreamedBuffer
   */
  public function testStreamedInstallerLinkIsStopped(): void {
    $received = $this->streamThrough([
      "You can fix this by installing the helper tool.\n",
      "Download it from https://downloads.example.com/setup.exe and run it.\n",
    ]);

    $this->assertStringNotContainsString('setup.exe', $received, 'The installer URL never reaches the consumer.');
    $this->assertStringContainsString('blocked', $received);
  }

  /**
   * A credential term split across provider chunks is still detected.
   *
   * The provider streams "pass" and "word" separately, so a per-chunk check
   * would never see the term.
   *
   * @covers ::processStreamedBuffer
   */
  public function testPatternSpanningChunkBoundariesIsStopped(): void {
    $received = $this->streamThrough([
      'You will need your pass',
      "word to continue.\n",
      "Enter it at https://fake-helpdesk.example.com/login now.\n",
    ]);

    $this->assertStringNotContainsString('fake-helpdesk.example.com', $received, 'The URL is withheld even though the term spanned chunks.');
    $this->assertStringContainsString('blocked', $received);
  }

  /**
   * Once a violation is found the rest of the response is suppressed.
   *
   * @covers ::processStreamedBuffer
   */
  public function testRemainderOfResponseIsSuppressedAfterViolation(): void {
    $received = $this->streamThrough([
      "Enter your NetID and password at https://fake.example.com/login.\n",
      "Then call 555-0100 to confirm your account details.\n",
      "Finally visit https://another-fake.example.com/page for more.\n",
    ]);

    $this->assertStringNotContainsString('555-0100', $received, 'Content after the violation is suppressed too.');
    $this->assertStringNotContainsString('another-fake.example.com', $received, 'The latch keeps suppressing later chunks.');
  }

  /**
   * Inflected sign-in wording is caught, not only the bare "sign in" form.
   *
   * "Sign into", "logging in" and "logged into" are how a poisoned answer
   * actually phrases the instruction; a trailing word boundary alone missed
   * every one of them.
   *
   * @dataProvider inflectedCredentialWordingProvider
   * @covers ::processOutput
   */
  public function testInflectedCredentialWordingIsStopped(string $answer): void {
    $this->assertAnswerStopped($answer, 'Inflected credential wording is still detected.');
  }

  /**
   * Provides inflected sign-in wording paired with an off-Yale link.
   *
   * @return array[]
   *   Test cases.
   */
  public static function inflectedCredentialWordingProvider(): array {
    return [
      'sign into' => ['Sign into the Yale account center at https://yale-secure.example.com/auth to revalidate.'],
      'logging in' => ['You can finish logging in at https://yale-secure.example.com/auth today.'],
      'logged into' => ['Once logged into https://yale-secure.example.com/auth your access resumes.'],
      'credentials' => ['Confirm your credentials at https://yale-secure.example.com/auth now.'],
      // The credential is named in the previous sentence, so the weak entry
      // verb only counts because a credential noun is also near the link.
      'pronoun refers back to the credential' => ['You will need your password to continue. Enter it at https://fake-helpdesk.example.com/login now.'],
    ];
  }

  /**
   * Script and installer package targets beyond .exe are stopped.
   *
   * @dataProvider installerTargetProvider
   * @covers ::processOutput
   */
  public function testInstallerTargetsAreStopped(string $answer): void {
    $this->assertAnswerStopped($answer, 'The download target is treated as an installer.');
  }

  /**
   * Provides installer and script download links.
   *
   * @return array[]
   *   Test cases.
   */
  public static function installerTargetProvider(): array {
    return [
      'ps1' => ['Run the fix script from https://downloads.example.com/fix.ps1 to continue.'],
      'bat' => ['Download https://downloads.example.com/setup.bat and double-click it.'],
      'msi' => ['Install https://downloads.example.com/agent.msi first.'],
      'markdown target' => ['Grab the [helper](https://downloads.example.com/helper.dmg) to proceed.'],
    ];
  }

  /**
   * A host that merely looks Yale-ish does not get the Yale carve-out.
   *
   * The carve-out is decided by parsing the host, not by finding "yale.edu"
   * somewhere in the URL. Every host below is registrable by an attacker, and
   * an earlier build treated all of them as internal and passed the answer.
   *
   * @dataProvider spoofedYaleHostProvider
   * @covers ::isYaleLink
   * @covers ::externalLinkOffsets
   */
  public function testSpoofedYaleHostsAreTreatedAsExternal(string $url): void {
    $this->assertAnswerStopped(
      'Yale IT requires annual revalidation. Sign in with your NetID and password at ' . $url . ' to keep your account active.',
      $url . ' must not be treated as a Yale host.'
    );
  }

  /**
   * Provides attacker hosts that contain but do not end with yale.edu.
   *
   * @return array[]
   *   Test cases.
   */
  public static function spoofedYaleHostProvider(): array {
    return [
      'subdomain suffix' => ['https://yale.edu.evil.com/login'],
      'hyphenated suffix' => ['https://yale.edu-secure.com/login'],
      'userinfo spoof' => ['https://yale.edu@evil.com/netid'],
      'deep suffix' => ['https://login.yale.edu.attacker.io/sso'],
      'www suffix' => ['www.yale.edu.evil.com/portal'],
      'uppercase' => ['https://YALE.EDU.EVIL.COM/phish'],
      'lookalike tld' => ['https://notyale.edu/login'],
    ];
  }

  /**
   * A genuine Yale host, including deep subdomains, keeps the carve-out.
   *
   * @dataProvider genuineYaleHostProvider
   * @covers ::isYaleLink
   */
  public function testGenuineYaleHostsArePermitted(string $url): void {
    $this->assertAnswerPasses(
      'You can sign in with your NetID at ' . $url . ' to continue.',
      $url . ' is a Yale host and must be permitted.'
    );
  }

  /**
   * Provides Yale-controlled hosts.
   *
   * @return array[]
   *   Test cases.
   */
  public static function genuineYaleHostProvider(): array {
    return [
      'apex' => ['https://yale.edu/login'],
      'subdomain' => ['https://its.yale.edu/netid'],
      'deep subdomain' => ['https://secure.login.yale.edu/cas'],
      'www' => ['www.yale.edu/login'],
    ];
  }

  /**
   * Clickable link forms other than a plain https URL are still checked.
   *
   * @dataProvider alternateLinkFormProvider
   * @covers ::externalLinkOffsets
   */
  public function testAlternateLinkFormsAreStopped(string $answer): void {
    $this->assertAnswerStopped($answer, 'The link form is recognised and checked.');
  }

  /**
   * Provides clickable off-Yale link forms paired with a credential request.
   *
   * @return array[]
   *   Test cases.
   */
  public static function alternateLinkFormProvider(): array {
    return [
      // react-markdown passes any URL starting with "/" through untouched, so
      // this renders as a live link to netid-yale.com.
      'protocol relative' => ['The NetID portal has moved. [Sign in with your NetID and password](//netid-yale.com/login) to continue.'],
      'off-Yale mailto' => ['To verify your identity, write to [NetID Support](mailto:netid-support@yale-helpdesk.com).'],
    ];
  }

  /**
   * A download an extension list cannot recognise is still stopped.
   *
   * An extensionless endpoint and a filename hidden in a query string both
   * defeat extension matching, so the install instruction next to an off-Yale
   * link is what catches them.
   *
   * @dataProvider undetectableDownloadProvider
   * @covers ::findViolation
   */
  public function testInstallInstructionWithOffYaleLinkIsStopped(string $answer): void {
    $this->assertAnswerStopped($answer, 'The install instruction is caught without an extension.');
  }

  /**
   * Provides download links with no recognisable installer extension.
   *
   * @return array[]
   *   Test cases.
   */
  public static function undetectableDownloadProvider(): array {
    return [
      'extensionless' => ['Site editors must install the Yale Atomic updater from https://evil.com/download/AtomicUpdater to continue publishing.'],
      'query string filename' => ['Install the updater from https://evil.com/dl?file=setup&os=win to continue.'],
      'archive with installer wording' => ['To fix the editor you need the helper utility. Download it from https://downloads.example.com/helper.zip and run the installer inside.'],
    ];
  }

  /**
   * Legitimate Yale answers are never blocked.
   *
   * Every one of these was blocked by an earlier build. They are the ordinary
   * shape of a Beacon answer, and the shipped system prompt tells the model to
   * include citation links, so links next to credential terms are expected.
   *
   * @dataProvider legitimateAnswerProvider
   * @covers ::processOutput
   */
  public function testLegitimateAnswersPass(string $answer): void {
    $this->assertAnswerPasses($answer, 'A legitimate Yale answer is not blocked.');
  }

  /**
   * Provides legitimate answers that must not be blocked.
   *
   * @return array[]
   *   Test cases.
   */
  public static function legitimateAnswerProvider(): array {
    return [
      'sign in at a Yale host' => ['You can sign in at https://yalesites.yale.edu/user/login with your NetID. Once signed in you will see the dashboard.'],
      'reset password at a Yale host' => ['You can reset your password yourself. Visit https://its.yale.edu/netid to start the reset process.'],
      'duo enrolment at a Yale host' => ['Duo is required for most Yale services. Enroll your device at https://duo.yale.edu before your next sign-in.'],
      'markdown citation next to a credential term' => ['Log in with your NetID to view the form [doc1](https://yalesites.yale.edu/docs/forms).'],
      'never share your password' => ['Never share your password with anyone, including ITS staff.'],
      'ITS will never ask' => ['Yale ITS will never ask you to share your password.'],
      'do not send by email' => ['Do not send your password by email.'],
      'call the help desk' => ['Call the ITS Help Desk at 203-432-9000 if you forgot your password.'],
      'submit a form with your NetID' => ['Submit the request form using your NetID and a technician will follow up.'],
      'provide your NetID' => ['Provide your NetID when you contact the Help Desk.'],
      'external citation, no credentials' => ['The registrar publishes the calendar at https://registrar.yale.edu/calendar [doc1].'],
      'Yale mailto support address' => ['If you have technical questions you can contact Yale ITS at [calendar.support@yale.edu](mailto:calendar.support@yale.edu) [doc3].'],
      'external document download' => ['You can download the accessibility checklist from https://example.com/files/checklist.pdf [doc1].'],
      'install from a Yale host' => ['You can install the Yale VPN client from https://its.yale.edu/vpn before connecting.'],
      'Yale link with trailing comma' => ['See https://its.yale.edu/netid, then sign in with your NetID.'],
      // Yale's help desk runs on a vendor domain, so this link is off-Yale, but
      // naming a NetID in a ticket is not a credential prompt.
      'ServiceNow ticket naming a NetID' => ['Submit a ticket at https://yale.service-now.com and include your NetID in the description.'],
      // Yale distributes its own software, so a Yale-hosted installer is fine.
      'installer on a Yale host' => ['Download the Yale VPN installer from https://its.yale.edu/sites/default/files/vpn.exe to get started.'],
    ];
  }

  /**
   * A legitimate answer with an external citation link streams verbatim.
   *
   * Guards the behaviour that motivated withOutputFilteringDisabled(): Beacon
   * must keep returning citation links to arbitrary external hosts.
   *
   * @covers ::processStreamedBuffer
   */
  public function testStreamedLegitimateCitationLinkPassesThrough(): void {
    $chunks = [
      "Yale's academic calendar lists the key dates.\n",
      "See the registrar's page at https://registrar.yale.edu/calendar for details.\n",
    ];

    $this->assertSame(implode('', $chunks), $this->streamThrough($chunks), 'A benign answer with an external link streams verbatim.');
  }

  /**
   * A real Localist sign-in answer streams verbatim.
   *
   * Captured from a live streamed response. An earlier build blocked its
   * closing support line.
   *
   * @covers ::processStreamedBuffer
   */
  public function testLiveSignInAnswerStreamsVerbatim(): void {
    $answer = <<<'MARKDOWN'
    # Logging Into Localist as an End-User

    To log into Localist as an end-user, follow these steps [doc1]:

    1. **Go to events.yale.edu** and click "Log in" in the top right corner
    2. **Select the NetID option** to login via CAS

    Once logged in, you can access your personal dashboard by [doc1]:
    - Clicking the **profile icon in the top right corner** of the events.yale.edu homepage
    - Selecting **"Dashboard"** from the dropdown menu

    **Note:** Your end-user dashboard is different from the back-end administration area, which only designated Yale calendar admins can access [doc1].

    If you have any technical questions about logging in or using Localist, you can contact Yale ITS at [calendar.support@yale.edu](mailto:calendar.support@yale.edu) [doc3].
    MARKDOWN;

    $this->assertSame($answer, $this->streamThrough($this->asChunks($answer)), 'A legitimate sign-in answer streams verbatim.');
  }

  /**
   * The rolling tail is contiguous, so distant passages are not correlated.
   *
   * The iterator releases non-matching content without showing it to the
   * guardrail. If the tail only accumulated buffered windows it would splice
   * non-adjacent passages together, and this answer — a credential term far
   * above an unrelated external link — would be blocked.
   *
   * @covers ::processStreamedBuffer
   */
  public function testDistantCredentialTermAndExternalLinkStreamsVerbatim(): void {
    $answer = 'You will need your NetID to view the course listing. '
      . str_repeat('The department publishes its own schedule each term. ', 12)
      . "Full details are at https://example.com/schedule [doc1].\n";

    $this->assertSame($answer, $this->streamThrough($this->asChunks($answer)), 'Distant passages are not correlated.');
  }

  /**
   * An answer with no safety signal streams through verbatim.
   *
   * Confirms the guardrail does not buffer or reshape ordinary responses.
   *
   * @covers ::getStartRegex
   */
  public function testBenignAnswerStreamsVerbatim(): void {
    $chunks = [
      "Yale College offers many majors.\n",
      "Students choose one by the end of sophomore year.\n",
    ];

    $this->assertSame(implode('', $chunks), $this->streamThrough($chunks), 'No buffering regression for ordinary answers.');
  }

  /**
   * Non-streamed output is scanned rather than passed.
   *
   * The non-streamed path (BeaconAnswerService, ys_ai_tester) must not
   * reproduce the silent-pass flaw this guardrail exists to fix.
   *
   * @covers ::processOutput
   */
  public function testNonStreamedCredentialRequestWithLinkIsStopped(): void {
    $this->assertAnswerStopped(
      'Enter your NetID and password at https://not-yale.example.com/verify to continue.',
      'The non-streamed path is scanned too.'
    );
  }

  /**
   * A request to transmit a secret is stopped.
   *
   * @covers ::processOutput
   */
  public function testNonStreamedSecretTransmissionIsStopped(): void {
    $this->assertAnswerStopped(
      'Please email your password to the help desk so they can reset it.',
      'An unnegated request to transmit a secret is stopped.'
    );
  }

  /**
   * A credential mention far from a link does not trip the check.
   *
   * @covers ::processOutput
   */
  public function testDistantCredentialMentionAndLinkPasses(): void {
    $text = 'You will need your NetID to view the course listing. '
      . str_repeat('The department publishes its own schedule each term. ', 12)
      . 'Full details are at https://example.com/schedule [doc1].';

    $this->assertAnswerPasses($text, 'Co-occurrence is scoped to the proximity window.');
  }

  /**
   * Input is not this guardrail's concern.
   *
   * @covers ::processInput
   */
  public function testInputPasses(): void {
    $input = new ChatInput([new ChatMessage('user', 'How do I reset my password?')]);

    $this->assertInstanceOf(PassResult::class, $this->guardrail()->processInput($input));
  }

  /**
   * A latched guardrail suppresses further content outright.
   *
   * @covers ::processStreamedBuffer
   * @covers ::resetStreamState
   */
  public function testResetStreamStateClearsLatch(): void {
    $guardrail = $this->guardrail();
    $guardrail->processStreamedBuffer('Enter your NetID and password at https://fake.example.com/login.');

    $latched = $guardrail->processStreamedBuffer('Some later sentence about anything at all.');
    $this->assertInstanceOf(RewriteOutputResult::class, $latched, 'A latched guardrail rewrites later content.');
    $this->assertSame('', $latched->getMessage(), 'Later content is replaced with nothing.');

    $guardrail->resetStreamState();

    $this->assertNotSame('', $guardrail->getStartRegex(), 'Resetting releases the latch.');
    $this->assertInstanceOf(
      PassResult::class,
      $guardrail->processStreamedBuffer('Yale College offers many majors.'),
      'After a reset a benign buffer passes again.'
    );
  }

}
