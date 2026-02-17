# Study: How Claude and OpenAI Codex Connect via Linux in OpenClaw

## Overview

OpenClaw is a multi-channel AI gateway that unifies access to multiple AI providers
— including Anthropic Claude and OpenAI Codex — through a single Linux daemon
process managed by systemd. Both providers are treated as interchangeable backends
behind a common auth, routing, and failover layer.

---

## 1. Architecture Diagram

```
systemd user service (openclaw-gateway.service)
│
└─ Node.js Gateway Process (port 18789)
    │
    ├─ WebSocket Control Plane ─── channels (Telegram, Discord, Slack, …)
    │
    ├─ OpenAI-Compatible HTTP API (/v1/chat/completions)
    │   └─ openai-http.ts — translates OpenAI chat format → internal agent calls
    │
    ├─ Session Manager
    │   └─ routes messages to agents based on session key / agent ID
    │
    ├─ Embedded Pi Agent Runtime (pi-embedded-runner)
    │   ├─ Model Resolution ─── resolves "opus" → claude-opus-4-6
    │   │                        resolves "gpt-5.3-codex" → openai-codex
    │   ├─ Auth Profile Store ─── reads ~/.openclaw/state/auth-profiles.json
    │   │                          supports API keys, tokens, and OAuth
    │   ├─ Failover Loop ─── cycles auth profiles on errors / rate limits
    │   └─ Process Supervisor ─── spawns child processes with correct env
    │
    └─ CLI Runner (cli-runner.ts)
        └─ Spawns external CLI agents (claude, codex) as child processes
            with managed timeouts, serialization queues, and session tracking
```

---

## 2. Provider Registration

Both Claude and Codex are registered as first-class providers:

| Internal ID      | Display Name | Usage API Endpoint                            |
|------------------|-------------|-----------------------------------------------|
| `anthropic`      | Claude      | `https://api.anthropic.com/api/oauth/usage`   |
| `openai-codex`   | Codex       | `https://chatgpt.com/backend-api/wham/usage`  |

**Source**: `src/infra/provider-usage.shared.ts:6-15`

```typescript
export const PROVIDER_LABELS: Record<UsageProviderId, string> = {
  anthropic: "Claude",
  "openai-codex": "Codex",
  // … other providers
};
```

---

## 3. Authentication — Unified Auth Profile Store

Both providers share the same credential storage format (`auth-profiles.json`):

```typescript
type AuthProfileCredential =
  | { type: "api_key"; provider: string; apiKey: string }
  | { type: "token";   provider: string; token: string }
  | { type: "oauth";   provider: string; accessToken: string; refreshToken: string; expires: number };
```

**Source**: `src/agents/auth-profiles/types.ts`

### Claude Auth Flow
- **OAuth**: `claude setup-token` → paste token → stored as `anthropic:default` profile
- **API Key**: Direct `ANTHROPIC_API_KEY` environment variable
- **Web Session**: Falls back to `CLAUDE_AI_SESSION_KEY` cookie for usage tracking

**Source**: `src/commands/auth-choice.apply.anthropic.ts`

### Codex Auth Flow
- **OAuth**: Browser-based OpenAI OAuth flow via `loginOpenAICodex()` on localhost:1455
- **Supports VPS**: Remote/headless mode shows URL for manual browser auth

**Source**: `src/commands/openai-codex-oauth.ts`

### Profile Constants
```typescript
export const CLAUDE_CLI_PROFILE_ID = "anthropic:claude-cli";
export const CODEX_CLI_PROFILE_ID  = "openai-codex:codex-cli";
```

**Source**: `src/agents/auth-profiles/constants.ts`

---

## 4. Model Selection and Routing

The model selection layer normalizes provider/model references and determines routing:

```typescript
export function normalizeModelRef(provider: string, model: string): ModelRef {
  const normalizedProvider = normalizeProviderId(provider);
  const normalizedModel = normalizeProviderModelId(normalizedProvider, model);

  if (shouldUseOpenAICodexProvider(normalizedProvider, normalizedModel)) {
    return { provider: "openai-codex", model: normalizedModel };
  }
  return { provider: normalizedProvider, model: normalizedModel };
}
```

**Source**: `src/agents/model-selection.ts`

### Model Aliases
- Claude: `opus-4.6` → `claude-opus-4-6`, `sonnet-4.5` → `claude-sonnet-4-5`
- Codex: `gpt-5.3-codex` prefix routes to `openai-codex` provider

---

## 5. Failover Between Providers

When one provider fails, the system cycles through auth profiles:

```
Request → Claude (profile 1) → 429 rate limit
       → Claude (profile 2) → 403 auth error
       → Codex (profile 1)  → 200 success ✓
```

### Failover Reasons
```typescript
type FailoverReason = "auth" | "rate_limit" | "billing" | "timeout" | "format" | "unknown";
```

### Cooldown Tracking
Rate-limited profiles enter cooldown to prevent hammering:

```typescript
if (isProfileInCooldown(authStore, candidate)) {
  nextIndex += 1;  // Skip this profile
  continue;
}
```

**Source**: `src/agents/pi-embedded-runner/run.ts:405-460`

---

## 6. Linux systemd Integration

The gateway runs as a systemd user service on Linux:

### Unit File
```ini
[Unit]
Description=OpenClaw Gateway (profile: default, v2026.2.16)
After=network-online.target
Wants=network-online.target

[Service]
ExecStart=/usr/local/bin/openclaw gateway --port 18789
Restart=always
RestartSec=5

[Install]
WantedBy=default.target
```

**Location**: `~/.config/systemd/user/openclaw-gateway.service`

### Service Environment

The daemon constructs a minimal environment for the Node.js process:

```typescript
function buildServiceEnvironment(params) {
  return {
    HOME: env.HOME,
    PATH: buildMinimalServicePath({ env }),      // includes nvm/fnm/pnpm paths
    OPENCLAW_STATE_DIR: env.OPENCLAW_STATE_DIR,  // where auth-profiles.json lives
    OPENCLAW_CONFIG_PATH: env.OPENCLAW_CONFIG_PATH,
    OPENCLAW_GATEWAY_PORT: String(port),
    OPENCLAW_GATEWAY_TOKEN: token,
    OPENCLAW_SYSTEMD_UNIT: systemdUnit,
  };
}
```

**Source**: `src/daemon/service-env.ts`

### Linux PATH Resolution

The service PATH is carefully constructed to find node binaries across version managers:

```typescript
function resolveLinuxUserBinDirs(home, env): string[] {
  return [
    env?.PNPM_HOME,
    `${home}/.nvm/current/bin`,   // nvm
    `${home}/.fnm/current/bin`,   // fnm
    `${home}/.local/share/pnpm`,  // pnpm global
  ];
}
```

**Source**: `src/daemon/service-env.ts:140-199`

### systemd Management API

Full lifecycle management via `systemctl --user`:

| Operation     | Function                      | systemctl command                        |
|---------------|-------------------------------|------------------------------------------|
| Install       | `installSystemdService()`     | `daemon-reload` → `enable` → `restart`   |
| Uninstall     | `uninstallSystemdService()`   | `disable --now` + remove unit file       |
| Stop          | `stopSystemdService()`        | `stop`                                   |
| Restart       | `restartSystemdService()`     | `restart`                                |
| Status        | `readSystemdServiceRuntime()` | `show --property ActiveState,SubState,…` |
| Linger        | `enableSystemdUserLinger()`   | `loginctl enable-linger`                 |

**Source**: `src/daemon/systemd.ts`

---

## 7. Process Supervisor — Agent Child Process Management

The process supervisor manages agent execution as Linux child processes:

```typescript
const supervisor = getProcessSupervisor();
const managedRun = await supervisor.spawn({
  sessionId,
  backendId,             // "anthropic" or "openai-codex"
  scopeKey,
  mode: "child",         // spawns via child_process.spawn
  argv: [command, ...args],
  timeoutMs,
  noOutputTimeoutMs,     // watchdog kills silent processes
  cwd: workspaceDir,
  env,                   // inherits OPENCLAW_STATE_DIR, auth keys, etc.
  input: stdinPayload,
});
```

**Source**: `src/agents/cli-runner.ts:241-253`

Key behaviors:
- **Serialization queue**: Prevents concurrent runs to the same backend
- **Watchdog timeout**: Kills agents that produce no output for too long
- **Scope-based replacement**: New runs for the same session replace stale ones

---

## 8. OpenAI-Compatible HTTP Gateway

The gateway exposes an OpenAI-compatible `/v1/chat/completions` endpoint that
internally routes to whichever provider is configured:

```typescript
export async function handleOpenAiHttpRequest(req, res, opts): Promise<boolean> {
  // POST /v1/chat/completions
  // Accepts standard OpenAI chat format
  // Routes internally through agentCommand() → embedded Pi agent → Claude or Codex
}
```

**Source**: `src/gateway/openai-http.ts`

This means any client that speaks the OpenAI protocol can transparently use
Claude or Codex through the Linux gateway, with automatic failover and
auth rotation handled server-side.

---

## 9. Usage Tracking

Both providers report usage through a unified interface:

```typescript
type ProviderUsageSnapshot = {
  provider: "anthropic" | "openai-codex";
  displayName: string;                     // "Claude" or "Codex"
  windows: UsageWindow[];                  // rate limit windows
  plan?: string;                           // subscription tier
  error?: string;
};
```

### Claude Usage Windows
- **5-hour window**: Short-term rate limit utilization
- **7-day window**: Weekly rate limit utilization
- **Model-specific**: Per-model (Sonnet/Opus) utilization

**Source**: `src/infra/provider-usage.fetch.claude.ts:19-47`

### Codex Usage Windows
- **Primary window**: Configurable (default 3h), used_percent based
- **Secondary window**: Configurable (default 24h), used_percent based
- **Plan/credits**: Shows subscription tier and credit balance

**Source**: `src/infra/provider-usage.fetch.codex.ts:65-93`

---

## 10. Key Takeaways

1. **Provider abstraction**: Claude and Codex are interchangeable at the auth,
   model selection, and agent runtime layers. The system treats them as pluggable
   backends behind a unified interface.

2. **Linux as the runtime platform**: systemd user services provide process
   lifecycle management (start/stop/restart/enable), while the process supervisor
   adds agent-specific concerns (timeouts, serialization, scope-based replacement).

3. **Credential unification**: A single `auth-profiles.json` file stores
   credentials for all providers. OAuth refresh, token rotation, and failover
   happen transparently.

4. **OpenAI protocol as lingua franca**: The gateway speaks the OpenAI chat
   completions protocol externally, regardless of which backend model handles
   the request internally.

5. **Failover as a first-class feature**: Rate limits, auth errors, and timeouts
   automatically trigger profile rotation and potentially provider switching,
   maximizing availability.

---

## File Reference Index

| File | Role |
|------|------|
| `src/infra/provider-usage.fetch.claude.ts` | Claude usage/rate-limit tracking |
| `src/infra/provider-usage.fetch.codex.ts` | Codex usage/rate-limit tracking |
| `src/infra/provider-usage.shared.ts` | Provider labels and shared utilities |
| `src/infra/provider-usage.types.ts` | Shared usage types |
| `src/agents/auth-profiles/` | Credential storage, rotation, OAuth refresh |
| `src/agents/model-selection.ts` | Provider/model normalization and routing |
| `src/agents/pi-embedded-runner/run.ts` | Core agent execution with failover loop |
| `src/agents/pi-embedded-runner/model.ts` | Model catalog resolution |
| `src/agents/cli-runner.ts` | CLI-based agent execution (Claude CLI, Codex CLI) |
| `src/agents/failover-error.ts` | Failover error classification |
| `src/commands/auth-choice.apply.anthropic.ts` | Claude auth onboarding |
| `src/commands/openai-codex-oauth.ts` | Codex OAuth onboarding |
| `src/gateway/openai-http.ts` | OpenAI-compatible HTTP endpoint |
| `src/daemon/systemd.ts` | systemd service lifecycle management |
| `src/daemon/service-env.ts` | Service environment construction |
| `src/process/supervisor/` | Agent child process supervision |
| `docs/platforms/linux.md` | Linux platform documentation |
