# Internship messaging foundation (Module 9A)

## Existing schema

The existing migrations remain the source of truth. `conversations` belongs to an internship; `conversation_participants` has a composite primary key on conversation/user; `messages` belongs to a conversation and sender. Existing foreign keys and the conversation/sent-time index are reused. Text is stored in `content`, timestamps in `created_at`, `joined_at` and `sent_at`. No type or updated timestamp column is needed for immutable messages. No migrations or dependencies were added.

## Lifecycle and authorization

APPROVED and ACTIVE internships permit opening conversations and sending text. COMPLETED permits only reading existing conversations and history. DRAFT, SUBMITTED, UNDER_REVIEW, REVISION_REQUIRED and REJECTED are inaccessible.

Every request requires an active authenticated account with a valid role profile. Students must own the internship. Supervisors must be directly assigned, belong to the internship company, and have an approved profile and an active approved company. Coordinators must be directly assigned. Administrators are excluded. Reading and sending also require stored conversation membership; guessed foreign IDs return 404.

Membership is an immutable snapshot. Reassignment revokes the former assignee's access and does not give the replacement access to legacy conversations. The replacement can open a new eligible conversation; history remains intact for remaining authorized members. An ineligible counterpart does not prevent another eligible member from reading historical evidence.

## API

All paths below are prefixed with `/api`:

| Method | Path | Input |
| --- | --- | --- |
| GET | `/messaging/conversations` | `page`, `per_page` (default 15, maximum 50) |
| GET | `/messaging/conversations/{conversation}` | None |
| POST | `/internships/{internship}/conversations` | `type`: `student_supervisor`, `student_coordinator`, or `group` |
| GET | `/messaging/conversations/{conversation}/messages` | `cursor`, `per_page` (default 25, maximum 50) |
| POST | `/messaging/conversations/{conversation}/messages` | `content` |

Creation and reuse return 200. Missing required assignments and completed write attempts return 409; invalid input returns 422; guests receive 401 for JSON requests and excluded/ineligible accounts receive 403. Unknown fields, participant IDs, sender IDs and uploads are rejected.

Conversation creation locks the internship row inside a transaction before finding an exact participant set or creating it. All application conversation writers must use this service: the schema has no unique constraint on an entire participant set, so privileged direct SQL is outside this guarantee. Concurrent direct and group openings are covered by separate-process PostgreSQL tests.

Messages are trimmed Unicode text with preserved internal line breaks, limited to 2,000 characters. Blank text and disallowed control characters are rejected. HTML-like text remains literal text; the future UI must render escaped text, never raw HTML. Sending is limited to 60 requests per minute. Sender identity comes exclusively from authentication. Repeated message POSTs are distinct sends, not idempotent message retries.

History uses ascending `(sent_at, id)` cursor pagination, including deterministic timestamp ties. Timestamps are ISO 8601 with offsets and inserted with an explicit offset. Resources expose only necessary IDs, participant/sender names, content and timestamps; no email, credentials or private storage paths. No message modification or deletion endpoints exist. No notification records are used as message history.

## Module 9C real-time plan — not implemented

Laravel's broadcasting infrastructure is available, but Reverb, broadcasting configuration, channel routes and frontend Echo are not installed/configured. Module 9C should use Laravel Reverb with Echo and its compatible browser transport, rather than treating manual refresh as real-time delivery.

1. Install/configure compatible first-party Reverb and frontend Echo transport only in 9C. Register a Sanctum-authenticated broadcast authorization endpoint using active-account and messaging-role middleware.
2. Authorize private `messaging.conversation.{id}` subscriptions through the same current assignment, eligibility and membership checks as the HTTP API. Never use public or administrator-accessible internship channels.
3. Broadcast a safe message-created payload only after the message transaction commits. Transport failure must not undo persisted history. Do not log message bodies or credentials.
4. Explicitly revoke/revalidate existing subscriptions after assignment, account or eligibility changes: private-channel authorization at connection time alone does not prevent a stale connection from receiving later events.
5. Fetch cursor history on initial connection/reconnection and deduplicate events by message ID. Incoming authorized messages must appear without manual refresh (KF 17); PostgreSQL remains authoritative for persistent history (KF 18).
6. Document the local Reverb process, allowed frontend origin and environment-only host/port/app keys. Production requires TLS/WSS and appropriate process/queue supervision if queued broadcasting is selected.
7. Test all three roles, administrator/guest/foreign denial, stale subscription revocation, transaction rollback, reconnect recovery and event/history ordering.

Official references: [Laravel broadcasting](https://laravel.com/docs/12.x/broadcasting), [Laravel Reverb](https://laravel.com/docs/12.x/reverb), and [Sanctum](https://laravel.com/docs/12.x/sanctum).

No frontend chat, WebSocket server, attachments, message editing/deletion or automatic messaging notifications are included in 9A. Development migrations must not be run without explicit authorization.
