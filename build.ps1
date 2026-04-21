#!/usr/bin/env pwsh
<#
.SYNOPSIS
    Builds Moodle-ready ZIP packages for the local_aichat plugin and the custom theme.

.DESCRIPTION
    Creates distributable ZIP files that can be installed via
    Site administration > Plugins > Install plugins in Moodle.

    Output:
      dist/local_aichat-<version>.zip   (install under local/)
      dist/theme_myuni-<version>.zip    (install under theme/)

.PARAMETER Plugin
    Which plugin to package: 'aichat', 'theme', or 'all' (default: 'all').

.EXAMPLE
    .\build.ps1
    .\build.ps1 -Plugin aichat
    .\build.ps1 -Plugin theme
#>
param(
    [ValidateSet('aichat', 'theme', 'all')]
    [string]$Plugin = 'all'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$rootDir  = $PSScriptRoot
$distDir  = Join-Path $rootDir 'dist'

# Ensure dist directory exists
if (-not (Test-Path $distDir)) {
    New-Item -ItemType Directory -Path $distDir -Force | Out-Null
}

function Get-PluginVersion {
    param([string]$VersionFile)
    $content = Get-Content $VersionFile -Raw
    if ($content -match "\`$plugin->release\s*=\s*'([^']+)'") {
        return $Matches[1]
    }
    if ($content -match "\`$plugin->version\s*=\s*(\d+)") {
        return $Matches[1]
    }
    return 'unknown'
}

function New-MoodleZip {
    param(
        [string]$SourceDir,
        [string]$TopLevelFolder,
        [string]$VersionFile,
        [string]$OutputPrefix
    )

    $version = Get-PluginVersion -VersionFile $VersionFile
    $zipName = "${OutputPrefix}-${version}.zip"
    $zipPath = Join-Path $distDir $zipName

    # Remove old zip if it exists
    if (Test-Path $zipPath) {
        Remove-Item $zipPath -Force
    }

    # Create a temporary staging directory
    $stagingDir = Join-Path ([System.IO.Path]::GetTempPath()) "moodle_build_$([guid]::NewGuid().ToString('N'))"
    $stagingPluginDir = Join-Path $stagingDir $TopLevelFolder

    try {
        # Copy plugin files to staging, preserving structure
        # Exclude dev/build artifacts that shouldn't be in the distributable
        $excludeDirs  = @('.git', 'node_modules', '.github', '__pycache__')
        $excludeFiles = @('.gitignore', '.gitattributes', '.editorconfig', '*.log')

        Copy-Item -Path $SourceDir -Destination $stagingPluginDir -Recurse -Force

        # Clean up excluded directories
        foreach ($dir in $excludeDirs) {
            Get-ChildItem -Path $stagingPluginDir -Directory -Recurse -Filter $dir -ErrorAction SilentlyContinue |
                Remove-Item -Recurse -Force -ErrorAction SilentlyContinue
        }

        # Clean up excluded files
        foreach ($pattern in $excludeFiles) {
            Get-ChildItem -Path $stagingPluginDir -File -Recurse -Filter $pattern -ErrorAction SilentlyContinue |
                Remove-Item -Force -ErrorAction SilentlyContinue
        }

        # Create the ZIP from the staging directory
        Compress-Archive -Path $stagingPluginDir -DestinationPath $zipPath -CompressionLevel Optimal

        $sizeKB = [math]::Round((Get-Item $zipPath).Length / 1KB, 1)
        Write-Host "  Created: $zipName ($sizeKB KB)" -ForegroundColor Green
    }
    finally {
        # Clean up staging directory
        if (Test-Path $stagingDir) {
            Remove-Item $stagingDir -Recurse -Force -ErrorAction SilentlyContinue
        }
    }
}

# --- Build local_aichat ---
if ($Plugin -eq 'aichat' -or $Plugin -eq 'all') {
    Write-Host "`nPackaging local_aichat plugin..." -ForegroundColor Cyan
    New-MoodleZip `
        -SourceDir    (Join-Path $rootDir 'local\aichat') `
        -TopLevelFolder 'aichat' `
        -VersionFile  (Join-Path $rootDir 'local\aichat\version.php') `
        -OutputPrefix 'local_aichat'
}

# --- Build theme_myuni ---
if ($Plugin -eq 'theme' -or $Plugin -eq 'all') {
    Write-Host "`nPackaging custom theme..." -ForegroundColor Cyan
    New-MoodleZip `
        -SourceDir    (Join-Path $rootDir 'theme\myuni') `
        -TopLevelFolder 'myuni' `
        -VersionFile  (Join-Path $rootDir 'theme\myuni\version.php') `
        -OutputPrefix 'theme_myuni'
}

Write-Host "`nDone! ZIP files are in: $distDir" -ForegroundColor Cyan
