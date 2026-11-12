# 0008: Integration surface

Status: accepted, 2026-10-14

Decision owner: Utsuri Foundry, SL.

The context below records the situation at the decision date. Consult current
operating guides for available features.

## Context

Client projects increasingly place Portable CMS next to other systems: a shop,
a CRM, an ERP, translation vendors and an integration hub that moves data
between them. Portable has no API, no outbound events and no non-human
credentials today. Several properties of Portable must survive any integration:

- Installations run on qualified conventional PHP hosting without a persistent
  process; background work runs in the bounded worker, usually started by cron.
- "Published" on a record is an intent. The public website changes only when a
  person approves an exact preview and the worker applies that release.
- A release activates a compiled artifact. Release identities are assigned at
  approval, after compilation, and a rollback is a new release that activates
  an older artifact again.
- The public website is static, independent of the CMS runtime and database,
  and its content security policy allows its own origin only.

No client has yet committed to a specific integration, so this record fixes the
rules any integration work must follow and leaves protocols, formats and limits
to the tasks that implement them.

## Decision

1. **The integration hub stays outside Portable.** Queues, handlers, connectors,
   field mappings, synchronization and the cross-system log are a separate,
   reusable module. Portable offers a surface for it and does not grow plugins
   or connectors for particular systems.
2. **What went live is the public event.** Other systems learn about public
   changes from activations (a release going live, including a rollback), never
   from a record's publication status. Record-level events serve draft and
   translation flows and never claim that anything is public.
3. **Snapshots and activations are separate identities.** A content snapshot is
   the immutable content of one compiled artifact, identified from its content
   and never naming a release. An activation is one release going live and
   names the snapshot it activated and the one it replaced. Changes between
   activations are computed from those two snapshots.
4. **Public exposure is opt-in and fails closed.** Anything published for
   machines beside the website contains only record types and fields a profile
   declares; a field nobody declared, including one added later, never leaves
   the administration. Such output is reviewed through the exact preview like
   any other public change.
5. **Change order is assigned in commit order.** Cursors and event positions use
   one site sequence whose numbers become visible in order, never timestamps or
   values that can commit late.
6. **Machine access is separate from people.** Integrations authenticate with
   their own scoped, revocable tokens. Machine endpoints accept only tokens and
   ignore cookies; administration routes ignore tokens.
7. **Integrations never publish.** Integration writes create revisions only.
   They never approve a preview, apply a release or change publication status;
   a person approves every release.
8. **Live outside data loads in the browser, behind a reviewed origin policy.**
   Current data from another system (such as price and stock) is fetched by
   reviewed profile code in the visitor's browser, so it needs no release. Each
   allowed origin is declared by the profile and applied consistently to public
   delivery and previews; only credentials designed to be public may reach a
   page.

## Consequences and risks

Integrations become possible without weakening static delivery or approval,
and every integration task inherits these rules. Machine access and outbound
traffic are security work: tokens, endpoint validation and rate limits are part
of each feature, not later hardening. Keeping older public snapshots available
for consumers keeps removed content reachable for a while, so retention needs an
explicit purge. Each allowed external origin widens the public page policy.

A client proposal that promises integration with a shop or a CRM must name the
flows it includes. The integration tasks deliver the surface those flows use,
not the flows, connectors or mappings.

## Rejected alternatives

- Building the integration hub into Portable: a CMS that becomes the hub turns
  into a plugin platform, and the hub is reused across CMSs and clients.
- Events for record publication status: consumers would act on content that is
  not live.
- A public request-time read API: it exposes the CMS runtime and database to
  public traffic and contradicts a static public website.
- Automatically approved releases for integration writes or external data: they
  remove the explicit approval that defines Portable.

## Implementation status

This decision defines constraints, not a complete integration API. The current
[profile authoring guide](../../development/profile-feed.md#public-content-feed)
and [publication workflow](../../development/content-feed.md#public-content-feed)
describe the public content feed. Additional integration capabilities require
their own implementation, documented protocol and qualification; this decision
alone does not make them available.
