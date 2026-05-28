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
