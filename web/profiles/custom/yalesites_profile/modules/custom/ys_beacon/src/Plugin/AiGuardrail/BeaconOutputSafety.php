<?php

namespace Drupal\ys_beacon\Plugin\AiGuardrail;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\AiGuardrail;
use Drupal\ai\Guardrail\AiGuardrailPluginBase;
use Drupal\ai\Guardrail\Result\GuardrailResultInterface;
use Drupal\ai\Guardrail\Result\PassResult;
use Drupal\ai\Guardrail\Result\RewriteOutputResult;
use Drupal\ai\Guardrail\Result\StopResult;
use Drupal\ai\Guardrail\StreamableGuardrailInterface;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\InputInterface;
use Drupal\ai\OperationType\OutputInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Blocks Beacon answers that phish for credentials or push a download.
 *
 * Beacon's prompt-side guardrail holds against instruction-style jailbreaks but
 * is bypassed by poisoned index content that states a plausible *fact* rather
 * than issuing an instruction. Confirmed harms included telling a user to enter
 * their NetID and password at an attacker-controlled URL and recommending an
 * .exe download to site editors.
 *
 * This is an output-side backstop. It implements StreamableGuardrailInterface
 * because Beacon streams (ys_beacon.settings:streaming), and a post-generate
 * guardrail that is not streamable silently never fires on a streamed response
 * — GuardrailsEventSubscriber neither registers it with the stream iterator nor
 * reconstructs the output for it.
 *
 * Four patterns are blocked:
 * - a credential term near a link that points *off* Yale,
 * - a link whose target is an executable or installer,
 * - an instruction to install something from an off-Yale link,
 * - a request to transmit a secret.
 *
 * Why the credential check looks at the host at all. Checking the action and
 * not the host is the right rule for links in general — YaleSites content links
 * to an open-ended set of external hosts that cannot be vetted, which is why
 * Beacon disables the AI module's hostname filter. But for credential entry
 * specifically, "sign in at X with your NetID" is legitimate or hostile purely
 * according to what X is, so an action-only rule cannot separate them: it
 * blocks "reset your password at its.yale.edu" as readily as the attack.
 * Measured against real answers, the action-only form produced false positives
 * on the single most common shape of Beacon answer, made worse by the shipped
 * system prompt telling the model to include citation links. So the credential
 * rule carries one narrow carve-out — a link to a Yale-controlled host is not
 * treated as a credential-harvesting target. This is deliberately NOT the
 * general allow-list of vetted external hosts that was ruled out: nothing here
 * enumerates acceptable third parties, and every non-credential rule stays
 * fully host-independent.
 *
 * How it behaves while streaming:
 * - getStartRegex() matches any single signal — a credential term, a link, an
 *   installer target or a secret noun — so buffering begins as soon as one
 *   appears. The link itself is a trigger because the confirmed bypass put the
 *   URL in the *second* sentence, after the first had already been flushed.
 * - getStopRegex() releases at the next sentence boundary, so a triggered
 *   answer is held for one sentence rather than to end-of-stream. The iterator
 *   would otherwise buffer up to maxGuardrailBufferSize (8192 characters),
 *   which would be a visible streaming regression.
 * - Correlation across those short windows uses a bounded rolling tail of the
 *   text already seen, so a link a sentence or two after a credential request
 *   is still caught.
 *
 * That rolling tail is only sound if it is *contiguous* with the window being
 * checked. The iterator releases non-matching content without ever showing it
 * to the guardrail, so once the guardrail has been triggered it keeps buffering
 * for the rest of the response (getStartRegex() returns an empty string) and
 * therefore sees every remaining character in order. Before the first trigger
 * nothing is missed either, because content released then matched no signal at
 * all and so has nothing to correlate. Without this, two passages hundreds of
 * characters apart would be concatenated into a window that never existed.
 *
 * Output filtering is inherently late under streaming — text already yielded
 * cannot be un-sent — so this reduces harm rather than eliminating it. An
 * input-side intent intercept for credential/account questions remains the
 * stronger primary control.
 */
#[AiGuardrail(
  id: 'ys_beacon_output_safety',
  label: new TranslatableMarkup('Beacon output safety'),
  description: new TranslatableMarkup('Blocks streamed Beacon answers that pair a credential request with an off-Yale link, link to an executable installer, or ask the user to transmit a secret.'),
)]
class BeaconOutputSafety extends AiGuardrailPluginBase implements StreamableGuardrailInterface, ContainerFactoryPluginInterface {

  use StringTranslationTrait;

  /**
   * How far apart a credential term and a link may be and still correlate.
   *
   * Also bounds the rolling tail kept between buffers.
   */
  private const PROXIMITY_WINDOW = 400;

  /**
   * How far back a negation is looked for before a transmit verb.
   */
  private const NEGATION_LOOKBACK = 60;

  /**
   * Things that name a credential.
   */
  private const CREDENTIAL_NOUN_BODY = '\b(?:net\s?ids?|pass(?:words?|phrases?|codes?)|credentials?|usernames?|(?:one[\s-]?time|verification|security|duo)\s+codes?|duo\s+(?:push|prompt)|mfa|2fa|(?:two|multi)[\s-]?factor)\b';

  /**
   * Phrases that are themselves an instruction to authenticate.
   *
   * These imply the credential without naming it, so one of these next to an
   * off-Yale link is enough on its own.
   *
   * The inflections are spelled out because a trailing word boundary alone
   * would miss the most common real forms — "sign into", "logging in",
   * "logged into" — which is exactly how a poisoned answer phrases it.
   */
  private const AUTH_PHRASE_BODY = '\b(?:(?:sign(?:s|ed|ing)?|log(?:s|ged|ging)?)[\s-]?in(?:to)?|verify\s+your\s+identity|authenticate)\b';

  /**
   * Verbs that ask the user to put a credential into something.
   *
   * Weaker than AUTH_PHRASE_BODY, so these only count when a credential noun
   * also appears near the link. "Submit" and "include" are absent on purpose:
   * "submit a ticket at yale.service-now.com and include your NetID" is
   * ordinary help-desk guidance, not a credential prompt.
   */
  private const ENTRY_VERB_BODY = '\b(?:(?:re-?)?enter|type|input|key\s+in|confirm|update|provide|supply)\b';

  /**
   * Secrets a user must never be asked to transmit.
   */
  private const SECRET_NOUN_BODY = '\b(?:pass(?:words?|phrases?|codes?)|net\s?ids?|social\s+security(?:\s+number)?|ssn|credit\s+card(?:\s+number)?|card\s+number|bank\s+account)\b';

  /**
   * Verbs that actually hand a secret to someone else.
   *
   * Deliberately narrow. Verbs like "call", "submit", "provide" and "give" were
   * removed because none transmits a secret on its own, and each of them
   * matched ordinary help-desk guidance ("call the Help Desk if you forgot your
   * password", "provide your NetID when you contact them").
   */
  private const TRANSMIT_VERB_BODY = '\b(?:e-?mail|send|text|reply\s+with|share|forward)\b';

  /**
   * Negations that turn a transmit phrase into advice not to transmit.
   */
  private const NEGATION_BODY = '\b(?:never|not|n\'t|cannot|no\s+one|nobody|nowhere|avoid|refuse)\b';

  /**
   * The Yale-controlled domain that credential entry may point at.
   */
  private const YALE_DOMAIN = 'yale.edu';

  /**
   * Any link form the rendered answer turns into something clickable.
   *
   * Protocol-relative links are included because react-markdown passes any URL
   * beginning with "/" through untouched, so "//host/path" renders as a live
   * link. Bare domains with no scheme are excluded: remark-gfm does not
   * autolink them, so they are text the user would have to retype.
   */
  private const LINK_BODY = '(?:\bhttps?://[^\s)>\]]+|(?<![:\w])//[a-z0-9-]+(?:\.[a-z0-9-]+)+[^\s)>\]]*|\bwww\.[a-z0-9-]+(?:\.[a-z0-9-]+)+[^\s)>\]]*|\bmailto:[^\s)>\]]+)';

  /**
   * Instructions to install or execute something.
   *
   * Paired with an off-Yale link, this catches the download links an extension
   * list cannot: an extensionless endpoint or a filename hidden in a query
   * string. "Download" alone is deliberately absent — answers legitimately
   * point at external PDFs and documents.
   */
  private const INSTALL_VERB_BODY = '\b(?:install(?:s|ing|er|ers)?|reinstall|execute|double[\s-]?click)\b';

  /**
   * An executable, installer or script extension, matched against one link.
   *
   * Archive formats (.zip, .7z, .rar) are deliberately absent: YaleSites
   * content legitimately links to archives, and because a violation stops the
   * rest of the answer, blocking every .zip link would cost more than it
   * catches. An archive carrying a payload is therefore a known gap — the
   * install-instruction rule is what catches the common phrasing.
   */
  private const INSTALLER_EXTENSION_BODY = '\.(?:exe|msi|msu|dmg|pkg|apk|appx|msix|bat|cmd|ps1|vbs|vbe|scr|hta|lnk|jse|wsf|reg|jar|iso|deb|sh|run|bin|command)\b';

  /**
   * Any single signal that is enough to start buffering.
   *
   * Composed from the same bodies the individual checks use, so a term can
   * never be added to one and forgotten in the other. This is deliberately
   * broader than what the rules block: buffering should begin on any mention of
   * a credential, so a link arriving later can still be correlated with it.
   */
  private const START_SIGNAL_BODY = self::CREDENTIAL_NOUN_BODY . '|' . self::AUTH_PHRASE_BODY . '|' . self::SECRET_NOUN_BODY . '|' . self::LINK_BODY;

  /**
   * Releases the buffer at the end of the sentence that triggered it.
   */
  private const SENTENCE_END = '#[.!?]["\')\]]?\s#';

  /**
   * Matches any non-empty buffer, so a latched response releases nothing.
   */
  private const ANY_CONTENT = '#[\s\S]#';

  /**
   * Whether a violation has already stopped this response.
   *
   * @var bool
   */
  protected bool $blocked = FALSE;

  /**
   * Whether a signal has been seen, after which every chunk is buffered.
   *
   * Keeps the rolling tail contiguous with the window being checked.
   *
   * @var bool
   */
  protected bool $triggered = FALSE;

  /**
   * The tail of already-seen text, bounded to the proximity window.
   *
   * @var string
   */
  protected string $recentText = '';

  /**
   * The Beacon logger channel, when the plugin was built by the container.
   *
   * @var \Psr\Log\LoggerInterface|null
   */
  protected ?LoggerInterface $logger = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->logger = $container->get('logger.channel.ys_beacon');

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function processInput(InputInterface $input): GuardrailResultInterface {
    return new PassResult('Beacon output safety only inspects output.', $this);
  }

  /**
   * {@inheritdoc}
   */
  public function processOutput(OutputInterface $output): GuardrailResultInterface {
    if (!$output instanceof ChatOutput) {
      return new PassResult('Output is not a chat output, skipping Beacon output safety.', $this);
    }

    $normalized = $output->getNormalized();
    if (!$normalized instanceof ChatMessage) {
      // A streamed response never reaches this method: the subscriber registers
      // this guardrail on the stream iterator instead, and each buffered window
      // is checked by processStreamedBuffer(). ChatOutput::getNormalized() is
      // typed to those two shapes, so this branch only guards an output shape
      // neither path understands.
      return new PassResult('Streamed output is inspected chunk by chunk instead.', $this);
    }

    return $this->resultFor($this->findViolation($normalized->getText()));
  }

  /**
   * {@inheritdoc}
   */
  public function getStartRegex(): string {
    // Buffer everything from here on: to suppress the remainder of a stopped
    // response, and to keep the rolling tail contiguous once a signal has been
    // seen. See the class docblock for why contiguity matters.
    if ($this->blocked || $this->triggered) {
      return '';
    }

    return $this->pattern(self::START_SIGNAL_BODY);
  }

  /**
   * {@inheritdoc}
   */
  public function getStopRegex(): string {
    // Latched: evaluate every chunk immediately so nothing else is released.
    return $this->blocked ? self::ANY_CONTENT : self::SENTENCE_END;
  }

  /**
   * {@inheritdoc}
   */
  public function processStreamedBuffer(string $buffered_content): GuardrailResultInterface {
    if ($this->blocked) {
      // Suppress the rest of the response without repeating the notice.
      return new RewriteOutputResult('', $this);
    }

    // From here on every chunk is buffered, so the rolling tail stays
    // contiguous with the text it is compared against.
    $this->triggered = TRUE;

    // Evaluate the buffer together with the tail already shown to the user, so
    // a credential request and a link in different sentences still correlate.
    $violation = $this->findViolation($this->recentText . $buffered_content);
    if ($violation !== NULL) {
      $this->blocked = TRUE;
      $this->recentText = '';
    }
    else {
      $this->recentText = substr($this->recentText . $buffered_content, -self::PROXIMITY_WINDOW);
    }

    return $this->resultFor($violation);
  }

  /**
   * Clears state left over from a previous streamed response.
   *
   * The contrib streaming-guardrail API has no stream-lifecycle hook, and
   * AiGuardrail::getGuardrail() caches the plugin on the config entity, so a
   * long-running PHP process could hand the same instance to two responses.
   * Beacon's streaming path is request-scoped (one chat per HTTP request) and
   * the non-streamed path keeps no state, so this exists for callers that reuse
   * an instance deliberately.
   */
  public function resetStreamState(): void {
    $this->blocked = FALSE;
    $this->triggered = FALSE;
    $this->recentText = '';
  }

  /**
   * Names the unsafe pattern in the given text, if any.
   *
   * @param string $text
   *   The model-authored text to inspect.
   *
   * @return string|null
   *   A short description of the violation, or NULL when the text is clean.
   */
  protected function findViolation(string $text): ?string {
    if ($this->hasUnnegatedMatch($text, $this->secretTransmitPattern())) {
      return 'request to transmit a secret';
    }

    $external_links = [];
    foreach ($this->linkMatches($text) as [$link, $offset]) {
      if ($this->isYaleLink($link)) {
        // Yale distributes its own software, so an installer on a Yale host is
        // legitimate. Every other rule below only sees off-Yale links.
        continue;
      }
      if (preg_match($this->pattern(self::INSTALLER_EXTENSION_BODY), $link)) {
        return 'link to an executable installer';
      }
      $external_links[] = $offset;
    }

    if ($external_links === []) {
      return NULL;
    }

    if ($this->hasNearbyOffsets($this->matchOffsets($text, $this->pattern(self::INSTALL_VERB_BODY)), $external_links)) {
      return 'instruction to install software from an off-Yale link';
    }

    // An explicit "sign in" style instruction next to an off-Yale link is
    // enough on its own; a weaker entry verb also needs a credential named
    // nearby, so "enter it at <link>" after "you will need your password" is
    // caught while "submit a ticket ... and include your NetID" is not.
    if ($this->hasNearbyOffsets($this->matchOffsets($text, $this->pattern(self::AUTH_PHRASE_BODY)), $external_links)) {
      return 'credential entry instruction paired with an off-Yale link';
    }

    $entry_verbs = $this->matchOffsets($text, $this->pattern(self::ENTRY_VERB_BODY));
    $credentials = $this->matchOffsets($text, $this->pattern(self::CREDENTIAL_NOUN_BODY));
    if ($this->hasNearbyOffsets($entry_verbs, $external_links) && $this->hasNearbyOffsets($credentials, $external_links)) {
      return 'credential entry instruction paired with an off-Yale link';
    }

    return NULL;
  }

  /**
   * Every link in the text, with the offset it appeared at.
   *
   * @param string $text
   *   The text to inspect.
   *
   * @return array[]
   *   A list of [link, offset] pairs.
   */
  protected function linkMatches(string $text): array {
    if (!preg_match_all($this->pattern(self::LINK_BODY), $text, $matches, PREG_OFFSET_CAPTURE)) {
      return [];
    }

    return $matches[0];
  }

  /**
   * Whether a link points at a Yale-controlled host.
   *
   * The host is parsed rather than pattern-matched. A regex that merely looked
   * for "yale.edu" in the URL treats yale.edu.evil.com, yale.edu-secure.com and
   * yale.edu@evil.com as internal — each of which is registrable by an attacker
   * and delivers the credential-harvesting link this guardrail exists to stop.
   *
   * @param string $link
   *   A link as it appeared in the answer.
   *
   * @return bool
   *   TRUE when the host is yale.edu or a subdomain of it.
   */
  protected function isYaleLink(string $link): bool {
    if (stripos($link, 'mailto:') === 0) {
      $at = strrpos($link, '@');
      $host = $at === FALSE ? '' : substr($link, $at + 1);
    }
    else {
      // Give parse_url() a scheme so protocol-relative and bare www links
      // resolve to a host. parse_url() puts any user:pass into its own
      // component, so "https://yale.edu@evil.com" correctly yields evil.com.
      $candidate = str_starts_with($link, '//') ? 'https:' . $link : $link;
      if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $candidate)) {
        $candidate = 'https://' . $candidate;
      }
      $host = (string) parse_url($candidate, PHP_URL_HOST);
    }

    $host = strtolower(rtrim(trim($host, '.,;:'), '.'));
    if ($host === '') {
      // An unparseable host is treated as off-Yale so the checks still apply.
      return FALSE;
    }

    return $host === self::YALE_DOMAIN || str_ends_with($host, '.' . self::YALE_DOMAIN);
  }

  /**
   * Turns a violation description into a guardrail result.
   *
   * @param string|null $violation
   *   The violation description, or NULL when the text is clean.
   *
   * @return \Drupal\ai\Guardrail\Result\GuardrailResultInterface
   *   A StopResult for a violation, otherwise a PassResult.
   */
  protected function resultFor(?string $violation): GuardrailResultInterface {
    if ($violation === NULL) {
      return new PassResult('Beacon output safety found no credential or download risk.', $this);
    }

    // A backstop that fires silently cannot be tuned: without this there is no
    // server-side record that an answer was cut, so neither a real bypass nor a
    // false positive is visible in production. Only the category is logged -
    // never the model text - so no answer content or user question is stored.
    $this->logger?->warning('Beacon output safety blocked part of an answer: @violation.', [
      '@violation' => $violation,
    ]);

    return new StopResult($this->blockedMessage(), $this, ['violation' => $violation]);
  }

  /**
   * Whether a pattern matches somewhere it is not negated.
   *
   * "Never share your password with anyone" is the advice Beacon should give,
   * so a transmit phrase only counts when its own clause does not negate it.
   *
   * @param string $text
   *   The text to inspect.
   * @param string $pattern
   *   The regex to match, including delimiters.
   *
   * @return bool
   *   TRUE when at least one match has no negation in front of it.
   */
  protected function hasUnnegatedMatch(string $text, string $pattern): bool {
    if (!preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
      return FALSE;
    }

    foreach ($matches[0] as $match) {
      $start = max(0, $match[1] - self::NEGATION_LOOKBACK);
      $preceding = substr($text, $start, $match[1] - $start);
      // Only look back to the start of the current clause, so a negation in an
      // earlier sentence does not excuse this one.
      $clause = preg_split('/[.!?\n]/', $preceding);
      if (!preg_match($this->pattern(self::NEGATION_BODY), (string) end($clause))) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Byte offsets of every match of a pattern.
   *
   * @param string $text
   *   The text to inspect.
   * @param string $pattern
   *   The regex to match, including delimiters.
   *
   * @return int[]
   *   The offsets, in order.
   */
  protected function matchOffsets(string $text, string $pattern): array {
    if (!preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
      return [];
    }

    return array_column($matches[0], 1);
  }

  /**
   * Whether any pair of offsets falls inside the proximity window.
   *
   * @param int[] $first
   *   The first set of offsets.
   * @param int[] $second
   *   The second set of offsets.
   *
   * @return bool
   *   TRUE when one offset from each set is within PROXIMITY_WINDOW of the
   *   other.
   */
  protected function hasNearbyOffsets(array $first, array $second): bool {
    foreach ($first as $a) {
      foreach ($second as $b) {
        if (abs($a - $b) <= self::PROXIMITY_WINDOW) {
          return TRUE;
        }
      }
    }

    return FALSE;
  }

  /**
   * Delimits a pattern body into a case-insensitive regex.
   *
   * The bodies are kept undelimited so the same term lists compose into both
   * START_SIGNAL_BODY and the individual checks — a term can never be added to
   * one and forgotten in the other.
   *
   * @param string $body
   *   A self-contained pattern body, without delimiters.
   *
   * @return string
   *   A PCRE pattern including delimiters.
   */
  protected function pattern(string $body): string {
    return '#' . $body . '#i';
  }

  /**
   * Builds the pattern for asking a user to hand over a secret.
   *
   * @return string
   *   A PCRE pattern including delimiters.
   */
  protected function secretTransmitPattern(): string {
    // Bounded and confined to one sentence so an unrelated verb earlier in the
    // answer is not paired with a later secret noun.
    return '#' . self::TRANSMIT_VERB_BODY . '[^.!?]{0,80}?' . self::SECRET_NOUN_BODY . '#i';
  }

  /**
   * The notice shown in place of blocked content.
   *
   * @return string
   *   The user-facing message.
   */
  protected function blockedMessage(): string {
    return (string) $this->t('This part of the answer was blocked because it appeared to ask for your credentials or link to a download. Never enter your NetID or password on a page you reached from a chat answer, and contact your local IT support if you need help.');
  }

}
