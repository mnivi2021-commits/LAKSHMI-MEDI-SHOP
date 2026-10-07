@echo off
rem Double-click this file to set up the Marketing CRM (asks Windows for administrator permission).
powershell -NoProfile -Command "Start-Process powershell -Verb RunAs -ArgumentList '-NoExit','-NoProfile','-ExecutionPolicy','Bypass','-File','\"%~dp0tools\setup-crm.ps1\"'"
