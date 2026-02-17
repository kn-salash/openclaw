# OpenClaw: Complete Architecture & Virtual Executive Assistant Blueprint

> Reverse-engineered from 20 parallel analysis agents covering every subsystem.
> Purpose: Understand the full system to build a virtual executive assistant.

---

## Table of Contents

1. [System Overview](#1-system-overview)
2. [Architecture Diagram](#2-architecture-diagram)
3. [End-to-End Message Flow](#3-end-to-end-message-flow)
4. [Core Subsystems](#4-core-subsystems)
   - 4.1 Gateway Core
   - 4.2 Agent Runtime (Pi Embedded)
   - 4.3 Auth & Credential Management
   - 4.4 Configuration & Model Selection
   - 4.5 Session & Routing
   - 4.6 Process Supervisor
   - 4.7 Channels (Telegram, Discord, Slack, Signal, Web)
   - 4.8 Extensions Architecture
   - 4.9 Skills Ecosystem
   - 4.10 Tools System
   - 4.11 Memory & Embeddings
   - 4.12 Media Pipeline
   - 4.13 Voice System
   - 4.14 Cron & Scheduling
   - 4.15 Daemon & Service Management
   - 4.16 CLI & Onboarding
   - 4.17 Security & Logging
   - 4.18 Auto-Reply & Thinking
   - 4.19 Web UI & Companion Apps
   - 4.20 Infrastructure Utilities
5. [How the Agent Stays Alive](#5-how-the-agent-stays-alive)
6. [How Payloads Are Exchanged](#6-how-payloads-are-exchanged)
7. [How Permissions Work](#7-how-permissions-work)
8. [Virtual Executive Assistant Blueprint](#8-virtual-executive-assistant-blueprint)

---

## 1. System Overview

OpenClaw is a **self-hosted, multi-channel AI gateway** that turns any AI model (Claude, Codex,
Gemini, etc.) into a persistent personal assistant accessible through 15+ messaging platforms.

**Key properties:**
- **Local-first**: Runs on your hardware (Linux VPS, Mac, etc.)
- **Multi-provider**: Claude, OpenAI Codex, Google Gemini, GitHub Copilot, MiniMax, and more
- **Multi-channel**: Telegram, Discord, Slack, Signal, iMessage, WhatsApp, Matrix, Teams, IRC, etc.
- **Multi-agent**: Route different channels/users to isolated agent workspaces
- **Persistent**: systemd/launchd daemon with auto-restart and credential rotation
- **Extensible**: 54 skills, 39 channel extensions, custom tool injection

**Tech stack:** TypeScript (ESM), Node.js 22+, pnpm monorepo, Vitest, Oxlint

---

## 2. Architecture Diagram

```
                              ┌─────────────────────────────────────────────┐
                              │         COMPANION APPS / WEB UI             │
                              │  macOS menu bar ▪ iOS ▪ Android ▪ Browser   │
                              └──────────────────┬──────────────────────────┘
                                                 │ WebSocket + HTTP
                                                 ▼
┌──────────────────┐    ┌─────────────────────────────────────────────────────────┐
│   MESSAGING      │    │                  GATEWAY SERVER                          │
│   CHANNELS       │    │                  (Node.js, port 18789)                   │
│                  │    │                                                           │
│  Telegram ───────┼───▶│  ┌─────────────┐  ┌──────────────┐  ┌───────────────┐   │
│  Discord ────────┼───▶│  │  WebSocket   │  │   HTTP API   │  │   Webhook     │   │
│  Slack ──────────┼───▶│  │  Control     │  │  /v1/chat/   │  │   Endpoints   │   │
│  Signal ─────────┼───▶│  │  Plane       │  │  completions │  │               │   │
│  WhatsApp ───────┼───▶│  └──────┬──────┘  └──────┬───────┘  └───────┬───────┘   │
│  Matrix ─────────┼───▶│         │                 │                   │           │
│  Teams ──────────┼───▶│         ▼                 ▼                   ▼           │
│  iMessage ───────┼───▶│  ┌──────────────────────────────────────────────────┐    │
│  IRC ────────────┼───▶│  │              SESSION MANAGER                     │    │
│  Web ────────────┼───▶│  │  session key → agent ID → provider/model        │    │
│  + 6 more        │    │  │  multi-agent routing, session persistence       │    │
│                  │    │  └──────────────────────┬───────────────────────────┘    │
└──────────────────┘    │                         │                                │
                        │                         ▼                                │
                        │  ┌──────────────────────────────────────────────────┐    │
                        │  │            AGENT RUNTIME                          │    │
                        │  │                                                   │    │
                        │  │  ┌─────────────────┐  ┌───────────────────────┐  │    │
                        │  │  │  Pi Embedded     │  │  CLI Runner           │  │    │
                        │  │  │  (in-process)    │  │  (child processes)    │  │    │
                        │  │  │                  │  │                       │  │    │
                        │  │  │  • Tool loop     │  │  • claude CLI         │  │    │
                        │  │  │  • Streaming     │  │  • codex CLI          │  │    │
                        │  │  │  • Compaction    │  │  • Custom backends    │  │    │
                        │  │  └────────┬─────────┘  └───────────┬───────────┘  │    │
                        │  │           │                         │              │    │
                        │  │           ▼                         ▼              │    │
                        │  │  ┌──────────────────────────────────────────┐     │    │
                        │  │  │         AUTH PROFILE STORE               │     │    │
                        │  │  │  ~/.openclaw/state/auth-profiles.json    │     │    │
                        │  │  │                                          │     │    │
                        │  │  │  Claude ─── API key / OAuth / Token      │     │    │
                        │  │  │  Codex ──── OAuth / Token                │     │    │
                        │  │  │  Copilot ── GitHub OAuth                 │     │    │
                        │  │  │  Gemini ─── API key                      │     │    │
                        │  │  │  + cooldown tracking + failover          │     │    │
                        │  │  └──────────────────────────────────────────┘     │    │
                        │  │                                                   │    │
                        │  │  ┌──────────────────────────────────────────┐     │    │
                        │  │  │         TOOLS & SKILLS                   │     │    │
                        │  │  │  Browser ▪ Canvas ▪ Cron ▪ Exec         │     │    │
                        │  │  │  54 bundled skills (Notion, 1Password…)  │     │    │
                        │  │  └──────────────────────────────────────────┘     │    │
                        │  └──────────────────────────────────────────────────┘    │
                        │                                                          │
                        │  ┌──────────────────────────────────────────────────┐    │
                        │  │  SUPPORTING SYSTEMS                              │    │
                        │  │  Memory/Embeddings ▪ Media Pipeline ▪ Voice      │    │
                        │  │  Cron Scheduler ▪ Process Supervisor              │    │
                        │  └──────────────────────────────────────────────────┘    │
                        └──────────────────────────────────────────────────────────┘
                                                 │
                              ┌──────────────────┴──────────────────┐
                              │    DAEMON (systemd / launchd)        │
                              │    Restart=always, RestartSec=5      │
                              │    enable-linger for headless        │
                              └─────────────────────────────────────┘
```

---

## 3. End-to-End Message Flow

Here is the complete path of a message from a user sending "Schedule a meeting
tomorrow at 3pm" on Telegram:

```
[1] USER MESSAGE
    │
    │  User sends "Schedule a meeting tomorrow at 3pm" on Telegram
    │
    ▼
[2] TELEGRAM CHANNEL (src/channels/telegram/)
    │  grammY bot receives message event
    │  ├─ Parse message: extract text, sender, chat ID, media
    │  ├─ Build channel context: { channel: "telegram", sender, chatId, ... }
    │  ├─ Determine session key: telegram:{chatId} or telegram:{chatId}:{userId}
    │  └─ Forward to gateway via internal channel→gateway bridge
    │
    ▼
[3] GATEWAY SESSION RESOLUTION (src/gateway/sessions-resolve.ts)
    │  ├─ Look up session by key in session store
    │  ├─ Resolve agent ID (which agent handles this session)
    │  ├─ Load agent config (model, provider, workspace, system prompt)
    │  ├─ Apply multi-agent routing rules (channel → agent mapping)
    │  └─ Create or resume session entry
    │
    ▼
[4] AGENT COMMAND (src/commands/agent.ts)
    │  ├─ Resolve run ID (unique per invocation)
    │  ├─ Load session state (previous conversation history)
    │  ├─ Resolve provider + model from config
    │  │   e.g., provider=anthropic, model=claude-opus-4-6
    │  ├─ Choose execution path:
    │  │   ├─ Pi Embedded Runner (in-process, default)
    │  │   └─ CLI Runner (external process, fallback)
    │  └─ Dispatch to chosen runner
    │
    ▼
[5] AUTH PROFILE RESOLUTION (src/agents/auth-profiles/)
    │  ├─ Load auth-profiles.json from OPENCLAW_STATE_DIR
    │  ├─ Resolve profile ordering (config → lastGood → round-robin)
    │  ├─ Select first non-cooldown profile
    │  ├─ If OAuth: check expiry, refresh if needed
    │  ├─ Inject API key into model's authStorage
    │  └─ Ready for API call
    │
    ▼
[6] PI EMBEDDED RUNNER (src/agents/pi-embedded-runner/run.ts)
    │  ┌─── ATTEMPT LOOP ───────────────────────────────────────┐
    │  │                                                         │
    │  │  [6a] Build system prompt                               │
    │  │       ├─ Base personality + workspace context            │
    │  │       ├─ Bootstrap files (CLAUDE.md, .env, etc.)        │
    │  │       ├─ Injected tools definitions                     │
    │  │       ├─ Skills system prompts                          │
    │  │       └─ Channel-specific instructions                  │
    │  │                                                         │
    │  │  [6b] Create/resume agent session                       │
    │  │       ├─ Load session from file (~/.openclaw/sessions/) │
    │  │       ├─ Attach conversation history                    │
    │  │       └─ Configure thinking level                       │
    │  │                                                         │
    │  │  [6c] Call AI model with prompt                         │
    │  │       ├─ Send to Anthropic/OpenAI/etc. API              │
    │  │       ├─ Stream response events:                        │
    │  │       │   message_start → message_update (text deltas)  │
    │  │       │   → tool_execution_start → tool_execution_end   │
    │  │       │   → message_end → agent_end                     │
    │  │       └─ Real-time streaming to channel                 │
    │  │                                                         │
    │  │  [6d] Tool execution (if model calls tools)             │
    │  │       ├─ Execute tool handler (browser, exec, etc.)     │
    │  │       ├─ Return tool result to model                    │
    │  │       └─ Model continues generation                     │
    │  │                                                         │
    │  │  [6e] Error handling                                    │
    │  │       ├─ Rate limit → rotate auth profile, retry        │
    │  │       ├─ Auth error → next profile or next provider     │
    │  │       ├─ Timeout → kill, rotate, retry                  │
    │  │       ├─ Context overflow → compact session, retry      │
    │  │       └─ Thinking error → fallback think level, retry   │
    │  │                                                         │
    │  └─── END ATTEMPT LOOP ───────────────────────────────────┘
    │
    ▼
[7] RESPONSE DELIVERY
    │  ├─ Agent produces: { payloads: [{ text: "..." }], meta: {...} }
    │  ├─ Save session state (conversation + tool results)
    │  ├─ Emit agent event (for WebSocket clients)
    │  └─ Return payloads to channel
    │
    ▼
[8] TELEGRAM CHANNEL (outbound)
    │  ├─ Format response for Telegram (Markdown, inline buttons, etc.)
    │  ├─ Split long messages (Telegram 4096 char limit)
    │  ├─ Send via Telegram Bot API
    │  └─ Update session metadata (last message time, etc.)
    │
    ▼
[9] USER RECEIVES RESPONSE
    "I'll schedule a meeting for tomorrow at 3:00 PM. Creating the event now..."
```

---

## 4. Core Subsystems

### 4.1 Gateway Core

**Entry point**: `src/gateway/server.ts`
**Protocol**: HTTP + WebSocket on single port (default 18789)

The gateway is the central nervous system. It:
- Accepts WebSocket connections from companion apps and web UI
- Serves the OpenAI-compatible HTTP API (`/v1/chat/completions`)
- Manages all channel connections (Telegram, Discord, etc.)
- Routes messages to the correct agent based on session keys
- Broadcasts events to connected clients in real-time

**Key types** (`src/gateway/server-methods/types.ts`):
```typescript
GatewayRequestContext {
  deps, cron, execApprovalManager,
  broadcast, nodeSendToSession,
  chatAbortControllers, chatRunBuffers,
  wizardSessions, startChannel, stopChannel,
  loadGatewayModelCatalog, refreshHealthSnapshot
}
```

**WebSocket frame protocol**:
```typescript
RequestFrame { type: string, params: Record<string, unknown> }
// → handler dispatches by type
// → RespondFn(ok, payload, error, meta)
```

### 4.2 Agent Runtime (Pi Embedded)

**Core file**: `src/agents/pi-embedded-runner/run.ts`
**Library**: `@mariozechner/pi-ai` (pi-coding-agent v0.49.3)

The runtime executes AI model calls in-process using the pi-coding-agent SDK.

**Execution model**:
1. Load/create session (file-backed)
2. Resolve model + auth
3. Build system prompt with context files
4. Call `activeSession.prompt()` with event streaming
5. Handle tool calls in a loop (model calls tool → execute → return result → model continues)
6. On error: rotate auth profile, fallback thinking level, compact context, or failover

**Failover priority**:
```
Auth error → rotate profile → retry
Rate limit → cooldown profile → rotate → retry
Timeout → kill → rotate → retry
Thinking error → lower think level → retry
Context overflow → compact → truncate tool results → retry
All profiles exhausted → throw FailoverError → external fallback model
```

**Session persistence**:
- Sessions stored as JSON files in `~/.openclaw/sessions/`
- Full conversation history with tool results
- Branching support (fork conversation at any point)
- Compaction (summarize older turns to save context)

### 4.3 Auth & Credential Management

**Store file**: `~/.openclaw/state/auth-profiles.json`

```typescript
AuthProfileStore {
  version: number,
  profiles: Record<profileId, AuthProfileCredential>,
  order?: Record<provider, profileId[]>,    // Rotation ordering
  lastGood?: Record<provider, profileId>,   // Last successful profile
  usageStats?: Record<profileId, { lastUsed, cooldownUntil }>
}

AuthProfileCredential =
  | { type: "api_key", provider, apiKey }
  | { type: "token",   provider, token }
  | { type: "oauth",   provider, accessToken, refreshToken, expires, email? }
```

**Supported providers and auth methods**:
| Provider | API Key | OAuth | Token | CLI Profile ID |
|----------|---------|-------|-------|----------------|
| Anthropic (Claude) | Yes | Yes | Yes | `anthropic:claude-cli` |
| OpenAI (Codex) | Yes | Yes | Yes | `openai-codex:codex-cli` |
| GitHub Copilot | — | Yes | — | `github-copilot:default` |
| Google Gemini | Yes | — | — | `google-gemini-cli:default` |
| Chutes | — | Yes | — | `chutes:default` |
| MiniMax | Yes | — | — | `minimax:default` |
| Xiaomi | Yes | — | — | `xiaomi:default` |

**Profile rotation algorithm**:
1. Check config-specified profile order
2. Try `lastGood` profile first
3. Skip profiles in cooldown (rate limited)
4. Rotate through remaining profiles round-robin
5. Track `lastUsed` timestamp for fair distribution

### 4.4 Configuration & Model Selection

**Config file**: `~/.openclaw/openclaw.json`

```typescript
OpenClawConfig {
  gateway: { port, token, auth, trustedProxies },
  agents: {
    defaults: { provider, model, workspace, thinkLevel, heartbeat },
    routes: [{ channel, pattern, agentId }],          // Multi-agent routing
    agents: Record<agentId, AgentConfig>,
  },
  auth: {
    profiles: Record<profileId, { provider, mode }>,
    order: Record<provider, profileId[]>,
  },
  channels: { telegram: {...}, discord: {...}, slack: {...}, ... },
  extensions: [...],
  skills: { enabled: [...], disabled: [...] },
  voice: { wake: [...], tts: {...} },
  cron: { jobs: [...] },
}
```

**Model normalization** (`src/agents/model-selection.ts`):
```
"opus"           → { provider: "anthropic",    model: "claude-opus-4-6" }
"opus-4.6"       → { provider: "anthropic",    model: "claude-opus-4-6" }
"sonnet-4.5"     → { provider: "anthropic",    model: "claude-sonnet-4-5" }
"gpt-5.3-codex"  → { provider: "openai-codex", model: "gpt-5.3-codex" }
```

### 4.5 Session & Routing

**Session key format**: `{channel}:{chatId}` or `{channel}:{chatId}:{userId}`

**Multi-agent routing**: Config rules map channels/users to different agents:
```json
{
  "agents": {
    "routes": [
      { "channel": "telegram", "pattern": "group:*", "agentId": "work" },
      { "channel": "discord",  "agentId": "personal" }
    ],
    "agents": {
      "work":     { "provider": "anthropic", "model": "opus", "workspace": "/projects" },
      "personal": { "provider": "openai-codex", "model": "gpt-5.3-codex" }
    }
  }
}
```

Each agent has its own:
- Workspace directory
- Session file
- Auth profiles (can inherit from main)
- System prompt and tool permissions

### 4.6 Process Supervisor

**File**: `src/process/supervisor/supervisor.ts`

Manages all child processes (CLI agents, external tools):

```typescript
ProcessSupervisor {
  spawn(input: SpawnInput): ManagedRun    // Start a process
  cancel(runId): void                       // Cancel by ID
  cancelScope(scopeKey): void              // Cancel all in scope
}

SpawnInput {
  mode: "child" | "pty",
  argv: string[],
  timeoutMs, noOutputTimeoutMs,            // Watchdog timers
  cwd, env, input,                          // Environment
  scopeKey,                                 // Scope-based replacement
  captureOutput, onStdout, onStderr,        // Output handling
}
```

**Two adapter modes**:
- **Child mode** (`child_process.spawn`): For CLI agents, batch jobs
- **PTY mode** (`node-pty`): For interactive shells, browser tools

**Kill strategy** (Unix):
```
SIGTERM → 3s grace → SIGKILL (process group via -pid)
```

### 4.7 Channels

**Built-in channels** (in `src/channels/`):

| Channel | Library | Key Features |
|---------|---------|-------------|
| Telegram | grammY | Groups, media, reactions, commands, inline keyboards |
| Discord | discord.js | Servers, threads, slash commands, voice, embeds |
| Slack | Bolt | Socket Mode, threads, blocks, interactive components |
| Signal | signal-cli | Secure messaging, groups, reactions |
| Web | Custom | Built-in WebSocket chat, no external dependency |

**Channel → Gateway bridge**:
Each channel implements a common interface:
```typescript
ChannelPlugin {
  id: ChannelId,
  start(): Promise<void>,
  stop(): Promise<void>,
  sendMessage(sessionKey, text, opts): Promise<void>,
}
```

Inbound messages are translated to a common format and dispatched to `agentCommand()`.

### 4.8 Extensions Architecture

**Directory**: `extensions/` (39 extensions)

Extensions are npm packages that register as channel plugins:
```typescript
// extensions/matrix/index.ts
export default {
  id: "matrix",
  name: "Matrix",
  createChannel(config): ChannelPlugin { ... }
}
```

**Notable extensions**: Microsoft Teams, Matrix, WhatsApp, Google Chat,
Mattermost, IRC, Line, Twitch, Feishu, Nostr, Zalo, Nextcloud Talk

### 4.9 Skills Ecosystem

**Directory**: `skills/` (54 skills)

Skills are injected into the agent's system prompt and provide specialized capabilities:

```
skills/
├── coding-agent/      # Full coding agent (file edit, git, exec)
├── discord/           # Discord-specific actions (roles, channels)
├── canvas/            # Visual workspace generation
├── notion/            # Notion API integration
├── 1password/         # Password manager queries
├── apple-reminders/   # macOS Reminders integration
├── obsidian/          # Obsidian vault access
├── gemini/            # Google Gemini model access
├── openai/            # OpenAI API (image gen, whisper)
├── himalaya/          # Email (IMAP/SMTP)
├── gifgrep/           # GIF search
├── healthcheck/       # System health monitoring
└── ... (41 more)
```

**Skill manifest format**:
```yaml
name: notion
description: Read and update Notion pages
tools:
  - name: notion_search
    description: Search Notion workspace
    parameters: { query: string }
system_prompt: |
  You have access to Notion. Use notion_search to find pages...
```

### 4.10 Tools System

Tools are functions the AI model can call during conversation.

**Built-in tools**: Browser, Canvas, Exec (shell), File read/write, Git,
Cron (schedule tasks), Session management, Discord/Slack actions

**Tool injection pipeline**:
1. Load skill tools from enabled skills
2. Load channel-specific tools (e.g., Discord role management)
3. Merge with agent config tool permissions
4. Inject tool definitions into system prompt
5. Register tool handlers with the agent session

**Tool execution flow**:
```
Model outputs tool_use → pi-agent dispatches to handler →
handler executes (with approval if needed) →
result returned to model → model continues
```

**Exec approval** (`ExecApprovalManager`):
Dangerous commands (rm, git push, etc.) require explicit user approval
via the companion app or web UI.

### 4.11 Memory & Embeddings

**File**: `src/memory/embeddings-openai.ts`

- OpenAI text-embedding-3-small for vector generation
- SQLite-based vector storage (local, no external DB)
- Cosine similarity search for retrieval
- Batch embedding processing for efficiency

**Use cases**: Conversation recall, document search, semantic skill matching

### 4.12 Media Pipeline

**Files**: `src/media/`, `src/media-understanding/`

```
Inbound media (photo, voice, document)
    │
    ├─ Image → Vision model (Anthropic Claude / OpenAI GPT-4V / Google Gemini)
    │          Returns text description for agent context
    │
    ├─ Audio → Transcription (OpenAI Whisper / ElevenLabs)
    │          Returns text transcript for agent context
    │
    └─ Document → Text extraction
                 PDF, Office docs → plain text for agent context
```

**Provider selection**: Configured per-model with fallback chain

### 4.13 Voice System

**Wake word detection** → **Speech-to-text** → **Agent** → **Text-to-speech**

- **STT**: OpenAI Whisper, platform native
- **TTS**: ElevenLabs (primary), platform native fallback
- **Wake words**: Configurable in config (`voice.wake`)
- **Platforms**: macOS (menu bar), iOS, Android

### 4.14 Cron & Scheduling

Periodic tasks executed by the gateway:

```typescript
CronJob {
  id: string,
  schedule: string,     // cron expression
  agentId?: string,     // which agent runs it
  prompt: string,       // what to ask the agent
  channel?: string,     // where to deliver results
}
```

Managed via the `CronService` in the gateway context.

### 4.15 Daemon & Service Management

**Linux (systemd)**:
```
~/.config/systemd/user/openclaw-gateway.service
├─ ExecStart=/usr/local/bin/openclaw gateway --port 18789
├─ Restart=always, RestartSec=5
├─ After=network-online.target
└─ Environment: HOME, PATH, OPENCLAW_STATE_DIR, OPENCLAW_GATEWAY_TOKEN, ...
```

**macOS (launchd)**:
```
~/Library/LaunchAgents/ai.openclaw.gateway.plist
├─ ProgramArguments: [node, openclaw, gateway, --port, 18789]
├─ RunAtLoad: true
├─ KeepAlive: true
└─ EnvironmentVariables: { OPENCLAW_STATE_DIR, ... }
```

**Management**: `openclaw gateway install/uninstall/start/stop/restart`
**Audit**: `openclaw doctor` checks PATH, token, runtime, unit config

### 4.16 CLI & Onboarding

**Entry**: `openclaw` → `src/cli/`

**Commands**:
| Command | Description |
|---------|-------------|
| `openclaw onboard` | Interactive setup wizard (auth, channels, daemon) |
| `openclaw gateway` | Start the gateway server |
| `openclaw gateway install` | Install as system service |
| `openclaw configure` | Modify config interactively |
| `openclaw doctor` | Diagnose and fix issues |
| `openclaw agent` | Direct agent interaction (CLI mode) |

**Onboarding flow**:
1. Welcome + platform detection
2. Auth provider selection (Anthropic, OpenAI, etc.)
3. OAuth/token flow
4. Channel setup (Telegram token, Discord bot, etc.)
5. Daemon installation (systemd/launchd)
6. Gateway start + verification

### 4.17 Security & Logging

**Gateway auth** (`src/gateway/auth.ts`):
- Token auth (bearer), password auth, Tailscale identity, trusted proxy, local direct
- Rate limiting on failed auth attempts per IP
- Constant-time secret comparison (`safeEqualSecret`)

**Logging** (`src/logging/subsystem.ts`):
- Subsystem-scoped loggers: `createSubsystemLogger("agent/pi")`
- Levels: debug, info, warn, error
- Sensitive data redaction in logs (`redactRunIdentifier`)

**Security policies**:
- Tool exec approval for dangerous operations
- Input validation at system boundaries
- No credential logging
- OAuth state parameter validation (CSRF protection)
- PKCE for OAuth flows

### 4.18 Auto-Reply & Thinking

**System prompt construction** (`src/agents/cli-runner/helpers.ts`):
```
[Base personality] +
[Workspace context (CLAUDE.md, etc.)] +
[Tool definitions] +
[Skill system prompts] +
[Channel-specific instructions] +
[Heartbeat/auto-reply config] +
[Owner contact info]
```

**Thinking levels**: `none` | `default` | `extended`
- Controls model reasoning depth
- Automatic fallback if model doesn't support a level
- Configurable per-agent

**Heartbeat**: Periodic "are you still there?" prompts for long-running sessions

### 4.19 Web UI & Companion Apps

**Web UI** (`ui/`): Browser-based chat interface connecting via WebSocket
**macOS**: SwiftUI menu bar app with voice wake + talk mode
**iOS/Android**: React Native companion with push notifications and node streaming

All apps connect to the gateway via WebSocket and receive real-time streaming events.

### 4.20 Infrastructure Utilities

| Utility | File | Purpose |
|---------|------|---------|
| WSL detection | `src/infra/wsl.ts` | Detect Windows Subsystem for Linux |
| System presence | `src/infra/system-presence.ts` | Detect if user is present at computer |
| Port management | `src/infra/ports.ts` | Find available ports, check conflicts |
| Path resolution | `src/infra/paths.ts` | Resolve home, state, config directories |
| Process restart | `src/infra/restart.ts` | Graceful self-restart on update |
| Env utilities | `src/infra/env.ts` | Truthy env values, platform detection |
| Provider usage | `src/infra/provider-usage*.ts` | Track rate limits across all providers |

---

## 5. How the Agent Stays Alive

**Three layers of resilience:**

### Layer 1: OS Service Manager
```
systemd (Linux) / launchd (macOS)
    │
    ├─ Restart=always → auto-restart on crash
    ├─ RestartSec=5 → 5-second cooldown between restarts
    ├─ enable-linger → survives user logout
    └─ After=network-online.target → waits for network
```

### Layer 2: Process Supervisor (in-process)
```
Gateway Process
    │
    ├─ ProcessSupervisor tracks all child processes
    │   ├─ Overall timeout → kill stale processes
    │   ├─ No-output watchdog → kill hung processes
    │   └─ Scope-based replacement → new run replaces old
    │
    ├─ Channel reconnection logic (per channel)
    │   ├─ Telegram: grammY auto-reconnect
    │   ├─ Discord: discord.js reconnection
    │   └─ Slack: Bolt Socket Mode reconnect
    │
    └─ Health monitoring
        ├─ HealthSummary cache with periodic refresh
        └─ openclaw doctor for manual diagnostics
```

### Layer 3: Agent Runtime Failover
```
AI Call Attempt
    │
    ├─ Try profile 1 (anthropic:oauth-1)
    │   └─ 429 rate limit → cooldown, next
    │
    ├─ Try profile 2 (anthropic:oauth-2)
    │   └─ 403 auth error → next
    │
    ├─ Try profile 3 (openai-codex:default)
    │   └─ 200 success ✓
    │
    └─ All exhausted → throw FailoverError
        └─ External fallback model (if configured)
```

---

## 6. How Payloads Are Exchanged

### Inbound (User → Agent)

```typescript
// Channel receives raw platform message
TelegramMessage { text, from, chat, photo?, voice?, document? }

// Translated to common format
ChannelMessage {
  channel: "telegram",
  sessionKey: "telegram:12345",
  sender: "John",
  text: "Schedule a meeting",
  media?: ImageContent[],
}

// Becomes agent command
agentCommand({
  message: "Schedule a meeting",
  sessionKey: "telegram:12345",
  runId: "run_abc123",
  messageChannel: "telegram",
  images?: ImageContent[],
})
```

### Processing (Agent Internal)

```typescript
// Pi embedded runner produces events during execution:
Event: message_start    → { type: "message_start" }
Event: message_update   → { delta: { text: "I'll schedule..." } }
Event: tool_use_start   → { tool: "calendar_create", input: {...} }
Event: tool_use_end     → { result: "Event created" }
Event: message_update   → { delta: { text: "Done! Meeting scheduled." } }
Event: message_end      → { usage: { input_tokens, output_tokens } }
Event: agent_end        → { success: true }
```

### Outbound (Agent → User)

```typescript
// Agent result
EmbeddedPiRunResult {
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

// Channel formats and sends
telegram.sendMessage(chatId, "Done! Meeting scheduled for tomorrow 3 PM.");
```

### WebSocket Streaming (Real-time to companion apps)

```typescript
// Gateway broadcasts events as they happen:
ws.send({ type: "agent:delta", runId, text: "I'll schedule..." })
ws.send({ type: "agent:tool_start", runId, tool: "calendar_create" })
ws.send({ type: "agent:tool_end", runId, result: "Event created" })
ws.send({ type: "agent:delta", runId, text: "Done! Meeting scheduled." })
ws.send({ type: "agent:end", runId })
```

---

## 7. How Permissions Work

### Gateway Access Control

```
Request arrives at gateway
    │
    ├─ Is localhost? → Trusted (no auth needed)
    │
    ├─ Has Bearer token? → Compare with OPENCLAW_GATEWAY_TOKEN
    │   ├─ Match → Authorized
    │   └─ Mismatch → 401 (rate-limited per IP)
    │
    ├─ Has password? → Compare with configured password
    │
    ├─ Tailscale headers present? → Verify via whois API
    │   └─ Match allowed user → Authorized
    │
    └─ Trusted proxy? → Check IP against trustedProxies list
```

### Provider API Permissions

```
Agent needs to call AI model
    │
    ├─ Resolve auth profile for provider
    ├─ Profile has API key / OAuth token
    ├─ Token injected into authStorage
    ├─ API call made with Authorization header
    │
    ├─ 200 → Success
    ├─ 401/403 → Auth failure → rotate profile
    ├─ 429 → Rate limit → cooldown + rotate
    └─ 402 → Billing → rotate or fail
```

### Tool Execution Permissions

```
Model wants to run a tool
    │
    ├─ Is tool in allowed list? (per-agent config)
    │
    ├─ Is tool dangerous? (exec, file write, git push)
    │   ├─ ExecApprovalManager checks policy
    │   ├─ If approval required → broadcast to companion app
    │   ├─ User approves/denies via WebSocket
    │   └─ Tool executes only if approved
    │
    └─ Tool executes → result returned to model
```

---

## 8. Virtual Executive Assistant Blueprint

Based on the complete reverse engineering, here is how to build a virtual
executive assistant using OpenClaw as the foundation.

### 8.1 What You Get Out of the Box

OpenClaw already provides the core infrastructure for an executive assistant:

| Capability | OpenClaw Component | Ready? |
|-----------|-------------------|--------|
| Multi-channel inbox | 15+ channels built-in | Yes |
| AI reasoning | Claude/Codex/Gemini with failover | Yes |
| Persistent memory | Session files + embeddings | Yes |
| Tool execution | Browser, exec, file I/O | Yes |
| Scheduling | Cron system | Yes |
| Voice interaction | Wake word + STT + TTS | Yes |
| Always-on availability | systemd/launchd daemon | Yes |
| Mobile access | iOS/Android companion apps | Yes |
| Security | Auth, rate limiting, approval flows | Yes |

### 8.2 What to Build as Custom Skills

For a virtual executive assistant, create these custom skills:

```
skills/
├── calendar-manager/        # Google Calendar / Outlook integration
│   ├── manifest.yaml
│   ├── tools.ts             # create_event, list_events, reschedule
│   └── system-prompt.md     # Calendar management personality
│
├── email-assistant/         # Email triage and drafting
│   ├── tools.ts             # read_inbox, draft_reply, send_email
│   └── system-prompt.md     # Email handling rules
│
├── task-tracker/            # Todoist / Linear / Jira integration
│   ├── tools.ts             # create_task, update_status, list_tasks
│   └── system-prompt.md     # Task prioritization rules
│
├── contact-manager/         # CRM / contact lookup
│   ├── tools.ts             # lookup_contact, log_interaction
│   └── system-prompt.md     # Relationship context
│
├── meeting-prep/            # Meeting preparation and notes
│   ├── tools.ts             # get_agenda, summarize_notes, send_recap
│   └── system-prompt.md     # Meeting workflow
│
├── expense-tracker/         # Receipt scanning and expense reports
│   ├── tools.ts             # log_expense, generate_report
│   └── system-prompt.md     # Expense policies
│
└── daily-briefing/          # Morning briefing generator
    ├── tools.ts             # compile_briefing
    └── system-prompt.md     # Briefing format and priorities
```

### 8.3 Configuration for Executive Assistant

```json
{
  "agents": {
    "defaults": {
      "provider": "anthropic",
      "model": "claude-opus-4-6",
      "thinkLevel": "extended",
      "workspace": "~/executive-workspace",
      "heartbeat": {
        "prompt": "Check calendar for upcoming meetings and pending tasks"
      }
    },
    "routes": [
      { "channel": "telegram", "pattern": "dm:*", "agentId": "exec" },
      { "channel": "slack",    "pattern": "dm:*", "agentId": "exec" },
      { "channel": "discord",  "agentId": "personal" }
    ]
  },
  "skills": {
    "enabled": [
      "calendar-manager", "email-assistant", "task-tracker",
      "contact-manager", "meeting-prep", "daily-briefing",
      "1password", "notion", "himalaya"
    ]
  },
  "cron": {
    "jobs": [
      {
        "id": "morning-briefing",
        "schedule": "0 7 * * 1-5",
        "prompt": "Compile my morning briefing",
        "channel": "telegram"
      },
      {
        "id": "eod-summary",
        "schedule": "0 18 * * 1-5",
        "prompt": "Summarize today's activities and tomorrow's agenda",
        "channel": "slack"
      }
    ]
  },
  "voice": {
    "wake": ["hey assistant", "okay claw"],
    "tts": { "provider": "elevenlabs", "voice": "professional" }
  }
}
```

### 8.4 Implementation Roadmap

```
Phase 1: Foundation (Week 1)
├─ Fork/deploy OpenClaw on Linux VPS
├─ Configure auth (Anthropic OAuth + Codex OAuth)
├─ Connect primary channels (Telegram + Slack)
├─ Install as systemd service
└─ Verify basic chat works end-to-end

Phase 2: Core Skills (Week 2-3)
├─ Build calendar-manager skill (Google Calendar API)
├─ Build email-assistant skill (Gmail API or IMAP via himalaya)
├─ Build task-tracker skill (Todoist/Linear API)
├─ Configure cron jobs (morning briefing, EOD summary)
└─ Test multi-channel delivery

Phase 3: Advanced Skills (Week 3-4)
├─ Build contact-manager skill
├─ Build meeting-prep skill
├─ Build expense-tracker skill (with media pipeline for receipts)
├─ Configure voice wake + talk mode
└─ Deploy companion app on phone

Phase 4: Polish (Week 4+)
├─ Fine-tune system prompts for executive personality
├─ Add approval flows for sensitive actions (sending emails, etc.)
├─ Set up memory/embeddings for long-term context
├─ Configure multi-agent routing (work vs personal)
└─ Set up monitoring and alerts
```

### 8.5 Key Files to Modify

| What | Where | Why |
|------|-------|-----|
| Add new skills | `skills/{skill-name}/` | Custom executive assistant capabilities |
| System prompt | Agent config `agents.defaults` | Executive assistant personality |
| Channel config | `openclaw.json` `channels` section | Connect your messaging accounts |
| Cron jobs | `openclaw.json` `cron` section | Scheduled briefings and reminders |
| Auth profiles | `~/.openclaw/state/auth-profiles.json` | AI provider credentials |
| Tool permissions | Agent config `agents.agents.{id}` | Control what the assistant can do |

### 8.6 Key Code Entry Points

| Purpose | File | Function |
|---------|------|----------|
| Gateway startup | `src/gateway/server.ts` | `startGateway()` |
| Message routing | `src/gateway/sessions-resolve.ts` | `resolveSessionKeyFromResolveParams()` |
| Agent execution | `src/commands/agent.ts` | `agentCommand()` |
| AI model call | `src/agents/pi-embedded-runner/run.ts` | Main run loop |
| Auth resolution | `src/agents/auth-profiles/oauth.ts` | `resolveApiKeyForProfile()` |
| System prompt | `src/agents/cli-runner/helpers.ts` | `buildSystemPrompt()` |
| Tool injection | `src/agents/pi-embedded-runner/` | Tool handler registration |
| Skill loading | `skills/` | Skill manifests and handlers |
| Channel bridge | `src/channels/{channel}/` | Channel → gateway integration |
| Daemon install | `src/daemon/systemd.ts` | `installSystemdService()` |

---

## Appendix: File Reference Map

```
src/
├── agents/                          # AI agent runtime
│   ├── pi-embedded-runner/          #   In-process agent (primary)
│   │   ├── run.ts                   #     Main execution + failover loop
│   │   ├── model.ts                 #     Model catalog resolution
│   │   └── attempt.ts               #     Single attempt execution
│   ├── cli-runner.ts                #   External CLI agent runner
│   ├── auth-profiles/               #   Credential management
│   │   ├── types.ts                 #     Data model
│   │   ├── oauth.ts                 #     OAuth refresh + resolution
│   │   └── profiles.ts              #     Profile CRUD
│   ├── model-selection.ts           #   Model normalization + routing
│   ├── agent-scope.ts               #   Multi-agent scope resolution
│   ├── bootstrap-files.ts           #   Context file loading
│   └── failover-error.ts            #   Error classification
├── gateway/                         # Gateway server
│   ├── server.ts                    #   HTTP + WebSocket server
│   ├── server-methods/              #   Request handlers
│   ├── sessions-resolve.ts          #   Session routing
│   ├── openai-http.ts               #   OpenAI-compat API
│   └── auth.ts                      #   Authentication
├── channels/                        # Built-in channels
│   ├── telegram/                    #   Telegram (grammY)
│   ├── discord/                     #   Discord (discord.js)
│   ├── slack/                       #   Slack (Bolt)
│   ├── signal/                      #   Signal (signal-cli)
│   └── webchat/                     #   Web chat
├── commands/                        # CLI commands
│   ├── agent.ts                     #   agentCommand() entry point
│   ├── onboard.ts                   #   Setup wizard
│   └── auth-choice*.ts              #   Auth flows per provider
├── config/                          # Configuration
│   └── config.ts                    #   Schema + loading
├── daemon/                          # Service management
│   ├── systemd.ts                   #   Linux systemd
│   ├── launchd.ts                   #   macOS launchd
│   └── service-env.ts               #   Environment construction
├── process/                         # Process management
│   └── supervisor/                  #   Process supervisor
│       ├── supervisor.ts            #     Main supervisor
│       └── adapters/                #     Child + PTY adapters
├── memory/                          # Memory + embeddings
├── media/                           # Media upload pipeline
├── media-understanding/             # Vision + transcription
├── security/                        # Audit + policy
├── logging/                         # Subsystem loggers
├── infra/                           # Infrastructure utilities
└── auto-reply/                      # Heartbeat + thinking

extensions/                          # 39 channel extensions
skills/                              # 54 bundled skills
ui/                                  # Web UI
apps/                                # Companion apps (macOS, iOS, Android)
docs/                                # Documentation
```
