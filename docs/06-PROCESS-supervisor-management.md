# 06 - Process Supervisor & Management

> **Cross-references:** [01-BRIDGE](./01-BRIDGE-agent-runtime.md) | [05-RESILIENCE](./05-RESILIENCE-failover-system.md)

---

## Overview

The Process Supervisor manages all child processes spawned by OpenClaw — CLI agent runs, external tools, interactive shells. It provides lifecycle management, watchdog timers, and scope-based process replacement.

```
┌─────────────────────────────────────────────────────────┐
│                  PROCESS SUPERVISOR                      │
│                  (singleton per gateway)                  │
│                                                          │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐ │
│  │ ManagedRun   │  │ ManagedRun   │  │ ManagedRun   │ │
│  │ runId: "r1"  │  │ runId: "r2"  │  │ runId: "r3"  │ │
│  │ scope: "s1"  │  │ scope: "s2"  │  │ scope: "s1"  │ │
│  │ mode: child  │  │ mode: pty    │  │ mode: child  │ │
│  │ state: run   │  │ state: run   │  │ state: exit  │ │
│  └──────────────┘  └──────────────┘  └──────────────┘ │
│                                                          │
│  Registry: runId → RunRecord                            │
│  Scope index: scopeKey → Set<runId>                     │
│  Watchdog: per-run timeout + no-output timeout          │
└─────────────────────────────────────────────────────────┘
```

---

## Core Types

**File:** `src/process/supervisor/types.ts`

```typescript
// Process states:
type RunState = "starting" | "running" | "exiting" | "exited";

// How a process ended:
type TerminationReason =
  | "manual-cancel"       // Explicitly cancelled by caller
  | "overall-timeout"     // Exceeded total time limit
  | "no-output-timeout"   // No stdout/stderr for too long
  | "spawn-error"         // Failed to start
  | "signal"              // Killed by signal
  | "exit";               // Normal exit (code 0 or non-zero)

// Per-run metadata tracked by supervisor:
type RunRecord = {
  runId: string;
  sessionId: string;
  backendId: string;
  scopeKey?: string;
  pid?: number;
  processGroupId?: number;
  startedAtMs: number;
  lastOutputAtMs: number;
  createdAtMs: number;
  updatedAtMs: number;
  state: RunState;
  terminationReason?: TerminationReason;
  exitCode?: number | null;
  exitSignal?: NodeJS.Signals | number | null;
};

// What the caller gets back:
type ManagedRun = {
  runId: string;
  pid?: number;
  startedAtMs: number;
  stdin?: ManagedRunStdin;        // Write to process stdin
  wait: () => Promise<RunExit>;   // Await completion
  cancel: (reason?) => void;      // Force termination
};

// Final result when process exits:
type RunExit = {
  reason: TerminationReason;
  exitCode: number | null;
  exitSignal: NodeJS.Signals | number | null;
  durationMs: number;
  stdout: string;
  stderr: string;
  timedOut: boolean;
  noOutputTimedOut: boolean;
};
```

---

## The Supervisor Interface

```typescript
interface ProcessSupervisor {
  spawn(input: SpawnInput): Promise<ManagedRun>;
  cancel(runId: string, reason?: TerminationReason): void;
  cancelScope(scopeKey: string, reason?: TerminationReason): void;
  reconcileOrphans(): Promise<void>;
  getRecord(runId: string): RunRecord | undefined;
}
```

---

## Spawn Modes

### Child Mode (`child_process.spawn`)

**File:** `src/process/supervisor/adapters/child.ts`

For non-interactive processes — CLI agents, batch jobs:

```typescript
type SpawnChildInput = {
  mode: "child";
  argv: string[];                  // [command, ...args]
  input?: string;                  // stdin payload
  stdinMode?: "inherit" | "pipe-open" | "pipe-closed";
  windowsVerbatimArguments?: boolean;
  // ... base fields (cwd, env, timeouts, etc.)
};

// Example: spawning claude CLI
supervisor.spawn({
  mode: "child",
  argv: ["claude", "-p", "Hello", "--output-format", "json"],
  sessionId: "sess_123",
  backendId: "claude-cli",
  scopeKey: "claude-cli:sess_123",
  timeoutMs: 120000,
  noOutputTimeoutMs: 60000,
  cwd: "/projects/myapp",
  env: { ...process.env, ANTHROPIC_API_KEY: undefined },
  input: "",
});
```

### PTY Mode (`node-pty`)

**File:** `src/process/supervisor/adapters/pty.ts`

For interactive processes — browser tools, shells:

```typescript
type SpawnPtyInput = {
  mode: "pty";
  ptyCommand: string;    // Shell command to run in pseudo-terminal
  // ... base fields
};
```

PTY mode creates a pseudo-terminal, enabling processes that require a TTY (like `node --inspect`, interactive installers, etc.)

---

## Watchdog Timers

Each spawned process has two independent watchdog timers:

```
Process starts
    │
    ├─ Overall timeout timer starts (timeoutMs)
    │   └─ Fires: kill process, reason = "overall-timeout"
    │
    ├─ No-output timer starts (noOutputTimeoutMs)
    │   └─ Fires: kill process, reason = "no-output-timeout"
    │
    ├─ Process produces output (stdout/stderr)
    │   └─ Reset no-output timer ← (restarts countdown)
    │
    ├─ Process produces more output
    │   └─ Reset no-output timer again
    │
    └─ Process exits normally
        └─ Cancel both timers
```

### Default Watchdog Values

```typescript
// Fresh session (first message, no resume):
CLI_FRESH_WATCHDOG_DEFAULTS = {
  noOutputTimeoutMs: 90_000,     // 90 seconds
  // Overall timeout comes from caller (typically 120-300s)
};

// Resume session (continuing conversation):
CLI_RESUME_WATCHDOG_DEFAULTS = {
  noOutputTimeoutMs: 60_000,     // 60 seconds (shorter for resumes)
};
```

---

## Scope-Based Process Replacement

The supervisor supports **scope keys** — when a new process is spawned with the same scope key as an existing one, the old process is killed:

```
User sends message in session "sess_123"
    │
    ├─ Spawn CLI run with scopeKey = "claude-cli:sess_123"
    │
    ├─ User sends another message before first completes
    │   └─ Spawn new CLI run with same scopeKey
    │       ├─ Supervisor finds existing run with same scope
    │       ├─ Cancels old run (SIGTERM → SIGKILL)
    │       └─ New run takes over
    │
    └─ Only one CLI run per session-backend at a time
```

```typescript
// Scope key construction for CLI backends:
function buildCliSupervisorScopeKey(params: {
  backend: CliBackendConfig;
  backendId: string;
  cliSessionId?: string;
}): string {
  // e.g., "claude-cli:sess_123" or "codex-cli:thread_456"
}
```

---

## Kill Strategy

**File:** `src/process/kill-tree.ts`

### Unix Kill Sequence

```
Step 1: SIGTERM to process group
    kill(-pid, SIGTERM)     // Negative PID = entire process group
    │
    ├─ Wait 3 seconds
    │
    ├─ Process exited? → done (reason: "exit" or "signal")
    │
    └─ Still running?
        │
        Step 2: SIGKILL to process group
        kill(-pid, SIGKILL)
        └─ Immediate termination (cannot be caught)
```

Using `-pid` (negative PID) sends the signal to the entire **process group**, ensuring child processes of the CLI agent (e.g., subprocess tools, background jobs) are also terminated.

### Why Process Groups?

A `claude` CLI process might spawn sub-processes (LSP servers, file watchers, etc.). Killing only the parent would leave orphans consuming resources. The process group kill ensures complete cleanup.

---

## Registry & Tracking

**File:** `src/process/supervisor/registry.ts`

```typescript
// Internal registry tracking all managed processes:
Map<runId, RunRecord>

// Operations:
registry.add(record: RunRecord): void
registry.get(runId: string): RunRecord | undefined
registry.updateState(runId, state, terminationReason?): void
registry.remove(runId: string): void
registry.listByScope(scopeKey: string): RunRecord[]
```

The registry enables:
- Cancelling by runId: `supervisor.cancel("run_abc")`
- Cancelling by scope: `supervisor.cancelScope("claude-cli:sess_123")`
- Orphan detection: find processes that outlived their parent context

---

## Output Capture

```typescript
type SpawnBaseInput = {
  captureOutput?: boolean;    // Whether to retain stdout/stderr in RunExit
  onStdout?: (chunk: string) => void;   // Streaming callback
  onStderr?: (chunk: string) => void;   // Streaming callback
};
```

Two modes:
1. **Capture mode** (`captureOutput: true`): Stdout/stderr accumulated in memory, returned in `RunExit.stdout/stderr`
2. **Stream-only mode** (`captureOutput: false`): Output sent to callbacks only, not retained (saves memory for long-running processes)

---

## Process Lifecycle

```
  spawn() called
      │
      ├─ State: "starting"
      │   ├─ Create RunRecord
      │   ├─ Register scope (if scopeKey provided)
      │   ├─ Kill existing scope run (if replaceExistingScope)
      │   └─ Spawn child_process or node-pty
      │
      ├─ Process starts
      │   State: "running"
      │   ├─ Start watchdog timers
      │   ├─ Pipe stdin (if input provided)
      │   └─ Begin output capture/streaming
      │
      ├─ Process produces output
      │   ├─ Update lastOutputAtMs
      │   ├─ Reset no-output timer
      │   └─ Call onStdout/onStderr callbacks
      │
      ├─ Process exits (or killed)
      │   State: "exiting" → "exited"
      │   ├─ Cancel watchdog timers
      │   ├─ Record exit code / signal
      │   ├─ Set termination reason
      │   └─ Resolve wait() promise with RunExit
      │
      └─ Cleanup
          ├─ Remove from scope index
          └─ Mark record as completed
```

---

## Integration with CLI Runner

The CLI Runner (see [01-BRIDGE](./01-BRIDGE-agent-runtime.md)) uses the supervisor like this:

```typescript
const supervisor = getProcessSupervisor();  // Singleton

const managedRun = await supervisor.spawn({
  sessionId: params.sessionId,
  backendId: backendResolved.id,           // "claude-cli" or "codex-cli"
  scopeKey,                                 // Prevents duplicate runs
  replaceExistingScope: Boolean(useResume && scopeKey),
  mode: "child",
  argv: [backend.command, ...args],         // ["claude", "-p", ...]
  timeoutMs: params.timeoutMs,
  noOutputTimeoutMs,
  cwd: workspaceDir,
  env,
  input: stdinPayload,
});

const result = await managedRun.wait();     // Block until exit

if (result.reason === "no-output-timeout") {
  throw new FailoverError("CLI produced no output...", { reason: "timeout" });
}
if (result.reason === "overall-timeout") {
  throw new FailoverError("CLI exceeded timeout...", { reason: "timeout" });
}
```

---

## Additional Process Utilities

| File | Purpose |
|------|---------|
| `src/process/child-process-bridge.ts` | Bridge between child process events and supervisor |
| `src/process/command-queue.ts` | Queue for serializing command execution |
| `src/process/exec.ts` | Simple exec wrapper for one-shot commands |
| `src/process/kill-tree.ts` | SIGTERM → SIGKILL process group kill |
| `src/process/lanes.ts` | Execution lanes for parallelism control |
| `src/process/restart-recovery.ts` | Graceful restart and recovery |
| `src/process/spawn-utils.ts` | Spawn helper utilities |
| `src/process/supervisor/adapters/env.ts` | Environment variable construction |

---

## Key Source Files

| File | Purpose |
|------|---------|
| `src/process/supervisor/supervisor.ts` | Main supervisor implementation |
| `src/process/supervisor/types.ts` | All supervisor types (SpawnInput, ManagedRun, etc.) |
| `src/process/supervisor/registry.ts` | Run tracking registry |
| `src/process/supervisor/adapters/child.ts` | child_process.spawn adapter |
| `src/process/supervisor/adapters/pty.ts` | node-pty adapter |
| `src/process/supervisor/index.ts` | Singleton accessor |
| `src/process/kill-tree.ts` | Process group kill strategy |
