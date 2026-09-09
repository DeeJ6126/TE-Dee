<?php
require_once __DIR__ . '/path_config.php';

$pageTitle = 'Download';
$activePage = 'download';
$protoCurrentPath = tekg_app_url('download.php');
$protoSubtitle = 'Downloadable data records and analysis tables';
$downloadCssVersion = (int)@filemtime(tekg_assets_fs_path('css/pages/download.css'));
$downloadJsVersion = (int)@filemtime(tekg_assets_fs_path('js/pages/download.js'));
$pageExtraStylesheets = [tekg_assets_url('css/pages/download.css') . '?v=' . $downloadCssVersion];

function download_file_size(string $relativePath): string
{
    $bytes = @filesize(tekg_data_fs_path($relativePath));
    if ($bytes === false) {
        return '';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $size = (float)$bytes;
    $unit = 0;
    while ($size >= 1024 && $unit < count($units) - 1) {
        $size /= 1024;
        $unit++;
    }
    $precision = $size >= 100 || $unit === 0 ? 0 : ($size >= 10 ? 1 : 2);
    return number_format($size, $precision) . ' ' . $units[$unit];
}

function download_file(string $relativePath, ?string $publicName = null): array
{
    return [
        'filename' => $publicName ?? basename($relativePath),
        'path' => tekg_data_url($relativePath),
        'size' => download_file_size($relativePath),
    ];
}

function download_directory_files(string $relativeDirectory, string $pattern = '*.tsv.gz'): array
{
    $paths = glob(tekg_data_fs_path($relativeDirectory) . '/' . $pattern) ?: [];
    sort($paths, SORT_NATURAL | SORT_FLAG_CASE);
    $files = [];
    foreach ($paths as $path) {
        $relativePath = trim($relativeDirectory, '/') . '/' . basename($path);
        $files[] = download_file($relativePath);
    }
    return $files;
}

function download_dataset(string $category, string $dataset, string $contents, string $records, string $format, string $version, string $description, array $files): array
{
    return compact('category', 'dataset', 'contents', 'records', 'format', 'version', 'description', 'files');
}

$eqtlRoot = 'eQTL/derived/gtex_v11_strict_te_overlap_v1/mysql';
$downloadItems = [
    download_dataset('Literature', 'Literature knowledge graph', 'Typed entities, directed relationships, and PMID evidence', 'Integrated graph records', 'JSON', 'Current release', 'Structured literature evidence connecting transposable elements with diseases, functions, biological processes, and supporting publications.', [
        download_file('processed/te_kg2_graph_seed.json', 'literature_knowledge_graph.json'),
        download_file('processed/te_kg2_normalized_output.jsonl', 'normalized_literature_relations.jsonl'),
    ]),
    download_dataset('Literature', 'Publication metadata', 'Bibliographic metadata and journal metrics', '2,308 publications', 'JSONL', 'Current release', 'Publication records associated with the literature relationships, including the available bibliographic fields and mapped journal metrics.', [
        download_file('processed/pubmed_metadata_with_metrics.jsonl', 'publication_metadata_with_journal_metrics.jsonl'),
    ]),
    download_dataset('Literature', 'Entity descriptions', 'Descriptions for graph entities and transposable elements', 'Description records', 'JSON', 'Current release', 'Normalized English descriptions used to provide context for literature-derived entities and transposable element records.', [
        download_file('processed/entity_descriptions.json'),
        download_file('processed/te_descriptions.json', 'transposable_element_descriptions.json'),
    ]),
    download_dataset('Literature', 'Disease classification', 'Disease nodes and top-level classes', 'Disease mapping records', 'CSV + JSON', 'Current release', 'Disease classification assignments retained for filtering and interpreting disease-related literature relationships.', [
        download_file('processed/disease_nodes_with_classes.csv'),
        download_file('processed/disease_top_class_map.json'),
    ]),
    download_dataset('TE Reference', 'Transposable element catalog', 'Names, classes, and structured reference annotations', 'Reference records', 'TSV + TXT + JSON', 'Current release', 'Reference tables used to harmonize transposable element names and classification across the integrated datasets.', [
        download_file('TE_names.txt', 'transposable_element_names.txt'),
        download_file('TEClasses.tsv', 'transposable_element_classes.tsv'),
        download_file('processed/te_repbase_structured.json', 'transposable_element_reference_annotations.json'),
    ]),
    download_dataset('TE Reference', 'Transposable element taxonomy', 'Classification trees at supported lineage levels', '2 trees', 'TXT', 'Current release', 'Tree-formatted classification records assembled from the supported reference taxonomies.', [
        download_file('taxonomy/transposon_tree/tree_rmsk_repbase.txt', 'taxonomy_rmsk_repbase.txt'),
        download_file('taxonomy/transposon_tree/tree_all.txt', 'taxonomy_all_records.txt'),
    ]),
    download_dataset('TE Reference', 'Genome occurrence annotation', 'Reference-genome intervals and RepeatMasker fields', 'Genome occurrence records', 'TXT', 'Current release', 'Genome occurrence records used to resolve individual transposable element instances and their genomic coordinates.', [
        download_file('rmsk.txt', 'transposable_element_genome_occurrences.txt'),
    ]),
    download_dataset('Expression', 'Cancer cell line expression', 'Normalized expression matrix and sample metadata', '37,868 features; 646 samples', 'TSV + CSV', 'Current release', 'Normalized transposable element and gene expression values with the metadata used to assign cancer cell line contexts.', [
        download_file('bulk_expression_web/cancer_cell_line/CCLE_TE_normalized_count.tsv', 'cancer_cell_line_normalized_expression.tsv'),
        download_file('bulk_expression_web/cancer_cell_line/CCLE_meta.csv', 'cancer_cell_line_metadata.csv'),
    ]),
    download_dataset('Expression', 'Normal cell line expression', 'Normalized expression matrix and sample metadata', '37,868 features; 307 samples', 'TSV + CSV', 'Current release', 'Normalized transposable element and gene expression values with the metadata used to assign normal cell line contexts.', [
        download_file('bulk_expression_web/normal_cell_line/Normal_cell_line_TE_normalized_count.tsv', 'normal_cell_line_normalized_expression.tsv'),
        download_file('bulk_expression_web/normal_cell_line/Normal_cell_line_meta.csv', 'normal_cell_line_metadata.csv'),
    ]),
    download_dataset('Expression', 'Normal tissue expression', 'Normalized expression matrix and sample metadata', '37,868 features; 205 linked samples', 'TSV + CSV', 'Current release', 'Normalized transposable element and gene expression values with the metadata used to assign normal tissue contexts.', [
        download_file('bulk_expression_web/normal_tissue/Normal_tissue_TE_normalized_count.tsv', 'normal_tissue_normalized_expression.tsv'),
        download_file('bulk_expression_web/normal_tissue/Normal_tissue_meta.csv', 'normal_tissue_metadata.csv'),
    ]),
    download_dataset('Expression', 'Processed expression summaries', 'Dataset, context, and feature-level summary tables', 'Processed summary records', 'TSV + JSON', 'Current release', 'Analysis-ready expression summaries, context definitions, and a processing manifest for reproducing the table relationships.', [
        download_file('bulk_expression_web/processed/te_expression_browse_summary.tsv', 'expression_feature_summary.tsv'),
        download_file('bulk_expression_web/processed/te_expression_context_catalog.tsv', 'expression_context_catalog.tsv'),
        download_file('bulk_expression_web/processed/te_expression_context_stats.tsv', 'expression_context_statistics.tsv'),
        download_file('bulk_expression_web/processed/te_expression_dataset_summary.tsv', 'expression_dataset_summary.tsv'),
        download_file('bulk_expression_web/processed/expression_processing_manifest.json'),
    ]),
    download_dataset('Co-expression', 'Feature identity annotation', 'Feature type, matched identifiers, and mapping confidence', '37,868 annotated features', 'TSV + JSON', 'v1', 'Feature-level identity assignments used to select high-confidence transposable elements and genes for co-expression analysis.', [
        download_file('coexpression/feature_annotation/feature_annotation.tsv', 'coexpression_feature_annotation.tsv'),
        download_file('coexpression/feature_annotation/feature_annotation_summary.json', 'coexpression_feature_annotation_summary.json'),
    ]),
    download_dataset('Co-expression', 'Cancer cell line co-expression network', 'Nodes, edges, and feature-selection audit', '2,609,470 edges', 'TSV', 'v1', 'Spearman co-expression network for the cancer cell line context, accompanied by node attributes and the complete feature-selection record.', [
        download_file('coexpression/networks/v1/cancer_cell_line_edges.tsv'),
        download_file('coexpression/networks/v1/cancer_cell_line_nodes.tsv'),
        download_file('coexpression/networks/v1/cancer_cell_line_feature_selection.tsv'),
    ]),
    download_dataset('Co-expression', 'Normal cell line co-expression network', 'Nodes, edges, and feature-selection audit', '2,609,470 edges', 'TSV', 'v1', 'Spearman co-expression network for the normal cell line context, accompanied by node attributes and the complete feature-selection record.', [
        download_file('coexpression/networks/v1/normal_cell_line_edges.tsv'),
        download_file('coexpression/networks/v1/normal_cell_line_nodes.tsv'),
        download_file('coexpression/networks/v1/normal_cell_line_feature_selection.tsv'),
    ]),
    download_dataset('Co-expression', 'Normal tissue co-expression network', 'Nodes, edges, and feature-selection audit', '2,609,470 edges', 'TSV', 'v1', 'Spearman co-expression network for the normal tissue context, accompanied by node attributes and the complete feature-selection record.', [
        download_file('coexpression/networks/v1/normal_tissue_edges.tsv'),
        download_file('coexpression/networks/v1/normal_tissue_nodes.tsv'),
        download_file('coexpression/networks/v1/normal_tissue_feature_selection.tsv'),
        download_file('coexpression/networks/v1/coexpression_run_manifest.json', 'coexpression_processing_manifest.json'),
    ]),
    download_dataset('eQTL', 'Tissue catalog', 'GTEx tissue identifiers and labels', '50 tissues', 'TSV.GZ', 'GTEx v11 / overlap v1', 'Tissue dimension for joining tissue-specific associations and transposable element-gene summaries.', download_directory_files($eqtlRoot . '/eqtl_tissues')),
    download_dataset('eQTL', 'Gene catalog', 'Versioned and unversioned gene identifiers', '52,962 genes', 'TSV.GZ', 'GTEx v11 / overlap v1', 'Gene dimension retained from the GTEx association files and used for audited mapping to co-expression gene symbols.', download_directory_files($eqtlRoot . '/eqtl_genes')),
    download_dataset('eQTL', 'Variant catalog', 'Normalized variant identifiers, intervals, and alleles', '664,555 variants', 'TSV.GZ', 'GTEx v11 / overlap v1', 'Distinct significant cis-eQTL variants represented as reference-allele intervals for strict interval overlap.', download_directory_files($eqtlRoot . '/eqtl_variants')),
    download_dataset('eQTL', 'Overlapping TE instances', 'Mapped genome instances participating in strict overlaps', '596,140 instances', 'TSV.GZ', 'GTEx v11 / overlap v1', 'Reference-genome transposable element instances that overlap at least one retained GTEx variant.', download_directory_files($eqtlRoot . '/eqtl_te_instances')),
    download_dataset('eQTL', 'TE-variant overlaps', 'Strict interval overlaps between TE instances and variants', '664,902 overlaps', 'TSV.GZ', 'GTEx v11 / overlap v1', 'Auditable overlap table connecting each retained variant to the reference transposable element interval it intersects.', download_directory_files($eqtlRoot . '/eqtl_te_variant_overlaps')),
    download_dataset('eQTL', 'Variant-gene-tissue associations', 'GTEx association statistics by variant, gene, and tissue', '10,670,298 associations', 'TSV.GZ', 'GTEx v11 / overlap v1', 'Significant cis-eQTL associations for variants intersecting mapped transposable element instances, partitioned by tissue.', download_directory_files($eqtlRoot . '/eqtl_variant_gene_tissue_associations')),
    download_dataset('eQTL', 'Tissue-level TE-gene summaries', 'Aggregated TE-gene support within each tissue', '3,320,749 summaries', 'TSV.GZ', 'GTEx v11 / overlap v1', 'Tissue-specific transposable element-gene evidence summaries with supporting variant and instance counts and retained statistical extrema.', download_directory_files($eqtlRoot . '/eqtl_te_gene_tissue_summary')),
    download_dataset('eQTL', 'Cross-tissue TE-gene summaries', 'Aggregated TE-gene support across tissues', '540,906 summaries', 'TSV.GZ', 'GTEx v11 / overlap v1', 'Cross-tissue transposable element-gene summaries retaining tissue coverage and supporting evidence counts.', download_directory_files($eqtlRoot . '/eqtl_te_gene_cross_tissue_summary')),
    download_dataset('eQTL', 'eQTL processing manifest', 'Schemas, partitions, checksums, and row counts', '1 manifest', 'JSON', 'GTEx v11 / overlap v1', 'Machine-readable provenance and audit metadata for all downloadable eQTL table partitions.', [download_file($eqtlRoot . '/manifest.json', 'eqtl_processing_manifest.json')]),
];

$downloadItems = array_values(array_filter($downloadItems, static function (array $item): bool {
    return !empty($item['files']);
}));
$categoryCounts = [];
foreach ($downloadItems as $item) {
    $categoryCounts[$item['category']] = ($categoryCounts[$item['category']] ?? 0) + 1;
}
$categoryOrder = ['Literature', 'TE Reference', 'Expression', 'Co-expression', 'eQTL'];
uksort($categoryCounts, static function (string $left, string $right) use ($categoryOrder): int {
    return array_search($left, $categoryOrder, true) <=> array_search($right, $categoryOrder, true);
});

require __DIR__ . '/head.php';
?>
      <section class="download-shell">
        <div class="proto-container">
          <h1 class="download-page-title">Download</h1>
          <section class="download-panel">
            <div class="download-category-filter" aria-label="Dataset category filter">
              <button type="button" class="is-active" data-download-category="All">All <span><?= htmlspecialchars((string)count($downloadItems), ENT_QUOTES, 'UTF-8') ?></span></button>
<?php foreach ($categoryCounts as $category => $count): ?>
              <button type="button" data-download-category="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?> <span><?= htmlspecialchars((string)$count, ENT_QUOTES, 'UTF-8') ?></span></button>
<?php endforeach; ?>
            </div>
            <div class="download-divider"></div>
            <div class="download-table-wrap">
              <table class="download-table">
                <thead><tr><th>Dataset</th><th>Contents</th><th>Records</th><th>Format</th><th>Version</th></tr></thead>
                <tbody id="download-table-body"></tbody>
              </table>
              <div id="download-empty" class="download-empty" hidden>No datasets are available in this category.</div>
            </div>
          </section>
        </div>
      </section>
      <script id="download-page-data" type="application/json"><?= json_encode($downloadItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
      <script src="<?= htmlspecialchars(tekg_assets_url('js/pages/download.js') . '?v=' . $downloadJsVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
    </main>
  </div>
</body>
</html>