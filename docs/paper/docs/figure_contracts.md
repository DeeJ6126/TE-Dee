# Figure contracts

## Figure 2. Data composition

- **Core conclusion:** TE-KG integrates biological entities, relation predicates and expression contexts rather than a single flat table.
- **Evidence chain:** Entity composition excludes literature nodes; relation composition summarizes main predicate groups and combines the long tail as Others; expression context panel shows tissue/cell-line scope.
- **Archetype:** Quantitative grid.
- **Export contract:** Python/matplotlib; source data in CSV; export PNG, SVG, PDF and TIFF.
- **Review risks:** Entity composition excludes Paper nodes by design; relation composition uses the current homepage API grouping and should be refreshed before final submission.

## Figure 3. TE classification hierarchy

- **Core conclusion:** TE-KG stores TE classification across Class, Order, Superfamily and Family levels.
- **Evidence chain:** Four pie charts show the composition at each classification level; long-tail Superfamily and Family categories are merged as Others for readability.
- **Archetype:** Quantitative grid.
- **Export contract:** Python/matplotlib; source data in CSV; export PNG, SVG, PDF and TIFF.
- **Review risks:** Family-level categories are numerous; the merged Others category should be interpreted as a readability grouping, not as a biological class.

## Figure 4. L1HS application case

- **Core conclusion:** A representative TE query can combine sequence, genomic locus, disease relation and assistant-mediated evidence synthesis.
- **Evidence chain:** L1HS cards summarize consensus length, representative locus, disease links, literature evidence and assistant output.
- **Archetype:** Schematic-like evidence card, not a workflow diagram.
- **Export contract:** Python/matplotlib; export PNG, SVG, PDF and TIFF.
- **Review risks:** Case details should be revalidated against the live database before final submission.
