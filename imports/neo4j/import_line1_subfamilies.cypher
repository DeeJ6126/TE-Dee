// Create the LINE-1 family node and its known subfamily relationships.
MERGE (family:TE {name: 'LINE-1'})
ON CREATE SET
  family.description = 'LINE-1 family node for linking known LINE-1 subfamilies.',
  family.category = 'family';

WITH family
UNWIND [
  {name: 'L1HS', copies: 1686},
  {name: 'L1PA2', copies: 5113},
  {name: 'L1PA3', copies: 11089},
  {name: 'L1PA4', copies: 12272},
  {name: 'L1PA5', copies: 11616},
  {name: 'L1PA6', copies: 6143},
  {name: 'L1PA7', copies: 13381},
  {name: 'L1PA8', copies: 8376},
  {name: 'L1PA8A', copies: 2514},
  {name: 'L1PA10', copies: 7367},
  {name: 'L1PA11', copies: 4207},
  {name: 'L1PA12', copies: 1811},
  {name: 'L1PA13', copies: 9208},
  {name: 'L1PA14', copies: 3116},
  {name: 'L1PA15', copies: 8569},
  {name: 'L1PA16', copies: 14421},
  {name: 'L1PA17', copies: 4863},
  {name: 'L1PB1', copies: 13446},
  {name: 'L1PB2', copies: 2929},
  {name: 'L1PB3', copies: 3656},
  {name: 'L1PB4', copies: 7745},
  {name: 'L1MA1', copies: 4359},
  {name: 'L1MA2', copies: 7636},
  {name: 'L1MA3', copies: 9341},
  {name: 'L1MA4', copies: 10943},
  {name: 'L1MA5', copies: 4580}
] AS subfamily
MERGE (child:TE {name: subfamily.name})
ON CREATE SET
  child.description = subfamily.name + ' is a LINE-1 subfamily.',
  child.category = 'subfamily'
MERGE (child)-[rel:SUBFAMILY_OF]->(family)
ON CREATE SET
  rel.copies = subfamily.copies,
  rel.source = 'lineage_reference';
