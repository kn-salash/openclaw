# 01 - Agent Runtime Bridge: How Claude & Codex Connect to OpenClaw

> **Cross-references:** [02-AUTH](./02-AUTH-credential-lifecycle.md) | [04-FLOW](./04-FLOW-message-pipeline.md) | [05-RESILIENCE](./05-RESILIENCE-failover-system.md) | [06-PROCESS](./06-PROCESS-supervisor-management.md) | [07-SESSION](./07-SESSION-state-management.md) | [10-CONFIG](./10-CONFIG-model-selection.md)

---

## Overview

OpenClaw bridges to AI models via **two execution paths**:

1. **Pi Embedded Runner** — In-process calls to AI APIs using the `@mariozechner/pi-ai` SDK (primary path)
2. **CLI Runner** — Spawns external CLI processes (`claude`, `codex`, custom backends) as child processes

Both paths share a common auth system ([02-AUTH](./02-AUTH-credential-lifecycle.md)), failover logic ([05-RESILIENCE](./05-RESILIENCE-failover-system.md)), and process supervision ([06-PROCESS](./06-PROCESS-supervisor-management.md)).

```
                    agentCommand()
                    (src/commands/agent.ts)
                         │
              ┌──────────┴──────────┐
              ▼                      ▼
    Pi Embedded Runner          CLI Runner
    (in-process API calls)      (child processes)
              │                      │
              ▼                      ▼
    @mariozechner/pi-ai         Process Supervisor
    SDK v0.49.3                     │
              │                 ┌───┴────┐
              ▼                 ▼        ▼
    Anthropic/OpenAI/       claude    codex
    Gemini APIs             CLI       CLI
    (direct HTTP)           process   process
```

---

## Path 1: Pi Embedded Runner (Primary)

**Entry:** `src/agents/pi-embedded-runner/run.ts`
**Library:** `@mariozechner/pi-ai` (pi-coding-agent v0.49.3)

The Pi Embedded Runner executes AI model calls **in-process** — no child processes, no CLI spawning. It calls provider APIs directly using the pi-ai SDK.

### Execution Model

```typescript
// Simplified execution flow
async function runPiEmbeddedAgent(params: {
  sessionId: string;
  sessionKey?: string;
  prompt: string;
  provider: string;           // "anthropic", "openai-codex", "google-gemini-cli"
  model?: string;             // "claude-opus-4-6", "gpt-5.3-codex"
  thinkLevel?: ThinkLevel;    // "none" | "default" | "extended"
  timeoutMs: number;
  config?: OpenClawConfig;
  images?: ImageContent[];
  streamParams?: AgentStreamParams;
}): Promise<EmbeddedPiRunResult>
```

### The Attempt Loop

The core of the Pi Embedded Runner is a **retry loop** that handles multiple failure modes:

```
┌─── ATTEMPT LOOP (max attempts configurable) ──────────────────────┐
│                                                                     │
│  1. Resolve auth profile (see 02-AUTH)                             │
│     → Select first non-cooldown profile from ordered list          │
│     → Inject API key into session's authStorage                    │
│                                                                     │
│  2. Build system prompt                                            │
│     → Base personality + workspace context                          │
│     → Bootstrap files (CLAUDE.md, .env, etc.)                      │
│     → Tool definitions from enabled skills                          │
│     → Channel-specific instructions                                 │
│     → Heartbeat config                                              │
│                                                                     │
│  3. Create/resume pi-ai session                                    │
│     → Load session from ~/.openclaw/sessions/                      │
│     → Attach conversation history                                   │
│     → Configure thinking level                                      │
│                                                                     │
│  4. Call activeSession.prompt(userMessage)                          │
│     → Stream events in real-time:                                   │
│       message_start → message_update (text deltas)                 │
│       → tool_execution_start → tool_execution_end                  │
│       → message_end → agent_end                                    │
│                                                                     │
│  5. Handle tool calls (if model requests tools)                    │
│     → Dispatch to registered tool handler                           │
│     → Return tool result to model                                   │
│     → Model continues generation                                    │
│                                                                     │
│  6. On error → classify and handle:                                │
│     ├─ 401/403 auth error   → rotate to next auth profile, retry  │
│     ├─ 429 rate limit       → cooldown current profile, rotate     │
│     ├─ 402 billing error    → disable profile (long backoff)       │
│     ├─ Timeout              → kill, rotate profile, retry          │
│     ├─ Context overflow     → compact session, truncate, retry     │
│     ├─ Thinking error       → fallback to lower think level        │
│     └─ All profiles gone    → throw FailoverError                  │
│                                                                     │
└─── END ATTEMPT LOOP ──────────────────────────────────────────────┘
```

### Streaming Events

During execution, the Pi Embedded Runner emits streaming events that flow to channels and WebSocket clients (see [03-COMM](./03-COMM-gateway-protocol.md)):

```typescript
// Event types emitted during a run:
Event: "message_start"      → { type: "message_start" }
Event: "message_update"     → { delta: { text: "I'll schedule..." } }
Event: "tool_use_start"     → { tool: "calendar_create", input: {...} }
Event: "tool_use_end"       → { result: "Event created" }
Event: "message_update"     → { delta: { text: "Done! Meeting scheduled." } }
Event: "message_end"        → { usage: { input_tokens, output_tokens } }
Event: "agent_end"          → { success: true }
```

### Result Shape

```typescript
type EmbeddedPiRunResult = {
  payloads?: Array<{ text: string }>;
  meta: {
    durationMs: number;
    agentMeta: {
      sessionId: string;
      provider: string;
      model: string;
      usage?: { input_tokens: number; output_tokens: number };
    };
  };
};
```

---

## Path 2: CLI Runner (Fallback / External Backends)

**Entry:** `src/agents/cli-runner.ts` → `runCliAgent()`
**Re-export:** `src/agents/claude-cli-runner.ts` (backwards compat)

The CLI Runner spawns external processes (the `claude` CLI, `codex` CLI, or custom backends) as child processes managed by the Process Supervisor.

### How It Works

```
1. Resolve backend config        → DEFAULT_CLAUDE_BACKEND or DEFAULT_CODEX_BACKEND
2. Normalize model alias          → "opus" → "opus", "sonnet-4.5" → "sonnet"
3. Build system prompt            → Same pipeline as Pi Embedded (helpers.ts)
4. Build CLI arguments            → ["-p", "--output-format", "json", "--model", "opus", ...]
5. Resolve prompt input           → arg-based or stdin-based
6. Enqueue via serialization      → One CLI run per backend at a time (serialize: true)
7. Spawn via Process Supervisor   → supervisor.spawn({ mode: "child", argv: [...] })
8. Wait for completion            → managedRun.wait() → RunExit
9. Parse output                   → JSON, JSONL, or plain text
10. Return EmbeddedPiRunResult    → Same shape as Pi Embedded path
```

### Built-in Backend Configs

**Claude CLI Backend:**
```typescript
const DEFAULT_CLAUDE_BACKEND: CliBackendConfig = {
  command: "claude",
  args: ["-p", "--output-format", "json", "--dangerously-skip-permissions"],
  resumeArgs: ["-p", "--output-format", "json", "--dangerously-skip-permissions",
               "--resume", "{sessionId}"],
  output: "json",
  input: "arg",
  modelArg: "--model",
  modelAliases: {
    "opus": "opus", "opus-4.6": "opus", "claude-opus-4-6": "opus",
    "sonnet": "sonnet", "sonnet-4.5": "sonnet", "claude-sonnet-4-5": "sonnet",
    "haiku": "haiku", "haiku-3.5": "haiku", "claude-haiku-3-5": "haiku",
  },
  sessionArg: "--session-id",
  sessionMode: "always",
  systemPromptArg: "--append-system-prompt",
  systemPromptMode: "append",
  systemPromptWhen: "first",
  clearEnv: ["ANTHROPIC_API_KEY", "ANTHROPIC_API_KEY_OLD"],  // Use own auth
  serialize: true,  // One run at a time
};
```

**Codex CLI Backend:**
```typescript
const DEFAULT_CODEX_BACKEND: CliBackendConfig = {
  command: "codex",
  args: ["exec", "--json", "--color", "never", "--sandbox", "read-only",
         "--skip-git-repo-check"],
  resumeArgs: ["exec", "resume", "{sessionId}", "--color", "never",
               "--sandbox", "read-only", "--skip-git-repo-check"],
  output: "jsonl",
  resumeOutput: "text",
  input: "arg",
  modelArg: "--model",
  imageArg: "--image",
  imageMode: "repeat",
  sessionIdFields: ["thread_id"],
  sessionMode: "existing",
  serialize: true,
};
```

### Session Resume

Both CLIs support **session resume** — continuing a previous conversation:

```
First call:    claude -p "Hello" --output-format json --session-id abc123
Resume call:   claude -p "Follow up" --output-format json --resume abc123
```

The CLI Runner tracks session IDs from the CLI's JSON output using `sessionIdFields`:
- Claude CLI: `session_id`, `sessionId`, `conversation_id`, `conversationId`
- Codex CLI: `thread_id`

### Output Parsing

```typescript
// JSON mode (Claude CLI default):
parseCliJson(stdout, backend) → { text: string, sessionId?: string, usage?: {...} }

// JSONL mode (Codex CLI default):
parseCliJsonl(stdout, backend) → { text: string, sessionId?: string }

// Text mode (fallback):
{ text: stdout, sessionId: undefined }
```

### Custom Backends

Users can define custom CLI backends in config:

```json
{
  "agents": {
    "defaults": {
      "cliBackends": {
        "my-custom-llm": {
          "command": "/usr/local/bin/my-llm",
          "args": ["--prompt"],
          "output": "text",
          "input": "arg",
          "modelArg": "--model"
        }
      }
    }
  }
}
```

Custom backends are merged with built-in defaults using `mergeBackendConfig()`.

---

## How the Two Paths Are Chosen

The `agentCommand()` function in `src/commands/agent.ts` decides which path to use:

```
agentCommand() receives message
    │
    ├─ Resolve provider + model from config (see 10-CONFIG)
    │
    ├─ Is provider a CLI backend?
    │   (claude-cli, codex-cli, or custom cliBackend)
    │   │
    │   ├─ YES → runCliAgent()   → Process Supervisor → child process
    │   └─ NO  → runPiEmbeddedAgent() → In-process API call
    │
    └─ Return EmbeddedPiRunResult (same shape from both paths)
```

### Provider → Path Mapping

| Provider | Path | How |
|----------|------|-----|
| `anthropic` | Pi Embedded | Direct API via pi-ai SDK |
| `openai-codex` | Pi Embedded | Direct API via pi-ai SDK |
| `google-gemini-cli` | Pi Embedded | Direct API via pi-ai SDK |
| `github-copilot` | Pi Embedded | Direct API via pi-ai SDK |
| `claude-cli` | CLI Runner | Spawns `claude` process |
| `codex-cli` | CLI Runner | Spawns `codex` process |
| Custom backend | CLI Runner | Spawns custom process |

---

## Environment Isolation

### Claude CLI: Credential Clearing

The Claude CLI backend has a critical security feature — it **clears** the `ANTHROPIC_API_KEY` env var:

```typescript
clearEnv: ["ANTHROPIC_API_KEY", "ANTHROPIC_API_KEY_OLD"]
```

This forces the `claude` CLI to use its **own** authentication (OAuth from `~/.claude/.credentials.json`) rather than inheriting OpenClaw's API key. OpenClaw manages its own auth separately through auth profiles.

### Codex CLI: Sandbox Mode

Codex runs with `--sandbox read-only` by default — it can read files but not write or execute arbitrary commands. This is a safety measure when running the Codex CLI as a subprocess.

### Workspace Isolation

Each agent run resolves its own workspace directory:

```typescript
const workspaceResolution = resolveRunWorkspaceDir({
  workspaceDir: params.workspaceDir,
  sessionKey: params.sessionKey,
  agentId: params.agentId,
  config: params.config,
});
```

The CLI process runs with `cwd` set to the resolved workspace, isolating file operations per agent.

---

## Serialization & Queueing

CLI backend runs are **serialized by default** — only one run per backend at a time:

```typescript
const serialize = backend.serialize ?? true;
const queueKey = serialize
  ? backendResolved.id                              // One at a time per backend
  : `${backendResolved.id}:${params.runId}`;       // Concurrent runs

const output = await enqueueCliRun(queueKey, async () => {
  // ... spawn and wait for CLI process
});
```

This prevents resource contention when multiple users send messages simultaneously. Messages are queued and processed sequentially per backend.

---

## Watchdog Timers

Both runners use watchdog timers to prevent hung processes:

```typescript
// Two independent timers:
timeoutMs          // Overall timeout — kill if total time exceeded
noOutputTimeoutMs  // No-output timeout — kill if no stdout/stderr for N seconds
```

Watchdog defaults are configured per backend in `cli-watchdog-defaults.ts` and can be overridden:

```typescript
CLI_FRESH_WATCHDOG_DEFAULTS    // For new sessions (longer timeouts)
CLI_RESUME_WATCHDOG_DEFAULTS   // For resumed sessions (shorter timeouts)
```

When a watchdog fires, the process is killed and a `FailoverError` is thrown with `reason: "timeout"`, triggering profile rotation (see [05-RESILIENCE](./05-RESILIENCE-failover-system.md)).

---

## Image Handling

Both paths support image inputs:

```typescript
// Pi Embedded: Images passed directly as ImageContent[]
// CLI Runner:  Images written to temp files, paths passed as args

if (params.images && params.images.length > 0) {
  const imagePayload = await writeCliImages(params.images);
  imagePaths = imagePayload.paths;     // Temp file paths
  cleanupImages = imagePayload.cleanup; // Cleanup function

  if (backend.imageArg) {
    // Pass via --image flag (Codex: --image path1 --image path2)
    args.push(backend.imageArg, ...imagePaths);
  } else {
    // Append paths to prompt text (Claude CLI fallback)
    prompt = appendImagePathsToPrompt(prompt, imagePaths);
  }
}
```

Temp files are always cleaned up in the `finally` block.

---

## Key Source Files

| File | Purpose |
|------|---------|
| `src/agents/pi-embedded-runner/run.ts` | Pi Embedded main execution + failover loop |
| `src/agents/pi-embedded-runner/model.ts` | Model catalog resolution for pi-ai |
| `src/agents/pi-embedded-runner/attempt.ts` | Single attempt execution |
| `src/agents/cli-runner.ts` | CLI Runner — spawn + parse + failover |
| `src/agents/claude-cli-runner.ts` | Backwards-compat re-export |
| `src/agents/cli-backends.ts` | Backend configs (Claude, Codex, custom) |
| `src/agents/cli-runner/helpers.ts` | System prompt, args, parsing, queueing |
| `src/agents/cli-runner/reliability.ts` | Watchdog configuration |
| `src/agents/cli-watchdog-defaults.ts` | Default timeout values |
| `src/agents/pi-embedded-helpers.ts` | Failover reason classification |
| `src/agents/pi-embedded-runner.ts` | Pi Embedded entry point |
| `src/commands/agent.ts` | agentCommand() — path selection |
