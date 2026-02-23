# 09 - Skills & Tools Ecosystem

> **Cross-references:** [01-BRIDGE](./01-BRIDGE-agent-runtime.md) | [03-COMM](./03-COMM-gateway-protocol.md) | [08-CHANNELS](./08-CHANNELS-integration-bridge.md) | [10-CONFIG](./10-CONFIG-model-selection.md)

---

## Overview

Skills and tools give the AI agent the ability to **take actions** beyond text generation. OpenClaw has **54 bundled skills** and a flexible tool system with an approval flow for dangerous operations.

```
┌──────────────────────────────────────────────────────────────────┐
│                        TOOL INJECTION PIPELINE                    │
│                                                                   │
│  1. Load skill tools from enabled skills (skills/)               │
│  2. Load channel-specific tools (channel-tools.ts)               │
│  3. Merge with agent config tool permissions                      │
│  4. Inject tool definitions into system prompt                    │
│  5. Register tool handlers with agent session                     │
│                                                                   │
│  Runtime:                                                         │
│  Model outputs tool_use → dispatcher → handler → approval? →    │
│  execute → result → back to model                                │
└──────────────────────────────────────────────────────────────────┘
```

---

## Skill Architecture

### Directory Structure

```
skills/
├── coding-agent/           # Full coding agent (file edit, git, exec)
│   ├── manifest.yaml       # Skill metadata + tool definitions
│   ├── system-prompt.md    # Personality/instructions for this skill
│   └── tools.ts            # Tool handler implementations
│
├── discord/                # Discord-specific actions
├── canvas/                 # Visual workspace generation
├── notion/                 # Notion API integration
├── 1password/              # Password manager queries
├── apple-reminders/        # macOS Reminders integration
├── obsidian/               # Obsidian vault access
├── gemini/                 # Google Gemini model access
├── openai/                 # OpenAI API (image gen, whisper)
├── himalaya/               # Email (IMAP/SMTP)
├── gifgrep/                # GIF search
├── healthcheck/            # System health monitoring
├── browser/                # Web browsing and scraping
├── calendar/               # Calendar integration
├── contacts/               # Contact management
├── file-manager/           # File system operations
├── git/                    # Git operations
├── search/                 # Web search
├── shell/                  # Shell command execution
├── ssh/                    # SSH remote access
├── todoist/                # Todoist task manager
├── linear/                 # Linear issue tracker
├── jira/                   # Jira integration
├── github/                 # GitHub API
├── docker/                 # Docker management
├── kubernetes/             # Kubernetes operations
└── ... (28 more)
```

### Skill Manifest Format

```yaml
# skills/notion/manifest.yaml
name: notion
description: Read and update Notion pages
version: 1.0.0
enabled: true

tools:
  - name: notion_search
    description: Search Notion workspace for pages
    parameters:
      type: object
      properties:
        query:
          type: string
          description: Search query
      required: [query]

  - name: notion_read_page
    description: Read a Notion page by ID
    parameters:
      type: object
      properties:
        page_id:
          type: string
      required: [page_id]

  - name: notion_create_page
    description: Create a new Notion page
    parameters:
      type: object
      properties:
        title:
          type: string
        content:
          type: string
        parent_id:
          type: string
      required: [title, content]

system_prompt: |
  You have access to Notion. Use notion_search to find pages,
  notion_read_page to read content, and notion_create_page
  to create new pages. Always search first before creating
  duplicates.
```

---

## Built-in Tools

### Exec Tool (Shell Execution)

**File:** `src/agents/bash-tools.exec.ts`

The most powerful and dangerous tool — executes shell commands:

```typescript
// Tool definition:
{
  name: "exec",
  description: "Execute a shell command",
  parameters: {
    command: string,       // The command to run
    cwd?: string,          // Working directory
    timeout?: number,      // Timeout in ms
    background?: boolean,  // Run in background
  }
}

// Execution pipeline:
exec("npm install")
    │
    ├─ Check command against policy (node-command-policy.ts)
    │   ├─ Safe commands (ls, cat, echo, etc.) → execute immediately
    │   └─ Dangerous commands (rm, git push, etc.) → require approval
    │
    ├─ If approval required:
    │   ├─ Broadcast: { type: "exec.pending", command, runId }
    │   ├─ Wait for user response via companion app
    │   ├─ Approved → execute
    │   └─ Denied → return "Command denied by user"
    │
    ├─ Execute via ProcessSupervisor
    │   ├─ mode: "child" or "pty"
    │   ├─ Apply timeout
    │   └─ Capture stdout/stderr
    │
    └─ Return result to model:
        { stdout: "...", stderr: "...", exitCode: 0 }
```

### Browser Tool

```typescript
{
  name: "browser",
  description: "Navigate to a URL and extract content",
  parameters: {
    url: string,
    action?: "navigate" | "click" | "type" | "screenshot",
    selector?: string,
    text?: string,
  }
}
```

### Canvas Tool

```typescript
{
  name: "canvas",
  description: "Create visual workspace with diagrams and layouts",
  parameters: {
    type: "diagram" | "wireframe" | "chart",
    content: string,
  }
}
```

### Cron Tool

```typescript
{
  name: "cron_create",
  description: "Schedule a recurring task",
  parameters: {
    schedule: string,   // cron expression
    prompt: string,     // What to ask the agent
    channel?: string,   // Where to deliver results
  }
}
```

---

## Tool Execution Flow

```
Model generates tool_use block
    │
    ├─ Pi Embedded Runner intercepts:
    │   event: tool_execution_start
    │   → { tool: "exec", input: { command: "npm test" } }
    │
    ├─ Dispatch to registered handler:
    │   toolHandlers["exec"](input)
    │
    ├─ Handler executes:
    │   ├─ Check permissions
    │   ├─ Check approval requirement
    │   ├─ Run the operation
    │   └─ Return result
    │
    ├─ Result returned to model:
    │   event: tool_execution_end
    │   → { result: "Tests passed: 42/42" }
    │
    └─ Model continues generation with tool result context
```

---

## Exec Approval System

**File:** `src/gateway/exec-approval-manager.ts`

### Command Policy

**File:** `src/gateway/node-command-policy.ts`

Commands are classified into safety categories:

```
SAFE (auto-approve):
├─ Read-only commands: ls, cat, head, tail, wc, grep, find
├─ Build commands: npm test, npm run build, make, cargo build
├─ Info commands: git status, git log, git diff, pwd, whoami
└─ Language REPLs: node -e, python -c (with restrictions)

REQUIRES APPROVAL:
├─ Destructive: rm, rmdir, git reset --hard, docker rm
├─ Network: curl -X POST/PUT/DELETE, wget, ssh, scp
├─ System: systemctl, kill, pkill, sudo, chmod, chown
├─ Git push: git push, git force-push
├─ Package install: npm install, pip install, apt install
└─ File writes: tee, dd, >, >> (in some contexts)
```

### Approval Flow via WebSocket

```
Tool requires approval
    │
    ├─ ExecApprovalManager creates pending approval:
    │   {
    │     id: "approval_xyz",
    │     runId: "run_abc",
    │     command: "git push origin main",
    │     timestamp: 1700000000,
    │     status: "pending"
    │   }
    │
    ├─ Broadcast to all WebSocket clients:
    │   { type: "exec.pending", id, command, runId }
    │
    ├─ Companion app shows dialog:
    │   "The assistant wants to run: git push origin main"
    │   [Approve] [Deny]
    │
    ├─ User responds:
    │   { type: "exec.approve", id: "approval_xyz" }
    │
    └─ ExecApprovalManager resolves:
        ├─ Approved → tool handler executes command
        └─ Denied → tool handler returns "Command denied"
```

### Input Sanitization

**File:** `src/gateway/node-invoke-sanitize.ts`

Commands are sanitized to prevent injection:

```typescript
// Sanitize shell metacharacters
// Prevent $(command) execution
// Prevent backtick expansion
// Validate file paths
```

---

## Skill Configuration

```json
{
  "skills": {
    "enabled": [
      "coding-agent",
      "notion",
      "1password",
      "himalaya",
      "browser",
      "calendar"
    ],
    "disabled": [
      "shell"     // Explicitly disable dangerous skills
    ]
  }
}
```

### Per-Agent Skill Override

```json
{
  "agents": {
    "agents": {
      "work": {
        "skills": {
          "enabled": ["coding-agent", "github", "linear"],
          "disabled": ["1password"]
        }
      },
      "personal": {
        "skills": {
          "enabled": ["notion", "calendar", "todoist"]
        }
      }
    }
  }
}
```

---

## Tool Definition Injection

When an agent session starts, tools are assembled from multiple sources:

```
Build tool list for agent session:
    │
    ├─ 1. Skill tools:
    │   ├─ Read manifests from enabled skills
    │   ├─ Parse tool definitions (name, description, parameters)
    │   └─ Register tool handlers
    │
    ├─ 2. Channel tools:
    │   ├─ Discord active? → add Discord management tools
    │   ├─ Slack active? → add Slack management tools
    │   └─ (Channel-specific tools for the originating channel)
    │
    ├─ 3. Built-in tools:
    │   ├─ exec (shell)
    │   ├─ browser
    │   ├─ canvas
    │   └─ cron
    │
    ├─ 4. Apply permissions:
    │   ├─ Agent config allows/blocks specific tools
    │   └─ Filter to permitted set
    │
    └─ 5. Inject into system prompt:
        ├─ Tool definitions as JSON schema
        ├─ Skill system prompts (instructions for using tools)
        └─ Register handlers for tool_use dispatch
```

---

## Process Tools (Background Execution)

**File:** `src/agents/bash-tools.process.ts`

Tools can run processes in the background:

```typescript
{
  name: "process_start",
  description: "Start a long-running background process",
  parameters: {
    command: string,
    timeout_ms?: number,
  }
}

{
  name: "process_poll",
  description: "Check status of a background process",
  parameters: {
    process_id: string,
  }
}

{
  name: "process_send_keys",
  description: "Send keystrokes to an interactive process",
  parameters: {
    process_id: string,
    keys: string,
  }
}
```

---

## Key Source Files

| File | Purpose |
|------|---------|
| `skills/` | 54 bundled skill directories |
| `src/agents/bash-tools.ts` | Main tool registration and dispatch |
| `src/agents/bash-tools.exec.ts` | Exec (shell) tool implementation |
| `src/agents/bash-tools.process.ts` | Background process tools |
| `src/agents/bash-tools.shared.ts` | Shared tool utilities |
| `src/agents/channel-tools.ts` | Channel-specific tool injection |
| `src/gateway/exec-approval-manager.ts` | Tool execution approval flow |
| `src/gateway/node-command-policy.ts` | Command safety classification |
| `src/gateway/node-invoke-sanitize.ts` | Command input sanitization |
| `src/gateway/node-invoke-system-run-approval.ts` | System run approval |
| `src/gateway/node-registry.ts` | Tool registry for gateway |
