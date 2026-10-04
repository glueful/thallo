<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support\Search;

use Thallo\Search\Engine\IndexNotFound;
use Thallo\Search\Engine\MeilisearchIndex;

/**
 * An in-memory Meilisearch for tests: indexes, documents, an ordered task queue whose tasks complete
 * at once (`autoComplete`) or wait for {@see complete()} / {@see fail()}, federated search with the
 * filter forms the search pack emits, and `index_not_found`.
 */
final class FakeMeilisearch implements MeilisearchIndex
{
    public string $version = '1.10.0';
    public bool $autoComplete = true;
    /** The next N document additions fail asynchronously (accepted, then failed). */
    public int $failNextAdds = 0;
    /** @var list<array{indexUid: string, q: string, filter: string}> */
    public array $lastQueries = [];
    /** @var array<string, array{settings: array<string, mixed>, docs: array<string, array<string, mixed>>}> */
    public array $indexes = [];
    /** @var array<int, array{status: string, apply: \Closure, error: ?string}> */
    private array $tasks = [];
    private int $nextTask = 1;

    public function serverVersion(): string
    {
        return $this->version;
    }

    public function ensureIndex(string $uid, array $settings): void
    {
        $this->indexes[$uid] ??= ['settings' => [], 'docs' => []];
        $this->indexes[$uid]['settings'] = $settings;
    }

    public function addDocuments(string $uid, array $documents): int
    {
        if ($this->failNextAdds > 0) {
            $this->failNextAdds--;
            $task = $this->nextTask++;
            $this->tasks[$task] = ['status' => 'failed', 'apply' => static fn () => null, 'error' => 'batch failed'];
            return $task;
        }
        return $this->enqueue(function () use ($uid, $documents): void {
            // Like the real server, adding to a missing index creates it.
            $this->indexes[$uid] ??= ['settings' => [], 'docs' => []];
            foreach ($documents as $document) {
                $this->indexes[$uid]['docs'][(string) $document['id']] = $document;
            }
        });
    }

    public function deleteDocuments(string $uid, array $ids): int
    {
        return $this->enqueue(function () use ($uid, $ids): void {
            foreach ($ids as $id) {
                unset($this->indexes[$uid]['docs'][$id]);
            }
        });
    }

    public function deleteByFilter(string $uid, string $filter): int
    {
        return $this->enqueue(function () use ($uid, $filter): void {
            foreach ($this->indexes[$uid]['docs'] ?? [] as $id => $document) {
                if (self::matches($document, $filter)) {
                    unset($this->indexes[$uid]['docs'][$id]);
                }
            }
        });
    }

    public function deleteIndex(string $uid): int
    {
        return $this->enqueue(function () use ($uid): void {
            unset($this->indexes[$uid]);
        });
    }

    public function task(int $taskUid): array
    {
        $task = $this->tasks[$taskUid] ?? null;
        return ['status' => $task['status'] ?? 'failed', 'error' => $task['error'] ?? 'unknown task'];
    }

    public function listIndexes(string $prefix): array
    {
        $names = array_values(array_filter(
            array_keys($this->indexes),
            static fn (string $n): bool => str_starts_with($n, $prefix),
        ));
        sort($names);
        return $names;
    }

    public function federatedSearch(array $queries, int $limit, int $offset): array
    {
        $this->lastQueries = $queries;
        $hits = [];
        foreach ($queries as $query) {
            $uid = $query['indexUid'];
            if (!isset($this->indexes[$uid])) {
                throw new IndexNotFound($uid);
            }
            foreach ($this->indexes[$uid]['docs'] as $document) {
                $score = self::score($document, $query['q']);
                if ($score > 0 && self::matches($document, $query['filter'])) {
                    $hits[] = $document + ['_index' => $uid, '_score' => $score];
                }
            }
        }
        usort($hits, static fn (array $a, array $b): int => $b['_score'] <=> $a['_score']);
        return ['hits' => array_slice($hits, $offset, $limit), 'estimatedTotalHits' => count($hits)];
    }


    public function reachable(string $uid): bool
    {
        return isset($this->indexes[$uid]);
    }

    public function complete(int $taskUid): void
    {
        $task = $this->tasks[$taskUid];
        ($task['apply'])();
        $this->tasks[$taskUid]['status'] = 'succeeded';
    }

    public function fail(int $taskUid, string $error = 'task failed'): void
    {
        $this->tasks[$taskUid]['status'] = 'failed';
        $this->tasks[$taskUid]['error'] = $error;
    }

    /** @return list<int> tasks still enqueued */
    public function pendingTasks(): array
    {
        return array_keys(array_filter($this->tasks, static fn (array $t): bool => $t['status'] === 'enqueued'));
    }

    /** @return list<string> */
    public function ids(string $uid): array
    {
        $ids = array_keys($this->indexes[$uid]['docs'] ?? []);
        sort($ids);
        return array_map('strval', $ids);
    }

    /** @return array<string, mixed>|null */
    public function document(string $uid, string $id): ?array
    {
        return $this->indexes[$uid]['docs'][$id] ?? null;
    }

    private function enqueue(\Closure $apply): int
    {
        $uid = $this->nextTask++;
        $this->tasks[$uid] = ['status' => 'enqueued', 'apply' => $apply, 'error' => null];
        if ($this->autoComplete) {
            $this->complete($uid);
        }
        return $uid;
    }

    /** @param array<string, mixed> $document */
    private static function score(array $document, string $q): int
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($q), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            return 0;
        }
        $tokens = preg_split(
            '/[^\p{L}\p{N}]+/u',
            mb_strtolower(($document['title'] ?? '') . ' ' . ($document['body'] ?? '')),
            -1,
            PREG_SPLIT_NO_EMPTY,
        ) ?: [];
        $score = 0;
        foreach ($words as $word) {
            $hit = false;
            foreach ($tokens as $token) {
                if (str_starts_with($token, $word)) {
                    $score++;
                    $hit = true;
                }
            }
            if (!$hit) {
                return 0;
            }
        }
        return $score;
    }

    /** The filter forms the search pack emits: `a = "x"`, `a IN [...]`, `a NOT IN [...]`, `(x OR y)`, AND. */
    private static function matches(array $document, string $filter): bool
    {
        if (trim($filter) === '') {
            return true;
        }
        foreach (self::splitTop($filter, ' AND ') as $clause) {
            $clause = trim($clause);
            if (str_starts_with($clause, '(') && str_ends_with($clause, ')')) {
                $any = false;
                foreach (self::splitTop(substr($clause, 1, -1), ' OR ') as $alt) {
                    $any = $any || self::clause($document, trim($alt));
                }
                if (!$any) {
                    return false;
                }
            } elseif (!self::clause($document, $clause)) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string, mixed> $document */
    private static function clause(array $document, string $clause): bool
    {
        if (preg_match('/\A(\w+) = "((?:[^"\\\\]|\\\\.)*)"\z/', $clause, $m) === 1) {
            return (string) ($document[$m[1]] ?? '') === stripcslashes($m[2]);
        }
        if (preg_match('/\A(\w+) (NOT IN|IN) (\[.*\])\z/', $clause, $m) === 1) {
            $list = (array) json_decode($m[3], true);
            $in = in_array((string) ($document[$m[1]] ?? ''), array_map('strval', $list), true);
            return $m[2] === 'IN' ? $in : !$in;
        }
        throw new \LogicException("FakeMeilisearch cannot read the filter clause: {$clause}");
    }

    /** @return list<string> */
    private static function splitTop(string $s, string $separator): array
    {
        $parts = [];
        $depth = 0;
        $quoted = false;
        $current = '';
        $length = strlen($s);
        for ($i = 0; $i < $length; $i++) {
            $c = $s[$i];
            if ($c === '"' && ($i === 0 || $s[$i - 1] !== '\\')) {
                $quoted = !$quoted;
            } elseif (!$quoted && ($c === '(' || $c === '[')) {
                $depth++;
            } elseif (!$quoted && ($c === ')' || $c === ']')) {
                $depth--;
            } elseif (!$quoted && $depth === 0 && substr($s, $i, strlen($separator)) === $separator) {
                $parts[] = $current;
                $current = '';
                $i += strlen($separator) - 1;
                continue;
            }
            $current .= $c;
        }
        $parts[] = $current;
        return $parts;
    }
}
