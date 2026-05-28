# AI Agent VPS Work Log

This log records each VPS-side AI agent step so work can continue smoothly between desktop and mobile.

## 2026-05-28

### Step 1 - VPS capability check

- Goal: confirm whether the VPS can run a local AI agent runtime.
- Server: `ringo@45.121.48.35`
- Result:
  - No Ollama / llama / local AI service was installed.
  - No GPU was detected.
  - CPU: 2 cores, Intel Xeon E5-2699 v4.
  - RAM: 4GB total, about 3GB available.
  - Disk: about 61GB available.
  - Python 3.10, pip, venv, curl, wget, git and build-essential are available.
  - Laravel agent commands already exist:
    - `market:export-agent-knowledge-pack`
    - `market:agents-ingest-local-report`
    - `market:agents-run`
    - `market:agents-review-cases`
    - `market:agents-seed-knowledge`
- Decision:
  - Use Ollama on VPS.
  - Start with a small CPU-friendly model, preferably `qwen2.5:1.5b`.
  - Avoid 7B+ models on this VPS because 2 CPU / 4GB RAM is too small.

### Step 2 - Install Ollama

- Goal: install local AI runtime on VPS.
- Official Linux install source checked: <https://docs.ollama.com/linux>
- Official install command:
  ```bash
  curl -fsSL https://ollama.com/install.sh | sh
  ```
- Attempt:
  ```bash
  ssh ringo@45.121.48.35 "curl -fsSL https://ollama.com/install.sh -o /tmp/ollama-install.sh && sh /tmp/ollama-install.sh"
  ```
- Result:
  - The installer started.
  - It stopped because sudo needs an interactive password:
    `sudo: a terminal is required to read the password`
- Next action:
  - Run the official install command manually inside an interactive SSH session:
    ```bash
    ssh ringo@45.121.48.35
    curl -fsSL https://ollama.com/install.sh | sh
    ```
  - Enter ringo's sudo password when prompted.
- Manual install result:
  - Ollama installed to `/usr/local`.
  - System user `ollama` created.
  - `ollama.service` created, enabled and started.
  - API is available at `127.0.0.1:11434`.
  - Warning: no NVIDIA/AMD GPU detected; Ollama will run CPU-only.
- Verification:
  ```bash
  ollama --version
  systemctl is-active ollama
  systemctl is-enabled ollama
  curl -s http://127.0.0.1:11434/api/tags
  ```
- Verification result:
  - Ollama version: `0.24.0`
  - Service: `active`
  - Enabled on boot: `enabled`
  - Installed models: none yet.

### Step 3 - Pull small CPU-friendly model

- Goal: install the first lightweight model for local agent reports.
- Candidate model: `qwen2.5:1.5b`
- Reason:
  - Better Chinese capability than many tiny English-first models.
  - Small enough for 2 CPU / 4GB RAM VPS testing.
- Result:
  - `qwen2.5:1.5b` pulled successfully.
  - Size: about `986 MB`.
  - A short Chinese generation test did not complete within about 3 minutes.
  - Conclusion: too slow for this VPS as the default agent model.

### Step 4 - Pull smaller fallback model

- Goal: test a smaller model because 1.5B was too slow.
- Candidate model: `qwen2.5:0.5b`
- Result:
  - `qwen2.5:0.5b` pulled successfully.
  - Size: about `397 MB`.
  - A very short API generation test still did not return within the command timeout.
- Current installed models:
  - `qwen2.5:0.5b`
  - `qwen2.5:1.5b`
- Current decision:
  - Ollama installation is successful.
  - Model inference on this 2 CPU / 4GB VPS is much slower than desired.
  - Any VPS local-agent runner must enforce strict timeout and never block website jobs.
  - First production runner should be conservative:
    - read Knowledge Pack,
    - ask the local model only for very small tasks,
    - fall back to rule-based findings if Ollama times out.

### Step 5 - Nightly agent schedule decision

- Goal: keep AI agents away from normal browsing/data-refresh hours.
- Decision:
  - VPS agents start at `01:00` Asia/Taipei.
  - Codex review/fix window starts at `04:00` Asia/Taipei.
- Laravel schedule design:
  - `00:50` build daily market context:
    `market:build-daily-context --session=daily`
  - `00:55` export Knowledge Pack:
    `market:export-agent-knowledge-pack`
  - `01:00` run rule-based agents:
    `market:agents-run`
  - `01:40` review/triage pending agent cases:
    `market:agents-review-cases`
- Reason:
  - 01:00 gives agents a clean overnight window.
  - 04:00 leaves time for agent findings to be created before Codex review.
  - This avoids competing with Taiwan market, US market, and user browsing peak periods.

### Step 6 - Stable agent case query command

- Problem found:
  - Manual DB queries through nested PowerShell, SSH and shell quoting are fragile.
  - This is unsafe for the future Ollama runner because the local model also needs reliable case/context lookup.
- Fix:
  - Added Laravel command:
    `market:agents-latest-findings`
  - Human-readable mode shows recent cases with case number, time, agent, status, severity, type, page/symbol and details.
  - Machine-readable mode supports:
    `market:agents-latest-findings --json`
  - Future Ollama integration must query agent findings through Laravel services/commands, not shell SQL.
- VPS verification:
  ```bash
  cd /home/ringo/apps/marketx
  php artisan market:agents-latest-findings --limit=5
  ```
- Verification result:
  - Command returned the latest 5 agent cases successfully.
  - Latest cases included `AG-20260529-00050` and `AG-20260529-00049` for missing daily AI reports.

### Step 7 - Connect Ollama as a conservative nightly reviewer

- Goal:
  - Let the VPS local model review recent agent cases without affecting website requests.
- Added command:
  - `market:agents-run-ollama`
  - `market:agents-mark-finding` for safe single-case status updates without shell SQL.
- Schedule:
  - `01:20` Asia/Taipei, after rule-based agents at `01:00` and before case review at `01:40`.
- Safety design:
  - Reads recent `pending` / `observing` cases through Laravel Eloquent.
  - Calls Ollama only from a background Artisan command.
  - Writes output to `agent_runs`.
  - Creates new findings only when the model cites an `AG-` case number as concrete evidence.
  - Invalid page values are ignored instead of written into the database.
  - Timeout is capped so the model cannot block normal site usage.
  - Running Ollama runs older than 12 minutes are marked failed before the next execution.
- Model test:
  - `qwen2.5:1.5b` timed out after 120 seconds on two cases, so it is too slow for the VPS default.
  - `qwen2.5:0.5b` completed a one-case test in about 34 seconds.
- Current default:
  - `qwen2.5:1.5b`
- Runtime policy:
  - Quality is more important than speed because the job runs at `01:20`.
  - Timeout is set to 600 seconds in the schedule.
  - The command allows up to 900 seconds for manual deep tests.
- Current limitation:
  - 1.5B is slow on the 2 CPU / 4GB VPS.
  - It should run only as an overnight background reviewer, not during user-facing requests.
- Cleanup:
  - The first 0.5B test produced one weak finding without concrete `AG-` evidence.
  - It was marked rejected after the guard was added.
- 2026-05-29 01:20 verification:
  - Schedule did start `qwen2.5:1.5b`.
  - The model returned, but one response field was an array, causing `Array to string conversion` during write.
  - Added array/object guards for nullable string fields and wrapped response processing so failures update `agent_runs`.
  - Manual retry with `qwen2.5:1.5b --limit=1 --timeout=600` succeeded in about 63 seconds.
