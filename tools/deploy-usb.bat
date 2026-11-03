@echo off
REM Накат змін на ESP32 по кабелю (USB). Використання:
REM   tools\deploy-usb.bat COM7
REM Запускати з кореня репозиторію. Потрібні .venv-build (ESPHome) і
REM .venv-flash (esptool 4.x). Пише через .venv-flash (--use-flash-venv).
setlocal
if "%~1"=="" (
  echo Вкажіть порт, напр.: tools\deploy-usb.bat COM7
  exit /b 2
)
".venv-build\Scripts\python.exe" tools\deploy.py usb --port %1 --use-flash-venv
