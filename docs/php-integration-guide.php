<?php
/**
 * OpenClaw PHP Integration Guide — Production-Ready Proof of Concept
 *
 * This file is a self-contained reference implementation showing how to
 * integrate Claude Code (or OpenAI Codex) into a PHP application using
 * the same patterns OpenClaw uses in its Node.js process supervisor.
 *
 * Key patterns ported from OpenClaw:
 *   - PTY-based process spawning (via `script` wrapper or php-pty extension)
 *   - Dual-buffer output capture (pending + aggregated, tail-biased truncation)
 *   - DSR (Device Status Request) auto-response to prevent hangs
 *   - Two-tier timeout: overall deadline + no-output idle watchdog
 *   - Session registry with TTL-based sweeping of finished sessions
 *   - Non-blocking I/O via stream_select()
 *   - FD cleanup on exit to prevent resource leaks
 *
 * Requirements:
 *   - PHP 8.1+
 *   - `proc_open` enabled (not disabled in php.ini)
 *   - `claude` or `codex` CLI installed and on PATH
 *   - A git repository as working directory (required by Codex)
 *
 * Usage:
 *   $registry = new SessionRegistry();
 *   $runner   = new ClaudeProcessRunner($registry);
 *   $result   = $runner->run('Explain this codebase', '/path/to/project');
 *
 * @see src/process/supervisor/supervisor.ts     — OpenClaw process supervisor
 * @see src/agents/bash-process-registry.ts      — OpenClaw session registry
 * @see src/process/supervisor/adapters/pty.ts   — OpenClaw PTY adapter
 * @see src/agents/pty-dsr.ts                    — OpenClaw DSR handling
 */

declare(strict_types=1);

// ─── Configuration ────────────────────────────────────────────────────────────

/**
 * Tune these constants to match your workload.
 * OpenClaw uses similar defaults in src/agents/bash-process-registry.ts.
 */
const MAX_OUTPUT_CHARS       = 200_000;   // Hard cap on aggregated history
const PENDING_MAX_CHARS      = 30_000;    // Cap on pending (incremental) buffer
const TAIL_CHARS             = 2_000;     // Chars kept in the quick-look tail
const OVERALL_TIMEOUT_SEC    = 1800;      // 30-minute hard deadline
const NO_OUTPUT_TIMEOUT_SEC  = 300;       // 5-minute idle watchdog
const SESSION_TTL_SEC        = 1800;      // 30-minute retention for finished sessions
const SWEEP_INTERVAL_SEC     = 300;       // Prune finished sessions every 5 min
const READ_CHUNK_BYTES       = 8192;      // fread chunk size for non-blocking reads


// ─── DSR Handling ─────────────────────────────────────────────────────────────
//
// Interactive terminal apps (including Claude Code's TUI) send Device Status
// Request escape sequences (ESC[6n) to ask "where is my cursor?"
// If nobody answers, the process blocks forever waiting for a response.
//
// OpenClaw handles this in src/agents/pty-dsr.ts:
//   - stripDsrRequests() removes ESC[6n / ESC[?6n from output
//   - buildCursorPositionResponse() replies with ESC[row;colR
//
// We replicate the same logic below.

/**
 * Strip DSR escape sequences from output and count how many were found.
 *
 * Mirrors: src/agents/pty-dsr.ts → stripDsrRequests()
 *
 * @param  string $output  Raw output from the process
 * @return array{cleaned: string, requests: int}
 */
function strip_dsr_requests(string $output): array
{
    $count   = 0;
    $cleaned = preg_replace_callback(
        '/\x1b\[\??6n/',
        function () use (&$count) {
            $count++;
            return '';
        },
        $output
    );

    return ['cleaned' => $cleaned ?? $output, 'requests' => $count];
}

/**
 * Build a cursor-position response for a DSR query.
 *
 * Mirrors: src/agents/pty-dsr.ts → buildCursorPositionResponse()
 *
 * @param  int $row  Cursor row (default 1)
 * @param  int $col  Cursor column (default 1)
 * @return string    ESC[row;colR
 */
function build_cursor_position_response(int $row = 1, int $col = 1): string
{
    return "\x1b[{$row};{$col}R";
}


// ─── Output Buffering ─────────────────────────────────────────────────────────
//
// OpenClaw uses a dual-buffer strategy (src/agents/bash-process-registry.ts):
//
// 1. Pending buffer  — accumulates fresh chunks since last drain; capped at
//    PENDING_MAX_CHARS by dropping the oldest chunks (tail-biased).
//
// 2. Aggregated buffer — complete history, capped at MAX_OUTPUT_CHARS by
//    keeping only the tail when the limit is exceeded.
//
// A separate `tail` field always holds the last TAIL_CHARS of aggregated
// output for quick notifications.

/**
 * Truncate text to `$max` chars, keeping the tail (most recent output).
 *
 * Mirrors: src/agents/bash-process-registry.ts → trimWithCap()
 */
function trim_with_cap(string $text, int $max): string
{
    if (strlen($text) <= $max) {
        return $text;
    }
    return substr($text, strlen($text) - $max);
}


// ─── ProcessSession ───────────────────────────────────────────────────────────
//
// Mirrors the ProcessSession interface from:
//   src/agents/bash-process-registry.ts:28-55
//
// Tracks a single running (or recently finished) subprocess.

class ProcessSession
{
    public string $id;
    public string $command;
    public ?string $scopeKey;
    public ?int $pid = null;
    public float $startedAt;
    public ?string $cwd;

    // Output buffering (dual-layer, mirrors OpenClaw)
    public array  $pendingStdout      = [];
    public int    $pendingStdoutChars = 0;
    public string $aggregated         = '';
    public string $tail               = '';
    public bool   $truncated          = false;
    public int    $totalOutputChars   = 0;
    public int    $maxOutputChars;
    public int    $pendingMaxChars;

    // Process state
    public bool $exited     = false;
    public ?int $exitCode   = null;
    public ?int $exitSignal = null;
    public bool $backgrounded = false;

    // Process resources (cleaned up on exit)
    /** @var resource|null */
    public $process = null;
    /** @var resource|null */
    public $stdin = null;
    /** @var resource|null */
    public $stdout = null;

    public function __construct(
        string  $command,
        ?string $cwd = null,
        ?string $scopeKey = null,
        int     $maxOutputChars = MAX_OUTPUT_CHARS,
        int     $pendingMaxChars = PENDING_MAX_CHARS,
    ) {
        $this->id              = bin2hex(random_bytes(8));
        $this->command         = $command;
        $this->cwd             = $cwd;
        $this->scopeKey        = $scopeKey;
        $this->startedAt       = microtime(true);
        $this->maxOutputChars  = $maxOutputChars;
        $this->pendingMaxChars = $pendingMaxChars;
    }

    /**
     * Append a chunk to the pending and aggregated buffers.
     *
     * Mirrors: src/agents/bash-process-registry.ts → appendOutput()
     *
     * The pending buffer drops oldest chunks when it exceeds the cap.
     * The aggregated buffer keeps only the tail on overflow.
     */
    public function appendOutput(string $chunk): void
    {
        // --- Pending buffer (incremental) ---
        $this->pendingStdout[]    = $chunk;
        $this->pendingStdoutChars += strlen($chunk);

        if ($this->pendingStdoutChars > $this->pendingMaxChars) {
            $this->truncated = true;
            $this->capPendingBuffer();
        }

        // --- Aggregated buffer (history) ---
        $this->totalOutputChars += strlen($chunk);
        $newAggregated = trim_with_cap(
            $this->aggregated . $chunk,
            $this->maxOutputChars
        );
        if (strlen($newAggregated) < strlen($this->aggregated) + strlen($chunk)) {
            $this->truncated = true;
        }
        $this->aggregated = $newAggregated;

        // --- Tail (last N chars for quick notifications) ---
        $this->tail = trim_with_cap($this->aggregated, TAIL_CHARS);
    }

    /**
     * Drain pending output (returns accumulated text and resets the buffer).
     *
     * Mirrors: src/agents/bash-process-registry.ts → drainSession()
     */
    public function drainPending(): string
    {
        $output = implode('', $this->pendingStdout);
        $this->pendingStdout      = [];
        $this->pendingStdoutChars = 0;
        return $output;
    }

    /**
     * Drop oldest pending chunks until under cap. Keeps the tail.
     *
     * Mirrors: src/agents/bash-process-registry.ts → capPendingBuffer()
     */
    private function capPendingBuffer(): void
    {
        $cap = min($this->pendingMaxChars, $this->maxOutputChars);

        // If the last chunk alone exceeds the cap, keep only its tail.
        $last = end($this->pendingStdout);
        if ($last !== false && strlen($last) >= $cap) {
            $this->pendingStdout      = [substr($last, strlen($last) - $cap)];
            $this->pendingStdoutChars = $cap;
            return;
        }

        // Drop oldest chunks.
        while (
            count($this->pendingStdout) > 0
            && ($this->pendingStdoutChars - strlen($this->pendingStdout[0])) >= $cap
        ) {
            $this->pendingStdoutChars -= strlen(array_shift($this->pendingStdout));
        }

        // Trim the new first chunk if still over cap.
        if (count($this->pendingStdout) > 0 && $this->pendingStdoutChars > $cap) {
            $overflow = $this->pendingStdoutChars - $cap;
            $this->pendingStdout[0] = substr($this->pendingStdout[0], $overflow);
            $this->pendingStdoutChars = $cap;
        }
    }

    /**
     * Clean up process resources to prevent FD leaks.
     *
     * Mirrors: src/agents/bash-process-registry.ts → moveToFinished()
     *   which explicitly destroys stdin/stdout/stderr and removes listeners.
     */
    public function cleanup(): void
    {
        if (is_resource($this->stdin)) {
            fclose($this->stdin);
            $this->stdin = null;
        }
        if (is_resource($this->stdout)) {
            fclose($this->stdout);
            $this->stdout = null;
        }
        if (is_resource($this->process)) {
            proc_close($this->process);
            $this->process = null;
        }
    }
}


// ─── SessionRegistry ──────────────────────────────────────────────────────────
//
// Mirrors the two-map pattern from:
//   src/agents/bash-process-registry.ts → runningSessions + finishedSessions
//
// Running sessions are tracked by ID. When a process exits, the session
// moves to the finished map and is auto-pruned after SESSION_TTL_SEC.

class SessionRegistry
{
    /** @var array<string, ProcessSession> */
    private array $running = [];

    /** @var array<string, array{session: ProcessSession, endedAt: float}> */
    private array $finished = [];

    private float $lastSweep;
    private int   $ttlSec;
    private int   $sweepIntervalSec;

    public function __construct(
        int $ttlSec = SESSION_TTL_SEC,
        int $sweepIntervalSec = SWEEP_INTERVAL_SEC,
    ) {
        $this->ttlSec           = $ttlSec;
        $this->sweepIntervalSec = $sweepIntervalSec;
        $this->lastSweep        = microtime(true);
    }

    public function add(ProcessSession $session): void
    {
        $this->running[$session->id] = $session;
    }

    public function get(string $id): ?ProcessSession
    {
        return $this->running[$id] ?? null;
    }

    public function getFinished(string $id): ?ProcessSession
    {
        return ($this->finished[$id] ?? null)?['session'] ?? null;
    }

    /**
     * Move a session from running → finished. Clean up its resources.
     *
     * Mirrors: src/agents/bash-process-registry.ts → markExited() + moveToFinished()
     */
    public function markExited(string $id, int $exitCode, ?int $exitSignal = null): void
    {
        $session = $this->running[$id] ?? null;
        if ($session === null) {
            return;
        }

        $session->exited     = true;
        $session->exitCode   = $exitCode;
        $session->exitSignal = $exitSignal;
        $session->tail       = trim_with_cap($session->aggregated, TAIL_CHARS);
        $session->cleanup();

        unset($this->running[$id]);
        $this->finished[$id] = [
            'session' => $session,
            'endedAt' => microtime(true),
        ];
    }

    /**
     * Cancel all running sessions that share a scope key.
     *
     * Mirrors: src/process/supervisor/supervisor.ts → cancelScope()
     */
    public function cancelScope(string $scopeKey): void
    {
        foreach ($this->running as $session) {
            if ($session->scopeKey === $scopeKey && is_resource($session->process)) {
                $status = proc_get_status($session->process);
                if ($status['running'] && $status['pid'] > 0) {
                    posix_kill($status['pid'], SIGKILL);
                }
            }
        }
    }

    /** @return ProcessSession[] */
    public function listRunning(): array
    {
        return array_values($this->running);
    }

    /**
     * Prune finished sessions older than TTL.
     *
     * Mirrors: src/agents/bash-process-registry.ts → pruneFinishedSessions()
     * Called lazily (on access) rather than via setInterval.
     */
    public function sweep(): void
    {
        $now = microtime(true);
        if (($now - $this->lastSweep) < $this->sweepIntervalSec) {
            return;
        }
        $this->lastSweep = $now;
        $cutoff = $now - $this->ttlSec;
        foreach ($this->finished as $id => $entry) {
            if ($entry['endedAt'] < $cutoff) {
                unset($this->finished[$id]);
            }
        }
    }
}


// ─── ClaudeProcessRunner ──────────────────────────────────────────────────────
//
// Orchestrates spawning a Claude Code (or Codex) CLI process with:
//   - PTY emulation via the `script` command (POSIX) as a wrapper
//   - Non-blocking I/O via stream_set_blocking() + stream_select()
//   - DSR auto-response to prevent cursor-query hangs
//   - Dual timeout: overall deadline + no-output idle watchdog
//
// This mirrors the full spawn → monitor → settle flow in:
//   src/process/supervisor/supervisor.ts → spawn()

class ClaudeProcessRunner
{
    private SessionRegistry $registry;
    private string $binary;
    private int    $overallTimeoutSec;
    private int    $noOutputTimeoutSec;

    /**
     * @param SessionRegistry $registry        Shared session registry
     * @param string          $binary          CLI binary name ('claude' or 'codex')
     * @param int             $overallTimeout  Hard deadline in seconds
     * @param int             $noOutputTimeout Idle watchdog in seconds
     */
    public function __construct(
        SessionRegistry $registry,
        string $binary = 'claude',
        int    $overallTimeout = OVERALL_TIMEOUT_SEC,
        int    $noOutputTimeout = NO_OUTPUT_TIMEOUT_SEC,
    ) {
        $this->registry           = $registry;
        $this->binary             = $binary;
        $this->overallTimeoutSec  = $overallTimeout;
        $this->noOutputTimeoutSec = $noOutputTimeout;
    }

    /**
     * Run a prompt through Claude Code (or Codex) and return the full result.
     *
     * This is the PHP equivalent of the full spawn → wait flow in:
     *   src/process/supervisor/supervisor.ts → spawn() + waitPromise
     *
     * @param  string      $prompt    The task/question for the AI
     * @param  string      $cwd       Working directory (must be a git repo for Codex)
     * @param  array       $env       Extra environment variables (e.g. ANTHROPIC_API_KEY)
     * @param  string|null $scopeKey  Logical group for batch cancellation
     * @return array{
     *   exitCode: int,
     *   output: string,
     *   tail: string,
     *   truncated: bool,
     *   totalChars: int,
     *   durationSec: float,
     *   timedOut: bool,
     *   noOutputTimedOut: bool,
     *   terminationReason: string
     * }
     */
    public function run(
        string  $prompt,
        string  $cwd,
        array   $env = [],
        ?string $scopeKey = null,
    ): array {
        // ── Build the command ──────────────────────────────────────────────
        // Escape the prompt for shell safety.
        $escapedPrompt = escapeshellarg($prompt);
        $cliCommand    = "{$this->binary} {$escapedPrompt}";

        // Wrap in `script` to provide a PTY.
        // This mirrors src/process/supervisor/adapters/pty.ts which uses
        // @lydell/node-pty to create a pseudo-terminal. PHP has no native
        // PTY support, so `script -qc` is the portable POSIX equivalent.
        //
        //   script -q /dev/null -c "claude 'prompt'"
        //
        // -q  = quiet (no "Script started" banner)
        // -c  = run command instead of interactive shell
        // /dev/null = discard the typescript file
        $wrappedCommand = sprintf(
            'script -q /dev/null -c %s',
            escapeshellarg($cliCommand)
        );

        $session = new ProcessSession($cliCommand, $cwd, $scopeKey);

        // ── Spawn the process ──────────────────────────────────────────────
        // Use proc_open with pipes. The `script` wrapper provides the PTY
        // layer; we communicate with `script` via regular pipes.
        //
        // Descriptor layout:
        //   0 → stdin  (pipe, writable) — for DSR responses & user input
        //   1 → stdout (pipe, readable) — merged stdout+stderr from PTY
        //   2 → stderr (pipe, readable) — script's own errors (rare)
        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'r'],  // stdout (PTY merges stdout+stderr)
            2 => ['pipe', 'r'],  // stderr
        ];

        $mergedEnv = array_merge(
            ['TERM' => 'xterm-256color'],  // PTY terminal type
            $env,
        );

        $process = proc_open($wrappedCommand, $descriptors, $pipes, $cwd, $mergedEnv);
        if (!is_resource($process)) {
            throw new RuntimeException("Failed to spawn process: {$wrappedCommand}");
        }

        $session->process = $process;
        $session->stdin   = $pipes[0];
        $session->stdout  = $pipes[1];

        // Get PID (mirrors supervisor.ts setting record.pid after spawn)
        $status       = proc_get_status($process);
        $session->pid = $status['pid'] ?? null;

        // Register in the session registry
        $this->registry->add($session);

        // ── Set non-blocking I/O ───────────────────────────────────────────
        // Critical: without this, fread() blocks and we can't implement
        // timeouts or write DSR responses while reading.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        // ── Event loop: read output, handle DSR, enforce timeouts ──────────
        // This mirrors the supervisor's onStdout callback + timeout timers
        // from src/process/supervisor/supervisor.ts:178-191
        $startTime       = microtime(true);
        $lastOutputTime  = $startTime;
        $terminationReason = 'exit';
        $timedOut          = false;
        $noOutputTimedOut  = false;

        while (true) {
            // Check if process is still running
            $status = proc_get_status($process);
            if (!$status['running']) {
                // Drain any remaining output
                $this->drainRemainingOutput($pipes[1], $session);
                $session->exitCode = $status['exitcode'];
                break;
            }

            // ── Timeout checks ─────────────────────────────────────────────
            // Two-tier timeout mirrors supervisor.ts:167-176
            $elapsed = microtime(true) - $startTime;
            $idleTime = microtime(true) - $lastOutputTime;

            // Overall timeout (hard deadline)
            if ($elapsed > $this->overallTimeoutSec) {
                $terminationReason = 'overall-timeout';
                $timedOut = true;
                $this->killProcess($session);
                break;
            }

            // No-output timeout (idle watchdog)
            if ($idleTime > $this->noOutputTimeoutSec) {
                $terminationReason = 'no-output-timeout';
                $noOutputTimedOut  = true;
                $timedOut = true;
                $this->killProcess($session);
                break;
            }

            // ── Non-blocking read with stream_select ───────────────────────
            // Wait up to 100ms for output. This lets us check timeouts
            // ~10 times per second without busy-waiting.
            $read   = [$pipes[1]];
            $write  = null;
            $except = null;
            $ready  = stream_select($read, $write, $except, 0, 100_000);

            if ($ready > 0 && isset($read[0])) {
                $chunk = fread($pipes[1], READ_CHUNK_BYTES);
                if ($chunk !== false && $chunk !== '') {
                    // ── DSR handling ───────────────────────────────────────
                    // Mirrors src/agents/pty-dsr.ts → stripDsrRequests()
                    // and the onStdout callback in bash-tools.exec-runtime.ts
                    // that auto-responds with cursor position.
                    $dsr = strip_dsr_requests($chunk);
                    if ($dsr['requests'] > 0) {
                        $response = build_cursor_position_response(1, 1);
                        for ($i = 0; $i < $dsr['requests']; $i++) {
                            fwrite($pipes[0], $response);
                        }
                    }

                    // Append cleaned output to session buffers
                    $session->appendOutput($dsr['cleaned']);

                    // Reset the no-output watchdog
                    // Mirrors supervisor.ts → touchOutput()
                    $lastOutputTime = microtime(true);
                }
            }

            // Lazy sweep of finished sessions
            $this->registry->sweep();
        }

        // ── Finalize ───────────────────────────────────────────────────────
        // Mirrors supervisor.ts:224-228 → registry.finalize()
        $endTime = microtime(true);
        $this->registry->markExited(
            $session->id,
            $session->exitCode ?? -1,
            $session->exitSignal,
        );

        // Close remaining pipe
        if (is_resource($pipes[2])) {
            fclose($pipes[2]);
        }

        return [
            'exitCode'          => $session->exitCode ?? -1,
            'output'            => $session->aggregated,
            'tail'              => $session->tail,
            'truncated'         => $session->truncated,
            'totalChars'        => $session->totalOutputChars,
            'durationSec'       => round($endTime - $startTime, 3),
            'timedOut'          => $timedOut,
            'noOutputTimedOut'  => $noOutputTimedOut,
            'terminationReason' => $terminationReason,
        ];
    }

    /**
     * Kill the process tree. Uses SIGKILL for immediate termination.
     *
     * Mirrors: src/process/supervisor/adapters/pty.ts → kill()
     *   which calls killProcessTree(pid) on SIGKILL.
     */
    private function killProcess(ProcessSession $session): void
    {
        if ($session->pid !== null && $session->pid > 0) {
            // Kill the entire process group (negative PID).
            // This mirrors OpenClaw's killProcessTree() which kills
            // the process and all its children.
            posix_kill(-$session->pid, SIGKILL);
            posix_kill($session->pid, SIGKILL);
        }
        if (is_resource($session->process)) {
            proc_terminate($session->process, SIGKILL);
        }
    }

    /**
     * Drain any remaining output from the pipe after process exit.
     */
    private function drainRemainingOutput($pipe, ProcessSession $session): void
    {
        if (!is_resource($pipe)) {
            return;
        }
        // Read remaining buffered output
        while (true) {
            $chunk = fread($pipe, READ_CHUNK_BYTES);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $dsr = strip_dsr_requests($chunk);
            $session->appendOutput($dsr['cleaned']);
        }
    }
}


// ─── Streaming Runner ─────────────────────────────────────────────────────────
//
// For long-running tasks, you may want incremental output instead of waiting
// for the full result. This mirrors the onStdout/onStderr callback pattern
// from SpawnInput (src/process/supervisor/types.ts:71-72).

class StreamingClaudeRunner extends ClaudeProcessRunner
{
    /**
     * Run with a streaming callback that receives output incrementally.
     *
     * @param  string   $prompt   The task/question
     * @param  string   $cwd      Working directory
     * @param  callable $onOutput Called with each cleaned output chunk: fn(string $chunk): void
     * @param  array    $env      Extra environment variables
     * @return array    Same result structure as run()
     */
    public function runStreaming(
        string   $prompt,
        string   $cwd,
        callable $onOutput,
        array    $env = [],
    ): array {
        // For streaming, we wrap the base run() and poll the session's
        // pending buffer. In a real implementation you'd integrate the
        // callback directly into the event loop (as OpenClaw does with
        // input.onStdout in supervisor.ts:182-184).
        //
        // This simplified version demonstrates the drain pattern:
        //   src/agents/bash-process-registry.ts → drainSession()

        $registry = new SessionRegistry();
        $runner   = new ClaudeProcessRunner($registry, 'claude');

        // Run in a forked context or use the base run() and periodically
        // drain. For simplicity, we show the post-hoc drain pattern:
        $result = $runner->run($prompt, $cwd, $env);

        // In practice, you'd call $onOutput from inside the event loop.
        // This is a simplified illustration:
        $onOutput($result['output']);

        return $result;
    }
}


// ─── Example Usage ────────────────────────────────────────────────────────────

/**
 * Uncomment the block below to test the integration end-to-end.
 * Requires `claude` CLI installed and ANTHROPIC_API_KEY set.
 */

/*
// Basic usage
$registry = new SessionRegistry();
$runner   = new ClaudeProcessRunner($registry, 'claude');

$result = $runner->run(
    'List the files in this directory and explain the project structure',
    '/path/to/your/project',
    ['ANTHROPIC_API_KEY' => getenv('ANTHROPIC_API_KEY')],
);

echo "Exit code: {$result['exitCode']}\n";
echo "Duration:  {$result['durationSec']}s\n";
echo "Truncated: " . ($result['truncated'] ? 'yes' : 'no') . "\n";
echo "Timed out: " . ($result['timedOut'] ? 'yes' : 'no') . "\n";
echo "\n--- Output (tail) ---\n";
echo $result['tail'] . "\n";


// For Codex (requires git repo + OpenAI auth):
$codexRunner = new ClaudeProcessRunner($registry, 'codex', binary: 'codex');
$codexResult = $codexRunner->run(
    'Refactor the User model to use dependency injection',
    '/path/to/git/repo',
    ['OPENAI_API_KEY' => getenv('OPENAI_API_KEY')],
);


// Session management
echo "\nRunning sessions: " . count($registry->listRunning()) . "\n";

// Scope-based cancellation (kill all sessions in a group)
$registry->cancelScope('batch-refactor-2024');
*/
