# Probe: a governed shared mailbox inside Nextcloud Mail (2026-11-08)

**Question:** can a governed shared mailbox run entirely inside Nextcloud, through the
Mail app, with no IMAP credentials in Taskpath and the same split as the intake folder
(Taskpath advises, the app acts)?

**Setup:** local Nextcloud 35.0.2, Mail 5.13.1, GreenMail 2.1.3 as a disposable IMAP/SMTP
server. A shared `info@` account owned by admin, delegated to bob through Mail's own
delegation; carol without access. A throwaway listener in the Taskpath app (branch
`spike/mailbox-probe`, not for main) logged Mail's events with timestamps. All mail
stayed on the local test server.

## Answers

| # | Question | Result |
|---|---|---|
| 1 | Does an app hear about new mail? | **Yes, as soon as Mail syncs.** Both `OCA\Mail\Events\NewMessagesSynchronized` (account, mailbox, messages) and `NewMessageReceivedEvent` (IDs, subject, RFC Message-ID, thread root; also a webhook event) reach a listener in our app within milliseconds of a sync. **But Mail's background sync is not a dependable trigger:** default interval 1 hour, 15 minutes if the user opened Mail in the last day, configurable to at least 5 minutes (`app.mail.background-sync-interval`); in this setup the job was not picked up again for 10+ minutes despite cron every 10 seconds. A forced sync (`occ mail:account:sync`) fires the events at once. Events also fire for the Sent mailbox: filter on the inbox. |
| 2 | Can we read the full message, as the person, in a delegated mailbox? | **Yes.** OCS: `account/list` (bob sees info@ with `isDelegated: true`, carol sees nothing), `ocs/mailboxes`, `ocs/mailboxes/{id}/messages`, `message/{id}` (from, to, body, attachments, plus `hasAiGeneratedHeader`, `phishingDetails`, `isSenderTrusted`), `message/{id}/raw`, attachments. Carol gets 404. (`message/{id}` failed on single-part mails because of a GreenMail bug in partial FETCH; multipart and raw work.) |
| 3 | Can a person send a reply from info@ after approval? | **Yes.** `POST message/send` as bob with `fromEmail: info@…` and `references: <Message-ID>`: delivered immediately (not queued), `In-Reply-To`/`References` set, copied to info@'s Sent. Mail refuses to send without a configured Sent mailbox. Rate limit 5 per 100 s per user. |
| 4 | Do Mail's permissions match ours? | **Delegation is per account** (a whole mailbox, not folders or messages), checked on every API route. A delegated send is reported as a critical action ("bob sent a message on behalf of admin") through `CriticalActionPerformedEvent`; admin_audit logs it at info level (not visible with the default warning log level). |
| 5 | How stable is this? | Events, OCS routes and delegation are **the Mail app's**, documented but not a server contract (the server's own Mail Provider interface can only send). This is the question for Nextcloud. |

## What it means for a design

- The governed loop fits: new mail event → Taskpath drafts with sources → a person
  approves on the Taskpath page → the app sends through Mail as that person, from the
  shared address, in the thread. No IMAP passwords leave Nextcloud.
- **Don't rely on Mail's background sync for latency.** The app should trigger a sync
  itself for the shared account (on its own schedule and when the Taskpath page is open),
  as the intake folder does for files. That means calling Mail's sync from our app:
  an internal API, to confirm with Nextcloud.
- Who may approve a reply: Mail's delegation already says who acts for info@. Like 0020,
  the app should ask Mail rather than keep its own list.
- The audit trail names the person: Mail's own critical-action event plus ours.

## Open

- Why the background SyncJob wasn't picked up in this setup (cron ran every 10 s). Worth
  one more look before blaming Mail; irrelevant if the app triggers syncs itself.
- A supported way for a third-party app to trigger a Mail sync, and whether the OCS
  routes and events are meant to stay stable (question for Nextcloud).
- Behaviour against a production-grade IMAP server (GreenMail lacks some extensions Mail
  uses for efficient sync).
