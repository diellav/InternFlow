# Module 9C architecture decision

Use Laravel Reverb (compatible with PHP 8.2 / Laravel 12), Laravel Echo and pusher-js. Reverb provides self-hosted WebSockets; Echo uses its Pusher-compatible protocol. These are the only new direct dependencies. PostgreSQL and the existing authorized HTTP API remain authoritative.

Authenticated Sanctum broadcasting authorization reuses messaging role/profile/assignment/membership checks. Conversation channels are private and recipient-scoped: `messaging.conversation.{conversationId}.user.{userId}`. A private `messaging.user.{userId}` channel invalidates the user's inbox, unread badge and notification count, including newly opened conversations. Neither channel permits another user's subscription or administrators.

After the outer database transaction commits, current recipient eligibility is checked again before dispatch. Broadcast only an empty change signal, not message bodies, names, IDs or private data. The frontend then fetches authorized HTTP resources. This protects content even if a previously authorized socket remains subscribed after reassignment or account deactivation. Logout/user switching disconnects Echo. Reconnect refetches counts/list/latest history and reauthorizes active subscriptions.

Publishing is synchronous and failure-isolated after commit: persistence survives an unavailable transport. No queue or automated server delivery retry is introduced; reconnect/focus reconciliation recovers persistent history. A missing Reverb environment configuration must be shown as offline rather than pretending real-time delivery is operational.

Add narrowly scoped backend support for authorized name/internship search, bounded eligible internship choices and `latest=true` history. Preserve the existing default chronological cursor behavior. The UI loads the latest page first, reverses it for chronological display, prepends older pages while preserving scroll, and merges refreshed latest pages by message ID.

Never automatically retry an ambiguous failed message POST: it may already have committed. Retain composer text, reconcile history, and ask the user to check before another explicit send. Mark read only through the maximum actually displayed message ID when the active conversation is visible and scrolled to the bottom.

Development migrations remain forbidden. Browser verification must use a separate testing-database server, isolated fixtures, session cookie and temporary Reverb credentials; no development records or existing user browser sessions may be changed.

Official references: [Reverb](https://laravel.com/docs/12.x/reverb), [broadcasting](https://laravel.com/docs/12.x/broadcasting). No Module 9D work is included.

## Configuration and running locally

Generate distinct Reverb app ID/key/secret values outside version control. Set backend `BROADCAST_CONNECTION=reverb`, `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST`, `REVERB_PORT` and `REVERB_SCHEME`. Only the public app key goes into frontend `VITE_REVERB_APP_KEY`; configure its host, port and scheme to match. Never put the app secret in frontend variables. Restart the relevant processes after changing environment configuration.

`php artisan reverb:start` starts the WebSocket server. `REVERB_SERVER_HOST` defaults to loopback, not public exposure. Allowed origins default to the configured frontend hostname; `REVERB_ALLOWED_ORIGINS` can explicitly list comma-separated hostnames. No wildcard origins or client-originated broadcasting are enabled. Production must use HTTPS/WSS and process supervision; production deployment is not verified by local testing.

Backend and frontend `.env.example` files contain empty key placeholders only. Actual development `.env` files are untouched. Applying the pending 9B migration to development still requires explicit authorization. Without that migration and Reverb configuration, development messaging is not ready to run; an absent frontend app key is presented as offline.

## Implemented contracts

- Routes: `/messages` and `/messages/conversations/:conversationId`, inside existing authentication/role guards and role-specific layouts. Administrator navigation is unchanged.
- Server search: `GET /api/messaging/conversations?search=...` searches only already authorized conversation participants and internship position/company context. Search is limited to 100 characters and escapes SQL wildcard characters. It does not search a global user directory.
- Creation options: `GET /api/messaging/internships` uses bounded pagination and returns only assigned/owned APPROVED/ACTIVE internship context and eligible canonical conversation types. Creation continues through the existing idempotent POST endpoint; IDs are not participant selections.
- Latest history: `latest=true` reverses cursor traversal while leaving default chronological history unchanged. The UI reverses/merges by timestamp and ID, prepends older results and preserves scroll height. Reconnect follows missed pages until previous history overlaps.
- Broadcasting authorization: `POST /api/broadcasting/auth`, with API, Sanctum, active-account and messaging-role middleware. Foreign membership, another user's channel, invalid profiles, ineligible supervisors and administrators are denied.
- Read acknowledgement: only successfully rendered messages in a visible conversation at the bottom are acknowledged, using the maximum displayed message ID. Reading older history or a hidden page does not acknowledge new arrivals. No list fetch acknowledges messages.
- Incoming signals invalidate unread conversation counts and the existing notification provider. Message-read and notification-read states remain separate. Backend notification creation is not duplicated in the frontend.
- Shared state is keyed by user ID/role under the existing authenticated provider. Requests use abort/sequence guards; logout disconnects sockets. Deferred conversation subscriptions avoid a child-before-provider initialization race on direct entry.
- A send with an ambiguous network/server failure is not automatically repeated. Text is retained and sending is disabled until history confirms delivery or the user explicitly discards the held text. This is not server-side message POST idempotency.

## Dependencies

Direct additions: Laravel Reverb 1.12.0, Laravel Echo 2.6.1 and pusher-js 8.6.0. Composer installed their required transport/event-loop dependencies; no unrelated existing package versions were upgraded. npm reported a pre-existing development dependency advisory for source-map-js 1.2.1, also present in the original lockfile. It was not broadly auto-fixed as part of messaging.

## Module 9C file inventory

Created (17):

- `backend/config/broadcasting.php`, `backend/config/reverb.php`, `backend/routes/channels.php`
- `backend/app/Modules/Messaging/Events/MessagingChanged.php`
- `backend/app/Modules/Messaging/Services/MessagingBroadcastService.php`
- `backend/tests/Feature/Messaging/MessagingRealtimeTest.php`
- `backend/tests/Support/MessagingBrowserFixture.php`
- `frontend/src/features/messaging/api/messagingApi.js`
- `frontend/src/features/messaging/realtime/messagingRealtime.js`
- `frontend/src/features/messaging/context/MessagingContext.js`
- `frontend/src/features/messaging/providers/MessagingProvider.jsx`
- `frontend/src/features/messaging/components/MessagesNavigation.jsx`
- `frontend/src/features/messaging/pages/MessagesPage.jsx`
- `frontend/src/features/messaging/styles/messaging.css`
- `frontend/src/features/messaging/MessagingApi.test.js`, `frontend/src/features/messaging/Messaging.test.jsx`
- `docs/messaging-realtime.md`

Modified (19), in addition to preserved 9A/9B work:

- `backend/.env.example`, `backend/bootstrap/app.php`, `backend/composer.json`, `backend/composer.lock`, `backend/routes/api.php`
- `backend/app/Modules/Messaging/Controllers/MessagingController.php`
- `backend/app/Modules/Messaging/Requests/ListMessagingRequest.php`
- `backend/app/Modules/Messaging/Resources/ConversationResource.php`
- `backend/app/Modules/Messaging/Services/MessagingService.php`
- `frontend/.env.example`, `frontend/package.json`, `frontend/package-lock.json`
- `frontend/src/app/layouts/StudentLayout.jsx`, `frontend/src/app/layouts/SupervisorLayout.jsx`, `frontend/src/app/layouts/CoordinatorLayout.jsx`
- `frontend/src/app/router/router.jsx`, `frontend/src/features/auth/guards/RequireAuth.jsx`
- `frontend/src/features/notifications/providers/NotificationProvider.jsx`, `frontend/src/test/setup.js`
