from __future__ import annotations

import sys
from pathlib import Path

SCRIPTS_ROOT = next(parent for parent in Path(__file__).resolve().parents if parent.name == "scripts")
if str(SCRIPTS_ROOT) not in sys.path:
    sys.path.insert(0, str(SCRIPTS_ROOT))

import json
from collections import Counter, defaultdict
from path_helpers import ROOT

JBROWSE_DIR = ROOT / 'data' / 'JBrowse'
FASTA_PATH = JBROWSE_DIR / 'hg38.fa'
FAI_PATH = JBROWSE_DIR / 'hg38.fa.fai'
CHROM_SIZES_PATH = JBROWSE_DIR / 'hg38.chrom.sizes'
GTF_PATH = JBROWSE_DIR / 'hg38.ncbiRefSeq.gtf' / 'hg38.ncbiRefSeq.gtf'
GTF_SUMMARY_PATH = JBROWSE_DIR / 'hg38.ncbiRefSeq.gtf' / 'hg38.ncbiRefSeq.summary.json'
RMSK_PATH = ROOT / 'data' / 'rmsk.txt'
REPEATS_DIR = JBROWSE_DIR / 'repeats'
REPEATS_BED_PATH = REPEATS_DIR / 'hg38.rmsk.repeats.bed'
REPEATS_INDEX_PATH = REPEATS_DIR / 'hg38.rmsk.repeats.index.json'
TE_LOCUS_INDEX_PATH = REPEATS_DIR / 'te_locus_index.json'
TE_REPRESENTATIVE_INDEX_PATH = REPEATS_DIR / 'te_representative_index.json'
MANIFEST_PATH = JBROWSE_DIR / 'jbrowse_assets_manifest.json'
CLINVAR_MAIN_PATH = JBROWSE_DIR / 'clinvarMain.bb'
CLINVAR_CNV_PATH = JBROWSE_DIR / 'clinvarCnv.bb'
PRIMARY_CHROMS = {f'chr{i}' for i in range(1, 23)} | {'chrX', 'chrY'}
PRIMARY_FEATURES = {'gene', 'transcript', 'exon', 'CDS', 'five_prime_UTR', 'three_prime_UTR', 'start_codon', 'stop_codon'}


def ensure_exists(path: Path) -> None:
    if not path.exists():
        raise FileNotFoundError(f'Missing required file: {path}')


def build_fasta_index() -> dict:
    ensure_exists(FASTA_PATH)
    entries = []
    chrom_sizes = []
    with FASTA_PATH.open('rb') as handle:
        seq_name = None
        seq_len = 0
        seq_offset = 0
        bases_per_line = None
        bytes_per_line = None
        while True:
            raw = handle.readline()
            if not raw:
                break
            if raw.startswith(b'>'):
                if seq_name is not None:
                    entries.append((seq_name, seq_len, seq_offset, bases_per_line or 0, bytes_per_line or 0))
                    chrom_sizes.append((seq_name, seq_len))
                seq_name = raw[1:].strip().split()[0].decode('utf-8')
                seq_len = 0
                seq_offset = handle.tell()
                bases_per_line = None
                bytes_per_line = None
                continue
            stripped = raw.rstrip(b'\r\n')
            if not stripped:
                continue
            if bases_per_line is None:
                bases_per_line = len(stripped)
                bytes_per_line = len(raw)
            seq_len += len(stripped)
        if seq_name is not None:
            entries.append((seq_name, seq_len, seq_offset, bases_per_line or 0, bytes_per_line or 0))
            chrom_sizes.append((seq_name, seq_len))

    with FAI_PATH.open('w', encoding='utf-8', newline='\n') as handle:
        for name, length, offset, bases, bytes_ in entries:
            handle.write(f'{name}\t{length}\t{offset}\t{bases}\t{bytes_}\n')

    with CHROM_SIZES_PATH.open('w', encoding='utf-8', newline='\n') as handle:
        for name, length in chrom_sizes:
            handle.write(f'{name}\t{length}\n')

    return {
        'path': str(FAI_PATH.relative_to(ROOT)).replace('\\', '/'),
        'chrom_sizes_path': str(CHROM_SIZES_PATH.relative_to(ROOT)).replace('\\', '/'),
        'sequence_count': len(entries),
        'primary_chromosomes_present': [name for name, _ in chrom_sizes if name in PRIMARY_CHROMS],
    }


def summarize_gtf() -> dict:
    ensure_exists(GTF_PATH)
    feature_counts = Counter()
    primary_feature_counts = Counter()
    chromosomes = Counter()
    total_rows = 0
    with GTF_PATH.open('r', encoding='utf-8', errors='replace') as handle:
        for raw in handle:
            if not raw or raw.startswith('#'):
                continue
            parts = raw.rstrip('\n').split('\t')
            if len(parts) < 9:
                continue
            total_rows += 1
            chrom = parts[0]
            feature = parts[2]
            feature_counts[feature] += 1
            if chrom in PRIMARY_CHROMS:
                chromosomes[chrom] += 1
            if feature in PRIMARY_FEATURES:
                primary_feature_counts[feature] += 1

    summary = {
        'path': str(GTF_PATH.relative_to(ROOT)).replace('\\', '/'),
        'total_rows': total_rows,
        'feature_counts': dict(feature_counts.most_common()),
        'primary_feature_counts': dict(primary_feature_counts.most_common()),
        'primary_chromosomes_present': [chrom for chrom, _ in sorted(chromosomes.items())],
    }
    GTF_SUMMARY_PATH.write_text(json.dumps(summary, ensure_ascii=False, indent=2), encoding='utf-8')
    return summary


def build_repeats_track() -> dict:
    ensure_exists(RMSK_PATH)
    REPEATS_DIR.mkdir(parents=True, exist_ok=True)
    total_rows = 0
    exported_rows = 0
    class_counts = Counter()
    family_counts = Counter()
    te_total_hits = Counter()
    te_index = defaultdict(list)
    with RMSK_PATH.open('r', encoding='utf-8', errors='replace') as source, REPEATS_BED_PATH.open('w', encoding='utf-8', newline='\n') as bed:
        for raw in source:
            if not raw.strip():
                continue
            parts = raw.rstrip('\n').split('\t')
            if len(parts) < 15:
                continue
            total_rows += 1
            chrom = parts[5]
            if chrom not in PRIMARY_CHROMS:
                continue
            try:
                score = int(parts[1])
                start = int(parts[6])
                end = int(parts[7])
            except ValueError:
                continue
            strand = parts[9]
            rep_name = parts[10]
            rep_class = parts[11]
            rep_family = parts[12]
            try:
                record_id = int(parts[16])
            except (IndexError, ValueError):
                record_id = exported_rows + 1
            bed.write(f'{chrom}\t{start}\t{end}\t{rep_name}\t0\t{strand}\t{rep_class}\t{rep_family}\n')
            exported_rows += 1
            class_counts[rep_class] += 1
            family_counts[rep_family] += 1
            te_total_hits[rep_name] += 1
            if len(te_index[rep_name]) < 200:
                te_index[rep_name].append({
                    'record_id': record_id,
                    'chrom': chrom,
                    'start': start,
                    'end': end,
                    'strand': strand,
                    'class': rep_class,
                    'family': rep_family,
                    'length': end - start,
                    'score': score,
                })

    sorted_index = {}
    for te_name, hits in te_index.items():
        hits.sort(key=lambda item: (-item['length'], -item['score'], item['chrom'], item['start'], item['record_id']))
        sorted_index[te_name] = {
            'selection_rule': 'length_desc__score_desc__chrom_asc__start_asc__record_id_asc',
            'total_hits': te_total_hits[te_name],
            'count_sampled': len(hits),
            'representative_locus': hits[0] if hits else None,
            'sample_hits': hits[:50],
        }

    summary = {
        'source_path': str(RMSK_PATH.relative_to(ROOT)).replace('\\', '/'),
        'track_path': str(REPEATS_BED_PATH.relative_to(ROOT)).replace('\\', '/'),
        'total_rows': total_rows,
        'exported_primary_chr_rows': exported_rows,
        'class_counts': dict(class_counts.most_common()),
        'family_counts': dict(family_counts.most_common(100)),
    }
    representative_index = {
        te_name: {
            'selection_rule': payload['selection_rule'],
            'total_hits': payload['total_hits'],
            'count_sampled': payload['count_sampled'],
            'representative_locus': payload['representative_locus'],
        }
        for te_name, payload in sorted_index.items()
    }
    REPEATS_INDEX_PATH.write_text(json.dumps(summary, ensure_ascii=False, indent=2), encoding='utf-8')
    TE_LOCUS_INDEX_PATH.write_text(json.dumps(sorted_index, ensure_ascii=False), encoding='utf-8')
    TE_REPRESENTATIVE_INDEX_PATH.write_text(json.dumps(representative_index, ensure_ascii=False), encoding='utf-8')
    return summary


def inspect_bigbed(path: Path) -> dict:
    ensure_exists(path)
    with path.open('rb') as handle:
        magic = handle.read(4).hex()
    return {
        'path': str(path.relative_to(ROOT)).replace('\\', '/'),
        'size_bytes': path.stat().st_size,
        'magic_hex': magic,
    }


def main() -> None:
    manifest = {
        'fasta': build_fasta_index(),
        'gtf': summarize_gtf(),
        'repeats': build_repeats_track(),
        'clinvar_main': inspect_bigbed(CLINVAR_MAIN_PATH),
        'clinvar_cnv': inspect_bigbed(CLINVAR_CNV_PATH),
    }
    MANIFEST_PATH.write_text(json.dumps(manifest, ensure_ascii=False, indent=2), encoding='utf-8')
    print(json.dumps({
        'status': 'ok',
        'manifest': str(MANIFEST_PATH.relative_to(ROOT)).replace('\\', '/'),
        'repeats_track': str(REPEATS_BED_PATH.relative_to(ROOT)).replace('\\', '/'),
        'te_locus_index': str(TE_LOCUS_INDEX_PATH.relative_to(ROOT)).replace('\\', '/'),
    }, ensure_ascii=False, indent=2))


if __name__ == '__main__':
    main()
