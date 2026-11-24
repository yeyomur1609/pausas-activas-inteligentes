$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Drawing

$publicDir = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\frontend\public'))

function New-IconPng([int]$size, [string]$outFile) {
  $bmp = New-Object System.Drawing.Bitmap($size, $size)
  $g = [System.Drawing.Graphics]::FromImage($bmp)
  $g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias

  $s = [float]$size
  $rect = New-Object System.Drawing.RectangleF(0, 0, $s, $s)

  $c1 = [System.Drawing.Color]::FromArgb(255, 46, 111, 101)   # #2e6f65 Eucalyptus
  $c2 = [System.Drawing.Color]::FromArgb(255, 30, 77, 69)     # #1e4d45 Deep Eucalyptus
  $bgBrush = New-Object System.Drawing.Drawing2D.LinearGradientBrush($rect, $c1, $c2, 45.0)
  $g.FillRectangle($bgBrush, $rect)

  # Borde secundario eucalipto suave + acento coral #f26b50
  $pad = [float]($s * 0.055)
  $borderPen = New-Object System.Drawing.Pen([System.Drawing.Color]::FromArgb(235, 242, 107, 80), [float][Math]::Max(2.0, $s * 0.032))
  $g.DrawRectangle($borderPen, $pad, $pad, [float]($s - 2.0 * $pad), [float]($s - 2.0 * $pad))

  # Aura sutil detrás del emblema
  $auraBrush = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(42, 242, 107, 80))
  $g.FillEllipse($auraBrush, [float]($s * 0.16), [float]($s * 0.14), [float]($s * 0.68), [float]($s * 0.68))

  # Cabeza de la figura humana en estiramiento (#f8fafc)
  $headBrush = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(255, 248, 250, 252))
  $headD = [float]($s * 0.15)
  $g.FillEllipse($headBrush, [float](($s - $headD) / 2.0), [float]($s * 0.19), $headD, $headD)

  # Torso vertical
  $torsoPen = New-Object System.Drawing.Pen([System.Drawing.Color]::FromArgb(255, 248, 250, 252), [float]($s * 0.068))
  $torsoPen.StartCap = [System.Drawing.Drawing2D.LineCap]::Round
  $torsoPen.EndCap = [System.Drawing.Drawing2D.LineCap]::Round
  $g.DrawLine($torsoPen, [float]($s * 0.50), [float]($s * 0.39), [float]($s * 0.50), [float]($s * 0.62))

  # Brazos abiertos en curva coral energético #f26b50
  $armsPen = New-Object System.Drawing.Pen([System.Drawing.Color]::FromArgb(255, 242, 107, 80), [float]($s * 0.072))
  $armsPen.StartCap = [System.Drawing.Drawing2D.LineCap]::Round
  $armsPen.EndCap = [System.Drawing.Drawing2D.LineCap]::Round
  $g.DrawBezier($armsPen, [float]($s * 0.25), [float]($s * 0.37), [float]($s * 0.37), [float]($s * 0.52), [float]($s * 0.63), [float]($s * 0.52), [float]($s * 0.75), [float]($s * 0.35))

  $armsHighlight = New-Object System.Drawing.Pen([System.Drawing.Color]::FromArgb(220, 251, 128, 103), [float]($s * 0.036))
  $armsHighlight.StartCap = [System.Drawing.Drawing2D.LineCap]::Round
  $armsHighlight.EndCap = [System.Drawing.Drawing2D.LineCap]::Round
  $g.DrawBezier($armsHighlight, [float]($s * 0.25), [float]($s * 0.37), [float]($s * 0.37), [float]($s * 0.52), [float]($s * 0.63), [float]($s * 0.52), [float]($s * 0.75), [float]($s * 0.35))

  # Onda eucalipto clara #d5e8e4
  $wavePen = New-Object System.Drawing.Pen([System.Drawing.Color]::FromArgb(255, 213, 232, 228), [float]($s * 0.062))
  $wavePen.StartCap = [System.Drawing.Drawing2D.LineCap]::Round
  $wavePen.EndCap = [System.Drawing.Drawing2D.LineCap]::Round
  $g.DrawBezier($wavePen, [float]($s * 0.19), [float]($s * 0.73), [float]($s * 0.39), [float]($s * 0.60), [float]($s * 0.61), [float]($s * 0.80), [float]($s * 0.81), [float]($s * 0.66))

  # Chispa IA coral #f26b50 superior derecha
  $sparkBrush = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(255, 242, 107, 80))
  $sparkR = [float]($s * 0.045)
  $g.FillEllipse($sparkBrush, [float]($s * 0.74 - $sparkR), [float]($s * 0.22 - $sparkR), [float]($sparkR * 2.0), [float]($sparkR * 2.0))

  $ms = New-Object System.IO.MemoryStream
  $bmp.Save($ms, [System.Drawing.Imaging.ImageFormat]::Png)
  $g.Dispose()
  $bmp.Dispose()
  [System.IO.File]::WriteAllBytes($outFile, $ms.ToArray())
  $ms.Dispose()
  Write-Output "Generated $outFile ($((Get-Item $outFile).Length) bytes)"
}

function New-OgCoverPng([string]$outFile) {
  $bmp = New-Object System.Drawing.Bitmap(1200, 630)
  $g = [System.Drawing.Graphics]::FromImage($bmp)
  $g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
  $g.TextRenderingHint = [System.Drawing.Text.TextRenderingHint]::ClearTypeGridFit

  $rect = New-Object System.Drawing.Rectangle(0, 0, 1200, 630)
  $c1 = [System.Drawing.Color]::FromArgb(255, 248, 250, 252)   # #f8fafc Soft Off-White
  $c2 = [System.Drawing.Color]::FromArgb(255, 233, 243, 241)   # #e9f3f1 Soft Eucalyptus Mist
  $bgBrush = New-Object System.Drawing.Drawing2D.LinearGradientBrush($rect, $c1, $c2, 35.0)
  $g.FillRectangle($bgBrush, $rect)

  $penAccent = New-Object System.Drawing.Pen([System.Drawing.Color]::FromArgb(230, 46, 111, 101), 4)
  $g.DrawRectangle($penAccent, 28, 28, 1144, 574)

  # Emblema izquierdo (Eucalipto #2e6f65 + coral energético #f26b50)
  $badgeBrush = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(255, 46, 111, 101))
  $g.FillEllipse($badgeBrush, 80, 130, 116, 116)
  $waveOgPen = New-Object System.Drawing.Pen([System.Drawing.Color]::FromArgb(255, 242, 107, 80), 8)
  $waveOgPen.StartCap = [System.Drawing.Drawing2D.LineCap]::Round
  $waveOgPen.EndCap = [System.Drawing.Drawing2D.LineCap]::Round
  $g.DrawBezier($waveOgPen, 98, 210, 122, 186, 152, 224, 178, 198)

  $fontLogo = New-Object System.Drawing.Font('Georgia', 30, [System.Drawing.FontStyle]::Bold)
  $fontTag = New-Object System.Drawing.Font('Consolas', 15, [System.Drawing.FontStyle]::Bold)
  $fontTitle = New-Object System.Drawing.Font('Georgia', 42, [System.Drawing.FontStyle]::Bold)
  $fontSub = New-Object System.Drawing.Font('Verdana', 17, [System.Drawing.FontStyle]::Regular)

  $inkBrush = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(255, 15, 23, 42))
  $whiteBrush = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(255, 255, 255, 255))
  $eucBrush = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(255, 46, 111, 101))
  $coralTextBrush = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(255, 194, 65, 41))
  $mutedBrush = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(255, 71, 85, 105))

  $textRectTag = New-Object System.Drawing.RectangleF(224, 144, 896, 36)
  $textRectTitle = New-Object System.Drawing.RectangleF(220, 184, 900, 96)
  $textRectSub1 = New-Object System.Drawing.RectangleF(80, 326, 1040, 48)
  $textRectSub2 = New-Object System.Drawing.RectangleF(80, 382, 1040, 96)

  $g.DrawString('PAI', $fontLogo, $whiteBrush, 102, 148)
  $g.DrawString('AI-POWERED WORKPLACE ERGONOMICS · SANTA CATARINA, N.L.', $fontTag, $eucBrush, $textRectTag)
  $g.DrawString('Pausas Activas Inteligentes', $fontTitle, $inkBrush, $textRectTitle)
  $g.DrawString('2-Minute Guided Breaks · Smart Posture Nudges · Eye-Strain Relief', $fontSub, $coralTextBrush, $textRectSub1)
  $g.DrawString('Universal Accessibility WCAG 2.2 AA · Wheelchair Adapted · OSM Parks', $fontSub, $mutedBrush, $textRectSub2)

  $ms = New-Object System.IO.MemoryStream
  $bmp.Save($ms, [System.Drawing.Imaging.ImageFormat]::Png)
  $g.Dispose()
  $bmp.Dispose()
  [System.IO.File]::WriteAllBytes($outFile, $ms.ToArray())
  $ms.Dispose()
  Write-Output "Generated $outFile ($((Get-Item $outFile).Length) bytes)"
}

New-OgCoverPng (Join-Path $publicDir 'og-cover.png')
New-IconPng 192 (Join-Path $publicDir 'icon-192.png')
New-IconPng 512 (Join-Path $publicDir 'icon-512.png')
