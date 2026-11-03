@echo off
REM Deploy firmware to ESP32 over cable (USB). Usage:
REM   tools\deploy-usb.bat COM7
REM Run from the repository root. Requires .venv-build (ESPHome) and
REM .venv-flash (esptool 4.x). Flashes via .venv-flash (--use-flash-venv).
setlocal
if "%~1"=="" (
  echo Specify the port, e.g.: tools\deploy-usb.bat COM7
  exit /b 2
)
".venv-build\Scripts\python.exe" tools\deploy.py usb --port %1 --use-flash-venv
