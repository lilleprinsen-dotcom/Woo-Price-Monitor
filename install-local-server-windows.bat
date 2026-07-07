@echo off
setlocal EnableExtensions
cd /d "%~dp0"

echo Woo Price Monitor local browser-worker installer
echo ================================================
echo.

where docker >nul 2>nul
if errorlevel 1 (
	echo Docker Desktop is needed to run the local browser worker.
	where winget >nul 2>nul
	if errorlevel 1 (
		echo Winget was not found, so this installer cannot install Docker automatically.
		echo Opening the Docker Desktop download page. Install Docker Desktop, then run this installer again.
		start "" "https://www.docker.com/products/docker-desktop/"
		pause
		exit /b 1
	) else (
		echo Installing Docker Desktop with winget...
		winget install -e --id Docker.DockerDesktop
	)
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
	echo Open Docker Desktop manually, wait until it is running, then double-click this installer again.
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
		echo Docker Compose was not found. Update Docker Desktop, then run this installer again.
		pause
		exit /b 1
	) else (
		set "COMPOSE=docker-compose"
	)
) else (
	set "COMPOSE=docker compose"
)

if not exist ".lpm-worker-secret" (
	powershell -NoProfile -ExecutionPolicy Bypass -Command "$b=New-Object byte[] 32; [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($b); [IO.File]::WriteAllText('.lpm-worker-secret', ([BitConverter]::ToString($b).Replace('-','').ToLower()))"
)

set /p SECRET=<.lpm-worker-secret

if not exist "docker-compose.yml" (
	copy "docker-compose.example.yml" "docker-compose.yml" >nul
)

powershell -NoProfile -ExecutionPolicy Bypass -Command "$p='docker-compose.yml'; $s=Get-Content '.lpm-worker-secret' -Raw; $s=$s.Trim(); $t=Get-Content $p -Raw; $t=$t.Replace('replace-with-a-long-random-secret',$s); Set-Content -Path $p -Value $t -NoNewline"

echo Building the local browser-worker. This may take a few minutes the first time...
%COMPOSE% build browser-worker
if errorlevel 1 (
	echo Build failed. Check Docker Desktop, then run this installer again.
	pause
	exit /b 1
)

echo.
echo Installed successfully.
echo.
echo Next step: double-click start-local-server-windows.bat
echo.
echo WordPress settings:
echo Endpoint URL: http://localhost:8787
echo API secret:   %SECRET%
powershell -NoProfile -ExecutionPolicy Bypass -Command "Get-Content '.lpm-worker-secret' -Raw | Set-Clipboard" >nul 2>nul
echo.
echo The API secret was copied to your clipboard if Windows allowed clipboard access.
echo.
pause
