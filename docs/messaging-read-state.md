# Message read state and notifications (Module 9B)

## Schema and cursor

The existing participant table already has `last_read_at`. Its timestamp alone cannot disambiguate messages sharing a second, so the additive migration adds nullable `last_read_message_id`, referencing `messages.id`, and an index on `messages(conversation_id, id)`. No tables are duplicated. The development migration has **not** been run.

Existing participant cursors start null: existing incoming messages therefore count as unread. Legacy timestamps are retained but are not silently converted into potentially incorrect read positions. The cursor follows immutable message IDs within one conversation. Message sends are serialized by the internship row lock; acknowledgement uses that same lock and a conditional forward-only update. The API also verifies that the referenced message belongs to the conversation. The foreign key prevents dangling cursors, while same-conversation validation is enforced by the service.

`last_read_at` records acknowledgement time, not a message boundary. It changes only when the cursor advances. Sender-owned messages are excluded from unread counts without automatically advancing read progress past incoming messages.

## API contract

`PATCH /api/messaging/conversations/{conversation}/read` requires:

```json
{"last_read_message_id": 123}
```

The client must provide the last message actually viewed; omission is rejected. There is no implicit "read everything now" behavior. New arrivals beyond that ID remain unread, even if they arrive during acknowledgement. Future, nonexistent and foreign-conversation cursors return 422. Foreign users receive 404; guests receive 401 for JSON requests, and administrators/ineligible accounts receive 403. Repeated or older acknowledgements preserve the current position and acknowledgement timestamp. The response is the updated conversation resource, including `read_state` and `unread_messages`.

`GET /api/messaging/unread-count` returns:

```json
{"data":{"unread_messages":5,"unread_conversations":2}}
```

The planned navigation badge uses **unread conversations**, not total messages. Both aggregates are calculated in SQL over currently authorized participant conversations. Group users have independent cursors. Fetching lists, details or history never marks anything read.

Conversation lists accept `filter=all|unread|read`, alongside existing bounded page pagination. Empty conversations are included in `read`. Each item adds `unread_messages`, `has_unread_messages`, current participant `read_state`, and nullable `latest_message` with ID, a maximum 200-character literal-text preview, timestamp and sender ID/name. No email, private profile data or evaluation fields are included. Latest message is selected by ID; lists sort by its timestamp (or conversation creation time when empty), then conversation ID descending. Pagination is deterministic for unchanged data; active conversations can move between pages as new messages arrive.

Participant, internship, latest-message/sender and read-state relations are eager loaded. Unread counts use correlated SQL rather than per-item queries. Query-count regression tests verify that list query count does not grow with conversation count.

## Notifications

Successful sends reuse the Module 8 notification table, payload validation, safe-resource contract and transaction semantics. Eligible non-sender members receive at most one `message_received` notification per send. Inactive, unapproved, administrator, unrelated or reassigned users are excluded. No message body or sensitive user information is included:

```json
{
  "category":"message_received",
  "title":"Mesazh i ri",
  "message":"Keni pranuar një mesazh të ri.",
  "action_url":"/messages/conversations/123",
  "related_type":"conversation",
  "related_id":123
}
```

The backend and frontend allowlists accept only the positive numeric conversation route above, without query strings, fragments, escaping, external hosts or alternate protocols. Module 9C must implement this exact authenticated route and enforce API authorization; accepting an internal URL is not an authorization grant. No messaging route or chat UI exists yet.

Delivery happens in a nested transaction/savepoint inside message persistence. An outer rollback removes the message, notifications and any read advancement. A delivery failure rolls back the entire notification batch for that message, including any earlier recipient insert, reports the failure and preserves the valid message. There is no automatic retry, queue or catch-up delivery. Separate sends are separate messages and notifications; transport retries are not message-idempotent.

Conversation read state and notification read state are deliberately independent. Viewing a conversation does not mark its notification records read, and marking a notification read does not acknowledge unseen messages.

## Lifecycle and verification boundaries

The Module 9A eligibility and assignment rules remain authoritative. APPROVED/ACTIVE permit sending; COMPLETED allows historical acknowledgement and reading but no sending or creation; all unapproved/rejected states remain inaccessible. Reassignment does not grant legacy membership to a new assignee.

PostgreSQL tests cover independent direct/group progress, timestamp ties, stale/new arrivals, invalid cursors, totals, filters, ordering, bounded pagination, safe previews, constant query count, recipient isolation, savepoint failure and rollback. Separate-process tests exercise concurrent creation, sends and high/low read acknowledgements in both arrival orders. Fixtures are restricted to `internflow_testing` and cleaned up; child processes are stopped in `finally` blocks.

Only URL-safety integration changed in the frontend; no chat UI, real-time infrastructure, attachments, reactions, message editing/deletion or new dependencies were introduced. The additive development migration requires explicit authorization before deployment/use against development data. Module 9C has not started.
