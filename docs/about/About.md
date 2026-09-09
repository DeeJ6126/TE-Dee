# TE-Dee About Guide

This document is the English source for the public About page. It contains both user-facing text and the static or animated media used by the website.

## 1. About TE-Dee

TE-Dee is an integrated resource for human transposable elements. It connects classification systems, literature-derived relationships, representative sequence and genomic records, expression and co-expression contexts, Variant and eQTL evidence, downloadable data, and evidence-grounded natural-language question answering.

### What TE-Dee is

TE-Dee brings several complementary views of human transposable elements into one interface rather than treating every dataset as an isolated table. The resource includes a TE catalog, entity details, literature-derived relationship graphs, classification views, expression and TE-Gene evidence exploration, and downloadable data. Its central aim is to make TE information easier to explore while keeping source evidence and interpretation boundaries visible.

### TE-Dee architecture

TE-Dee integrates four main data streams. Literature is screened before AI-assisted entity and relationship extraction, normalization, and manual curation. RMSK genomic annotations and RepBase classification and sequence records are harmonized into a shared taxonomy, sequence, and genomic annotation layer. Expression datasets are processed separately and used to build context-specific TE-Gene co-expression results. GTEx v11 eQTL data are organized through Variant normalization, strict TE-Variant coordinate overlap, and Variant-Gene-Tissue association, producing queryable TE-Gene eQTL evidence.

The basic eQTL relationship is that a TE instance strictly overlaps the reference-allele interval of a Variant, and that Variant has a GTEx eQTL association with a Gene in a specific tissue. The knowledge graph and taxonomy run in Neo4j. The catalog, expression, co-expression, and eQTL/Variant runtime data are stored in MySQL and exposed through unified APIs and evidence services for Browse, Path, Graph, Expression, Agent, and DeepThink workflows.

![TE-Dee data architecture and public services](<figs/TE-KG Data Architecture and Public Services.svg>)

### Data access routes

- Use Home to review the current overall composition of the database.
- Use Browse to explore the TE catalog, then use Search to inspect the selected entity's detailed records, genomic locations, and Variant/eQTL evidence.
- Use Graph to explore knowledge relationships, TE classification, and the TE-Gene Graph; use Path Finder to inspect connections between two specified entities.
- Use Expression to examine abundance patterns in the currently available expression datasets.
- Use Agent or DeepThink to ask evidence-grounded natural-language questions and continue with follow-up questions in the current conversation.
- Use Download to obtain files currently provided by the website.
- Use the AI window to access DeepThink from supported pages across the site.

![TE-Dee resource overview](<figs/TE-KG Resource Overview.svg>)

## 2. Home Overview

Home is the main overview entry point for TE-Dee. It introduces the resource, summarizes the current database composition, and provides shortcuts to the principal public workflows.

- Overview summarizes the purpose and scope of TE-Dee.
- Dataset Status reports the current Neo4j database scale. Its donut charts show entity composition, TE classification, and relation predicate composition.
  - Entity Composition counts the principal knowledge-graph entity categories among stored nodes.
  - TE Classification can switch classification levels, allowing the chart to move from broad classes to more specific levels.
  - Relation Composition uses detailed predicate counts to reveal common relationship types.
- Quick Links provide direct access to the main lookup, graph, expression, download, and help workflows.

## 3. Browse

Browse provides the complete workflow for finding and reviewing a TE record. Its content includes Summary, Local Graph, Sequence, Genome Annotation Distribution, Genome Browser, and Variants.

![Browse record overview](figs/Browse.png)

- Summary confirms the current record and displays the available metadata.
- Local Graph shows nearby entities and relationships.
- Sequence displays a supported representative or consensus sequence and its available annotation; it does not represent every genomic copy of a TE.
- Genome Annotation Distribution summarizes supported hits on the current assembly.
- Genome Browser shows specific genomic locations. Selecting a hit in Genome Annotation Distribution updates the Genomic hit list.
- Variants displays GTEx eQTL variants that strictly overlap the current TE. The default summary view presents one row per Variant with linked Gene and tissue counts and the minimum nominal p-value. Evidence rows expose each Variant-Gene-Tissue association with its slope and p-value. Overlapping Variants without an associated Gene remain visible.

<video src="figs/Browse.mp4" poster="figs/Browse.png" controls loop muted playsinline></video>

## 4. Path

Path searches stored connections between two specified entities and presents evidence for each relationship in a verifiable form. It is intended for questions about how two entities are connected rather than for looking up one entity in isolation.

![Path search and result interface](figs/Path.png)

### Search structure

- Both sides of the search form contain a narrow category selector and a wider entity selector.
- After an entity is selected on one side, candidates offered on the other side are constrained to entities connected with the selected entity.
- Increase MAX HOPS to search a wider multi-hop neighborhood.

### Reading path results

Results are available in two modes: Table and Graph.

- Table records each path in detail, including entity names, entity categories, and relationships between entities. Select a relationship to expand or collapse its supporting literature table. Evidence rows can include PMID, Year, Journal, IF, JCR, Match, and Title.
- Graph combines all returned paths in one network. Select nodes or edges to inspect details, including literature associated with an edge. Use Show relations to display relationship labels and Export to export the graph view.

<video src="figs/Path.mp4" poster="figs/Path.png" controls loop muted playsinline></video>

## 5. Graph Workspace

Graph provides three complementary visual workflows: literature-derived knowledge relationships, TE classification in Tree or Graph form, and a TE-Gene Graph that integrates co-expression and eQTL evidence.

### Classification Tree and Graph

- When TE classification is displayed, Tree provides a stable hierarchical view, while Graph provides a force-directed view that can be rearranged interactively.
- All includes TE names not covered by RMSK and RepBase as well as some non-standard names.

### Graph operations

- Select an entity category and enter an entity name in the search box to move directly to its graph.
- Use Show relations to display edge labels, Back to entity to return to the previous graph, and Export to export the current graph.
- When the searched entity is a TE, use Knowledge Graph and TE-Gene Graph to switch between its two network views.
- Select legend entries to emphasize content temporarily, or change legend filters and select Apply to focus on specific entity or relationship types.

### Knowledge Graph workspace

- Select a node to open an information card showing its category, connection count, and summary. Jump opens a graph centered on that node, Expand adds its neighborhood to the current graph, and Detail opens available detailed or TE classification information.
- Searched and expanded nodes are marked with a ripple cue.
- Use Show relations to display edge labels and Export to export graph information.
- Entity legends distinguish TE, disease, and other node types. Relationship legends show predicate categories such as activate and affect and support category filtering.

![Knowledge Graph controls and detail card](figs/Graph.png)

### TE-Gene Graph workspace

- The edge legend contains Co-expression, eQTL, and Both. Both means that the same TE-Gene pair has both types of evidence.
- The co-expression network retains its existing context-specific structure. The legend can show or hide TE or Gene nodes, identify module hubs, and choose the visible edge range.
- When Expression activity is enabled, co-expression nodes with expression-intensity data display ripples that reflect expression intensity. Non-central Genes introduced only by eQTL do not display these ripples.
- All tissues summarizes eQTL evidence across tissues. A tissue selector can be used to inspect one GTEx tissue at a time.
- The co-expression network uses Spearman r >= 0.4 and FDR <= 0.05. No additional p-value threshold is currently applied to eQTL evidence.

## 6. Agent and DeepThink

Agent is the natural-language research interface. Agent mode collects evidence through a structured multi-stage workflow, while DeepThink uses a shorter evidence-grounded reasoning flow for more direct questions.

### Choosing a mode

- Agent integrates sequence, genomic location, Variant/eQTL, expression, disease relationships, literature, and other database areas. It uses multiple models and proceeds through six stages: Understanding, Planning, Collecting, Executing, Integrating, and Writing.
- DeepThink is suitable when a direct question can be handled with a shorter reasoning and writing process. It uses one model and proceeds through four stages: Understanding, Planning, Executing, and Writing.

### Asking questions

- Use clear TE, disease, or gene names, or a PMID, whenever possible. Ask for clarification when an abbreviation or entity name is ambiguous.
- Questions may cover TE classification, sequences, genomic records, Variant overlap, GTEx eQTL, expression, co-expression, graph relationships, diseases, genes, or literature evidence.
- When literature evidence matters, follow PMID links in the answer to inspect the corresponding PubMed records.
- Answers may remain appropriately limited when the database lacks the requested evidence. Absence from a retrieved result does not demonstrate biological absence.

## 7. Expression

Expression is the TE abundance lookup interface. It supports catalog-level filtering and TE detail views across normal tissue, normal cell line, and cancer cell line datasets.

### Finding a TE

- Use Keyword to search for a TE, or combine dataset source, top-context text, and minimum global median filters to narrow the table. Use Sort to order records by the available summary measure, then select a TE row to open its detail view.
- When the corresponding data are available, the browse table summarizes the top normal tissue, normal cell line, and cancer cell line contexts together with the coefficient of variation.
- On the detail page, use Display Controls to choose the Chart Type, Metric, and Order.

## 8. Contact

If you have suggestions or questions, please contact us through:

| Jiang XianDi | Chen Ming |
| --- | --- |
| 3240105782@zju.edu.cn | mchen@zju.edu.cn<br>+86 (0) 571-88206612 |
