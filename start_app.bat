@echo off
title FormulaPaddock F1 Reel Engine - Local Web & Proxy Server
echo ============================================================
echo   FormulaPaddock F1 Reel Engine — Local Server & Scraper Proxy
echo ============================================================
echo.
echo Starting local web server & scraper API on port 5173...
echo Opening application in your default web browser...
echo.

start http://localhost:5173
python server.py

pause
