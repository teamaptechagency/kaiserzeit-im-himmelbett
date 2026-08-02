# Minimal static server for local preview.
# Node is not installed on this machine, so this stands in for `vercel dev`
# when you just want to look at the pages. It serves public/ only and knows
# nothing about /api/* — the pages fall back to assets/uploads/manifest.json.
#
#   powershell -ExecutionPolicy Bypass -File scripts\serve.ps1 -Port 8787

param([int]$Port = 8787)

$ErrorActionPreference = "Stop"
$root = Join-Path (Split-Path -Parent $PSScriptRoot) "public"

$mime = @{
  ".html" = "text/html; charset=utf-8"
  ".js"   = "text/javascript; charset=utf-8"
  ".json" = "application/json; charset=utf-8"
  ".css"  = "text/css; charset=utf-8"
  ".png"  = "image/png"
  ".jpg"  = "image/jpeg"
  ".jpeg" = "image/jpeg"
  ".webp" = "image/webp"
  ".svg"  = "image/svg+xml"
}

$listener = New-Object System.Net.HttpListener
$listener.Prefixes.Add("http://localhost:$Port/")
$listener.Start()
Write-Host "serving $root on http://localhost:$Port/"

while ($listener.IsListening) {
  $context = $listener.GetContext()
  $path = [System.Uri]::UnescapeDataString($context.Request.Url.AbsolutePath)
  if ($path -eq "/") { $path = "/Home.dc.html" }

  $file = Join-Path $root ($path.TrimStart("/") -replace "/", "\")
  $full = [System.IO.Path]::GetFullPath($file)

  # Refuse anything that escapes public/.
  if (-not $full.StartsWith([System.IO.Path]::GetFullPath($root))) {
    $context.Response.StatusCode = 403
    $context.Response.Close()
    continue
  }

  if (Test-Path -LiteralPath $full -PathType Leaf) {
    $bytes = [System.IO.File]::ReadAllBytes($full)
    $ext = [System.IO.Path]::GetExtension($full).ToLower()
    $type = $mime[$ext]
    if (-not $type) { $type = "application/octet-stream" }
    $context.Response.ContentType = $type
    $context.Response.Headers.Add("Cache-Control", "no-store")
    $context.Response.ContentLength64 = $bytes.Length
    $context.Response.OutputStream.Write($bytes, 0, $bytes.Length)
  } else {
    $context.Response.StatusCode = 404
    $body = [System.Text.Encoding]::UTF8.GetBytes("not found: $path")
    $context.Response.OutputStream.Write($body, 0, $body.Length)
  }
  $context.Response.Close()
}
