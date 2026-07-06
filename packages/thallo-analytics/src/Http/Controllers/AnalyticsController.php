<?php

declare(strict_types=1);

namespace Thallo\Analytics\Http\Controllers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Response;
use Thallo\Analytics\Query\AnalyticsQuery;
use Glueful\Routing\Attributes\ApiOperation;
use Symfony\Component\HttpFoundation\Request;

use function config;

/**
 * Admin read API over the analytics rollups. Gated by analytics.read in the route definitions.
 */
final class AnalyticsController
{
    public function __construct(
        private readonly AnalyticsQuery $query,
        private readonly ApplicationContext $context,
    ) {
    }

    #[ApiOperation(summary: 'Analytics time-series for one metric', tags: ['Analytics'])]
    public function series(Request $request): Response
    {
        $metric = (string) $request->query->get('metric', '');
        $from = (string) $request->query->get('from', '');
        $to = (string) $request->query->get('to', '');
        if ($metric === '' || $from === '' || $to === '') {
            return Response::error('metric, from and to are required.', 422);
        }
        $range = $this->normalizeRange($from, $to);
        if ($range instanceof Response) {
            return $range;
        }
        [$from, $to] = $range;
        $subject = null;
        if ($request->query->get('dimension') === 'subject') {
            $subject = (string) $request->query->get('subject', '');
            if ($subject === '') {
                // Falling back to '__total__' here would return totals mislabeled as a breakdown.
                return Response::error('subject is required when dimension=subject.', 422);
            }
        }

        return Response::success([
            'metric' => $metric,
            'from' => $from,
            'to' => $to,
            'series' => $this->query->series($metric, $from, $to, $subject),
        ]);
    }

    #[ApiOperation(summary: 'Analytics summary (KPIs incl. active users)', tags: ['Analytics'])]
    public function summary(Request $request): Response
    {
        $from = (string) $request->query->get('from', '');
        $to = (string) $request->query->get('to', '');
        if ($from === '' || $to === '') {
            return Response::error('from and to are required.', 422);
        }
        $range = $this->normalizeRange($from, $to);
        if ($range instanceof Response) {
            return $range;
        }
        [$from, $to] = $range;
        return Response::success($this->query->summary($from, $to));
    }

    #[ApiOperation(summary: 'Analytics breakdown: top subjects for one event', tags: ['Analytics'])]
    public function breakdown(Request $request): Response
    {
        $event = (string) $request->query->get('event', '');
        $from = (string) $request->query->get('from', '');
        $to = (string) $request->query->get('to', '');
        if ($event === '' || $from === '' || $to === '') {
            return Response::error('event, from and to are required.', 422);
        }
        $range = $this->normalizeRange($from, $to);
        if ($range instanceof Response) {
            return $range;
        }
        [$from, $to] = $range;
        $limit = (int) $request->query->get('limit', 10);

        return Response::success([
            'event' => $event,
            'from' => $from,
            'to' => $to,
            'breakdown' => $this->query->breakdown($event, $from, $to, $limit),
        ]);
    }

    /**
     * Validate the date range and return it normalized to canonical Y-m-d strings, or a 422
     * Response. Rejecting bad ranges here prevents an unparseable date reaching series()'s
     * `new DateTimeImmutable($from)` as an uncaught 500, and an enormous span making its per-day
     * zero-fill loop run for millions of iterations. Normalizing matters because PHP accepts
     * inputs ('next tuesday', '01/02/2026') that the database would cast differently — or not at
     * all — against the `day` date column; only the normalized values may reach AnalyticsQuery.
     *
     * @return array{string, string}|Response
     */
    private function normalizeRange(string $from, string $to): array|Response
    {
        try {
            $fromDate = new \DateTimeImmutable($from);
            $toDate = new \DateTimeImmutable($to);
        } catch (\Exception) {
            return Response::error('from and to must be valid dates.', 422);
        }
        if ($fromDate > $toDate) {
            return Response::error('from must be on or before to.', 422);
        }
        $maxDays = (int) config($this->context, 'analytics.max_range_days', 366);
        if ($maxDays > 0 && $fromDate->diff($toDate)->days > $maxDays) {
            return Response::error(sprintf('Date range too large (max %d days).', $maxDays), 422);
        }
        return [$fromDate->format('Y-m-d'), $toDate->format('Y-m-d')];
    }
}
