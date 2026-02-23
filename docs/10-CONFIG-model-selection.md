# 10 - Configuration & Model Selection

> **Cross-references:** [01-BRIDGE](./01-BRIDGE-agent-runtime.md) | [02-AUTH](./02-AUTH-credential-lifecycle.md) | [04-FLOW](./04-FLOW-message-pipeline.md) | [07-SESSION](./07-SESSION-state-management.md) | [08-CHANNELS](./08-CHANNELS-integration-bridge.md) | [09-SKILLS](./09-SKILLS-tools-ecosystem.md)

---

## Overview

OpenClaw's configuration system defines how agents are routed, which models are used, how channels connect, and what skills are available. The config supports multi-agent routing — different channels/users can be served by different agents with different models.

---

## Config File

**File:** `~/.openclaw/openclaw.json`
**Schema:** `src/config/config.ts`, `src/config/types.ts`

```json
{
  "gateway": {
    "port": 18789,
    "token": "...",               // OPENCLAW_GATEWAY_TOKEN override
    "auth": {
      "method": "token",          // "token" | "password" | "tailscale"
      "password": "...",          // For password auth
      "tailscaleUsers": ["user@example.com"]
    },
    "trustedProxies": ["10.0.0.1/24"]
  },

  "agents": {
    "defaults": {
      "provider": "anthropic",
      "model": "claude-opus-4-6",
      "workspace": "~/projects",
      "thinkLevel": "default",     // "none" | "default" | "extended"
      "heartbeat": {
        "prompt": "Check for pending tasks"
      },
      "cliBackends": {
        "claude-cli": { ... },     // Override built-in Claude CLI config
        "codex-cli": { ... },      // Override built-in Codex CLI config
        "custom-llm": { ... }      // Add custom CLI backend
      }
    },
    "routes": [
      { "channel": "telegram", "pattern": "group:*", "agentId": "work" },
      { "channel": "discord",  "agentId": "personal" },
      { "channel": "slack",    "pattern": "dm:*", "agentId": "work" }
    ],
    "agents": {
      "work": {
        "provider": "anthropic",
        "model": "claude-opus-4-6",
        "workspace": "/projects/work",
        "thinkLevel": "extended",
        "skills": { "enabled": ["coding-agent", "github", "linear"] }
      },
      "personal": {
        "provider": "openai-codex",
        "model": "gpt-5.3-codex",
        "workspace": "~/personal",
        "skills": { "enabled": ["notion", "calendar", "todoist"] }
      }
    }
  },

  "auth": {
    "profiles": {
      "anthropic:personal": { "provider": "anthropic", "mode": "oauth" },
      "anthropic:work-key": { "provider": "anthropic", "mode": "api_key" },
      "openai-codex:default": { "provider": "openai-codex", "mode": "oauth" }
    },
    "order": {
      "anthropic": ["anthropic:personal", "anthropic:work-key"]
    },
    "cooldowns": {
      "billingBackoffHours": 5,
      "billingMaxHours": 24,
      "failureWindowHours": 24
    }
  },

  "channels": {
    "telegram": { "botToken": "..." },
    "discord": { "botToken": "...", "applicationId": "..." },
    "slack": { "botToken": "xoxb-...", "appToken": "xapp-..." },
    "signal": { "number": "+1234567890" }
  },

  "extensions": ["matrix", "whatsapp", "teams"],

  "skills": {
    "enabled": ["coding-agent", "notion", "browser"],
    "disabled": ["shell"]
  },

  "voice": {
    "wake": ["hey assistant", "okay claw"],
    "tts": { "provider": "elevenlabs", "voice": "professional" }
  },

  "cron": {
    "jobs": [
      {
        "id": "morning-briefing",
        "schedule": "0 7 * * 1-5",
        "prompt": "Compile morning briefing",
        "channel": "telegram"
      }
    ]
  }
}
```

---

## Model Selection & Normalization

**File:** `src/agents/model-selection.ts`

Model identifiers are normalized from human-friendly aliases to canonical IDs:

```typescript
// Alias normalization:
"opus"           → { provider: "anthropic",        model: "claude-opus-4-6" }
"opus-4.6"       → { provider: "anthropic",        model: "claude-opus-4-6" }
"sonnet"         → { provider: "anthropic",        model: "claude-sonnet-4-5" }
"sonnet-4.5"     → { provider: "anthropic",        model: "claude-sonnet-4-5" }
"haiku"          → { provider: "anthropic",        model: "claude-haiku-3-5" }
"gpt-5.3-codex"  → { provider: "openai-codex",    model: "gpt-5.3-codex" }
"gemini"         → { provider: "google-gemini-cli", model: "gemini-2.5-pro" }
```

### Provider ID Normalization

```typescript
function normalizeProviderId(provider: string): string {
  // Lowercase, trim, handle aliases:
  // "Anthropic" → "anthropic"
  // "openai"    → "openai-codex"
  // "claude"    → "anthropic"
  // "gemini"    → "google-gemini-cli"
}
```

### CLI Model Aliases

For CLI backends, model names are further normalized (see [01-BRIDGE](./01-BRIDGE-agent-runtime.md)):

```typescript
// Claude CLI aliases:
"opus"              → "opus"        // claude --model opus
"claude-opus-4-6"   → "opus"
"sonnet-4.5"        → "sonnet"
"claude-sonnet-4-5" → "sonnet"

// Codex CLI: passes model ID directly
"gpt-5.3-codex"    → "gpt-5.3-codex"
```

---

## Multi-Agent Routing

**File:** `src/agents/agent-scope.ts`

### Route Matching

Routes determine which agent handles which messages:

```typescript
type AgentRoute = {
  channel: string;        // "telegram", "discord", etc.
  pattern?: string;       // Optional pattern: "group:*", "dm:*", etc.
  agentId: string;        // Which agent handles matching messages
};
```

```
Incoming message: sessionKey = "telegram:-1001234567"
    │
    ├─ Check routes in order:
    │
    │   Route 1: { channel: "telegram", pattern: "group:*", agentId: "work" }
    │   ├─ Channel matches? "telegram" == "telegram" ✓
    │   ├─ Pattern matches? chatId starts with "-" (group) ✓
    │   └─ → agentId: "work"
    │
    │   Route 2: { channel: "discord", agentId: "personal" }
    │   └─ Channel doesn't match → skip
    │
    ├─ No route matches?
    │   └─ Use default agent (agents.defaults)
    │
    └─ Load agent config: agents.agents["work"]
        → { provider: "anthropic", model: "opus", workspace: "/projects/work" }
```

### Agent Scope Resolution

```typescript
function resolveSessionAgentIds(params: {
  sessionKey?: string;
  config?: OpenClawConfig;
}): { defaultAgentId: string; sessionAgentId: string }
```

Each agent gets its own:
- **Workspace directory** — file operations are isolated
- **Session files** — conversations don't cross agents
- **Auth profiles** — can inherit from main or have own
- **System prompt** — custom personality per agent
- **Tool permissions** — different skills per agent
- **Thinking level** — per-agent reasoning depth

---

## Config Hot-Reload

**File:** `src/gateway/config-reload.ts`

The gateway supports reloading config without restart:

```
Config change detected (file watch or WebSocket command)
    │
    ├─ Read and validate new config
    │
    ├─ Apply non-disruptive changes:
    │   ├─ Model/provider changes → next message uses new model
    │   ├─ Route changes → next message routes to new agent
    │   ├─ Skill changes → next session loads new skills
    │   └─ Cron changes → update scheduled jobs
    │
    ├─ Apply disruptive changes (require channel restart):
    │   ├─ Channel config changes → stop + restart channel
    │   └─ Extension changes → load/unload extensions
    │
    └─ Broadcast: { type: "config.reloaded" }
```

---

## CliBackendConfig Type

**File:** `src/config/types.ts`

Full type definition for CLI backend configuration:

```typescript
type CliBackendConfig = {
  command: string;              // Binary to execute ("claude", "codex", etc.)
  args?: string[];              // Default arguments
  resumeArgs?: string[];        // Arguments when resuming session
  output?: "json" | "jsonl" | "text";
  resumeOutput?: "json" | "jsonl" | "text";
  input?: "arg" | "stdin";     // How to pass prompt
  modelArg?: string;            // "--model"
  modelAliases?: Record<string, string>;
  sessionArg?: string;          // "--session-id"
  sessionArgs?: string[];       // Multi-arg session (["--resume", "{sessionId}"])
  sessionMode?: "always" | "existing";
  sessionIdFields?: string[];   // JSON fields to extract session ID
  systemPromptArg?: string;     // "--append-system-prompt"
  systemPromptMode?: "append" | "replace";
  systemPromptWhen?: "first" | "always";
  imageArg?: string;            // "--image"
  imageMode?: "repeat" | "csv";
  env?: Record<string, string>; // Extra environment variables
  clearEnv?: string[];          // Env vars to remove
  serialize?: boolean;          // One run at a time per backend
  reliability?: {
    watchdog?: {
      fresh?: { noOutputTimeoutMs?: number };
      resume?: { noOutputTimeoutMs?: number };
    };
  };
};
```

---

## Agent Defaults

**File:** `src/agents/defaults.ts`

Default values when not specified in config:

```typescript
// Default provider: anthropic
// Default model: claude-sonnet-4-5 (balanced cost/capability)
// Default thinkLevel: "default"
// Default workspace: process.cwd() or ~
// Default heartbeat: none
```

---

## Model Catalog

The gateway maintains a catalog of available models:

```
loadGatewayModelCatalog()
    │
    ├─ Check auth profiles → which providers are configured?
    │
    ├─ For each provider:
    │   ├─ Anthropic → claude-opus-4-6, claude-sonnet-4-5, claude-haiku-3-5
    │   ├─ OpenAI Codex → gpt-5.3-codex, gpt-4.1-codex
    │   ├─ Google Gemini → gemini-2.5-pro, gemini-2.5-flash
    │   ├─ GitHub Copilot → copilot-chat
    │   └─ CLI backends → whatever models the CLI supports
    │
    └─ Return catalog with pricing, context limits, capabilities
```

Exposed via WebSocket: `{ type: "models.catalog" }` and HTTP API model listing.

---

## Environment Variables

Key environment variables that override config:

| Variable | Purpose |
|----------|---------|
| `OPENCLAW_STATE_DIR` | State directory (default: `~/.openclaw`) |
| `OPENCLAW_GATEWAY_TOKEN` | Gateway auth token |
| `OPENCLAW_GATEWAY_PORT` | Gateway port (default: 18789) |
| `ANTHROPIC_API_KEY` | Direct Anthropic API key |
| `OPENAI_API_KEY` | Direct OpenAI API key |
| `CODEX_HOME` | Codex CLI home directory |
| `OPENCLAW_CLAUDE_CLI_LOG_OUTPUT` | Log CLI stdout/stderr |

---

## Config Paths

**File:** `src/config/paths.ts`

```
~/.openclaw/
├─ openclaw.json           # Main config file
├─ state/
│  └─ auth-profiles.json   # Credential store
├─ sessions/               # Session files
├─ agents/                 # Per-agent state
│  ├─ work/
│  │  ├─ sessions/
│  │  └─ state/
│  └─ personal/
│     ├─ sessions/
│     └─ state/
└─ logs/                   # Log files
```

---

## Key Source Files

| File | Purpose |
|------|---------|
| `src/config/config.ts` | Config schema, loading, validation |
| `src/config/types.ts` | All config type definitions |
| `src/config/paths.ts` | Config/state directory resolution |
| `src/agents/model-selection.ts` | Model normalization and routing |
| `src/agents/agent-scope.ts` | Multi-agent scope resolution |
| `src/agents/defaults.ts` | Default agent values |
| `src/agents/cli-backends.ts` | CLI backend config resolution |
| `src/gateway/config-reload.ts` | Hot-reload configuration |
| `src/gateway/protocol/schema/config.ts` | Config WebSocket frames |
| `src/gateway/protocol/schema/agents-models-skills.ts` | Model catalog frames |
