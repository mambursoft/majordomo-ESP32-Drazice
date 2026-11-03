@echo off
REM Deploy firmware to ESP32 over the network (OTA). Usage:
REM   tools\deploy-ota.bat 172.16.0.150
REM   tools\deploy-ota.bat drazice-boiler.local
REM Run from the repository root. Requires .venv-build with ESPHome.
setlocal
if "%~1"=="" (
  echo Specify the board IP or hostname, e.g.: tools\deploy-ota.bat 172.16.0.150
  exit /b 2
)
".venv-build\Scripts\python.exe" tools\deploy.py ota --host %1
