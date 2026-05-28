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
