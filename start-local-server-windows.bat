@echo off
setlocal EnableExtensions
cd /d "%~dp0"

echo Starting Woo Price Monitor local browser worker
echo ==============================================
echo.

where docker >nul 2>nul
if errorlevel 1 (
	echo Docker Desktop is not installed.
	echo Double-click install-local-server-windows.bat first.
	pause
	exit /b 1
)

if not exist ".lpm-worker-secret" (
	echo The local server has not been installed yet.
	echo Double-click install-local-server-windows.bat first.
	pause
	exit /b 1
)

if not exist "docker-compose.yml" (
	echo The local server has not been installed yet.
	echo Double-click install-local-server-windows.bat first.
	pause
	exit /b 1
)

if exist "C:\Program Files\Docker\Docker\Docker Desktop.exe" (
	start "" "C:\Program Files\Docker\Docker\Docker Desktop.exe"
)

echo Waiting for Docker Desktop to be ready...
set /a COUNT=0
:wait_docker
docker info >nul 2>nul
if not errorlevel 1 goto docker_ready
set /a COUNT+=1
if %COUNT% GEQ 60 (
	echo Docker Desktop did not become ready.
	echo Open Docker Desktop manually, wait until it is running, then start again.
	pause
	exit /b 1
)
timeout /t 3 /nobreak >nul
goto wait_docker

:docker_ready
docker compose version >nul 2>nul
if errorlevel 1 (
	where docker-compose >nul 2>nul
	if errorlevel 1 (
		echo Docker Compose was not found. Update Docker Desktop, then start again.
		pause
		exit /b 1
	) else (
		set "COMPOSE=docker-compose"
	)
) else (
	set "COMPOSE=docker compose"
)

set /p SECRET=<.lpm-worker-secret

echo.
echo Server will run at: http://localhost:8787
echo WordPress API secret: %SECRET%
echo.
echo Keep this window open while using the worker.
echo To stop the worker, press Ctrl+C or close this window.
echo.

%COMPOSE% up --build browser-worker
pause
