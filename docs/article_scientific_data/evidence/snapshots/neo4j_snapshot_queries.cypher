// TE-KG manuscript snapshot queries. Run against Neo4j database tekg3.
// Snapshot used in the current working draft: 2026-07-31.

MATCH (n)
UNWIND labels(n) AS label
RETURN label, count(*) AS node_count
ORDER BY node_count DESC, label;

MATCH ()-[r]->()
RETURN type(r) AS relationship_type, count(*) AS directed_relationship_count
ORDER BY directed_relationship_count DESC, relationship_type;

MATCH ()-[r:BIO_RELATION]->()
RETURN
  count(r) AS relation_count,
  count(CASE WHEN r.predicate IS NOT NULL THEN 1 END) AS with_predicate,
  count(CASE WHEN r.pmids IS NOT NULL THEN 1 END) AS with_pmids,
  count(CASE WHEN r.support_pmid_count IS NOT NULL THEN 1 END) AS with_support_pmid_count;

