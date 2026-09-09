@echo off
setlocal

cd /d %~dp0\..

set RELAY_PORT=18087
set PROXY_PORT=%~1
set RUN_TAG=%~2

if "%RUN_TAG%"=="" (
  for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyyMMdd_HHmmss"') do set RUN_TAG=manual_%%i
)

set OUT_ROOT=docs\eval\runs\template_question_smoke_20260530
set DT_CASES=%OUT_ROOT%\dt_replacement_cases.jsonl
set AGENT_CASES=%OUT_ROOT%\agent_template_cases.jsonl
set DT_OUT=%OUT_ROOT%\dt_replacements_%RUN_TAG%
set AGENT_OUT=%OUT_ROOT%\agent_templates_%RUN_TAG%
set LOG_DIR=%OUT_ROOT%\manual_logs

if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"

echo [1/5] Stopping existing relay on port %RELAY_PORT% if present...
powershell -NoProfile -ExecutionPolicy Bypass -Command "$conn = Get-NetTCPConnection -LocalPort %RELAY_PORT% -ErrorAction SilentlyContinue | Where-Object {$_.State -eq 'Listen'} | Select-Object -First 1; if ($conn) { Stop-Process -Id $conn.OwningProcess -Force; Start-Sleep -Seconds 1; Write-Output ('Stopped relay process ' + $conn.OwningProcess) } else { Write-Output 'No existing relay listener' }"

echo [2/5] Starting relay...
if "%PROXY_PORT%"=="" (
  echo Starting relay in direct/bypass mode.
  set BIOLOGY_LLM_RELAY_HTTPS_PROXY=
  set BIOLOGY_LLM_RELAY_HTTP_PROXY=
  set BIOLOGY_LLM_RELAY_BYPASS_PROXY=true
) else (
  echo Starting relay through explicit HTTP/Mixed proxy 127.0.0.1:%PROXY_PORT%.
  set BIOLOGY_LLM_RELAY_HTTPS_PROXY=http://127.0.0.1:%PROXY_PORT%
  set BIOLOGY_LLM_RELAY_HTTP_PROXY=http://127.0.0.1:%PROXY_PORT%
  set BIOLOGY_LLM_RELAY_BYPASS_PROXY=
)

start "TE-KG LLM Relay %RUN_TAG%" /min cmd /c "cd /d %CD%\scripts && python llm_relay.py > ..\%LOG_DIR%\relay_%RUN_TAG%.log 2>&1"

echo Waiting for relay health...
timeout /t 3 /nobreak >nul
powershell -NoProfile -ExecutionPolicy Bypass -Command "Invoke-RestMethod -Uri http://127.0.0.1:%RELAY_PORT%/health -TimeoutSec 10 | ConvertTo-Json -Depth 8" > "%LOG_DIR%\relay_health_%RUN_TAG%.json"
if errorlevel 1 (
  echo Relay health check failed. See %LOG_DIR%\relay_%RUN_TAG%.log
  exit /b 1
)
type "%LOG_DIR%\relay_health_%RUN_TAG%.json"

echo [3/5] Running 2 Deep Think replacement template cases...
python scripts\eval\run_dt_agent_live_eval.py --base-url http://127.0.0.1/TE- --cases "%DT_CASES%" --dt-only --out-dir "%DT_OUT%" --timeout 600 --poll-interval 2
set DT_EXIT=%ERRORLEVEL%

echo [4/5] Running 5 Agent template cases...
python scripts\eval\run_dt_agent_live_eval.py --base-url http://127.0.0.1/TE- --cases "%AGENT_CASES%" --agent-only --model deepseek-v4-pro --out-dir "%AGENT_OUT%" --timeout 2400 --poll-interval 2
set AGENT_EXIT=%ERRORLEVEL%

echo [5/5] Summary paths:
echo   DT:    %DT_OUT%
echo   Agent: %AGENT_OUT%
echo   Relay log: %LOG_DIR%\relay_%RUN_TAG%.log
echo   Relay health: %LOG_DIR%\relay_health_%RUN_TAG%.json

if not "%DT_EXIT%"=="0" (
  echo Deep Think eval command failed with exit %DT_EXIT%.
)
if not "%AGENT_EXIT%"=="0" (
  echo Agent eval command failed with exit %AGENT_EXIT%.
)

if "%DT_EXIT%%AGENT_EXIT%"=="00" (
  echo Template quality eval commands finished.
  exit /b 0
)

exit /b 1

