@echo off
title Tikwele P2P Platform - System Start
color 0B

echo ==========================================
echo    STARTING TIKWELE P2P PLATFORM
echo ==========================================

:: 1. Start XAMPP Apache and MySQL
echo [1/3] Starting Database and Web Server...
start /b C:\xampp\mysql\bin\mysqld.exe
start /b C:\xampp\apache\bin\httpd.exe

:: 2. Give the services a moment to warm up
timeout /t 3 /nobreak > NUL

:: 3. Start the Ngrok Tunnel with your permanent domain
echo [2/3] Starting Permanent Internet Tunnel...
:: Note: This assumes ngrok.exe is in your leave-tracker folder
start /b C:\xampp\htdocs\leave-tracker\ngrok.exe http --url=overwithered-braden-incremental.ngrok-free.dev 80

:: 4. Open the project in your browser
echo [3/3] Opening Tikwele Dashboard...
start http://localhost/leave-tracker

echo ------------------------------------------
echo SYSTEM IS LIVE!
echo Tunnel: https://overwithered-braden-incremental.ngrok-free.dev
echo ------------------------------------------
echo Keep this window open while you work.
pause