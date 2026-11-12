# 0012: Isolated Lighthouse measurements

Status: accepted, 2026-11-11. Supersedes ADR 0006's decision to run performance
measurements in parallel on one host; its tools, coverage and quality bar remain.

## Context

Hosted complete qualification reported performance 90 and median total blocking
time 411 ms on Reference Studio's people template. The same artifact had passed
earlier qualification. The audit launched three Lighthouse instances on the same
host, each using a separate Chromium process. Separate browser processes still
compete for the host's CPU, memory and network resources.

[Lighthouse's variability guidance](https://github.com/GoogleChrome/lighthouse/blob/main/docs/variability.md)
explicitly prohibits concurrent Lighthouse measurements on the same machine.
Its suggested way to scale measurements is separate hosts.

## Decision

The public-site audit measures one template at a time using one browser. Each
template retains the configured repeated performance samples and their median.
The existing `--concurrency=1` argument remains accepted; greater values and
malformed overrides fail before artifact, server or browser effects begin.
The default is serial, independent of host CPU count.

Keep performance 95, TBT 200 ms, every other threshold, all templates, HTML,
behaviour, links and the deliberately degraded fixture unchanged. Installed
runtime, profile assets, generated public bytes and dependencies do not change.
Independent CI jobs may run on their own hosted virtual machines. The focused
PR audit includes the home, project and people templates. A manual
`public-site-full` check runs the complete public-site lane independently of the
rest of qualification and is never labelled the complete repository gate.

## Consequences and alternatives

Full qualification takes longer because its measurements share one host. Routine
PRs retain focused coverage. Lowering thresholds, retrying until green or dropping
the failing template would weaken the product bar and are rejected. Limiting only
the number of parallel instances according to CPU count still permits competing
measurements and contradicts upstream guidance. Silently ignoring an explicit
parallel override is rejected because it misstates the operator's request.

## Proof

Compare the same compiled fixture with the old parallel collector and serial
collector under the same bounded CPU affinity, retaining per-run reports outside
Git. Verify all samples and templates still run, the measured bar is unchanged,
and the hostile fixture is rejected. CLI tests prevent parallel overrides from
returning. Qualify the public-site lane and complete gate on the hosted runner.
