# 05 - Resilience & Failover System

> **Cross-references:** [01-BRIDGE](./01-BRIDGE-agent-runtime.md) | [02-AUTH](./02-AUTH-credential-lifecycle.md) | [06-PROCESS](./06-PROCESS-supervisor-management.md) | [07-SESSION](./07-SESSION-state-management.md)

---

## Overview

OpenClaw has **three layers of resilience** that work together to keep the assistant always available:

```
Layer 1: OS Service Manager     — Auto-restart crashed gateway
Layer 2: Process Supervisor     — Kill hung processes, reconnect channels
Layer 3: Agent Runtime Failover — Rotate credentials, fallback models, compact context
```

---

## Layer 1: OS Service Manager

### Linux (systemd)

```ini
# ~/.config/systemd/user/openclaw-gateway.service
[Unit]
Description=OpenClaw Gateway
After=network-online.target

[Service]
ExecStart=/usr/local/bin/openclaw gateway --port 18789
Restart=always
RestartSec=5
Environment=HOME=/home/user
Environment=PATH=/usr/local/bin:/usr/bin
Environment=OPENCLAW_STATE_DIR=~/.openclaw
Environment=OPENCLAW_GATEWAY_TOKEN=...

[Install]
WantedBy=default.target
```

- `Restart=always` → auto-restart on crash, OOM, or signal
- `RestartSec=5` → 5-second cooldown between restarts
- `enable-linger` → survives user logout (headless operation)
- `After=network-online.target` → waits for network connectivity

### macOS (launchd)

```xml
<!-- ~/Library/LaunchAgents/ai.openclaw.gateway.plist -->
<dict>
  <key>ProgramArguments</key>
  <array><string>node</string><string>openclaw</string><string>gateway</string></array>
  <key>RunAtLoad</key><true/>
  <key>KeepAlive</key><true/>
  <key>EnvironmentVariables</key>
  <dict>
    <key>OPENCLAW_STATE_DIR</key><string>~/.openclaw</string>
  </dict>
</dict>
```

---

## Layer 2: Process Supervisor

**File:** `src/process/supervisor/supervisor.ts`

See [06-PROCESS](./06-PROCESS-supervisor-management.md) for full details.

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
    ├─ Channel health monitoring
    │   └─ Periodic health checks, restart unhealthy channels
    │
    └─ Health monitoring
        ├─ HealthSummary cache with periodic refresh
        └─ `openclaw doctor` for manual diagnostics
```

---

## Layer 3: Agent Runtime Failover

**Files:** `src/agents/failover-error.ts`, `src/agents/pi-embedded-helpers.ts`

### The FailoverError Class

```typescript
class FailoverError extends Error {
  readonly reason: FailoverReason;    // Why it failed
  readonly provider?: string;          // Which provider
  readonly model?: string;             // Which model
  readonly profileId?: string;         // Which auth profile
  readonly status?: number;            // HTTP status code
  readonly code?: string;              // Error code (ETIMEDOUT, etc.)
}

type FailoverReason =
  | "auth"        // 401/403 — bad credentials
  | "rate_limit"  // 429 — too many requests
  | "billing"     // 402 — payment required
  | "timeout"     // 408/ETIMEDOUT — request too slow
  | "format"      // 400 — bad request (thinking level, etc.)
  | "unknown";    // Unclassified error
```

### Error Classification

```typescript
function resolveFailoverReasonFromError(err: unknown): FailoverReason | null {
  // 1. Already a FailoverError? Use its reason
  if (isFailoverError(err)) return err.reason;

  // 2. Check HTTP status codes
  if (status === 402) return "billing";
  if (status === 429) return "rate_limit";
  if (status === 401 || status === 403) return "auth";
  if (status === 408) return "timeout";
  if (status === 400) return "format";

  // 3. Check error codes
  if (["ETIMEDOUT","ESOCKETTIMEDOUT","ECONNRESET","ECONNABORTED"].includes(code))
    return "timeout";

  // 4. Check error names
  if (errorName === "TimeoutError") return "timeout";
  if (errorName === "AbortError" && messageMatchesTimeout) return "timeout";

  // 5. Pattern match on error message
  return classifyFailoverReason(message);
}
```

### Failover Priority Chain

```
AI Call Attempt
    │
    ├─ Try profile 1 (anthropic:oauth-1)
    │   ├─ 401 auth error
    │   ├─ → markAuthProfileFailure(reason: "auth")
    │   │   → cooldownUntil = now + 1min
    │   └─ → rotate to next profile
    │
    ├─ Try profile 2 (anthropic:oauth-2)
    │   ├─ 429 rate limit
    │   ├─ → markAuthProfileFailure(reason: "rate_limit")
    │   │   → cooldownUntil = now + 1min
    │   └─ → rotate to next profile
    │
    ├─ Try profile 3 (anthropic:api-key)
    │   ├─ 402 billing error
    │   ├─ → markAuthProfileFailure(reason: "billing")
    │   │   → disabledUntil = now + 5 hours (billing backoff)
    │   └─ → rotate to next profile
    │
    ├─ All profiles exhausted for "anthropic"
    │   └─ FailoverError thrown
    │       └─ External fallback model (if configured)
    │
    └─ Success? → markAuthProfileUsed()
        → errorCount = 0, cooldownUntil = null
```

### Failover Actions by Reason

| Reason | Action | Cooldown |
|--------|--------|----------|
| `auth` (401/403) | Rotate to next profile | 1min → 5min → 25min → 1h |
| `rate_limit` (429) | Cooldown current profile, rotate | 1min → 5min → 25min → 1h |
| `billing` (402) | Disable profile long-term, rotate | 5h → 10h → 20h → 24h |
| `timeout` (408) | Kill process, rotate profile | 1min → 5min → 25min → 1h |
| `format` (400) | Reduce thinking level, retry | No cooldown (retry same profile) |
| `unknown` | Cooldown + rotate | 1min → 5min → 25min → 1h |

### Exponential Backoff

```typescript
// Standard cooldown (rate_limit, auth, timeout, unknown):
function calculateAuthProfileCooldownMs(errorCount: number): number {
  return Math.min(
    60 * 60 * 1000,                              // 1 hour max
    60 * 1000 * 5 ** Math.min(errorCount - 1, 3)  // 1min, 5min, 25min, 60min
  );
}

// Billing cooldown (much longer):
function calculateBillingDisableMs(errorCount, baseMs, maxMs): number {
  return Math.min(maxMs, baseMs * 2 ** Math.min(errorCount - 1, 10));
  // 5h, 10h, 20h, 24h (capped)
}
```

---

## Context Window Management

**File:** `src/agents/context-window-guard.ts`, `src/agents/compaction.ts`

When the conversation gets too long for the model's context window:

```
Context overflow detected
    │
    ├─ Step 1: Truncate large tool results
    │   └─ Tool results > threshold → summarized or trimmed
    │
    ├─ Step 2: Compact conversation
    │   ├─ Summarize older turns into a condensed format
    │   ├─ Keep recent N turns intact
    │   └─ Replace old turns with summary
    │
    ├─ Step 3: Retry the request
    │   └─ With reduced context, the request fits
    │
    └─ Still too large?
        └─ Start fresh session (last resort)
```

See [07-SESSION](./07-SESSION-state-management.md) for compaction details.

---

## Thinking Level Fallback

```
Model call with thinkLevel="extended"
    │
    ├─ 400 error: "thinking not supported for this model"
    │
    ├─ Retry with thinkLevel="default"
    │   ├─ Success? → continue
    │   └─ 400 again?
    │
    └─ Retry with thinkLevel="none"
        └─ Model must support this → success or real error
```

---

## Success Recovery

When a profile succeeds after previous failures:

```typescript
async function markAuthProfileUsed(params): Promise<void> {
  freshStore.usageStats[profileId] = {
    lastUsed: Date.now(),
    errorCount: 0,             // Reset!
    cooldownUntil: undefined,  // Clear!
    disabledUntil: undefined,  // Clear!
    disabledReason: undefined, // Clear!
    failureCounts: undefined,  // Clear!
  };
}
```

All error state is immediately cleared on success — the circuit breaker closes.

---

## Failure Window Decay

Error counts don't accumulate forever. After a configurable window (default 24h), they reset:

```typescript
const windowExpired = now - existing.lastFailureAt > failureWindowMs;
const baseErrorCount = windowExpired ? 0 : existing.errorCount;
// If 24h since last failure, start fresh
```

This prevents a profile from being permanently penalized for transient issues from hours ago.

---

## Kill Strategy (Unix)

**File:** `src/process/kill-tree.ts`

When a process needs to be terminated (timeout, cancel, scope replacement):

```
Step 1: Send SIGTERM to process group (-pid)
    │
    ├─ Wait 3 seconds for graceful shutdown
    │
    ├─ Process exited? → done
    │
    └─ Still running?
        └─ Step 2: Send SIGKILL to process group (-pid)
            → Immediate termination, no graceful shutdown
```

Using the process group (`-pid`) ensures all child processes of the CLI agent are also killed.

---

## Health Monitoring

### openclaw doctor

Diagnostic command that checks:
- PATH includes node and openclaw
- Gateway token is set
- Runtime (Node.js) version is compatible
- systemd/launchd unit is configured correctly
- Auth profiles have valid credentials
- Channels are connected and healthy

### Channel Health Monitor

**File:** `src/gateway/channel-health-monitor.ts`

```
Periodic check (every N seconds):
    │
    ├─ For each active channel:
    │   ├─ Is connection alive?
    │   ├─ Last message time within threshold?
    │   └─ Any error state?
    │
    ├─ Unhealthy channel detected:
    │   ├─ Stop channel
    │   ├─ Wait backoff period
    │   └─ Restart channel
    │
    └─ Report health summary
```

---

## Key Source Files

| File | Purpose |
|------|---------|
| `src/agents/failover-error.ts` | FailoverError class, classification, status mapping |
| `src/agents/pi-embedded-helpers.ts` | Error message pattern matching |
| `src/agents/auth-profiles/usage.ts` | Cooldown tracking, exponential backoff |
| `src/agents/compaction.ts` | Conversation compaction |
| `src/agents/context-window-guard.ts` | Context overflow detection |
| `src/process/kill-tree.ts` | Process group kill strategy |
| `src/gateway/channel-health-monitor.ts` | Channel health checking |
| `src/agents/auth-health.ts` | Auth profile health diagnostics |
| `src/daemon/systemd.ts` | systemd service installation |
| `src/daemon/launchd.ts` | launchd service installation |
