# 02 - Authentication & Credential Lifecycle

> **Cross-references:** [01-BRIDGE](./01-BRIDGE-agent-runtime.md) | [05-RESILIENCE](./05-RESILIENCE-failover-system.md) | [10-CONFIG](./10-CONFIG-model-selection.md)

---

## Overview

OpenClaw manages credentials for **7+ AI providers** through a unified **Auth Profile Store**. Each profile can be an API key, static token, or OAuth credential with automatic refresh. Profiles are rotated on failure using round-robin with exponential backoff cooldowns.

```
┌─────────────────────────────────────────────────────────────────┐
│                    AUTH PROFILE STORE                            │
│              ~/.openclaw/state/auth-profiles.json                │
│                                                                  │
│  ┌──────────────────┐  ┌──────────────────┐  ┌──────────────┐ │
│  │ anthropic:oauth-1 │  │ anthropic:oauth-2 │  │ openai:key-1 │ │
│  │ type: "oauth"     │  │ type: "oauth"     │  │ type:"api_key"│ │
│  │ access: "ey..."   │  │ access: "ey..."   │  │ key: "sk-..." │ │
│  │ refresh: "rt..."  │  │ refresh: "rt..."  │  └──────────────┘ │
│  │ expires: 170...   │  │ expires: 170...   │                    │
│  └──────────────────┘  └──────────────────┘                    │
│                                                                  │
│  order:    { anthropic: ["oauth-1", "oauth-2"] }               │
│  lastGood: { anthropic: "oauth-1" }                            │
│  usageStats: { "oauth-1": { lastUsed, cooldownUntil, ... } }  │
└─────────────────────────────────────────────────────────────────┘
```

---

## Credential Types

**File:** `src/agents/auth-profiles/types.ts`

```typescript
// Three credential types:
type ApiKeyCredential = {
  type: "api_key";
  provider: string;       // "anthropic", "openai-codex", "google-gemini-cli"
  key?: string;           // The actual API key (e.g., "sk-ant-...")
  email?: string;
  metadata?: Record<string, string>;  // Provider-specific (account IDs, etc.)
};

type TokenCredential = {
  type: "token";
  provider: string;
  token: string;          // Static bearer token (PAT, OAuth access token)
  expires?: number;       // Optional expiry (ms since epoch)
  email?: string;
};

type OAuthCredential = OAuthCredentials & {
  type: "oauth";
  provider: string;
  clientId?: string;
  email?: string;
  // Inherited from OAuthCredentials:
  // access: string;      // OAuth access token
  // refresh: string;     // OAuth refresh token
  // expires: number;     // Expiry timestamp (ms since epoch)
};

type AuthProfileCredential = ApiKeyCredential | TokenCredential | OAuthCredential;
```

---

## The Auth Profile Store

**File:** `src/agents/auth-profiles/store.ts`
**Location:** `~/.openclaw/state/auth-profiles.json`

```typescript
type AuthProfileStore = {
  version: number;                                    // Schema version
  profiles: Record<string, AuthProfileCredential>;    // profileId → credential
  order?: Record<string, string[]>;                   // provider → profile ordering
  lastGood?: Record<string, string>;                  // provider → last successful profileId
  usageStats?: Record<string, ProfileUsageStats>;     // profileId → usage tracking
};

type ProfileUsageStats = {
  lastUsed?: number;              // When this profile was last successfully used
  cooldownUntil?: number;         // Rate-limit cooldown expiry timestamp
  disabledUntil?: number;         // Billing-error disable expiry timestamp
  disabledReason?: AuthProfileFailureReason;
  errorCount?: number;            // Consecutive error count
  failureCounts?: Partial<Record<AuthProfileFailureReason, number>>;
  lastFailureAt?: number;         // Most recent failure timestamp
};

type AuthProfileFailureReason =
  | "auth" | "format" | "rate_limit" | "billing" | "timeout" | "unknown";
```

### Store Loading Pipeline

```
loadAuthProfileStore()
    │
    ├─ Read ~/.openclaw/state/auth-profiles.json
    │   ├─ Valid v2 store? → use it
    │   └─ Not found? → check legacy format
    │
    ├─ Check legacy auth.json (flat Record<provider, credential>)
    │   └─ Found? → migrate to v2 format, delete legacy file
    │
    ├─ Merge OAuth file (~/.openclaw/oauth.json) into store
    │   └─ Old-format OAuth credentials get profileId: "{provider}:default"
    │
    ├─ Sync external CLI credentials:
    │   ├─ Claude CLI: ~/.claude/.credentials.json or macOS Keychain
    │   ├─ Codex CLI:  ~/.codex/auth.json or macOS Keychain
    │   ├─ Qwen CLI:   ~/.qwen/oauth_creds.json
    │   └─ MiniMax CLI: ~/.minimax/oauth_creds.json
    │
    └─ Return merged AuthProfileStore
```

### Multi-Agent Store Inheritance

When running with multiple agents, sub-agents inherit auth from the main agent:

```typescript
function ensureAuthProfileStore(agentDir?: string): AuthProfileStore {
  const store = loadAuthProfileStoreForAgent(agentDir);
  if (agentDir) {
    const mainStore = loadAuthProfileStoreForAgent(undefined); // main
    return mergeAuthProfileStores(mainStore, store);
  }
  return store;
}
```

---

## External CLI Credential Sync

**File:** `src/agents/cli-credentials.ts`

OpenClaw automatically **imports** credentials from external CLI tools installed on the system.

### Claude CLI Credentials

```
Read priority:
1. macOS Keychain (service: "Claude Code-credentials")
2. File: ~/.claude/.credentials.json

File format:
{
  "claudeAiOauth": {
    "accessToken": "ey...",
    "refreshToken": "rt...",
    "expiresAt": 1700000000000
  }
}
```

```typescript
type ClaudeCliCredential =
  | { type: "oauth"; provider: "anthropic"; access: string; refresh: string; expires: number }
  | { type: "token"; provider: "anthropic"; token: string; expires: number };
```

### Codex CLI Credentials

```
Read priority:
1. macOS Keychain (service: "Codex Auth", account: "cli|{sha256(codexHome)[:16]}")
2. File: ~/.codex/auth.json (or $CODEX_HOME/auth.json)

File format:
{
  "tokens": {
    "access_token": "...",
    "refresh_token": "...",
    "account_id": "..."
  },
  "last_refresh": "2025-01-01T00:00:00Z"
}
```

### Write-back on Refresh

When OpenClaw refreshes OAuth tokens, it **writes them back** to the CLI credential stores:

```typescript
// Claude CLI write-back:
writeClaudeCliCredentials(newCredentials, { platform });
// Tries: 1) Keychain (macOS) → 2) File (~/.claude/.credentials.json)

// Security: Uses execFileSync (not execSync) for keychain writes
// to prevent command injection via $() or backtick expansion in tokens
```

### Credential Caching

External credentials are cached with configurable TTL to avoid repeated disk reads:

```typescript
readClaudeCliCredentialsCached({ ttlMs: 30000 });  // 30s cache
readCodexCliCredentialsCached({ ttlMs: 30000 });
readQwenCliCredentialsCached({ ttlMs: 60000 });
readMiniMaxCliCredentialsCached({ ttlMs: 60000 });
```

---

## Profile Ordering & Rotation

**File:** `src/agents/auth-profiles/order.ts`

### The Ordering Algorithm

```typescript
function resolveAuthProfileOrder(params: {
  cfg?: OpenClawConfig;
  store: AuthProfileStore;
  provider: string;
  preferredProfile?: string;
}): string[]
```

```
resolveAuthProfileOrder("anthropic")
    │
    ├─ 1. Check store override order (store.order["anthropic"])
    ├─ 2. Check config order (cfg.auth.order["anthropic"])
    ├─ 3. Check config-defined profiles (cfg.auth.profiles matching provider)
    ├─ 4. Fallback: list all store profiles for provider
    │
    ├─ Filter: remove profiles with no credentials, wrong provider, expired tokens
    ├─ Deduplicate profile IDs
    │
    ├─ If explicit order exists:
    │   ├─ Partition into available vs in-cooldown
    │   ├─ Available first, cooldown sorted by expiry (soonest first)
    │   └─ preferredProfile goes to front if specified
    │
    └─ If no explicit order (round-robin mode):
        ├─ Sort by type preference: oauth > token > api_key
        ├─ Within same type: sort by lastUsed (oldest first = round-robin)
        ├─ Append cooldown profiles at end (sorted by cooldown expiry)
        └─ preferredProfile goes to front if specified
```

### Cooldown & Disabled States

Profiles have two independent "unusable" states:

```
cooldownUntil:  Temporary cooldown from rate limits / transient errors
                Exponential backoff: 1min → 5min → 25min → 1 hour max

disabledUntil:  Longer disable from billing errors
                Exponential backoff: 5h base × 2^N, max 24h
```

```typescript
function calculateAuthProfileCooldownMs(errorCount: number): number {
  const normalized = Math.max(1, errorCount);
  return Math.min(
    60 * 60 * 1000,   // 1 hour max
    60 * 1000 * 5 ** Math.min(normalized - 1, 3),
  );
}
// errorCount 1 → 1 min
// errorCount 2 → 5 min
// errorCount 3 → 25 min
// errorCount 4+ → 60 min (capped)
```

### Cooldown Expiry & Circuit Breaker

When cooldowns expire, error counters are **reset** (circuit breaker half-open → closed):

```typescript
function clearExpiredCooldowns(store: AuthProfileStore, now?: number): boolean {
  for (const [profileId, stats] of Object.entries(usageStats)) {
    if (cooldownExpired) {
      stats.cooldownUntil = undefined;
      stats.errorCount = 0;          // Fresh start!
      stats.failureCounts = undefined;
    }
    if (disabledExpired) {
      stats.disabledUntil = undefined;
      stats.disabledReason = undefined;
    }
  }
}
```

### Failure Window Decay

Error counts also decay after a configurable window (default 24h):

```typescript
const windowExpired = now - existing.lastFailureAt > failureWindowMs;
const baseErrorCount = windowExpired ? 0 : (existing.errorCount ?? 0);
```

---

## OAuth Token Resolution & Refresh

**File:** `src/agents/auth-profiles/oauth.ts`

### Resolution Flow

```typescript
async function resolveApiKeyForProfile(params: {
  cfg?: OpenClawConfig;
  store: AuthProfileStore;
  profileId: string;
  agentDir?: string;
}): Promise<{ apiKey: string; provider: string; email?: string } | null>
```

```
resolveApiKeyForProfile("anthropic:oauth-1")
    │
    ├─ Load credential from store
    │
    ├─ type: "api_key"
    │   └─ Return key directly
    │
    ├─ type: "token"
    │   ├─ Check expiry (if set)
    │   └─ Return token directly
    │
    ├─ type: "oauth"
    │   ├─ Token still valid (Date.now() < expires)?
    │   │   └─ YES → return access token
    │   │
    │   └─ Token expired → refresh with file lock
    │       │
    │       ├─ Acquire file lock on auth-profiles.json
    │       ├─ Re-check (another process may have refreshed)
    │       ├─ Route to provider-specific refresh:
    │       │   ├─ "chutes"       → refreshChutesTokens()
    │       │   ├─ "qwen-portal"  → refreshQwenPortalCredentials()
    │       │   └─ Standard OAuth → getOAuthApiKey() via pi-ai SDK
    │       ├─ Save refreshed credentials to store
    │       └─ Return new access token
    │
    └─ Refresh failed? → Fallback chain:
        ├─ 1. Check if store was refreshed by another process
        ├─ 2. Try suggested legacy profile ID migration
        ├─ 3. Try inheriting from main agent (if sub-agent)
        └─ 4. Throw with doctor hint for user
```

### Google Gemini Special Case

Google Gemini credentials include a `projectId` that must be sent alongside the token:

```typescript
function buildOAuthApiKey(provider: string, credentials: OAuthCredentials): string {
  const needsProjectId = provider === "google-gemini-cli" || provider === "google-antigravity";
  return needsProjectId
    ? JSON.stringify({ token: credentials.access, projectId: credentials.projectId })
    : credentials.access;
}
```

### File Locking

All store mutations use file-based locking to prevent race conditions:

```typescript
await withFileLock(authPath, AUTH_STORE_LOCK_OPTIONS, async () => {
  const store = ensureAuthProfileStore(agentDir);
  // ... mutate store ...
  saveAuthProfileStore(store, agentDir);
});
```

---

## Supported Providers & Auth Methods

| Provider | Profile ID Pattern | API Key | OAuth | Token | CLI Sync Source |
|----------|-------------------|---------|-------|-------|-----------------|
| Anthropic (Claude) | `anthropic:*` | Yes | Yes | Yes | `~/.claude/.credentials.json` |
| OpenAI (Codex) | `openai-codex:*` | Yes | Yes | Yes | `~/.codex/auth.json` |
| GitHub Copilot | `github-copilot:*` | -- | Yes | -- | -- |
| Google Gemini | `google-gemini-cli:*` | Yes | Yes* | -- | -- |
| Chutes | `chutes:*` | -- | Yes | -- | Custom refresh |
| Qwen Portal | `qwen-portal:*` | -- | Yes | -- | `~/.qwen/oauth_creds.json` |
| MiniMax Portal | `minimax-portal:*` | -- | Yes | -- | `~/.minimax/oauth_creds.json` |
| AWS Bedrock | `bedrock:*` | Yes** | -- | -- | AWS SDK |
| Cloudflare AI Gateway | via proxy | Yes | -- | -- | -- |

*Gemini OAuth includes `projectId`
**Bedrock uses AWS credentials (access key + secret + region)

---

## Configuration

```json
{
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
      "failureWindowHours": 24,
      "billingBackoffHoursByProvider": {
        "anthropic": 2
      }
    }
  }
}
```

---

## Key Source Files

| File | Purpose |
|------|---------|
| `src/agents/auth-profiles/types.ts` | All credential and store type definitions |
| `src/agents/auth-profiles/store.ts` | Store load, save, merge, migration |
| `src/agents/auth-profiles/oauth.ts` | OAuth resolution, refresh with file lock |
| `src/agents/auth-profiles/order.ts` | Profile ordering and rotation algorithm |
| `src/agents/auth-profiles/usage.ts` | Cooldown tracking, exponential backoff |
| `src/agents/auth-profiles/profiles.ts` | Profile listing and deduplication |
| `src/agents/auth-profiles/external-cli-sync.ts` | Sync from Qwen/MiniMax CLIs |
| `src/agents/auth-profiles/paths.ts` | Store file path resolution |
| `src/agents/auth-profiles/repair.ts` | Legacy profile ID migration |
| `src/agents/auth-profiles/doctor.ts` | Diagnostic hints for auth failures |
| `src/agents/auth-profiles/constants.ts` | Lock options, version, profile IDs |
| `src/agents/cli-credentials.ts` | Claude/Codex/Qwen/MiniMax CLI credential reading |
| `src/agents/chutes-oauth.ts` | Chutes provider OAuth refresh |
| `src/providers/qwen-portal-oauth.ts` | Qwen Portal OAuth refresh |
