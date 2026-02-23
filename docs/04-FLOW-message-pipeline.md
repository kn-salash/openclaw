# 04 - End-to-End Message Pipeline

> **Cross-references:** [01-BRIDGE](./01-BRIDGE-agent-runtime.md) | [03-COMM](./03-COMM-gateway-protocol.md) | [07-SESSION](./07-SESSION-state-management.md) | [08-CHANNELS](./08-CHANNELS-integration-bridge.md) | [10-CONFIG](./10-CONFIG-model-selection.md)

---

## Overview

Every message in OpenClaw follows a 9-step pipeline from user input to response delivery. This document traces the complete journey.

```
USER → Channel → Gateway → Session → Agent → Auth → AI Model → Channel → USER
 [1]    [2]       [3]       [4]      [5]     [6]     [7]        [8]      [9]
```

---

## Step 1: User Sends Message

A user sends "Schedule a meeting tomorrow at 3pm" on Telegram.

---

## Step 2: Channel Receives & Translates

**Files:** `src/channels/telegram/`, `src/channels/discord/`, etc.

Each channel receives the platform-native message and translates it to OpenClaw's common format:

```typescript
// Platform-native (Telegram via grammY):
{
  message_id: 42,
  from: { id: 12345, first_name: "John" },
  chat: { id: -1001234567, type: "group" },
  text: "Schedule a meeting tomorrow at 3pm",
  photo?: [...],
  voice?: { file_id: "..." }
}

// Translated to common format:
{
  channel: "telegram",
  sessionKey: "telegram:-1001234567",    // {channel}:{chatId}
  sender: "John",
  text: "Schedule a meeting tomorrow at 3pm",
  media?: ImageContent[],                // Photos → base64 or URLs
  // Additional metadata varies by channel
}
```

### Session Key Construction

The session key determines which conversation this message belongs to:

| Channel | Key Format | Example |
|---------|-----------|---------|
| Telegram DM | `telegram:{userId}` | `telegram:12345` |
| Telegram Group | `telegram:{chatId}` | `telegram:-1001234567` |
| Discord DM | `discord:{channelId}` | `discord:998877665544` |
| Discord Server | `discord:{guildId}:{channelId}` | `discord:112233:445566` |
| Slack DM | `slack:{teamId}:{channelId}` | `slack:T01:D02` |
| Slack Thread | `slack:{teamId}:{channelId}:{threadTs}` | `slack:T01:C02:1700.0` |
| Signal | `signal:{number}` | `signal:+1234567890` |
| Web | `web:{connectionId}` | `web:ws_abc123` |

---

## Step 3: Gateway Session Resolution

**File:** `src/gateway/sessions-resolve.ts`

```
Incoming message with sessionKey
    │
    ├─ Look up session in session store
    │   ├─ Existing session? → Load state, agent ID, provider/model
    │   └─ New session? → Create new entry
    │
    ├─ Apply multi-agent routing rules (see 10-CONFIG):
    │   routes: [
    │     { channel: "telegram", pattern: "group:*", agentId: "work" },
    │     { channel: "discord",  agentId: "personal" }
    │   ]
    │   → Maps session to specific agent
    │
    ├─ Resolve agent config:
    │   ├─ agentId → agents.agents[agentId]
    │   ├─ provider (e.g., "anthropic")
    │   ├─ model (e.g., "claude-opus-4-6")
    │   ├─ workspace directory
    │   ├─ system prompt additions
    │   └─ tool permissions
    │
    └─ Return resolved session context
```

---

## Step 4: Agent Command Dispatch

**File:** `src/commands/agent.ts`

```typescript
async function agentCommand(params: {
  message: string;
  sessionKey: string;
  runId: string;               // Unique per invocation
  messageChannel: string;      // "telegram", "discord", etc.
  images?: ImageContent[];
  streamParams?: AgentStreamParams;
}): Promise<EmbeddedPiRunResult>
```

The agent command:

```
agentCommand()
    │
    ├─ Generate unique runId
    │
    ├─ Load session state (conversation history)
    │
    ├─ Resolve provider + model from agent config
    │   "opus" → { provider: "anthropic", model: "claude-opus-4-6" }
    │
    ├─ Choose execution path (see 01-BRIDGE):
    │   ├─ Provider is CLI backend (claude-cli, codex-cli)?
    │   │   └─ runCliAgent() → spawn CLI process
    │   │
    │   └─ Standard provider (anthropic, openai-codex, google-gemini-cli)?
    │       └─ runPiEmbeddedAgent() → in-process API call
    │
    └─ Return result to channel for delivery
```

---

## Step 5: Auth Profile Resolution

**File:** `src/agents/auth-profiles/oauth.ts`

See [02-AUTH](./02-AUTH-credential-lifecycle.md) for full details.

```
Agent needs API key for "anthropic"
    │
    ├─ resolveAuthProfileOrder("anthropic")
    │   → ["anthropic:oauth-1", "anthropic:oauth-2", "anthropic:api-key"]
    │
    ├─ Try first profile: "anthropic:oauth-1"
    │   ├─ Token fresh? → use access token
    │   └─ Token expired? → refresh via OAuth, save, use new token
    │
    ├─ Inject API key into model's auth storage
    │
    └─ Ready for API call
```

---

## Step 6: AI Model Execution

### Pi Embedded Path

```
activeSession.prompt("Schedule a meeting tomorrow at 3pm")
    │
    ├─ Build full prompt:
    │   [System prompt + context files + tool definitions]
    │   [Conversation history]
    │   [User message + any images]
    │
    ├─ Send to Anthropic/OpenAI/Gemini API
    │
    ├─ Stream response events:
    │   ├─ message_start
    │   ├─ message_update → "I'll schedule a meeting for..."
    │   ├─ tool_use_start → { tool: "calendar_create" }
    │   ├─ tool_use_end   → { result: "Event created" }
    │   ├─ message_update → "Done! Meeting scheduled for tomorrow 3 PM."
    │   ├─ message_end    → { usage: { input: 1200, output: 85 } }
    │   └─ agent_end      → { success: true }
    │
    └─ Each event flows to:
        ├─ Channel (partial reply delivery)
        └─ WebSocket broadcast (companion app updates)
```

### CLI Runner Path

```
supervisor.spawn({ mode: "child", argv: ["claude", "-p", "Schedule...", ...] })
    │
    ├─ Child process executes
    ├─ stdout captured
    ├─ Process exits
    │
    └─ Parse JSON output → text + sessionId + usage
```

---

## Step 7: Response Construction

```typescript
// Result from either path:
{
  payloads: [{ text: "Done! Meeting scheduled for tomorrow 3 PM." }],
  meta: {
    durationMs: 2340,
    agentMeta: {
      sessionId: "sess_xyz",
      provider: "anthropic",
      model: "claude-opus-4-6",
      usage: { input_tokens: 1200, output_tokens: 85 }
    }
  }
}
```

---

## Step 8: Channel Delivery

**Files:** `src/channels/{channel}/`

```
Response payloads arrive at channel
    │
    ├─ Format for platform:
    │   ├─ Telegram: Markdown formatting, 4096 char limit → split
    │   ├─ Discord: Embed formatting, 2000 char limit → split
    │   ├─ Slack: Block Kit formatting
    │   └─ Signal: Plain text
    │
    ├─ Handle long messages:
    │   ├─ Split at message boundaries (paragraphs, code blocks)
    │   ├─ Send multiple messages if needed
    │   └─ Maintain formatting integrity across splits
    │
    ├─ Send via platform API:
    │   ├─ Telegram: bot.api.sendMessage(chatId, text)
    │   ├─ Discord: channel.send({ embeds: [...] })
    │   ├─ Slack: client.chat.postMessage({ channel, text, blocks })
    │   └─ Signal: signal-cli send -m "text" +number
    │
    └─ Update session metadata (lastMessageTime, etc.)
```

---

## Step 9: Session State Saved

**Files:** `src/agents/cli-session.ts`, `~/.openclaw/sessions/`

```
After response delivery:
    │
    ├─ Save conversation turn:
    │   { role: "user", content: "Schedule a meeting..." }
    │   { role: "assistant", content: "Done! Meeting scheduled..." }
    │   (+ any tool call/result pairs)
    │
    ├─ Update session file (~/.openclaw/sessions/{sessionId}.json)
    │
    ├─ Check context window size:
    │   ├─ Within limits? → done
    │   └─ Approaching limit? → schedule compaction (see 07-SESSION)
    │
    └─ Emit agent event via broadcast (see 03-COMM)
```

---

## Parallel Flows

### WebSocket Companion App Flow

When a companion app sends a message via WebSocket instead of a channel:

```
Companion app → WebSocket frame: { type: "chat.send", params: { text: "..." } }
    │
    ├─ Gateway receives frame
    ├─ Dispatch to chat.send handler
    ├─ Create session (or use existing)
    ├─ agentCommand() (same pipeline from Step 4)
    │
    ├─ During execution:
    │   broadcast("agent:delta", { text: "..." })  → sent back to app
    │
    └─ Final response:
        broadcast("agent:end", { ... })  → app shows complete
```

### HTTP API Flow (OpenAI-Compatible)

```
Client → POST /v1/chat/completions { messages: [...], stream: true }
    │
    ├─ Authenticate (Bearer token)
    ├─ Translate to agentCommand()
    │
    ├─ Stream: SSE events → data: { choices: [{ delta: { content: "..." } }] }
    │
    └─ Non-stream: { choices: [{ message: { content: "full response" } }] }
```

---

## Error Recovery During Pipeline

At any point in the pipeline, errors are handled:

```
Error in Step 6 (AI Model):
    │
    ├─ FailoverError with reason:
    │   ├─ "auth"       → Rotate auth profile, retry (Step 5)
    │   ├─ "rate_limit" → Cooldown profile, rotate, retry
    │   ├─ "timeout"    → Kill process, rotate, retry
    │   ├─ "billing"    → Disable profile (long cooldown), rotate
    │   └─ "format"     → Reduce thinking level, retry
    │
    ├─ All profiles exhausted?
    │   └─ Try fallback model/provider if configured
    │
    └─ Complete failure?
        └─ Send error message to channel: "Sorry, I'm having trouble..."
```

---

## Key Source Files

| File | Purpose |
|------|---------|
| `src/commands/agent.ts` | agentCommand() — pipeline entry point |
| `src/gateway/sessions-resolve.ts` | Session resolution and routing |
| `src/agents/pi-embedded-runner/run.ts` | Pi Embedded execution (Step 6) |
| `src/agents/cli-runner.ts` | CLI execution (Step 6 alt) |
| `src/agents/auth-profiles/oauth.ts` | Auth resolution (Step 5) |
| `src/gateway/server-broadcast.ts` | Event broadcast (throughout) |
| `src/channels/{channel}/` | Channel-specific send/receive (Steps 2, 8) |
| `src/agents/cli-session.ts` | Session save (Step 9) |
