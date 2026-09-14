// Apply-to-paint instrumentation (visual builder spec §3.5): four performance marks per
// apply — input, request, response, paint — and per-path samples (fragment swap versus
// whole-page refresh) with the medians and p95s the activation gate reads, plus the fallback
// count. Pure timing over an injectable clock; `performance.mark` rides along when present.

export type ApplyPath = 'fragments' | 'page'

export type ApplyMark = 'thallo:input' | 'thallo:request' | 'thallo:response' | 'thallo:paint'

export interface ApplyPathSummary {
  path: ApplyPath
  count: number
  fallbacks: number
  inputToPaint: { median: number; p95: number }
  requestToPaint: { median: number; p95: number }
}

/** The nearest-rank quantile of a sample (0 for an empty one). */
export function quantile(values: number[], q: number): number {
  if (values.length === 0) return 0
  const sorted = [...values].sort((a, b) => a - b)
  const rank = Math.min(sorted.length - 1, Math.max(0, Math.ceil(q * sorted.length) - 1))
  return sorted[rank]!
}

export function createApplyMetrics(now: () => number = () => performance.now()) {
  const samples: Record<ApplyPath, { inputToPaint: number[]; requestToPaint: number[] }> = {
    fragments: { inputToPaint: [], requestToPaint: [] },
    page: { inputToPaint: [], requestToPaint: [] },
  }
  const fallbacks: Record<ApplyPath, number> = { fragments: 0, page: 0 }
  let input: number | null = null
  let request: number | null = null

  function mark(name: ApplyMark): number {
    const at = now()
    try {
      performance.mark?.(name)
    } catch {
      // A host without the User Timing API: the timestamps still measure.
    }
    return at
  }

  return {
    /** The first input of a burst starts the input-to-paint clock; later inputs join it. */
    input(): void {
      const at = mark('thallo:input')
      if (input === null) input = at
    },
    request(): void {
      request = mark('thallo:request')
    },
    response(): void {
      mark('thallo:response')
    },
    /** The stage painted the apply through `path`: record and reset the clocks. */
    paint(path: ApplyPath): void {
      const at = mark('thallo:paint')
      if (input !== null) samples[path].inputToPaint.push(at - input)
      if (request !== null) samples[path].requestToPaint.push(at - request)
      input = null
      request = null
    },
    /** A fragment patch the stage refused, answered by a whole-page refresh. */
    fallback(path: ApplyPath): void {
      fallbacks[path] += 1
    },
    summary(): ApplyPathSummary[] {
      return (['fragments', 'page'] as ApplyPath[]).map((path) => ({
        path,
        count: samples[path].requestToPaint.length,
        fallbacks: fallbacks[path],
        inputToPaint: {
          median: quantile(samples[path].inputToPaint, 0.5),
          p95: quantile(samples[path].inputToPaint, 0.95),
        },
        requestToPaint: {
          median: quantile(samples[path].requestToPaint, 0.5),
          p95: quantile(samples[path].requestToPaint, 0.95),
        },
      }))
    },
  }
}

export type ApplyMetrics = ReturnType<typeof createApplyMetrics>
