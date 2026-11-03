@echo off
REM Накат змін на ESP32 по мережі (OTA). Використання:
REM   tools\deploy-ota.bat 172.16.0.150
REM   tools\deploy-ota.bat drazice-boiler.local
REM Запускати з кореня репозиторію. Потрібен .venv-build з ESPHome.
setlocal
if "%~1"=="" (
  echo Вкажіть IP або hostname плати, напр.: tools\deploy-ota.bat 172.16.0.150
  exit /b 2
)
".venv-build\Scripts\python.exe" tools\deploy.py ota --host %1
