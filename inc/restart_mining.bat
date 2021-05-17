@echo off

echo 'Restarting Computer..'
taskkill /IM "msApp.exe" /F
taskkill /IM "statService.exe" /F
taskkill /IM "PhoenixMiner.exe" /F

ping -n 2 127.0.0.1

echo 'Sending info to API..'
start "C:\RIG_API_Infos\api_send.bat"

echo 'Shutdown..'
shutdown /r /f /t 0