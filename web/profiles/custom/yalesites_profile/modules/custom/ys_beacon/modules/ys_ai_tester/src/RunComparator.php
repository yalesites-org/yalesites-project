<?php

declare(strict_types=1);

namespace Drupal\ys_ai_tester;

use Drupal\Core\Database\Connection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Compares two AI Tester runs question-by-question.
 *
 * The comparison logic is split in two so it can be reasoned about and unit
 * tested without a database: compareResults() is pure and operates on already
 * loaded result arrays; compare() is the thin loader the controller calls.
 */
class RunComparator {

  /**
   * Constructs the run comparator.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(
    protected Connection $database,
  ) {}

  /**
   * Loads two runs and returns their structured comparison.
   *
   * @param int $run_a
   *   The first (older, by convention) run id.
   * @param int $run_b
   *   The second run id.
   *
   * @return array
   *   The comparison structure produced by compareResults().
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   When either run id does not exist.
   */
  public function compare(int $run_a, int $run_b): array {
    return $this->compareResults(
      $this->loadRun($run_a),
      $this->loadRun($run_b),
      $this->loadResults($run_a),
      $this->loadResults($run_b),
    );
  }

  /**
   * Compares two runs' results, aligning questions by text.
   *
   * @param array $meta_a
   *   Run A meta: id, created, source_filename, status.
   * @param array $meta_b
   *   Run B meta, same shape.
   * @param array $results_a
   *   Run A results in delta order, each: question, answer, citations (array).
   * @param array $results_b
   *   Run B results, same shape.
   *
   * @return array
   *   A structure with run_a, run_b, pairs, and summary keys.
   */
  public function compareResults(array $meta_a, array $meta_b, array $results_a, array $results_b): array {
    // Queue B's results per trimmed question so duplicate questions pair by
    // occurrence (the Nth "Q" in A matches the Nth "Q" in B).
    $b_by_question = [];
    foreach ($results_b as $result) {
      $b_by_question[trim((string) $result['question'])][] = $result;
    }

    $pairs = [];
    $summary = [
      'total_compared' => 0,
      'differ' => 0,
      'identical' => 0,
      'only_a' => 0,
      'only_b' => 0,
    ];
    $b_pointers = [];

    // Each side's own host, resolved once: it is what citation matching treats
    // as noise, and what the run meta reports.
    $host_a = $this->citationHost($results_a);
    $host_b = $this->citationHost($results_b);

    // Walk A in order, pairing each question with the next unused B match.
    foreach ($results_a as $a) {
      $key = trim((string) $a['question']);
      $pointer = $b_pointers[$key] ?? 0;
      $b = $b_by_question[$key][$pointer] ?? NULL;

      if ($b !== NULL) {
        $b_pointers[$key] = $pointer + 1;
        $status = trim((string) $a['answer']) === trim((string) $b['answer'])
          ? 'identical'
          : 'differs';
        $pairs[] = $this->buildPair($key, $a, $b, $status, $host_a, $host_b);
        $summary['total_compared']++;
        $summary[$status === 'identical' ? 'identical' : 'differ']++;
      }
      else {
        $pairs[] = $this->buildPair($key, $a, NULL, 'only_a', $host_a, $host_b);
        $summary['only_a']++;
      }
    }

    // Append B questions never matched above. The per-question queues keep B's
    // order; array_slice drops the prefix already consumed by matched pairs.
    foreach ($b_by_question as $key => $queue) {
      foreach (array_slice($queue, $b_pointers[$key] ?? 0) as $b) {
        $pairs[] = $this->buildPair($key, NULL, $b, 'only_b', $host_a, $host_b);
        $summary['only_b']++;
      }
    }

    return [
      'run_a' => $this->metaArray($meta_a, $host_a),
      'run_b' => $this->metaArray($meta_b, $host_b),
      'pairs' => $pairs,
      'summary' => $summary,
    ];
  }

  /**
   * Builds one comparison pair for a question.
   *
   * @param string $question
   *   The question text.
   * @param array|null $a
   *   Run A's result, or NULL when A did not ask this question.
   * @param array|null $b
   *   Run B's result, or NULL when B did not ask it.
   * @param string $status
   *   One of identical, differs, only_a, only_b.
   * @param string $host_a
   *   Run A's own host, the one its citation URLs are matched independently of.
   * @param string $host_b
   *   Run B's own host, likewise.
   *
   * @return array
   *   The comparison pair.
   */
  protected function buildPair(string $question, ?array $a, ?array $b, string $status, string $host_a, string $host_b): array {
    $side_a = $a !== NULL ? $this->side($a) : NULL;
    $side_b = $b !== NULL ? $this->side($b) : NULL;

    return [
      'question' => $question,
      'status' => $status,
      'a' => $side_a,
      'b' => $side_b,
      'len_delta' => ($side_a !== NULL && $side_b !== NULL)
        ? $side_b['len'] - $side_a['len']
        : 0,
      'citation_overlap' => $this->overlap(
        $a['citations'] ?? [],
        $b['citations'] ?? [],
        $host_a,
        $host_b,
      ),
    ];
  }

  /**
   * Computes the per-side display signals for one result.
   */
  protected function side(array $result): array {
    $answer = (string) ($result['answer'] ?? '');
    $citations = $result['citations'] ?? [];

    $cited = 0;
    foreach ($citations as $citation) {
      if (!empty($citation['cited'])) {
        $cited++;
      }
    }

    return [
      'answer' => $answer,
      'citations' => $citations,
      'len' => mb_strlen($answer),
      'cited' => $cited,
      'retrieved' => count($citations),
      'empty' => trim($answer) === '',
      // Distinguishes "the assistant failed on this question" from "the
      // assistant genuinely answered with nothing".
      'error' => (string) ($result['error'] ?? ''),
    ];
  }

  /**
   * Partitions two citation lists into shared and run-unique sources.
   *
   * @param array $citations_a
   *   Run A's citations for one question.
   * @param array $citations_b
   *   Run B's citations for the same question.
   * @param string $host_a
   *   Run A's own host, matched independently of.
   * @param string $host_b
   *   Run B's own host, likewise.
   *
   * @return array
   *   The both, only_a and only_b partitions.
   */
  protected function overlap(array $citations_a, array $citations_b, string $host_a, string $host_b): array {
    $a_by_key = $this->indexByMatchKey($citations_a, $host_a);
    $b_by_key = $this->indexByMatchKey($citations_b, $host_b);

    $both = $only_a = $only_b = [];
    foreach ($a_by_key as $key => $citation) {
      if (isset($b_by_key[$key])) {
        $both[] = $this->overlapEntry($citation);
      }
      else {
        $only_a[] = $this->overlapEntry($citation);
      }
    }
    foreach ($b_by_key as $key => $citation) {
      if (!isset($a_by_key[$key])) {
        $only_b[] = $this->overlapEntry($citation);
      }
    }

    return ['both' => $both, 'only_a' => $only_a, 'only_b' => $only_b];
  }

  /**
   * Reduces a citation to what the overlap partitions expose.
   *
   * The URL shown is the run's own, not the match key: a reader following a
   * shared source needs a link that resolves, and the key has the run's own
   * host stripped out.
   */
  protected function overlapEntry(array $citation): array {
    return [
      'url' => (string) ($citation['url'] ?? ''),
      'title' => (string) ($citation['title'] ?? ''),
    ];
  }

  /**
   * Indexes citations by match key, keeping the first per key.
   *
   * Two spellings of one source therefore fold to a single overlap entry, while
   * the side's own 'retrieved' count still counts both.
   *
   * @param array $citations
   *   One side's citations for a question.
   * @param string $own_host
   *   The run's own host, stripped from its citations' match keys.
   *
   * @return array
   *   Citations keyed by match key.
   */
  protected function indexByMatchKey(array $citations, string $own_host): array {
    $by_key = [];
    foreach ($citations as $citation) {
      // Sources without a URL are dropped from overlap: they cannot be matched
      // across runs. This intentionally diverges from CitationFormatter, which
      // keeps URL-less sources distinct for display.
      // Trimmed because a stored citation_url reaches us through ai_search's
      // HTML-to-Markdown conversion and RagRetriever::decodeStoredValue(),
      // neither of which trims; padding would otherwise defeat every match and
      // hide the host.
      $url = trim((string) ($citation['url'] ?? ''));
      if ($url === '') {
        continue;
      }
      $by_key[$this->citationMatchKey($url, $own_host)] ??= $citation;
    }
    return $by_key;
  }

  /**
   * Reduces a citation URL to the identity two runs are matched on.
   *
   * The whole point of a run comparison is to hold the question set constant
   * and vary the assistant, so the normal case is comparing a multidev or
   * staging build against production -- two hosts serving the same content.
   * Keying overlap on the full URL made every source differ by host, so no
   * question ever reported a shared source.
   *
   * Only the run's **own** host is dropped, not every host. A run is not always
   * confined to one site: a read-only borrowed index cites other sites'
   * documents from their stored URLs, and a site querying the whole collection
   * gets a deliberate mix of its own pages and foreign ones (see
   * RagRetriever::buildStoredCitations() and buildMixedCitations()). Only the
   * own host changes between a multidev and production, so it is the only noise
   * here; dropping foreign hosts too would report siteA.yale.edu/about and
   * siteB.yale.edu/about as one shared source, and "/about" is among the most
   * common paths on the platform. A false shared source would corrupt exactly
   * the number this fix exists to make trustworthy.
   *
   * Deliberately ignored: scheme, the own host (with any port or credentials),
   * a trailing slash, percent-encoding, and the fragment -- a fragment
   * addresses a place inside a source, not a different source. Deliberately
   * kept: the query string, because it selects the resource on real Beacon
   * citations such as /media/3359/download?inline.
   *
   * @param string $url
   *   The citation URL as the run recorded it. May be relative.
   * @param string $own_host
   *   The host of the site this run was answered on. Empty when unknown, in
   *   which case every host is treated as foreign and kept.
   *
   * @return string
   *   The key two runs' citations are matched on.
   */
  protected function citationMatchKey(string $url, string $own_host): string {
    $parts = parse_url($url);
    if ($parts === FALSE) {
      return $url;
    }

    // A foreign host stays in the key so it cannot be confused with the same
    // path on the run's own site. Compared case-insensitively: hosts are.
    $host = (string) ($parts['host'] ?? '');
    $prefix = ($host === '' || ($own_host !== '' && strcasecmp($host, $own_host) === 0))
      ? ''
      : $host;

    // Decoded so the percent-encoded and literal spellings of one path agree.
    // This also decodes encoded delimiters, so /a%2Fb keys as /a/b and
    // ?q=x%26y=z keys as ?q=x&y=z. Accepted rather than worked around: our
    // citations come from Drupal routes and file URLs, which do not carry
    // encoded delimiters, so no real citation reaches those collisions.
    $path = rawurldecode($parts['path'] ?? '');
    if ($path !== '/') {
      $path = rtrim($path, '/');
    }
    $query = isset($parts['query']) ? '?' . rawurldecode($parts['query']) : '';
    $key = $prefix . $path . $query;

    // Nothing left to key on -- the run's own bare host, or something parse_url
    // could not make sense of. Such a URL stays whole rather than being
    // normalized: every one of them would otherwise collapse onto the same
    // empty key and be reported as one shared source, and a false shared source
    // misleads a reader worse than a missed one does. The cost is that a bare
    // https://own-host does not match https://other-host/ even though both name
    // a front page; real citations are page and file URLs that always carry a
    // path, so that asymmetry is unreachable from actual run data.
    return $key === '' ? $url : $key;
  }

  /**
   * Finds the host a run was answered on.
   *
   * Runs do not record the site they ran against, so the host is read back off
   * the citations. The most frequent one wins rather than the first: a run
   * whose index is read-only or queried whole cites foreign sites alongside its
   * own, so the first citation can name a site the run was not answered on, and
   * that value both labels the run in the UI and decides which host citation
   * matching treats as noise. Ties go to whichever host was seen first.
   *
   * @param array $results
   *   The run's results, each with a 'citations' list.
   *
   * @return string
   *   The host, or an empty string when no citation names one.
   */
  protected function citationHost(array $results): string {
    $counts = [];
    foreach ($results as $result) {
      foreach ($result['citations'] ?? [] as $citation) {
        $host = parse_url(trim((string) ($citation['url'] ?? '')), PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
          $counts[$host] = ($counts[$host] ?? 0) + 1;
        }
      }
    }
    if ($counts === []) {
      return '';
    }
    // PHP sorts are stable, so equal counts keep insertion order and a tie
    // resolves to the host seen first.
    arsort($counts, SORT_NUMERIC);
    return (string) array_key_first($counts);
  }

  /**
   * Normalizes a run meta row to the keys the comparison exposes.
   *
   * @param array $meta
   *   The run row loaded from storage.
   * @param string $host
   *   The host this run's citations point at, empty when none name one.
   *
   * @return array
   *   The run meta the comparison exposes.
   */
  protected function metaArray(array $meta, string $host): array {
    return [
      'id' => (int) $meta['id'],
      'created' => (int) $meta['created'],
      'source_filename' => (string) $meta['source_filename'],
      'status' => (string) $meta['status'],
      // Which assistant answered this side. Runs recorded before the tester
      // supported more than one read back as Beacon.
      'backend' => (string) ($meta['backend'] ?? AnswerBackendInterface::DEFAULT_ID),
      // Citation overlap ignores this host when matching, so it has to be
      // stated for the overlap figures to be interpretable.
      'host' => $host,
    ];
  }

  /**
   * Loads a run meta row, or throws a 404.
   */
  protected function loadRun(int $run_id): array {
    $row = $this->database->query(
      'SELECT id, created, source_filename, status, backend FROM {ys_ai_tester_run} WHERE id = :id',
      [':id' => $run_id]
    )->fetchAssoc();

    if (!$row) {
      throw new NotFoundHttpException();
    }
    return $row;
  }

  /**
   * Loads a run's results in delta order with decoded citations.
   */
  protected function loadResults(int $run_id): array {
    $rows = $this->database->query(
      'SELECT question, answer, citations, error FROM {ys_ai_tester_result} WHERE run_id = :run_id ORDER BY delta ASC',
      [':run_id' => $run_id]
    )->fetchAll();

    $results = [];
    foreach ($rows as $row) {
      $results[] = [
        'question' => $row->question,
        'answer' => $row->answer,
        'citations' => json_decode($row->citations ?? '', TRUE) ?: [],
        'error' => (string) ($row->error ?? ''),
      ];
    }
    return $results;
  }

}
