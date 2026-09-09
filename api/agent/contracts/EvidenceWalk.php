<?php
declare(strict_types=1);

final class EvidenceWalk
{
    private const SCHEMA_VERSION = 'evidence_walk.v1';

    public static function fromEvidencePackage(array $package, array $analysis = [], array $planning = [], array $sufficiency = []): array
    {
        $walkSteps = [];
        $claimNodes = [];
        $supportEdges = [];
        $citationRefs = [];
        $routeRefs = [];
        $gaps = [];

        foreach (array_values((array)($package['claims'] ?? [])) as $index => $claim) {
            if (!is_array($claim)) {
                continue;
            }

            $claimId = self::stringValue($claim['id'] ?? '');
            $claimNodeId = 'claim_node_' . (count($claimNodes) + 1);
            $evidenceIds = array_values((array)($claim['evidence_ids'] ?? []));
            $citationIds = array_values((array)($claim['citation_ids'] ?? []));
            $routeIds = array_values((array)($claim['route_ids'] ?? []));

            $claimNodes[] = [
                'id' => $claimNodeId,
                'claim_id' => $claimId,
                'text' => self::stringValue($claim['text'] ?? ''),
                'source_plugin' => self::stringValue($claim['source_plugin'] ?? ''),
                'intent' => self::stringValue($claim['intent'] ?? ($analysis['intent'] ?? 'unknown')),
                'status' => self::stringValue($claim['status'] ?? 'unknown'),
                'confidence' => $claim['confidence'] ?? null,
                'evidence_ids' => $evidenceIds,
                'citation_ids' => $citationIds,
                'route_ids' => $routeIds,
            ];

            $walkSteps[] = [
                'id' => 'walk_step_' . (count($walkSteps) + 1),
                'claim_node_id' => $claimNodeId,
                'claim_id' => $claimId,
                'order' => $index + 1,
                'operation' => self::operationForClaim($claim, $analysis),
                'summary' => self::stringValue($claim['text'] ?? ''),
                'evidence_ids' => $evidenceIds,
                'citation_refs' => $citationIds,
                'route_refs' => $routeIds,
            ];

            foreach ($evidenceIds as $evidenceId) {
                $supportEdges[] = [
                    'id' => 'support_edge_' . (count($supportEdges) + 1),
                    'claim_node_id' => $claimNodeId,
                    'claim_id' => $claimId,
                    'evidence_id' => self::stringValue($evidenceId),
                    'support_strength' => self::supportStrengthForEvidence($package, self::stringValue($evidenceId)),
                ];
            }
        }

        foreach (array_values((array)($package['citation_map'] ?? [])) as $citation) {
            if (!is_array($citation)) {
                continue;
            }
            $citationRefs[] = [
                'id' => 'citation_ref_' . (count($citationRefs) + 1),
                'citation_id' => self::stringValue($citation['id'] ?? ''),
                'claim_id' => self::stringValue($citation['claim_id'] ?? ''),
                'plugin' => self::stringValue($citation['plugin'] ?? ''),
                'citation' => is_array($citation['citation'] ?? null) ? $citation['citation'] : [],
            ];
        }

        foreach (array_values((array)($package['route_map'] ?? [])) as $route) {
            if (!is_array($route)) {
                continue;
            }
            $routeRefs[] = [
                'id' => 'route_ref_' . (count($routeRefs) + 1),
                'route_id' => self::stringValue($route['id'] ?? ''),
                'claim_id' => self::stringValue($route['claim_id'] ?? ''),
                'plugin' => self::stringValue($route['plugin'] ?? ''),
                'route' => is_array($route['route'] ?? null) ? $route['route'] : [],
            ];
        }

        if ($claimNodes === []) {
            $gaps[] = [
                'id' => 'gap_1',
                'type' => 'no_claims',
                'severity' => 'blocking',
                'message' => 'Evidence package contains no claim nodes.',
                'required_evidence' => array_values((array)($sufficiency['required_evidence'] ?? [])),
            ];
        }

        foreach (array_values((array)($package['errors'] ?? [])) as $error) {
            if (!is_array($error)) {
                continue;
            }
            $gaps[] = [
                'id' => 'gap_' . (count($gaps) + 1),
                'type' => 'source_error',
                'severity' => 'warning',
                'message' => self::stringValue($error['message'] ?? 'Evidence source error.'),
                'plugin' => self::stringValue($error['plugin'] ?? ''),
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => gmdate('c'),
            'walk_steps' => $walkSteps,
            'claim_nodes' => $claimNodes,
            'support_edges' => $supportEdges,
            'citation_refs' => $citationRefs,
            'route_refs' => $routeRefs,
            'gaps' => $gaps,
            'coverage_metrics' => [
                'claim_node_count' => count($claimNodes),
                'walk_step_count' => count($walkSteps),
                'support_edge_count' => count($supportEdges),
                'citation_ref_count' => count($citationRefs),
                'route_ref_count' => count($routeRefs),
                'gap_count' => count($gaps),
                'has_minimum_evidence' => $claimNodes !== [] && $supportEdges !== [],
                'source_claim_count' => (int)($package['metrics']['claim_count'] ?? count($claimNodes)),
                'source_evidence_count' => (int)($package['metrics']['evidence_count'] ?? count($supportEdges)),
                'selected_plugin_count' => count((array)($planning['selected_plugins'] ?? [])),
                'sufficiency_status' => self::stringValue($sufficiency['status'] ?? 'unknown'),
            ],
        ];
    }

    public static function validate(array $walk): array
    {
        $errors = [];
        $schema = require __DIR__ . '/../config/evidence_walk_schema.php';

        foreach ((array)($schema['required'] ?? []) as $key) {
            if (!array_key_exists($key, $walk)) {
                $errors[] = "{$key} is required";
            }
        }

        if (($walk['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            $errors[] = 'schema_version must be evidence_walk.v1';
        }
        if (!is_string($walk['generated_at'] ?? null) || strtotime((string)$walk['generated_at']) === false) {
            $errors[] = 'generated_at must be an ISO-8601 date-time string';
        }
        foreach (['walk_steps', 'claim_nodes', 'support_edges', 'citation_refs', 'route_refs', 'gaps'] as $key) {
            if (array_key_exists($key, $walk) && !is_array($walk[$key])) {
                $errors[] = "{$key} must be an array";
            }
        }
        if (array_key_exists('coverage_metrics', $walk) && !is_array($walk['coverage_metrics'])) {
            $errors[] = 'coverage_metrics must be an object';
        }

        foreach ((array)($walk['walk_steps'] ?? []) as $index => $step) {
            self::validateObject($step, "walk_steps[{$index}]", ['id', 'claim_node_id', 'claim_id', 'operation', 'summary'], $errors);
            if (is_array($step)) {
                foreach (['evidence_ids', 'citation_refs', 'route_refs'] as $key) {
                    if (!array_key_exists($key, $step) || !is_array($step[$key])) {
                        $errors[] = "walk_steps[{$index}].{$key} must be an array";
                    }
                }
            }
        }
        foreach ((array)($walk['claim_nodes'] ?? []) as $index => $claimNode) {
            self::validateObject($claimNode, "claim_nodes[{$index}]", ['id', 'claim_id', 'text', 'source_plugin', 'intent', 'status'], $errors);
        }
        foreach ((array)($walk['support_edges'] ?? []) as $index => $edge) {
            self::validateObject($edge, "support_edges[{$index}]", ['id', 'claim_node_id', 'claim_id', 'evidence_id'], $errors);
        }
        foreach ((array)($walk['citation_refs'] ?? []) as $index => $citationRef) {
            self::validateObject($citationRef, "citation_refs[{$index}]", ['id', 'citation_id', 'claim_id', 'plugin'], $errors);
        }
        foreach ((array)($walk['route_refs'] ?? []) as $index => $routeRef) {
            self::validateObject($routeRef, "route_refs[{$index}]", ['id', 'route_id', 'claim_id', 'plugin'], $errors);
        }
        foreach ((array)($walk['gaps'] ?? []) as $index => $gap) {
            self::validateObject($gap, "gaps[{$index}]", ['id', 'type', 'severity', 'message'], $errors);
        }

        if (isset($walk['coverage_metrics']) && is_array($walk['coverage_metrics'])) {
            foreach ((array)($schema['properties']['coverage_metrics']['required'] ?? []) as $key) {
                if (!array_key_exists($key, $walk['coverage_metrics'])) {
                    $errors[] = "coverage_metrics.{$key} is required";
                }
            }
        }

        return ['ok' => $errors === [], 'errors' => $errors];
    }

    private static function operationForClaim(array $claim, array $analysis): string
    {
        $intent = strtolower(self::stringValue($claim['intent'] ?? ($analysis['intent'] ?? '')));
        return match ($intent) {
            'literature', 'citation' => 'cite_literature_evidence',
            'navigation' => 'link_runtime_route',
            'graph_analytics', 'analytics' => 'rank_graph_evidence',
            'mechanism' => 'trace_mechanism_claim',
            default => 'collect_evidence_claim',
        };
    }

    private static function supportStrengthForEvidence(array $package, string $evidenceId): mixed
    {
        foreach ((array)($package['evidence_items'] ?? []) as $evidence) {
            if (is_array($evidence) && ($evidence['id'] ?? null) === $evidenceId) {
                return $evidence['support_strength'] ?? null;
            }
        }
        return null;
    }

    private static function validateObject(mixed $value, string $path, array $requiredStringKeys, array &$errors): void
    {
        if (!is_array($value)) {
            $errors[] = "{$path} must be an object";
            return;
        }
        foreach ($requiredStringKeys as $key) {
            if (!array_key_exists($key, $value) || !is_string($value[$key]) || trim($value[$key]) === '') {
                $errors[] = "{$path}.{$key} is required";
            }
        }
    }

    private static function stringValue(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return (string)$value;
        }
        return '';
    }
}
