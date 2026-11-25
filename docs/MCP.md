# Convomail MCP tools

Spec for exposing Convomail to AI assistants (Claude, the Dolibarr assistant, any MCP client) through the MCP server of Dolibarr's AI module. Status: **MVP, for discussion** (branch `mcp-tools-mvp`).

- Roadmap item: "Mailbox tools for core's AI assistant" ([ROADMAP.md](ROADMAP.md))
- Pattern followed: `doli-metamessenger`'s MCP tools (Instagram/Messenger DMs), which run in production on Dolibarr 24.0.3

## 1. Why

The AI module's MCP server already lets an assistant read customers, invoices, stock, calls and DMs. Email is the main customer channel it can't see. Convomail owns the email data model (accounts, access grants, the message ↔ event link), so the tools belong here rather than in core's AI module.

## 2. How it plugs in

| Piece | Where |
|---|---|
| Hook context `aimcp` | `modConvomail::$module_parts['hooks']` |
| `addMcpTools` hook | `ActionsConvomail::addMcpTools()`: returns `array(array(new ConvomailMcpTools(...)))` |
| Tools | `class/convomailmcptools.class.php`, extends core's `McpTool` |

- The hook does nothing when `McpTool` doesn't exist, so Dolibarr versions without the MCP server are unaffected.
- One list of tool instances per module: 24.0.x skips a bare instance without logging why.
- 24.0.x doesn't enforce `getRequiredRights()`, so `execute()` checks the right itself. Develop does both.
- Errors use the same two shapes as metamessenger: `['error' => …]` where core has `McpTool::NO_WRITE` (develop), an `isError` content block otherwise (24.0.x turns a plain `error` into "Internal server error").
- Existing installs: the hook list is rewritten on the next version bump (`upgradeSchema()`), or by disabling and enabling the module.
- If the admin saved an explicit tool list in the AI module's MCP server setup (`AI_MCP_SERVER_ALLOWED_TOOLS`), the new tools must be ticked there.

## 3. Naming

Every tool name starts with **`convomail_`**.

Core registers its own tools first and silently skips a module tool whose name is already taken. Core develop already has email tools from the email cleaner (`get_email_thread_context`, `get_email_handoff_payload`), and the API bridge builds names at run time. A prefix is the only way to be sure a name stays ours. Metamessenger's unprefixed names (`list_accounts`, `search_messages`, …) should get the same treatment in a separate change.

## 4. MVP tools (read only)

| Tool | Does | Main parameters |
|---|---|---|
| `convomail_list_accounts` | Mail accounts the user can read here: id, label, access (`own` / `shared`), can_send | none |
| `convomail_search_emails` | Sent and filed emails, newest first: email_id, date, direction, account, From/To/Cc, third party, project. Subjects in an untrusted block | `query`, `thirdparty_id`, `project_id`, `account_id`, `direction` (any/in/out), `within_days` (default 90, max 365), `limit` (default 20, max 50), `offset` |
| `convomail_get_thread` | One email and its conversation (up through In-Reply-To, then all replies), oldest first, with each body as plain text cut at 4,000 characters, in an untrusted block | `email_id` |
| `convomail_get_email` | One email in full: headers, the whole body as plain text (cut at 20,000 characters, in an untrusted block), and the list of its filed attachments (file name, size, type, `has_text`) | `email_id` |
| `convomail_get_attachment` | One attachment of an email: file name, size, type, and — for a PDF or a plain-text file (txt/csv/…) — the text read from it (cut at 20,000 characters, in an untrusted block). Images and other binary files return details but no text | `email_id`, `filename` |

### What "email" means in the MVP

Only messages Convomail **sent or filed**: rows of `llx_convomail_message` joined to their `llx_actioncomm` event. That is what Dolibarr already knows about a customer. The tools don't read the live mailbox, so unfiled mail doesn't appear (the tools say so in their answers).

Attachments are the files that were copied into Dolibarr when the email was filed (the agenda event's documents, `{agenda}/{eventid}/`); the mailbox copy may hold more. Only a file the directory listing returns is opened — the file name from the model is matched against that listing, never used to build a path.

## 5. Access

- Right `convomail/account/read` for every tool.
- Mailboxes: the user's own accounts and the shared mailboxes they were granted (`fetchAllUsableBy()` filtered to `readAccess()` in `own` / `shared`).
- **Not** mailboxes reached through `readall` or `readhierarchy`. In the browser those need a reason first (`convomail_read_reason_missing()`), kept in the PHP session; an MCP request has no session to carry it. See open question 2.
- `account_id` narrows, never widens: an account the user can't read is an error, not "all accounts".
- `convomail_get_thread`, `convomail_get_email` and `convomail_get_attachment` answer the same for a missing email and for someone else's, so ids can't be probed (`resolveReadableMessage()`, the same account + entity test).
- Thread walking stays inside the readable accounts and the entity, and stops at 30 messages.
- `convomail_get_attachment` opens only a file the event folder's listing returns (matched by `ConvomailFiler::cleanName()`); the name from the model never builds a path. Extensions that are never stored (executables, scripts, SVG — `ConvomailFiler::refusedExtension()`) yield no text, and a file over the filing size cap (`CONVOMAIL_FILE_MAX_SIZE`) is not read.

## 6. Load

The concern: Dolibarr often runs on shared hosting with CPU and process limits.

- **No IMAP.** Every tool is SQL on Dolibarr's own tables; attachments are read from the agenda event's own document folder on disk. No tool opens a mail-server connection — the collectors keep doing the mailbox work on their own schedule.
- Search matches header columns only (subject, From, To, Cc, third-party name), never the HTML body, and always within a date window (≤ 365 days) and a page (≤ 50).
- Lookups go through existing indexes (`uk_convomail_message_account_msgid`, `idx_convomail_message_fk_actioncomm`), except replies by `in_reply_to`, which has no index: see open question 4.
- Bodies are read once per thread, in one query. `convomail_get_email` reads one body and lists one folder; `convomail_get_attachment` reads one file, capped at `CONVOMAIL_FILE_MAX_SIZE` and 20,000 characters of text (PDFs: 5 pages).

## 7. Untrusted content

Subjects and bodies are written outside Dolibarr. They go back between random markers with a line saying they are data, not instructions (same wording pattern as metamessenger). Addresses and Dolibarr's own fields (third party, project, dates) stay as plain fields.

## 8. Later phases

| Phase | Tools | Notes |
|---|---|---|
| 2: live mailbox, read | `convomail_mailbox_summary` (unread per account), `convomail_search_mailbox` (live IMAP header search), `convomail_get_message` (unfiled message by account/folder/uid) | Uses `ConvomailMailboxClient` only. Needs hard limits and a per-request connection budget: IMAP is the expensive part |
| 3: write, drafts | `convomail_draft_reply` (saved to the account's Drafts folder or as a note, never sent), `convomail_file_email` (Log to… a third party/project) | Confirmation preview through core's gate where it exists (develop), signed one-time token on 24.0.x, as metamessenger does |
| 4: send | `convomail_send_reply` | Behind a setup switch, off by default, `canSend()`, exact text approved by a person |

## 9. Open questions

1. **Core's MCP contract.** Which of `getRequiredRights()`, `writeConfirmationPreview()`, `writePrecheck()` and `NO_WRITE` will 24.0.x get, and when? It decides how much compatibility code the write phases carry.
2. **Reading through `readall` / `readhierarchy`.** Leave them out of MCP (MVP), or allow them with a reason passed as a tool argument and logged?
3. **Collector-filed mail.** Events created by core's Email Collector (including Convomail's own `CONVOMAIL<id>_IN/_OUT` collectors) have no `llx_convomail_message` row, so the MVP doesn't see them. Add the link row when a Convomail collector files, or search `llx_actioncomm` (code `AC_EMAIL_IN`) by event owner and agenda rights?
4. **Index on `in_reply_to`.** Add `idx_convomail_message_in_reply_to` in the next `update_X.Y.Z.sql`?
5. **Naming.** Is `convomail_` the prefix we want, and should core document a prefix rule for module tools?
