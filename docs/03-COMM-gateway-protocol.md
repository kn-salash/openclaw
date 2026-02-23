# 03 - Gateway Communication Protocol

> **Cross-references:** [01-BRIDGE](./01-BRIDGE-agent-runtime.md) | [04-FLOW](./04-FLOW-message-pipeline.md) | [08-CHANNELS](./08-CHANNELS-integration-bridge.md)

---

## Overview

The OpenClaw Gateway is a **single-port HTTP + WebSocket server** (default port 18789) that serves as the central communication hub. All channels, companion apps, and the web UI connect through it.

```
┌──────────────┐  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐
│  Telegram     │  │  Discord      │  │  Companion   │  │  Web UI       │
│  Channel      │  │  Channel      │  │  App (iOS)   │  │  (Browser)    │
└──────┬───────┘  └──────┬───────┘  └──────┬───────┘  └──────┬───────┘
       │                  │                  │                  │
       │    Internal      │    Internal      │   WebSocket      │   WebSocket
       │    Bridge        │    Bridge        │                  │
       │                  │                  │                  │
       ▼                  ▼                  ▼                  ▼
┌──────────────────────────────────────────────────────────────────────┐
│                     GATEWAY SERVER (port 18789)                      │
│                                                                      │
│  ┌─────────────────────────────────────────────────────────────┐    │
│  │  HTTP Layer                                                  │    │
│  │  ├─ POST /v1/chat/completions  (OpenAI-compat API)          │    │
│  │  ├─ POST /v1/responses         (Open Responses API)         │    │
│  │  ├─ GET  /health               (Health check)               │    │
│  │  ├─ GET  /ui/*                 (Control UI static files)    │    │
│  │  └─ POST /webhooks/*           (Channel webhooks)           │    │
│  └─────────────────────────────────────────────────────────────┘    │
│                                                                      │
│  ┌─────────────────────────────────────────────────────────────┐    │
│  │  WebSocket Layer                                             │    │
│  │  ├─ Connection auth handshake (token/password)              │    │
│  │  ├─ Request-response frames (type → handler → response)     │    │
│  │  ├─ Broadcast events (agent deltas, tool status, etc.)      │    │
│  │  └─ Subscription to sessions                                │    │
│  └─────────────────────────────────────────────────────────────┘    │
│                                                                      │
│  ┌──────────────┐  ┌──────────────┐  ┌───────────────────────┐    │
│  │  Session Mgr  │  │  Broadcast    │  │  Exec Approval Mgr   │    │
│  │  (routing)    │  │  (fanout)     │  │  (tool permissions)   │    │
│  └──────────────┘  └──────────────┘  └───────────────────────┘    │
└──────────────────────────────────────────────────────────────────────┘
```

---

## HTTP Endpoints

### OpenAI-Compatible API

**File:** `src/gateway/openai-http.ts`

```
POST /v1/chat/completions
Authorization: Bearer <OPENCLAW_GATEWAY_TOKEN>
Content-Type: application/json

{
  "model": "claude-opus-4-6",
  "messages": [
    { "role": "user", "content": "Hello" }
  ],
  "stream": true
}
```

**Streaming mode** returns Server-Sent Events (SSE):
```
data: {"id":"run_abc","object":"chat.completion.chunk","choices":[{"delta":{"content":"Hello"}}]}
data: {"id":"run_abc","object":"chat.completion.chunk","choices":[{"delta":{"content":"!"}}]}
data: [DONE]
```

**Non-streaming mode** returns the complete response.

The endpoint translates OpenAI-format requests into OpenClaw's internal `agentCommand()` calls and maps the results back to OpenAI-format responses.

### Open Responses API

**File:** `src/gateway/openresponses-http.ts`

```
POST /v1/responses
```

Alternative API format for creating responses with richer metadata.

### Health Check

```
GET /health → { "status": "ok", "uptime": 3600, ... }
```

---

## WebSocket Protocol

### Connection & Authentication

**File:** `src/gateway/server/ws-connection.ts`, `src/gateway/server/ws-connection/auth-messages.ts`

```
Client connects to ws://host:18789
    │
    ├─ Server sends: { type: "auth_required" }
    │
    ├─ Client sends: { type: "auth", token: "<OPENCLAW_GATEWAY_TOKEN>" }
    │                 or { type: "auth", password: "<password>" }
    │
    ├─ Server validates (constant-time comparison)
    │   ├─ Valid  → { type: "auth_ok", ... }
    │   └─ Invalid → { type: "auth_failed" } + rate limit per IP
    │
    └─ Connection authenticated → ready for frames
```

### Frame Protocol

**File:** `src/gateway/protocol/schema/frames.ts`

```typescript
// Client → Server (Request):
type RequestFrame = {
  id: string;           // Unique request ID for correlation
  type: string;         // Handler type (e.g., "chat.send", "session.list")
  params: Record<string, unknown>;
};

// Server → Client (Response):
// Delivered via RespondFn(ok, payload, error, meta)
type ResponseFrame = {
  id: string;           // Matches request ID
  ok: boolean;
  payload?: unknown;
  error?: string;
  meta?: Record<string, unknown>;
};

// Server → Client (Broadcast/Push):
type BroadcastFrame = {
  type: string;         // Event type
  data: unknown;        // Event payload
};
```

### Frame Types (Protocol Schema)

**Directory:** `src/gateway/protocol/schema/`

| Schema File | Frame Types |
|-------------|-------------|
| `agent.ts` | `agent:delta`, `agent:tool_start`, `agent:tool_end`, `agent:end`, `agent:error` |
| `sessions.ts` | `session.list`, `session.get`, `session.create`, `session.delete` |
| `channels.ts` | `channel.list`, `channel.start`, `channel.stop`, `channel.status` |
| `config.ts` | `config.get`, `config.update`, `config.reload` |
| `cron.ts` | `cron.list`, `cron.create`, `cron.delete`, `cron.trigger` |
| `exec-approvals.ts` | `exec.approve`, `exec.deny`, `exec.pending` |
| `devices.ts` | `device.register`, `device.list` |
| `logs-chat.ts` | `log.stream`, `chat.history` |
| `nodes.ts` | `node.list`, `node.invoke` |
| `mesh.ts` | `mesh.peer`, `mesh.forward` |
| `wizard.ts` | `wizard.start`, `wizard.step`, `wizard.complete` |
| `agents-models-skills.ts` | `agents.list`, `models.catalog`, `skills.list` |
| `snapshot.ts` | `snapshot.get` (full gateway state) |

---

## Broadcast System

**File:** `src/gateway/server-broadcast.ts`

The broadcast system delivers real-time events to all connected WebSocket clients:

```typescript
// Gateway context includes broadcast function:
broadcast(event: BroadcastEvent): void

// Used during agent runs:
broadcast({ type: "agent:delta",      runId, sessionKey, text: "Hello..." });
broadcast({ type: "agent:tool_start", runId, sessionKey, tool: "exec", input: {...} });
broadcast({ type: "agent:tool_end",   runId, sessionKey, result: "..." });
broadcast({ type: "agent:end",        runId, sessionKey, success: true });
```

### Broadcast During Agent Execution

When the Pi Embedded Runner streams events, they flow to both:
1. The **originating channel** (e.g., Telegram → send reply)
2. All **WebSocket clients** (companion apps, web UI)

```
Pi Embedded Runner
    │
    ├─ message_update event
    │   ├─ → streamParams.onDelta(text)     → Channel sends partial reply
    │   └─ → broadcast("agent:delta")       → WebSocket clients get real-time update
    │
    ├─ tool_use_start event
    │   └─ → broadcast("agent:tool_start")  → Companion app shows tool in progress
    │
    └─ agent_end event
        └─ → broadcast("agent:end")         → All clients know run is complete
```

---

## Gateway Authentication

**File:** `src/gateway/auth.ts`

### Auth Methods (checked in order)

```
Incoming request
    │
    ├─ 1. Localhost check
    │      Is request from 127.0.0.1/::1?
    │      → Trusted (no auth needed)
    │
    ├─ 2. Bearer token
    │      Authorization: Bearer <token>
    │      → Constant-time compare with OPENCLAW_GATEWAY_TOKEN
    │
    ├─ 3. Password auth
    │      → Compare with configured password
    │
    ├─ 4. Tailscale identity
    │      Tailscale-User-Login header present?
    │      → Verify via whois API against allowed users
    │
    └─ 5. Trusted proxy
           X-Forwarded-For from trusted proxy IP?
           → Check against trustedProxies list in config
```

### Rate Limiting

**File:** `src/gateway/auth-rate-limit.ts`

Failed auth attempts are rate-limited per IP:

```
IP attempts auth
    │
    ├─ Track failed attempts per IP
    ├─ After N failures → increase delay
    └─ Eventually block IP for cooldown period
```

### Constant-Time Comparison

All secret comparisons use `safeEqualSecret()` to prevent timing attacks:

```typescript
import { timingSafeEqual } from "node:crypto";
// Compares token bytes in constant time regardless of where they differ
```

---

## OpenAI-Compatible Streaming

The `/v1/chat/completions` endpoint with `stream: true` bridges the gap between OpenClaw's internal event system and the OpenAI SSE format:

```
Client sends POST /v1/chat/completions { stream: true }
    │
    ├─ Gateway creates agentCommand() call
    │
    ├─ Agent runs, produces events:
    │   ├─ message_update({ delta: { text: "Hello" } })
    │   │   → SSE: data: {"choices":[{"delta":{"content":"Hello"}}]}
    │   │
    │   ├─ message_update({ delta: { text: " world" } })
    │   │   → SSE: data: {"choices":[{"delta":{"content":" world"}}]}
    │   │
    │   └─ message_end({ usage: {...} })
    │       → SSE: data: {"choices":[{"finish_reason":"stop"}]}
    │       → SSE: data: [DONE]
    │
    └─ HTTP response ends
```

---

## Exec Approval Flow

**File:** `src/gateway/exec-approval-manager.ts`

When an agent wants to run a dangerous command, the approval flow uses WebSocket:

```
Agent calls tool: exec("rm -rf /tmp/data")
    │
    ├─ ExecApprovalManager checks command policy
    │   ├─ Is command in safe list? → Execute immediately
    │   └─ Is command dangerous? → Require approval
    │
    ├─ Broadcast: { type: "exec.pending", command: "rm -rf /tmp/data", runId }
    │   → Companion app shows approval dialog
    │
    ├─ User responds via WebSocket:
    │   { type: "exec.approve", runId }  or  { type: "exec.deny", runId }
    │
    └─ ExecApprovalManager resolves → tool executes or rejects
```

---

## Origin & CORS

**File:** `src/gateway/origin-check.ts`

WebSocket connections are validated for origin to prevent cross-site WebSocket hijacking:

```typescript
// Allowed origins:
// - localhost variants (127.0.0.1, ::1, localhost)
// - Configured trusted origins
// - Companion app origins (tauri://, capacitor://)
```

---

## Key Source Files

| File | Purpose |
|------|---------|
| `src/gateway/server/http-listen.ts` | HTTP server startup and port binding |
| `src/gateway/server/ws-connection.ts` | WebSocket connection lifecycle |
| `src/gateway/server/ws-connection/auth-messages.ts` | Auth handshake |
| `src/gateway/server/ws-connection/message-handler.ts` | Frame dispatch |
| `src/gateway/server-broadcast.ts` | Broadcast fanout to connected clients |
| `src/gateway/auth.ts` | Gateway authentication (5 methods) |
| `src/gateway/auth-rate-limit.ts` | Per-IP auth rate limiting |
| `src/gateway/openai-http.ts` | OpenAI-compat `/v1/chat/completions` |
| `src/gateway/openresponses-http.ts` | Open Responses API |
| `src/gateway/protocol/schema/` | All protocol frame type definitions |
| `src/gateway/exec-approval-manager.ts` | Tool execution approval flow |
| `src/gateway/origin-check.ts` | WebSocket origin validation |
| `src/gateway/control-ui.ts` | Control UI static file serving |
| `src/gateway/control-ui-csp.ts` | Content Security Policy for UI |
