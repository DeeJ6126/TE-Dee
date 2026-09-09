<?php
require_once __DIR__ . '/path_config.php';
$pageTitle = TEKG_PRODUCT_NAME . ' About';
$activePage = 'about';
$protoCurrentPath = tekg_app_url('about.php');
$protoSubtitle = 'Detailed guide to the TE-Dee interface and public workflows';
$aboutCssVersion = (int)@filemtime(tekg_assets_fs_path('css/pages/about.css'));
$aboutJsVersion = (int)@filemtime(tekg_assets_fs_path('js/pages/about.js'));
$pageExtraStylesheets = [
    tekg_assets_url('css/pages/about.css') . '?v=' . $aboutCssVersion,
];
require __DIR__ . '/head.php';

$aboutSections = [
    'resource' => [
        'nav' => 'Resource',
        'title' => 'About TE-Dee',
        'summary' => 'TE-Dee is an integrated resource for human transposable elements. It connects classification systems, literature-derived relationships, representative sequence and genomic records, expression and co-expression contexts, Variant and eQTL evidence, downloadable data, and evidence-grounded natural-language question answering.',
        'sections' => [
            [
                'heading' => 'What TE-Dee is',
                'paragraphs' => [
                    'TE-Dee brings several complementary views of human transposable elements into one interface rather than treating every dataset as an isolated table. The resource includes a TE catalog, entity details, literature-derived relationship graphs, classification views, expression and TE-Gene evidence exploration, and downloadable data. Its central aim is to make TE information easier to explore while keeping source evidence and interpretation boundaries visible.',
                ],
            ],
            [
                'heading' => 'TE-Dee architecture',
                'paragraphs' => [
                    'TE-Dee integrates four main data streams. Literature is screened before AI-assisted entity and relationship extraction, normalization, and manual curation. RMSK genomic annotations and RepBase classification and sequence records are harmonized into a shared taxonomy, sequence, and genomic annotation layer. Expression datasets are processed separately and used to build context-specific TE-Gene co-expression results. GTEx v11 eQTL data are organized through Variant normalization, strict TE-Variant coordinate overlap, and Variant-Gene-Tissue association, producing queryable TE-Gene eQTL evidence.',
                    'The basic eQTL relationship is that a TE instance strictly overlaps the reference-allele interval of a Variant, and that Variant has a GTEx eQTL association with a Gene in a specific tissue. The knowledge graph and taxonomy run in Neo4j. The catalog, expression, co-expression, and eQTL/Variant runtime data are stored in MySQL and exposed through unified APIs and evidence services for Browse, Path, Graph, Expression, Agent, and DeepThink workflows.',
                ],
            ],
            [
                'heading' => 'Data access routes',
                'items' => [
                    'Use Home to review the current overall composition of the database.',
                    'Use Browse to explore the TE catalog, then use Search to inspect the selected entity\'s detailed records, genomic locations, and Variant/eQTL evidence.',
                    'Use Graph to explore knowledge relationships, TE classification, and the TE-Gene Graph; use Path Finder to inspect connections between two specified entities.',
                    'Use Expression to examine abundance patterns in the currently available expression datasets.',
                    'Use Agent or DeepThink to ask evidence-grounded natural-language questions and continue with follow-up questions in the current conversation.',
                    'Use Download to obtain files currently provided by the website.',
                    'Use the AI window to access DeepThink from supported pages across the site.',
                ],
            ],
        ],
    ],
    'home' => [
        'nav' => 'Home',
        'title' => 'Home Overview',
        'summary' => 'Home is the main overview entry point for TE-Dee. It introduces the resource, summarizes the current database composition, and provides shortcuts to the principal public workflows.',
        'sections' => [
            [
                'heading' => 'What the page contains',
                'items' => [
                    'Overview summarizes the purpose and scope of TE-Dee.',
                    'Dataset status reports the current Neo4j database scale. Its donut charts show entity composition, TE classification, and relation predicate composition.',
                    'Entity Composition counts the principal knowledge-graph entity categories among stored nodes.',
                    'TE Classification can switch classification levels, allowing the chart to move from broad classes to more specific levels.',
                    'Relation Composition uses detailed predicate counts to reveal common relationship types.',
                    'Quick Links provide direct access to the main lookup, graph, expression, download, and help workflows.',
                ],
            ],
        ],
    ],
    'browse' => [
        'nav' => 'Browse',
        'title' => 'Browse',
        'summary' => 'Browse provides the complete workflow for finding and reviewing a TE record. Its content includes Summary, Local Graph, Sequence, Genome Annotation Distribution, Genome Browser, and Variants.',
        'sections' => [
            [
                'heading' => 'Record overview',
                'paragraphs' => [
                    'The record view places the available information for a selected TE in one vertically organized page, allowing users to confirm the entity before moving from summary information to sequence and genomic evidence.',
                ],
            ],
            [
                'heading' => 'Record panels',
                'items' => [
                    'Summary confirms the current record and displays the available metadata.',
                    'Local Graph shows nearby entities and relationships.',
                    'Sequence displays a supported representative or consensus sequence and its available annotation; it does not represent every genomic copy of a TE.',
                    'Genome Annotation Distribution summarizes supported hits on the current assembly.',
                    'Genome Browser shows specific genomic locations. Selecting a hit in Genome Annotation Distribution updates the Genomic hit list.',
                    'Variants displays GTEx eQTL variants that strictly overlap the current TE. The default summary view presents one row per Variant with linked Gene and tissue counts and the minimum nominal p-value. Evidence rows expose each Variant-Gene-Tissue association with its slope and p-value. Overlapping Variants without an associated Gene remain visible.',
                ],
            ],
        ],
    ],
    'path' => [
        'nav' => 'Path',
        'title' => 'Path',
        'summary' => 'Path searches stored connections between two specified entities and presents evidence for each relationship in a verifiable form. It is intended for questions about how two entities are connected rather than for looking up one entity in isolation.',
        'sections' => [
            [
                'heading' => 'Interface overview',
                'paragraphs' => [
                    'The Path interface combines a constrained two-entity search form with Table and Graph result modes so the same returned connections can be read as detailed paths or as one combined network.',
                ],
            ],
            [
                'heading' => 'Search structure',
                'items' => [
                    'Both sides of the search form contain a narrow category selector and a wider entity selector.',
                    'After an entity is selected on one side, candidates offered on the other side are constrained to entities connected with the selected entity.',
                    'Increase Max hops to search a wider multi-hop neighborhood.',
                ],
            ],
            [
                'heading' => 'Reading path results',
                'paragraphs' => [
                    'Results are available in two modes: Table and Graph.',
                ],
                'items' => [
                    'Table records each path in detail, including entity names, entity categories, and relationships between entities. Select a relationship to expand or collapse its supporting literature table. Evidence rows can include PMID, Year, Journal, IF, JCR, Match, and Title.',
                    'Graph combines all returned paths in one network. Select nodes or edges to inspect details, including literature associated with an edge. Use Show relations to display relationship labels and Export to export the graph view.',
                ],
            ],
        ],
    ],
    'graph' => [
        'nav' => 'Graph',
        'title' => 'Graph Workspace',
        'summary' => 'Graph provides three complementary visual workflows: literature-derived knowledge relationships, TE classification in Tree or Graph form, and a TE-Gene Graph that integrates co-expression and eQTL evidence.',
        'sections' => [
            [
                'heading' => 'Classification Tree and Graph',
                'items' => [
                    'When TE classification is displayed, Tree provides a stable hierarchical view, while Graph provides a force-directed view that can be rearranged interactively.',
                    'All includes TE names not covered by RMSK and RepBase as well as some non-standard names.',
                ],
            ],
            [
                'heading' => 'Graph operations',
                'items' => [
                    'Select an entity category and enter an entity name in the search box to move directly to its graph.',
                    'Use Show relations to display edge labels, Back to entity to return to the previous graph, and Export to export the current graph.',
                    'When the searched entity is a TE, use Knowledge Graph and TE-Gene Graph to switch between its two network views.',
                    'Select legend entries to emphasize content temporarily, or change legend filters and select Apply to focus on specific entity or relationship types.',
                ],
            ],
            [
                'heading' => 'Knowledge Graph workspace',
                'items' => [
                    'Select a node to open an information card showing its category, connection count, and summary. Jump opens a graph centered on that node, Expand adds its neighborhood to the current graph, and Detail opens available detailed or TE classification information.',
                    'Searched and expanded nodes are marked with a ripple cue.',
                    'Use Show relations to display edge labels and Export to export graph information.',
                    'Entity legends distinguish TE, disease, and other node types. Relationship legends show predicate categories such as activate and affect and support category filtering.',
                ],
            ],
            [
                'heading' => 'TE-Gene Graph workspace',
                'items' => [
                    'The edge legend contains Co-expression, eQTL, and Both. Both means that the same TE-Gene pair has both types of evidence.',
                    'The co-expression network retains its existing context-specific structure. The legend can show or hide TE or Gene nodes, identify module hubs, and choose the visible edge range.',
                    'When Expression activity is enabled, co-expression nodes with expression-intensity data display ripples that reflect expression intensity. Non-central Genes introduced only by eQTL do not display these ripples.',
                    'All tissues summarizes eQTL evidence across tissues. A tissue selector can be used to inspect one GTEx tissue at a time.',
                    'The co-expression network uses Spearman r >= 0.4 and FDR <= 0.05. No additional p-value threshold is currently applied to eQTL evidence.',
                ],
            ],
        ],
    ],
    'agent' => [
        'nav' => 'Agent',
        'title' => 'Agent and DeepThink',
        'summary' => 'Agent is the natural-language research interface. Agent mode collects evidence through a structured multi-stage workflow, while DeepThink uses a shorter evidence-grounded reasoning flow for more direct questions.',
        'sections' => [
            [
                'heading' => 'Choosing a mode',
                'items' => [
                    'Agent integrates sequence, genomic location, Variant/eQTL, expression, disease relationships, literature, and other database areas. It uses multiple models and proceeds through six stages: Understanding, Planning, Collecting, Executing, Integrating, and Writing.',
                    'DeepThink is suitable when a direct question can be handled with a shorter reasoning and writing process. It uses one model and proceeds through four stages: Understanding, Planning, Executing, and Writing.',
                ],
            ],
            [
                'heading' => 'Asking questions',
                'items' => [
                    'Use clear TE, disease, or gene names, or a PMID, whenever possible. Ask for clarification when an abbreviation or entity name is ambiguous.',
                    'Questions may cover TE classification, sequences, genomic records, Variant overlap, GTEx eQTL, expression, co-expression, graph relationships, diseases, genes, or literature evidence.',
                    'When literature evidence matters, follow PMID links in the answer to inspect the corresponding PubMed records.',
                    'Answers may remain appropriately limited when the database lacks the requested evidence. Absence from a retrieved result does not demonstrate biological absence.',
                ],
            ],
        ],
    ],
    'expression' => [
        'nav' => 'Expression',
        'title' => 'Expression',
        'summary' => 'Expression is the TE abundance lookup interface. It supports catalog-level filtering and TE detail views across normal tissue, normal cell line, and cancer cell line datasets.',
        'sections' => [
            [
                'heading' => 'Finding a TE',
                'items' => [
                    'Use Keyword to search for a TE, or combine dataset source, top-context text, and minimum global median filters to narrow the table. Use Sort to order records by the available summary measure, then select a TE row to open its detail view.',
                    'When the corresponding data are available, the browse table summarizes the top normal tissue, normal cell line, and cancer cell line contexts together with the coefficient of variation.',
                    'On the detail page, use Display Controls to choose the Chart Type, Metric, and Order.',
                ],
            ],
        ],
    ],
    'contact' => [
        'nav' => 'Contact',
        'title' => 'Contact',
        'summary' => 'If you have suggestions or questions, please contact us through:',
        'sections' => [],
        'contacts' => [
            [
                'name' => 'Jiang XianDi',
                'email' => '3240105782@zju.edu.cn',
            ],
            [
                'name' => 'Chen Ming',
                'email' => 'mchen@zju.edu.cn',
                'phone' => '+86 (0) 571-88206612',
                'phone_href' => '+8657188206612',
            ],
        ],
    ],
];

function about_anchor_slug(string $text): string
{
    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $text), '-'));
    return $slug !== '' ? $slug : 'section';
}

function about_media_spec(string $sectionKey, string $heading): ?array
{
    $media = [
        'resource:TE-Dee architecture' => [
            'filename' => 'tekg-data-architecture.svg',
            'alt' => 'TE-Dee data architecture from literature, taxonomy, sequence, and expression processing to public services.',
        ],
        'resource:Data access routes' => [
            'filename' => 'tekg-resource-overview.svg',
            'alt' => 'Overview of the main TE-Dee public pages and the evidence routes available to users.',
        ],
        'browse:Record overview' => [
            'filename' => 'browse.png',
            'alt' => 'Browse record showing summary, local graph, sequence, genome annotation, and genome browser sections.',
        ],
        'browse:Record panels' => [
            'filename' => 'browse.mp4',
            'type' => 'video',
            'poster' => 'browse.png',
            'alt' => 'Animated Browse workflow demonstrating genome annotation and genomic hit selection.',
        ],
        'path:Interface overview' => [
            'filename' => 'path.png',
            'alt' => 'Path interface with entity selectors and returned connection results.',
        ],
        'path:Reading path results' => [
            'filename' => 'path.mp4',
            'type' => 'video',
            'poster' => 'path.png',
            'alt' => 'Animated Path workflow switching between result views and inspecting relationships.',
        ],
        'graph:Knowledge Graph workspace' => [
            'filename' => 'graph.png',
            'alt' => 'Knowledge Graph workspace showing network controls, legend filters, and a node detail card.',
        ],
    ];
    return $media[$sectionKey . ':' . $heading] ?? null;
}
?>
      <section class="about-shell">
        <div class="proto-container">
          <h1 class="page-title-hero">About</h1>

          <section class="about-panel">
            <div class="about-layout">
              <aside class="about-side">
                <label class="about-search" for="about-search-input">
                  <span>Search</span>
                  <input id="about-search-input" type="search" placeholder="Search this guide" autocomplete="off">
                </label>
                <div class="about-side-title">Contents</div>
                <nav class="about-nav" aria-label="About page sections">
<?php foreach ($aboutSections as $key => $section): ?>
                  <a class="about-nav-parent <?= $key === 'resource' ? 'is-active' : '' ?>" href="#section-<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" data-pane="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($section['nav'], ENT_QUOTES, 'UTF-8') ?></a>
<?php foreach ($section['sections'] as $detail): ?>
<?php $detailId = 'section-' . $key . '-' . about_anchor_slug($detail['heading']); ?>
                  <a class="about-nav-child" href="#<?= htmlspecialchars($detailId, ENT_QUOTES, 'UTF-8') ?>" data-pane="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" data-subsection="<?= htmlspecialchars($detailId, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($detail['heading'], ENT_QUOTES, 'UTF-8') ?></a>
<?php endforeach; ?>
<?php endforeach; ?>
                </nav>
              </aside>

              <div class="about-content">
<?php $sectionIndex = 1; ?>
<?php foreach ($aboutSections as $key => $section): ?>
                <article class="about-doc-section" id="section-<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" data-section="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>">
                  <header class="about-doc-header">
                    <span><?= str_pad((string)$sectionIndex, 2, '0', STR_PAD_LEFT) ?></span>
                    <div>
                      <h3><?= htmlspecialchars($section['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                      <p><?= htmlspecialchars($section['summary'], ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                  </header>

<?php if (!empty($section['contacts'])): ?>
                  <div class="about-contact-grid" aria-label="Contact information">
<?php foreach ($section['contacts'] as $contact): ?>
                    <address class="about-contact-person">
                      <strong><?= htmlspecialchars($contact['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                      <a href="mailto:<?= htmlspecialchars($contact['email'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($contact['email'], ENT_QUOTES, 'UTF-8') ?></a>
<?php if (!empty($contact['phone'])): ?>
                      <a href="tel:<?= htmlspecialchars($contact['phone_href'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($contact['phone'], ENT_QUOTES, 'UTF-8') ?></a>
<?php endif; ?>
                    </address>
<?php endforeach; ?>
                  </div>
<?php endif; ?>
<?php if (!empty($section['sections'])): ?>
                  <div class="about-detail-grid">
<?php foreach ($section['sections'] as $detail): ?>
<?php $detailId = 'section-' . $key . '-' . about_anchor_slug($detail['heading']); ?>
<?php $media = about_media_spec($key, $detail['heading']); ?>
                    <section class="about-doc-subsection" id="<?= htmlspecialchars($detailId, ENT_QUOTES, 'UTF-8') ?>" data-subsection-title="<?= htmlspecialchars($detail['heading'], ENT_QUOTES, 'UTF-8') ?>">
                      <div class="about-detail-card">
                        <h4><?= htmlspecialchars($detail['heading'], ENT_QUOTES, 'UTF-8') ?></h4>
<?php foreach (($detail['paragraphs'] ?? []) as $paragraph): ?>
                        <p><?= htmlspecialchars($paragraph, ENT_QUOTES, 'UTF-8') ?></p>
<?php endforeach; ?>
<?php if (!empty($detail['items'])): ?>
                        <ul>
<?php foreach ($detail['items'] as $item): ?>
                          <li><?= htmlspecialchars($item, ENT_QUOTES, 'UTF-8') ?></li>
<?php endforeach; ?>
                        </ul>
<?php endif; ?>
                      </div>
<?php if ($media !== null): ?>
<?php
    $mediaUrl = tekg_assets_url('img/about/' . $media['filename']);
    $mediaVersion = (int)@filemtime(tekg_assets_fs_path('img/about/' . $media['filename']));
    $isVideo = ($media['type'] ?? 'image') === 'video';
    $posterUrl = null;
    if ($isVideo && !empty($media['poster'])) {
        $posterVersion = (int)@filemtime(tekg_assets_fs_path('img/about/' . $media['poster']));
        $posterUrl = tekg_assets_url('img/about/' . $media['poster']) . '?v=' . $posterVersion;
    }
?>
                      <figure class="about-placeholder-media">
<?php if ($isVideo): ?>
                        <video class="about-media-video" autoplay loop muted playsinline preload="metadata"<?= $posterUrl !== null ? ' poster="' . htmlspecialchars($posterUrl, ENT_QUOTES, 'UTF-8') . '"' : '' ?> aria-label="<?= htmlspecialchars($media['alt'], ENT_QUOTES, 'UTF-8') ?>">
                          <source src="<?= htmlspecialchars($mediaUrl . '?v=' . $mediaVersion, ENT_QUOTES, 'UTF-8') ?>" type="video/mp4">
                        </video>
<?php else: ?>
                        <img class="about-media-image" src="<?= htmlspecialchars($mediaUrl . '?v=' . $mediaVersion, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($media['alt'], ENT_QUOTES, 'UTF-8') ?>" loading="lazy" decoding="async">
<?php endif; ?>
                      </figure>
<?php endif; ?>
                    </section>
<?php endforeach; ?>
                  </div>
<?php endif; ?>
                </article>
<?php $sectionIndex += 1; ?>
<?php endforeach; ?>
              </div>
            </div>
          </section>
        </div>
      </section>
      <p class="about-no-results" hidden>No matching guide sections.</p>

      <script src="<?= htmlspecialchars(tekg_assets_url('js/pages/about.js') . '?v=' . $aboutJsVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
<?php require __DIR__ . '/foot.php'; ?>
