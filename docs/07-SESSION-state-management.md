# 07 - Session & State Management

> **Cross-references:** [01-BRIDGE](./01-BRIDGE-agent-runtime.md) | [04-FLOW](./04-FLOW-message-pipeline.md) | [05-RESILIENCE](./05-RESILIENCE-failover-system.md) | [10-CONFIG](./10-CONFIG-model-selection.md)

---

## Overview

Sessions are the persistent memory of conversations. OpenClaw stores them as JSON files, supports branching (fork at any point), and uses compaction to manage context window limits.

```
~/.openclaw/sessions/
├── sess_abc123.json         # Main session file
├── sess_abc123.branch1.json # Branched conversation
├── sess_def456.json         # Another session
└── ...
```

---

## Session Key → Session ID Mapping

**File:** `src/gateway/sessions-resolve.ts`

Each incoming message has a **session key** (channel-specific identifier) that maps to a **session ID** (internal identifier):

```
Session Key (external):              Session ID (internal):
"telegram:12345"          ────→      "sess_abc123"
"discord:112233:445566"   ────→      "sess_def456"
"slack:T01:C02:1700.0"   ────→      "sess_ghi789"
```

### Resolution Flow

```
resolveSessionKeyFromResolveParams()
    │
    ├─ Check existing session map (sessionKey → sessionId)
    │   ├─ Found? → return existing session
    │   └─ Not found? → create new session
    │
    ├─ Apply multi-agent routing rules:
    │   routes: [
    │     { channel: "telegram", pattern: "group:*", agentId: "work" }
    │   ]
    │   → Different agents get different sessions
    │
    ├─ Resolve agent config for session:
    │   ├─ provider + model
    │   ├─ workspace directory
    │   └─ tool permissions
    │
    └─ Return { sessionId, agentId, provider, model, ... }
```

---

## Session File Format

### Pi Embedded Sessions

Sessions for the Pi Embedded Runner are managed by the `@mariozechner/pi-ai` SDK:

```
~/.openclaw/sessions/{sessionId}.json

{
  "id": "sess_abc123",
  "provider": "anthropic",
  "model": "claude-opus-4-6",
  "createdAt": 1700000000000,
  "updatedAt": 1700001000000,
  "turns": [
    {
      "role": "system",
      "content": "You are a helpful assistant..."
    },
    {
      "role": "user",
      "content": "Schedule a meeting tomorrow"
    },
    {
      "role": "assistant",
      "content": "I'll schedule that meeting...",
      "toolCalls": [
        {
          "id": "tc_1",
          "name": "calendar_create",
          "input": { "title": "Meeting", "date": "2025-01-02", "time": "15:00" }
        }
      ]
    },
    {
      "role": "tool",
      "toolCallId": "tc_1",
      "content": "Event created: Meeting at 3:00 PM"
    },
    {
      "role": "assistant",
      "content": "Done! I've scheduled your meeting for tomorrow at 3 PM."
    }
  ],
  "metadata": {
    "totalInputTokens": 5000,
    "totalOutputTokens": 800,
    "compactionCount": 0
  }
}
```

### CLI Sessions

CLI agents (claude, codex) manage their own sessions internally. OpenClaw tracks only the CLI session ID:

```typescript
// Session ID extraction from CLI JSON output:
sessionIdFields: ["session_id", "sessionId", "conversation_id", "conversationId"]
// For Claude CLI

sessionIdFields: ["thread_id"]
// For Codex CLI
```

The CLI session ID is stored and used for `--resume` on subsequent messages.

---

## Session Lifecycle

```
1. CREATE
   User sends first message on a channel
   → New session created with unique ID
   → Session file created on disk

2. ACCUMULATE
   Each message exchange appends turns
   → User messages, assistant responses, tool calls/results
   → Session file updated after each turn

3. COMPACT (when needed)
   Context window approaching limit
   → Older turns summarized into condensed text
   → Recent turns preserved intact
   → Session file rewritten with compacted history

4. BRANCH (optional)
   Fork conversation at any point
   → Create new session file with shared history up to branch point
   → Original session continues independently

5. DELETE (manual)
   User or admin deletes session
   → Session file removed from disk
```

---

## Compaction

**File:** `src/agents/compaction.ts`

When conversation history approaches the model's context window limit, compaction kicks in:

```
Context window check before each prompt:
    │
    ├─ Calculate current context size:
    │   system prompt + all turns + tool results + new message
    │
    ├─ Under limit? → proceed normally
    │
    └─ Approaching/exceeding limit? → compact
        │
        ├─ Step 1: Truncate large tool results
        │   ├─ Find tool results exceeding threshold
        │   └─ Replace with: "[Tool result truncated: was N chars]"
        │
        ├─ Step 2: Summarize old turns
        │   ├─ Keep last N turns intact (recent context)
        │   ├─ Summarize older turns into a compacted block:
        │   │   "[Previous conversation summary: User asked about X,
        │   │    assistant helped with Y, used tools Z...]"
        │   └─ Replace old turns with summary
        │
        ├─ Step 3: Save compacted session
        │   ├─ Update session file with new turn list
        │   └─ Increment compactionCount in metadata
        │
        └─ Step 4: Retry the original request
            └─ With reduced context, should now fit
```

### Compaction Retry

```typescript
// In the attempt loop (pi-embedded-runner):
const attemptCompactionCount = Math.max(0, attempt.compactionCount ?? 0);
autoCompactionCount += attemptCompactionCount;

// If context overflow detected:
// → compact session
// → retry with compacted context
// → track compaction count for logging
```

---

## Context Window Guard

**File:** `src/agents/context-window-guard.ts`

Proactive context window monitoring:

```
Before each API call:
    │
    ├─ Estimate total token count:
    │   tokens(systemPrompt) + tokens(conversationHistory) + tokens(newMessage)
    │
    ├─ Compare against model's context limit:
    │   ├─ Claude Opus: 200K tokens
    │   ├─ Claude Sonnet: 200K tokens
    │   ├─ GPT-5.3 Codex: 128K tokens
    │   └─ Gemini: varies
    │
    ├─ Under 80%? → proceed
    ├─ 80-95%? → warn, consider compaction
    └─ Over 95%? → mandatory compaction before proceeding
```

---

## Bootstrap Files

**File:** `src/agents/bootstrap-files.ts`

When a session starts or resumes, bootstrap files provide initial context:

```
resolveBootstrapContextForRun()
    │
    ├─ Search workspace for context files:
    │   ├─ CLAUDE.md          → Project instructions
    │   ├─ .claude/settings.json → Project settings
    │   ├─ .env               → Environment context (redacted)
    │   └─ Custom bootstrap files from config
    │
    ├─ Read and concatenate:
    │   contextFiles: [
    │     { path: "CLAUDE.md", content: "# Project\n..." },
    │     { path: ".env", content: "NODE_ENV=production\n..." }
    │   ]
    │
    └─ Inject into system prompt (see 01-BRIDGE)
```

---

## CLI Session Management

**File:** `src/agents/cli-session.ts`

For CLI backends (claude-cli, codex-cli), session management is different:

```typescript
// Session ID resolution for CLI:
function resolveSessionIdToSend(params: {
  backend: CliBackendConfig;
  cliSessionId?: string;       // Previously captured session ID
}): { sessionId: string | undefined; isNew: boolean }

// Cases:
// 1. First message → no cliSessionId → isNew: true
//    CLI creates new session, returns sessionId in output
//
// 2. Follow-up → cliSessionId from previous run → isNew: false
//    CLI resumes session with --resume {sessionId}
//
// 3. sessionMode: "always" → always send session ID
// 4. sessionMode: "existing" → only send on resume
```

### System Prompt Timing

```typescript
// System prompts are expensive; only send on first message:
function resolveSystemPromptUsage(params: {
  backend: CliBackendConfig;
  isNewSession: boolean;
  systemPrompt: string;
}): string | undefined

// systemPromptWhen: "first" → only include system prompt for new sessions
// systemPromptWhen: "always" → include every time
```

---

## Multi-Agent Sessions

Each agent has its own session namespace:

```
~/.openclaw/
├─ sessions/                    # Main agent sessions
│  ├─ sess_abc123.json
│  └─ sess_def456.json
│
├─ agents/
│  ├─ work/                    # "work" agent
│  │  └─ sessions/
│  │     └─ sess_ghi789.json
│  │
│  └─ personal/                # "personal" agent
│     └─ sessions/
│        └─ sess_jkl012.json
```

Sessions don't cross agent boundaries — the "work" agent can't see "personal" agent conversations.

---

## Session-Related Events

Sessions emit events that flow through the broadcast system (see [03-COMM](./03-COMM-gateway-protocol.md)):

```typescript
// Available via WebSocket protocol:
"session.list"    → List all sessions for current agent
"session.get"     → Get session details (turns, metadata)
"session.create"  → Create new session
"session.delete"  → Delete session and file
```

---

## Key Source Files

| File | Purpose |
|------|---------|
| `src/gateway/sessions-resolve.ts` | Session key → session ID resolution |
| `src/agents/cli-session.ts` | CLI session ID tracking |
| `src/agents/compaction.ts` | Conversation compaction logic |
| `src/agents/context-window-guard.ts` | Context limit monitoring |
| `src/agents/context.ts` | Context assembly for model calls |
| `src/agents/bootstrap-files.ts` | CLAUDE.md and context file loading |
| `src/agents/bootstrap-hooks.ts` | Pre-session hooks |
| `src/agents/agent-scope.ts` | Multi-agent session scope resolution |
| `src/agents/workspace-run.ts` | Per-run workspace directory resolution |
| `src/gateway/protocol/schema/sessions.ts` | Session WebSocket frame types |
