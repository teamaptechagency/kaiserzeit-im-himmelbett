# Bring the client's work back into the repo.
#
# Downloads every uploaded photo plus the copy and background changes from the
# live preview into public/assets/uploads/, rewriting the image URLs to local
# paths. After this the repo renders the client's final version on its own,
# with no backend — which is the copy you hand to the WordPress build.
#
#   powershell -ExecutionPolicy Bypass -File scripts\pull.ps1 -Site https://your-preview.vercel.app
#
# Then review and commit:
#   git add public/assets/uploads
#   git commit -m "Pull client photos and copy"

param(
  [Parameter(Mandatory = $true)][string]$Site
)

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
$outDir = Join-Path $root "public\assets\uploads"

New-Item -ItemType Directory -Force -Path $outDir | Out-Null

$stateUrl = $Site.TrimEnd("/") + "/api/state"
Write-Host "reading $stateUrl"
$state = Invoke-RestMethod -Uri $stateUrl -Headers @{ "Cache-Control" = "no-cache" }

if (-not $state.ok) {
  Write-Warning "the server reported a problem: $($state.error)"
}

$extByType = @{
  "image/jpeg" = ".jpg"; "image/png" = ".png"; "image/webp" = ".webp"
  "image/avif" = ".avif"; "image/gif" = ".gif"; "image/svg+xml" = ".svg"
}

$localImages = [ordered]@{}
$count = 0

foreach ($entry in $state.images.PSObject.Properties) {
  $slot = $entry.Name
  $url = $entry.Value

  # Blob pathnames carry the real extension; the ?v= cache buster does not.
  $clean = ($url -split "\?")[0]
  $ext = [System.IO.Path]::GetExtension($clean)
  if (-not $ext) { $ext = ".jpg" }

  $fileName = "$slot$ext"
  $target = Join-Path $outDir $fileName

  Write-Host ("  {0,-28} -> assets/uploads/{1}" -f $slot, $fileName)
  Invoke-WebRequest -Uri $url -OutFile $target -UseBasicParsing

  if (-not $ext -or $ext -eq ".bin") {
    $type = (Invoke-WebRequest -Uri $url -Method Head -UseBasicParsing).Headers["Content-Type"]
    if ($extByType.ContainsKey($type)) { $ext = $extByType[$type] }
  }

  $localImages[$slot] = "assets/uploads/$fileName"
  $count++
}

# Background images live in styles as absolute blob URLs; point them at the
# local copies too, so the checked-in version needs no network at all.
$styles = [ordered]@{}
if ($state.styles) {
  foreach ($entry in $state.styles.PSObject.Properties) {
    $value = [ordered]@{}
    foreach ($field in $entry.Value.PSObject.Properties) {
      $fieldValue = $field.Value
      if ($field.Name -eq "image" -and $fieldValue) {
        foreach ($slot in $localImages.Keys) {
          if ($fieldValue -like "*$slot.*") { $fieldValue = $localImages[$slot]; break }
        }
      }
      $value[$field.Name] = $fieldValue
    }
    $styles[$entry.Name] = $value
  }
}

$texts = [ordered]@{}
if ($state.texts) {
  foreach ($entry in $state.texts.PSObject.Properties) { $texts[$entry.Name] = $entry.Value }
}

$out = [ordered]@{ images = $localImages; texts = $texts; styles = $styles }
$json = $out | ConvertTo-Json -Depth 6
[System.IO.File]::WriteAllText((Join-Path $outDir "state.json"), $json, [System.Text.UTF8Encoding]::new($false))

Write-Host ""
Write-Host "$count image(s), $($texts.Count) text change(s), $($styles.Count) background change(s)"
Write-Host "written to public/assets/uploads/"
Write-Host ""
Write-Host "review, then:  git add public/assets/uploads && git commit -m ""Pull client content"""
