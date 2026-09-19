param(
    [string]$HBuilderCli = 'D:\HBuilderX\cli.exe'
)

$ErrorActionPreference = 'Stop'
$repositoryRoot = Split-Path -Parent $PSScriptRoot
$manifestPath = Join-Path $repositoryRoot 'LessonTable\manifest.json'
$outputPath = Join-Path $repositoryRoot 'LessonTable\unpackage\dist\build\web'
$originalManifest = [System.IO.File]::ReadAllText($manifestPath, [System.Text.Encoding]::UTF8)

if ($originalManifest -notmatch '"base"\s*:\s*"/LessonSchedule/"') {
    throw 'manifest.json 中未找到正式版路由基路径，已停止测试构建。'
}
if ($originalManifest -notmatch '"versionName"\s*:\s*"(\d+\.\d+\.\d+)"') {
    throw 'manifest.json 中未找到合法候选版本号，已停止测试构建。'
}
$candidateVersion = $Matches[1]

$testManifest = [regex]::Replace(
    $originalManifest,
    '"base"\s*:\s*"/LessonSchedule/"',
    '"base" : "/LessonSchedule-test/"',
    1
)

try {
    [System.IO.File]::WriteAllText($manifestPath, $testManifest, [System.Text.UTF8Encoding]::new($false))
    # 注意：cli open 是阻塞调用（不返回），必须后台启动 GUI 再执行构建；
    # 冷启动需等约 40 秒，否则 publish 会报"与主程序的连接已中断"
    Start-Process -FilePath $HBuilderCli -ArgumentList 'open'
    Start-Sleep -Seconds 40
    & $HBuilderCli publish --platform web --project LessonTable | Out-Host
    if ($LASTEXITCODE -ne 0) {
        throw "HBuilderX 测试构建失败，退出码：$LASTEXITCODE"
    }
} finally {
    [System.IO.File]::WriteAllText($manifestPath, $originalManifest, [System.Text.UTF8Encoding]::new($false))
}

$indexPath = Join-Path $outputPath 'index.html'
if (!(Test-Path -LiteralPath $indexPath)) {
    throw '未找到测试构建产物 index.html。'
}
$indexHtml = [System.IO.File]::ReadAllText($indexPath, [System.Text.Encoding]::UTF8)
if ($indexHtml -notmatch '/LessonSchedule-test/assets/') {
    throw '测试构建仍引用正式版资源路径，已停止部署。'
}
if ($indexHtml -match 'fetchpriority\s*=\s*["'']high["'']') {
    throw '测试构建包含高优先级大资源预加载，已停止部署。'
}
[System.IO.File]::WriteAllText(
    (Join-Path $outputPath 'candidate-version.txt'),
    $candidateVersion + [Environment]::NewLine,
    [System.Text.UTF8Encoding]::new($false)
)

Write-Host "测试构建完成：v$candidateVersion $outputPath"
