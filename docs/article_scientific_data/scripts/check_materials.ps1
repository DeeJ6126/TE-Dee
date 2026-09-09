param()
$ErrorActionPreference = 'Stop'
$package = Split-Path $PSScriptRoot -Parent
$repo = [IO.Path]::GetFullPath((Join-Path $package '../..'))

function Assert-Material([bool]$condition, [string]$message) {
    if (-not $condition) { throw $message }
}

$decisions = @(Import-Csv (Join-Path $package 'migration_manifest.csv'))
Assert-Material ($decisions.Count -eq 118) 'Incomplete legacy inventory'
$i = 0
foreach ($item in $decisions) {
    Write-Progress -Activity 'Checking preserved legacy materials' -Status $item.source -PercentComplete (100 * $i / $decisions.Count)
    $source = Join-Path $repo $item.source
    Assert-Material (Test-Path -LiteralPath $source) "Missing legacy file: $source"
    Assert-Material ((Get-FileHash -LiteralPath $source -Algorithm SHA256).Hash -eq $item.sha256) "Changed legacy file: $source"
    Assert-Material ((Get-Item -LiteralPath $source).Length -eq [long]$item.bytes) "Changed legacy size: $source"
    foreach ($destination in ($item.destination -split ';')) {
        if ($destination) { Assert-Material (Test-Path -LiteralPath (Join-Path $package $destination)) "Missing migration destination: $destination" }
    }
    $i++
}
Write-Progress -Activity 'Checking preserved legacy materials' -Completed
$copies = Get-Content (Join-Path $package 'copy_provenance.json') -Raw | ConvertFrom-Json
foreach ($copy in $copies) {
    foreach ($path in @((Join-Path $repo $copy.source), (Join-Path $package $copy.destination))) {
        Assert-Material ((Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash -eq $copy.sha256) "Copy/source mismatch: $path"
    }
}

$all = Get-Content (Join-Path $package 'evidence/snapshots/eqtl_all_tissue_manifest.json') -Raw | ConvertFrom-Json
$mysqlPath = Join-Path $package 'evidence/snapshots/eqtl_mysql_manifest.json'
$mysql = Get-Content $mysqlPath -Raw | ConvertFrom-Json
$tissues = @($all.tissues.PSObject.Properties)
Assert-Material ($all.status -eq 'complete' -and $mysql.status -eq 'complete') 'Production manifest not complete'
Assert-Material ($all.analysis_version -eq $mysql.version_key) 'Version mismatch'
foreach ($hash in $all.input_hashes.PSObject.Properties) {
    Assert-Material ($mysql.input_hashes.($hash.Name) -eq $hash.Value) "Input identity mismatch: $($hash.Name)"
}
$allHash = (Get-FileHash (Join-Path $package 'evidence/snapshots/eqtl_all_tissue_manifest.json') -Algorithm SHA256).Hash
Assert-Material ($allHash -eq $mysql.all_tissue_manifest_sha256) 'Consolidation refers to a different all-tissue manifest'
Assert-Material ($tissues.Count -eq 50 -and $tissues.Count -eq $all.counts.tissue_count) 'Tissue count mismatch'
$sourceRows = 0L; $evidenceRows = 0L; $summaryRows = 0L
foreach ($tissue in $tissues) {
    $sourceRows += $tissue.Value.counts.eqtl_row_count
    $evidenceRows += $tissue.Value.counts.overlap_evidence_row_count
    $summaryRows += $tissue.Value.counts.te_gene_pair_count
}
Assert-Material ($sourceRows -eq $all.counts.source_association_count) 'Source-row sum mismatch'
Assert-Material ($evidenceRows -eq $all.counts.overlap_evidence_row_count) 'Evidence-row sum mismatch'
Assert-Material ($summaryRows -eq $all.counts.te_gene_tissue_summary_count) 'Summary-row sum mismatch'
$totalRows = 0L; $parts = 0
foreach ($table in $mysql.tables.PSObject.Properties) {
    $rows = ($table.Value.files | Measure-Object -Property rows -Sum).Sum
    Assert-Material ($rows -eq $table.Value.rows) "Partition rows mismatch: $($table.Name)"
    $totalRows += $rows
    $parts += @($table.Value.files).Count
}
Assert-Material ($totalRows -eq 16510562 -and $parts -eq 130) 'Import accounting mismatch'
Assert-Material ($mysql.tables.eqtl_te_gene_tissue_summary.rows -eq $summaryRows) 'Cross-manifest summary mismatch'

$mapping = @(Import-Csv (Join-Path $package 'evidence/snapshots/browse_te_mapping.tsv') -Delimiter "`t")
Assert-Material ($mapping.Count -eq 276) 'TE mapping row count mismatch'
Assert-Material (@($mapping | Where-Object has_hg38_instance -eq '1').Count -eq 202) 'Mapped TE count mismatch'
Assert-Material (@($mapping | Where-Object has_hg38_instance -eq '0').Count -eq 74) 'Unmapped TE count mismatch'
Assert-Material (($mapping | Measure-Object instance_count -Sum).Sum -eq 596140) 'TE instance sum mismatch'
Assert-Material (($mapping | Measure-Object evidence_count -Sum).Sum -eq $evidenceRows) 'Mapping evidence sum mismatch'

$tableCsv = @(Import-Csv (Join-Path $package 'tables/eqtl_table_counts.csv'))
Assert-Material ($tableCsv.Count -eq 8) 'Missing normalized table'
foreach ($row in $tableCsv) {
    Assert-Material ([long]$row.rows -eq $mysql.tables.($row.table).rows) "Generated table count mismatch: $($row.table)"
}
$tissueCsv = @(Import-Csv (Join-Path $package 'tables/eqtl_tissue_counts.csv'))
Assert-Material ($tissueCsv.Count -eq 50) 'Generated tissue table incomplete'
foreach ($row in $tissueCsv) {
    $counts = $all.tissues.($row.tissue).counts
    Assert-Material ([long]$row.source_associations -eq $counts.eqtl_row_count) "Generated tissue count mismatch: $($row.tissue)"
    Assert-Material ([long]$row.instance_evidence_rows -eq $counts.overlap_evidence_row_count) "Generated evidence count mismatch: $($row.tissue)"
    Assert-Material ([long]$row.te_gene_tissue_rows -eq $counts.te_gene_pair_count) "Generated pair count mismatch: $($row.tissue)"
}

$linkCount = 0
foreach ($md in Get-ChildItem -LiteralPath $package -Recurse -File -Filter '*.md') {
    $content = Get-Content -LiteralPath $md.FullName -Raw -Encoding UTF8
    foreach ($match in [regex]::Matches($content, '\[[^\]]*\]\(([^)]+)\)')) {
        $target = $match.Groups[1].Value.Trim('<', '>')
        if ($target -match '^(https?://|#)') { continue }
        $target = ($target -split '#')[0]
        Assert-Material (Test-Path -LiteralPath (Join-Path $md.DirectoryName $target)) "Broken Markdown link in $($md.Name): $target"
        $linkCount++
    }
}
$officialForm = [IO.Path]::GetFullPath((Join-Path $package 'journal_guidance/official_files/sdata-sensitive-data-checklist.docx'))
$guidanceManifestPath = Join-Path $package 'journal_guidance/download_manifest.json'
if (Test-Path -LiteralPath $guidanceManifestPath) {
    $guidance = Get-Content -LiteralPath $guidanceManifestPath -Raw | ConvertFrom-Json
    Assert-Material ($guidance.Count -eq 9) 'Incomplete official guidance collection'
    $sources = Get-Content (Join-Path $package 'journal_guidance/sources.json') -Raw | ConvertFrom-Json
    foreach ($entry in $guidance) {
        $source = @($sources | Where-Object id -eq $entry.id)
        Assert-Material ($source.Count -eq 1 -and $source[0].file -eq $entry.file -and $source[0].url -eq $entry.url) "Unknown official source: $($entry.id)"
        $file = Join-Path $package (Join-Path 'journal_guidance' $entry.file)
        Assert-Material ((Get-FileHash -LiteralPath $file -Algorithm SHA256).Hash -eq $entry.sha256) "Changed official download: $($entry.file)"
        Assert-Material ((Get-Item -LiteralPath $file).Length -eq $entry.bytes -and $entry.status -eq 200) "Invalid official download: $($entry.file)"
    }
    Write-Output 'PASS: nine official guidance downloads match their recorded sources, sizes and hashes.'
}
$publishedPapers = @()
$paperManifestPath = Join-Path $package 'writing_examples/collection_manifest.json'
if (Test-Path -LiteralPath $paperManifestPath) {
    $papers = Get-Content -LiteralPath $paperManifestPath -Raw -Encoding UTF8 | ConvertFrom-Json
    $selection = Get-Content (Join-Path $package 'writing_examples/selection.json') -Raw -Encoding UTF8 | ConvertFrom-Json
    Assert-Material ($papers.Count -eq 5 -and $selection.Count -eq 5) 'Incomplete published-paper collection'
    $paperRoot = [IO.Path]::GetFullPath((Join-Path $package 'writing_examples'))
    foreach ($paper in $papers) {
        $selected = @($selection | Where-Object id -eq $paper.id)
        Assert-Material ($selected.Count -eq 1 -and $selected[0].doi -eq $paper.doi) "Unknown published paper: $($paper.id)"
        $file = [IO.Path]::GetFullPath((Join-Path $paperRoot $paper.file))
        Assert-Material ($file.StartsWith($paperRoot + [IO.Path]::DirectorySeparatorChar) -and [IO.Path]::GetExtension($file) -eq '.pdf') 'Invalid published-paper path'
        Assert-Material ($paper.pdf_url -eq ('https://www.nature.com/articles/' + ($paper.doi -split '/')[1] + '.pdf') -and $paper.si_requested -eq $false) 'Unexpected paper download source/scope'
        Assert-Material ((Get-FileHash -LiteralPath $file -Algorithm SHA256).Hash -eq $paper.sha256) "Changed published PDF: $($paper.id)"
        Assert-Material ((Get-Item -LiteralPath $file).Length -eq $paper.bytes) "Invalid published PDF size: $($paper.id)"
        $publishedPapers += $file
    }
    Assert-Material (@($publishedPapers | Select-Object -Unique).Count -eq 5) 'Duplicate published-paper files'
    Write-Output 'PASS: five requested published PDFs match their recorded sources, sizes and hashes.'
}
# Exempt only the requested, provenance-checked form and published papers.
$forbidden = @(Get-ChildItem -LiteralPath $package -Recurse -File | Where-Object {
    $_.Extension -in @('.docx','.pdf','.tex','.cls','.bst','.csl','.zip','.parquet','.gz','.sqlite') -and
    -not ($_.FullName -eq $officialForm -and (Test-Path -LiteralPath $guidanceManifestPath)) -and
    $_.FullName -notin $publishedPapers
})
Assert-Material ($forbidden.Count -eq 0) 'Unrequested draft/template/bulk data in material package'
$bib = Get-Content (Join-Path $package 'references.bib') -Raw
$keys = @([regex]::Matches($bib, '@article\{([^,]+),') | ForEach-Object { $_.Groups[1].Value })
Assert-Material ($keys.Count -eq 22 -and @($keys | Select-Object -Unique).Count -eq 22) 'Bibliography entry count/uniqueness mismatch'
Write-Output "PASS: $($decisions.Count) legacy source hashes unchanged; $($copies.Count) copies match."
Write-Output "PASS: 50 tissues; $sourceRows source rows; $evidenceRows instance evidence rows."
Write-Output "PASS: 8 tables; $parts partitions; $totalRows import rows; 202 mapped + 74 unmapped TE names."
Write-Output "PASS: generated CSVs match manifests; $linkCount local links resolve; 22 unique bibliography entries; no unrequested drafts/templates/bulk data."
Write-Output 'These are material/manifest checks, not live database or biological validation.'
