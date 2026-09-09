const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.resolve(__dirname, '..', '..', '..');
const runtimeDataPath = path.join(root, 'assets', 'js', 'tekg_runtime_data.js');
const seedPath = path.join(root, 'data', 'processed', 'te_kg2_graph_seed.json');
const terminologyDir = path.join(root, 'data', 'terminology');
const jsonOut = path.join(terminologyDir, 'te_terminology.json');
const csvOut = path.join(terminologyDir, 'te_terminology.csv');
const reportOut = path.join(terminologyDir, 'missing_terminology_report.md');
const jsonReportOut = path.join(terminologyDir, 'missing_terminology_report.json');

const curatedNameAdditions = {
  zh: {
    "schizophrenia": "精神分裂症",
    "human disease": "人类疾病",
    "Amyotrophic lateral sclerosis": "肌萎缩侧索硬化症",
    "colorectal cancer": "结直肠癌",
    "Duchenne muscular dystrophy": "杜氏肌营养不良",
    "hepatocellular carcinoma": "肝细胞癌",
    "autoimmunity": "自身免疫",
    "Aicardi-Goutières syndrome": "艾卡迪-古铁雷斯综合征",
    "major depressive disorder": "重度抑郁障碍",
    "neurological disease": "神经系统疾病",
    "neurological conditions": "神经系统相关疾病",
    "Hepatitis C virus infection": "丙型肝炎病毒感染",
    "chronic inflammation": "慢性炎症",
    "esophageal adenocarcinoma": "食管腺癌",
    "Chromothripsis": "染色体碎裂",
    "Li-Fraumeni Syndrome": "李-弗劳梅尼综合征",
    "Fanconi Anemia": "范可尼贫血",
    "human cancers": "人类癌症",
    "human diseases": "人类疾病",
    "normal aging": "正常衰老",
    "Bardet-Biedl syndrome": "巴德-比德尔综合征",
    "inflammation": "炎症",
    "autoimmune disorders": "自身免疫性疾病",
    "age-related macular degeneration": "年龄相关性黄斑变性",
    "carcinoma": "癌",
    "seizures/epilepsy": "癫痫/癫痫发作",
    "EBV-associated gastric cancer": "EBV相关胃癌",
    "ageing": "衰老",
    "brain disorders": "脑部疾病",
    "carcinogenesis": "癌发生",
    "Hepatitis B virus infection": "乙型肝炎病毒感染",
    "idiopathic temporal lobe epilepsy": "特发性颞叶癫痫",
    "acquired immunodeficiency syndrome (AIDS)": "获得性免疫缺陷综合征（AIDS）",
    "AIDS": "艾滋病",
    "choroideremia": "脉络膜缺失症",
    "neurodegenerative diseases": "神经退行性疾病",
    "lung squamous cell carcinoma": "肺鳞状细胞癌",
    "frontotemporal lobar degeneration": "额颞叶变性",
    "substance abuse disorders": "物质滥用障碍",
    "neurodevelopmental disorders": "神经发育障碍",
    "mood disorders": "心境障碍",
    "geographic atrophy": "地图样萎缩",
    "cancers": "癌症",
    "Kaposi's sarcoma": "卡波西肉瘤",
    "primary effusion lymphoma": "原发性渗出性淋巴瘤",
    "heart disease": "心脏病",
    "FTD": "额颞叶痴呆",
    "ALS": "肌萎缩侧索硬化症",
    "HNSCC": "头颈部鳞状细胞癌",
    "lung cancer": "肺癌",
    "major depression": "重度抑郁",
    "major depression disorder": "重度抑郁障碍",
    "mental disorders": "精神障碍",
    "Angelman syndrome": "天使综合征",
    "Barrett's esophagus": "巴雷特食管",
    "bipolar disorder": "双相情感障碍",
    "Chagas disease": "查加斯病",
    "familial ALS": "家族性肌萎缩侧索硬化症",
    "leukemia": "白血病",
    "lung adenocarcinoma": "肺腺癌",
    "muscular dystrophy": "肌营养不良",
    "non-small cell lung cancer": "非小细胞肺癌",
    "Oral squamous cell carcinoma": "口腔鳞状细胞癌",
    "post-traumatic stress disorder": "创伤后应激障碍",
    "Prader-Willi syndrome (PWS)": "普拉德-威利综合征",
    "stroke": "脑卒中",
    "tauopathies": "tau蛋白病",
    "tuberous sclerosis": "结节性硬化症",
    "UCEC": "子宫内膜癌",
    "X-linked retinitis pigmentosa": "X连锁视网膜色素变性",
    "hemimegalencephaly": "半侧巨脑畸形",
    "late-onset AD": "迟发性阿尔茨海默病",
    "retinitis pigmentosa-2": "视网膜色素变性2型",
    "neuropsychiatric disorders": "神经精神障碍",
    "neurodevelopmental diseases": "神经发育疾病",
    "neurodegenerative conditions": "神经退行性疾病",
    "disease": "疾病",
    "ALS with other neurological disorder": "伴其他神经系统疾病的肌萎缩侧索硬化症",
    "atrophic macular degeneration": "萎缩性黄斑变性",
    "C9orf72 gene-mutated ALS": "C9orf72基因突变型肌萎缩侧索硬化症",
    "chronic human illness": "慢性人类疾病",
    "colorectal cancers": "结直肠癌",
    "colorectal tumourigenesis": "结直肠肿瘤发生",
    "human genetic disorders": "人类遗传病",
    "human pathogenesis": "人类致病过程",
    "neurological and psychiatric diseases": "神经及精神疾病",
    "neuropsychiatric conditions": "神经精神疾病",
    "sporadic ALS": "散发性肌萎缩侧索硬化症",
    "TLE": "颞叶癫痫",
    "HIV-1感染": "HIV-1感染",
    "HIV-associated neurocognitive disorder (HAND)": "HIV相关神经认知障碍",
    "HPV相关癌症": "HPV相关癌症",
    "NF1突变相关疾病": "NF1突变相关疾病",
    "de novo突变相关疾病": "de novo突变相关疾病"
  },
  en: {
    "精神分裂症": "schizophrenia",
    "人类疾病": "human disease",
    "肌萎缩侧索硬化症": "Amyotrophic lateral sclerosis",
    "结直肠癌": "colorectal cancer",
    "杜氏肌营养不良": "Duchenne muscular dystrophy",
    "肝细胞癌": "hepatocellular carcinoma",
    "自身免疫": "autoimmunity",
    "艾卡迪-古铁雷斯综合征": "Aicardi-Goutières syndrome",
    "重度抑郁障碍": "major depressive disorder",
    "神经系统疾病": "neurological disease",
    "神经系统相关疾病": "neurological conditions",
    "丙型肝炎病毒感染": "Hepatitis C virus infection",
    "慢性炎症": "chronic inflammation",
    "食管腺癌": "esophageal adenocarcinoma",
    "染色体碎裂": "Chromothripsis",
    "李-弗劳梅尼综合征": "Li-Fraumeni Syndrome",
    "范可尼贫血": "Fanconi Anemia",
    "人类癌症": "human cancers",
    "正常衰老": "normal aging",
    "巴德-比德尔综合征": "Bardet-Biedl syndrome",
    "炎症": "inflammation",
    "自身免疫性疾病": "autoimmune disorders",
    "年龄相关性黄斑变性": "age-related macular degeneration",
    "癌": "carcinoma",
    "癫痫/癫痫发作": "seizures/epilepsy",
    "EBV相关胃癌": "EBV-associated gastric cancer",
    "衰老": "ageing",
    "脑部疾病": "brain disorders",
    "癌发生": "carcinogenesis",
    "乙型肝炎病毒感染": "Hepatitis B virus infection",
    "特发性颞叶癫痫": "idiopathic temporal lobe epilepsy",
    "获得性免疫缺陷综合征（AIDS）": "acquired immunodeficiency syndrome (AIDS)",
    "艾滋病": "AIDS",
    "脉络膜缺失症": "choroideremia",
    "神经退行性疾病": "neurodegenerative diseases",
    "肺鳞状细胞癌": "lung squamous cell carcinoma",
    "额颞叶变性": "frontotemporal lobar degeneration",
    "物质滥用障碍": "substance abuse disorders",
    "神经发育障碍": "neurodevelopmental disorders",
    "心境障碍": "mood disorders",
    "地图样萎缩": "geographic atrophy",
    "癌症": "cancers",
    "卡波西肉瘤": "Kaposi's sarcoma",
    "原发性渗出性淋巴瘤": "primary effusion lymphoma",
    "心脏病": "heart disease",
    "额颞叶痴呆": "FTD",
    "头颈部鳞状细胞癌": "HNSCC",
    "肺癌": "lung cancer",
    "重度抑郁": "major depression",
    "精神障碍": "mental disorders",
    "血友病A": "hemophilia A",
    "血友病B": "hemophilia B",
    "天使综合征": "Angelman syndrome",
    "巴雷特食管": "Barrett's esophagus",
    "双相情感障碍": "bipolar disorder",
    "查加斯病": "Chagas disease",
    "家族性肌萎缩侧索硬化症": "familial ALS",
    "白血病": "leukemia",
    "肺腺癌": "lung adenocarcinoma",
    "肌营养不良": "muscular dystrophy",
    "非小细胞肺癌": "non-small cell lung cancer",
    "口腔鳞状细胞癌": "Oral squamous cell carcinoma",
    "创伤后应激障碍": "post-traumatic stress disorder",
    "普拉德-威利综合征": "Prader-Willi syndrome (PWS)",
    "脑卒中": "stroke",
    "tau蛋白病": "tauopathies",
    "结节性硬化症": "tuberous sclerosis",
    "子宫内膜癌": "UCEC",
    "X连锁视网膜色素变性": "X-linked retinitis pigmentosa",
    "半侧巨脑畸形": "hemimegalencephaly",
    "迟发性阿尔茨海默病": "late-onset AD",
    "视网膜色素变性2型": "retinitis pigmentosa-2",
    "神经精神障碍": "neuropsychiatric disorders",
    "神经发育疾病": "neurodevelopmental diseases",
    "神经退行性疾病": "neurodegenerative conditions",
    "疾病": "disease",
    "伴其他神经系统疾病的肌萎缩侧索硬化症": "ALS with other neurological disorder",
    "萎缩性黄斑变性": "atrophic macular degeneration",
    "C9orf72基因突变型肌萎缩侧索硬化症": "C9orf72 gene-mutated ALS",
    "慢性人类疾病": "chronic human illness",
    "结直肠肿瘤发生": "colorectal tumourigenesis",
    "人类遗传病": "human genetic disorders",
    "人类致病过程": "human pathogenesis",
    "神经及精神疾病": "neurological and psychiatric diseases",
    "神经精神疾病": "neuropsychiatric conditions",
    "散发性肌萎缩侧索硬化症": "sporadic ALS",
    "颞叶癫痫": "TLE",
    "HIV相关神经认知障碍": "HIV-associated neurocognitive disorder (HAND)"
  }
};

Object.assign(curatedNameAdditions.zh, {
  "genomic instability": "基因组不稳定性",
  "somatic retrotransposition": "体细胞逆转录转座",
  "LINE-1 retrotransposition": "LINE-1逆转录转座",
  "L1 retrotransposition": "L1逆转录转座",
  "DNA double-strand breaks": "DNA双链断裂",
  "somatic expression": "体细胞表达",
  "L1 endonuclease activity": "L1内切酶活性",
  "aberrant expression": "异常表达",
  "L1 expression": "L1表达",
  "gene disruption": "基因破坏",
  "insertional mutagenesis": "插入诱变",
  "somatic mosaicism": "体细胞嵌合",
  "LINE1-triggered immune activation": "LINE-1触发的免疫激活",
  "reverse transcription": "逆转录",
  "endonuclease activity": "核酸内切酶活性",
  "target-primed reverse transcription": "靶引发逆转录",
  "genomic integrity erosion": "基因组完整性侵蚀",
  "hyperactive retrotransposition": "高活性逆转录转座",
  "RT inhibition": "逆转录酶抑制",
  "retrotransposition-replication conflict": "逆转录转座-复制冲突",
  "L1 transcriptional repression": "L1转录抑制",
  "cytoplasmic cDNA synthesis": "胞质cDNA合成",
  "misregulation of expression": "表达失调",
  "retrotransposal integration": "逆转录转座整合",
  "de novo L1 insertion": "从头L1插入",
  "de novo L1 insertions": "从头L1插入",
  "de novo somatic retrotransposition": "从头体细胞逆转录转座",
  "reverse transcriptase activity": "逆转录酶活性",
  "altered retrotransposon expression": "逆转录转座子表达改变",
  "cell cycle arrest": "细胞周期停滞",
  "chromatin topology remodeling": "染色质拓扑重塑",
  "apoptosis": "细胞凋亡",
  "DNA damage response": "DNA损伤反应",
  "stress granule formation": "应激颗粒形成",
  "endonuclease-independent retrotransposition": "非内切酶依赖性逆转录转座",
  "retrotransposition repression": "逆转录转座抑制",
  "host defense factor restriction": "宿主防御因子限制",
  "reactivation": "再激活",
  "RNA binding": "RNA结合",
  "ORF1p expression": "ORF1p表达",
  "5' truncation": "5'端截短",
  "5' UTR activity": "5' UTR活性",
  "5' UTR promoter activity": "5' UTR启动子活性",
  "3' poly(A) tail dependence": "3' poly(A)尾依赖性",
  "3' UTR stem-loop structure": "3' UTR茎环结构",
  "3' UTR-dependent retrotransposition": "3' UTR依赖的逆转录转座",
  "target DNA cleavage": "靶向DNA切割",
  "protein-DNA interaction": "蛋白-DNA相互作用",
  "non-LTR retrotransposition": "非LTR逆转录转座",
  "DNA damage": "DNA损伤",
  "DNA methylation": "DNA甲基化",
  "DNA repair": "DNA修复",
  "DNA recognition": "DNA识别",
  "DNA deletion": "DNA缺失",
  "DNA integration": "DNA整合",
  "Alu retrotransposition": "Alu逆转录转座",
  "SINE retrotransposition": "SINE逆转录转座",
  "SINE transposition": "SINE转座",
  "Alu transposition": "Alu转座",
  "L1 promoter hypomethylation": "L1启动子低甲基化",
  "cytoplasmic DNA accumulation": "细胞质DNA积累",
  "innate immune response": "先天免疫反应",
  "interferon response": "干扰素反应",
  "immune activation": "免疫激活",
  "immune modulation": "免疫调节",
  "RNA editing": "RNA编辑",
  "RNA degradation": "RNA降解",
  "RNA processing": "RNA加工",
  "RNA methylation": "RNA甲基化",
  "RNA binding activity": "RNA结合活性",
  "transcriptional repression": "转录抑制",
  "transcriptional activation": "转录激活",
  "epigenetic regulation": "表观遗传调控",
  "epigenetic silencing": "表观遗传沉默",
  "genome rearrangement": "基因组重排",
  "chromosomal rearrangements": "染色体重排",
  "double-strand break repair": "双链断裂修复",
  "apoptosis induction": "诱导细胞凋亡",
  "gene expression alteration": "基因表达改变",
  "gene expression regulation": "基因表达调控",
  "cell proliferation": "细胞增殖",
  "cell migration": "细胞迁移",
  "retrotransposition activity": "逆转录转座活性",
  "retrotransposition inhibition": "逆转录转座抑制",
  "insertion mutation": "插入突变"
});

Object.assign(curatedNameAdditions.en, {
  "基因组不稳定性": "genomic instability",
  "体细胞逆转录转座": "somatic retrotransposition",
  "LINE-1逆转录转座": "LINE-1 retrotransposition",
  "L1逆转录转座": "L1 retrotransposition",
  "DNA双链断裂": "DNA double-strand breaks",
  "体细胞表达": "somatic expression",
  "L1内切酶活性": "L1 endonuclease activity",
  "异常表达": "aberrant expression",
  "L1表达": "L1 expression",
  "基因破坏": "gene disruption",
  "插入诱变": "insertional mutagenesis",
  "体细胞嵌合": "somatic mosaicism",
  "LINE-1触发的免疫激活": "LINE1-triggered immune activation",
  "逆转录": "reverse transcription",
  "核酸内切酶活性": "endonuclease activity",
  "靶引发逆转录": "target-primed reverse transcription",
  "基因组完整性侵蚀": "genomic integrity erosion",
  "高活性逆转录转座": "hyperactive retrotransposition",
  "逆转录酶抑制": "RT inhibition",
  "逆转录转座-复制冲突": "retrotransposition-replication conflict",
  "L1转录抑制": "L1 transcriptional repression",
  "胞质cDNA合成": "cytoplasmic cDNA synthesis",
  "表达失调": "misregulation of expression",
  "逆转录转座整合": "retrotransposal integration",
  "从头L1插入": "de novo L1 insertion",
  "从头体细胞逆转录转座": "de novo somatic retrotransposition",
  "逆转录酶活性": "reverse transcriptase activity",
  "逆转录转座子表达改变": "altered retrotransposon expression",
  "细胞周期停滞": "cell cycle arrest",
  "染色质拓扑重塑": "chromatin topology remodeling",
  "细胞凋亡": "apoptosis",
  "DNA损伤反应": "DNA damage response",
  "应激颗粒形成": "stress granule formation",
  "非内切酶依赖性逆转录转座": "endonuclease-independent retrotransposition",
  "逆转录转座抑制": "retrotransposition repression",
  "宿主防御因子限制": "host defense factor restriction",
  "再激活": "reactivation",
  "RNA结合": "RNA binding",
  "ORF1p表达": "ORF1p expression",
  "5'端截短": "5' truncation",
  "5' UTR活性": "5' UTR activity",
  "5' UTR启动子活性": "5' UTR promoter activity",
  "3' poly(A)尾依赖性": "3' poly(A) tail dependence",
  "3' UTR茎环结构": "3' UTR stem-loop structure",
  "3' UTR依赖的逆转录转座": "3' UTR-dependent retrotransposition",
  "靶向DNA切割": "target DNA cleavage",
  "蛋白-DNA相互作用": "protein-DNA interaction",
  "非LTR逆转录转座": "non-LTR retrotransposition",
  "DNA损伤": "DNA damage",
  "DNA甲基化": "DNA methylation",
  "DNA修复": "DNA repair",
  "DNA识别": "DNA recognition",
  "DNA缺失": "DNA deletion",
  "DNA整合": "DNA integration",
  "Alu逆转录转座": "Alu retrotransposition",
  "SINE逆转录转座": "SINE retrotransposition",
  "SINE转座": "SINE transposition",
  "Alu转座": "Alu transposition",
  "L1启动子低甲基化": "L1 promoter hypomethylation",
  "细胞质DNA积累": "cytoplasmic DNA accumulation",
  "先天免疫反应": "innate immune response",
  "干扰素反应": "interferon response",
  "免疫激活": "immune activation",
  "免疫调节": "immune modulation",
  "RNA编辑": "RNA editing",
  "RNA降解": "RNA degradation",
  "RNA加工": "RNA processing",
  "RNA甲基化": "RNA methylation",
  "RNA结合活性": "RNA binding activity",
  "转录抑制": "transcriptional repression",
  "转录激活": "transcriptional activation",
  "表观遗传调控": "epigenetic regulation",
  "表观遗传沉默": "epigenetic silencing",
  "基因组重排": "genome rearrangement",
  "染色体重排": "chromosomal rearrangements",
  "双链断裂修复": "double-strand break repair",
  "诱导细胞凋亡": "apoptosis induction",
  "基因表达改变": "gene expression alteration",
  "基因表达调控": "gene expression regulation",
  "细胞增殖": "cell proliferation",
  "细胞迁移": "cell migration",
  "逆转录转座活性": "retrotransposition activity",
  "逆转录转座抑制": "retrotransposition inhibition",
  "插入突变": "insertion mutation",
  "靶基因组DNA缺失": "target genomic DNA deletion",
  "被L1机制识别": "recognized by the L1 machinery",
  "病毒RNA逆转录与整合": "viral RNA reverse transcription and integration",
  "产生嵌合L1转录本": "generation of chimeric L1 transcripts",
  "创建CpG岛": "CpG island creation",
  "单链DNA断裂": "single-strand DNA breaks",
  "调节免疫刺激性SINE表达": "regulation of immunostimulatory SINE expression",
  "反式介导的细胞RNA动员": "trans-mediated cellular RNA mobilization",
  "非编码RNA调控": "noncoding RNA regulation",
  "非参考L1多态性插入": "non-reference L1 polymorphic insertion",
  "干扰素（IFN）产生": "interferon (IFN) production",
  "核酸内切酶非依赖性LINE-1逆转录转座": "endonuclease-independent LINE-1 retrotransposition",
  "核糖核蛋白（RNP）颗粒形成": "ribonucleoprotein (RNP) granule formation",
  "基因组DNA不稳定性": "genomic DNA instability",
  "基因组DNA缺失": "genomic DNA deletion",
  "基因组DNA损伤": "genomic DNA damage",
  "交错DNA双链断裂修复": "staggered DNA double-strand break repair",
  "结合LINE-1 RNP复合体": "binding to the LINE-1 RNP complex",
  "截短的LINE-1逆转录转座": "truncated LINE-1 retrotransposition",
  "介导非L1 RNA转座": "mobilization of non-L1 RNA",
  "介导mRNA逆转座": "mRNA retrotransposition",
  "经典的两击CRC通路": "classical two-hit CRC pathway",
  "利用切割的poly-T链作为引物": "use of the nicked poly-T strand as primer",
  "利用DNA修复机制": "use of DNA repair mechanisms",
  "慢性DNA损伤": "chronic DNA damage",
  "内源性L1 mRNA水平": "endogenous L1 mRNA level",
  "逆转录转座相关DNA损伤": "retrotransposition-associated DNA damage",
  "年龄相关的LINE-1拷贝数变化": "age-related LINE-1 copy number changes",
  "启动子区CpG甲基化降低": "reduced CpG methylation in the promoter region",
  "嵌合L1形成": "chimeric L1 formation",
  "驱动Alu和SVA转座": "drives Alu and SVA mobilization",
  "全基因组DNA甲基化标志物": "genome-wide DNA methylation marker",
  "缺乏RNase H活性": "lack of RNase H activity",
  "染色体外DNA积累": "extrachromosomal DNA accumulation",
  "宿主DNA修复因子": "host DNA repair factors",
  "体细胞L1逆转录转座": "somatic L1 retrotransposition",
  "体细胞RNA基因重组": "somatic RNA gene recombination",
  "体细胞TE插入": "somatic TE insertion",
  "外源DNA整合": "exogenous DNA integration",
  "无义介导的mRNA降解": "nonsense-mediated mRNA decay",
  "形成和运输L1核糖核蛋白颗粒": "formation and transport of L1 ribonucleoprotein particles",
  "形成L1核糖核蛋白": "formation of L1 ribonucleoprotein",
  "序列特异性DNA识别": "sequence-specific DNA recognition",
  "亚细胞RNA分区": "subcellular RNA partitioning",
  "异常mRNA剪接": "aberrant mRNA splicing",
  "影响DNA甲基化": "affects DNA methylation",
  "与非编码RNA相关": "associated with noncoding RNA",
  "在G1/S期阻滞细胞中转座": "retrotransposition in G1/S-arrested cells"
});

const curatedRelationAdditions = {
  zh: {},
  en: {}
};

function extractMapBlock(source, marker, nextMarker) {
  const start = source.indexOf(marker);
  const end = source.indexOf(nextMarker, start);
  if (start === -1 || end === -1) {
    throw new Error(`Unable to extract block between ${marker} and ${nextMarker}`);
  }
  return source.slice(start, end);
}

function evaluateMaps(source) {
  const relBlock = extractMapBlock(source, 'const relLabel =', 'const nameMap =');
  const nameBlock = extractMapBlock(source, 'const nameMap =', 'const descMap =');
  const script = `
    ${relBlock}
    ${nameBlock}
    module.exports = { relLabel, nameMap };
  `;
  const sandbox = { module: { exports: {} }, exports: {} };
  vm.createContext(sandbox);
  vm.runInContext(script, sandbox);
  return sandbox.module.exports;
}

function normalize(text) {
  return String(text || '').trim();
}

function hasChinese(text) {
  return /[\u4e00-\u9fff]/.test(String(text || ''));
}

function hasAsciiLetters(text) {
  return /[A-Za-z]/.test(String(text || ''));
}

function csvEscape(value) {
  const text = String(value ?? '');
  if (/[",\n]/.test(text)) {
    return `"${text.replace(/"/g, '""')}"`;
  }
  return text;
}

function uniqueSorted(values) {
  return Array.from(new Set(values.filter(Boolean))).sort((a, b) => a.localeCompare(b));
}

function buildTerminologyPayload(nameMap, relLabel) {
  return {
    version: 3,
    generated_from: 'assets/js/tekg_runtime_data.js',
    generated_at: new Date().toISOString(),
    names: {
      zh: { ...(nameMap.zh || {}), ...(curatedNameAdditions.zh || {}) },
      en: { ...(nameMap.en || {}), ...(curatedNameAdditions.en || {}) },
    },
    relations: {
      zh: { ...(relLabel.zh || {}), ...(curatedRelationAdditions.zh || {}) },
      en: { ...(relLabel.en || {}), ...(curatedRelationAdditions.en || {}) },
    },
  };
}

function writeTerminologyCsv(payload) {
  const rows = ['category,key,value'];
  for (const [k, v] of Object.entries(payload.names.zh || {})) {
    rows.push([ 'name_zh', k, v ].map(csvEscape).join(','));
  }
  for (const [k, v] of Object.entries(payload.names.en || {})) {
    rows.push([ 'name_en', k, v ].map(csvEscape).join(','));
  }
  for (const [k, v] of Object.entries(payload.relations.zh || {})) {
    rows.push([ 'relation_zh', k, v ].map(csvEscape).join(','));
  }
  for (const [k, v] of Object.entries(payload.relations.en || {})) {
    rows.push([ 'relation_en', k, v ].map(csvEscape).join(','));
  }
  fs.writeFileSync(csvOut, rows.join('\n'), 'utf8');
}

function collectSeedTerms(seed) {
  const nodes = seed.nodes || {};
  const relations = seed.relations || [];

  const namesByType = {
    TE: [],
    Disease: [],
    Function: [],
    Paper: [],
  };

  for (const item of nodes.transposons || []) namesByType.TE.push(normalize(item.name));
  for (const item of nodes.diseases || []) namesByType.Disease.push(normalize(item.name));
  for (const item of nodes.functions || []) namesByType.Function.push(normalize(item.name));
  for (const item of nodes.papers || []) namesByType.Paper.push(normalize(item.name));

  const relationLabels = uniqueSorted(relations.map(r => normalize(r.predicate)));

  return { namesByType, relationLabels };
}

function buildMissingReport(payload, seedTerms) {
  const zhMap = payload.names.zh || {};
  const enMap = payload.names.en || {};
  const relZhMap = payload.relations.zh || {};
  const relEnMap = payload.relations.en || {};

  const missing = {
    zh_mode_exposed_english: {},
    en_mode_exposed_chinese: {},
    missing_relation_zh: [],
    missing_relation_en: [],
  };

  for (const [type, names] of Object.entries(seedTerms.namesByType)) {
    missing.zh_mode_exposed_english[type] = uniqueSorted(
      names.filter(name => hasAsciiLetters(name) && !zhMap[name])
    );
    missing.en_mode_exposed_chinese[type] = uniqueSorted(
      names.filter(name => hasChinese(name) && !enMap[name])
    );
  }

  missing.missing_relation_zh = uniqueSorted(
    seedTerms.relationLabels.filter(label => hasAsciiLetters(label) && !relZhMap[label])
  );
  missing.missing_relation_en = uniqueSorted(
    seedTerms.relationLabels.filter(label => hasChinese(label) && !relEnMap[label])
  );

  return missing;
}

function writeMissingReportMarkdown(report) {
  const lines = [];
  lines.push('# 术语表漏项清单');
  lines.push('');
  lines.push('这个清单用于补齐中英术语表。优先处理“中文模式下露出英文”和“英文模式下露出中文”的高频节点。');
  lines.push('');

  for (const [type, items] of Object.entries(report.zh_mode_exposed_english)) {
    lines.push(`## 中文模式下仍会露出英文：${type}`);
    lines.push('');
    if (!items.length) {
      lines.push('- 无');
    } else {
      for (const item of items) lines.push(`- ${item}`);
    }
    lines.push('');
  }

  for (const [type, items] of Object.entries(report.en_mode_exposed_chinese)) {
    lines.push(`## 英文模式下仍会露出中文：${type}`);
    lines.push('');
    if (!items.length) {
      lines.push('- 无');
    } else {
      for (const item of items) lines.push(`- ${item}`);
    }
    lines.push('');
  }

  lines.push('## 中文模式缺少关系映射');
  lines.push('');
  if (!report.missing_relation_zh.length) {
    lines.push('- 无');
  } else {
    for (const item of report.missing_relation_zh) lines.push(`- ${item}`);
  }
  lines.push('');

  lines.push('## 英文模式缺少关系映射');
  lines.push('');
  if (!report.missing_relation_en.length) {
    lines.push('- 无');
  } else {
    for (const item of report.missing_relation_en) lines.push(`- ${item}`);
  }
  lines.push('');

  fs.writeFileSync(reportOut, lines.join('\n'), 'utf8');
}

function main() {
  const runtimeDataSource = fs.readFileSync(runtimeDataPath, 'utf8');
  const { relLabel, nameMap } = evaluateMaps(runtimeDataSource);
  const payload = buildTerminologyPayload(nameMap, relLabel);
  fs.writeFileSync(jsonOut, JSON.stringify(payload, null, 2), 'utf8');
  writeTerminologyCsv(payload);

  const seed = JSON.parse(fs.readFileSync(seedPath, 'utf8'));
  const seedTerms = collectSeedTerms(seed);
  const report = buildMissingReport(payload, seedTerms);
  fs.writeFileSync(jsonReportOut, JSON.stringify(report, null, 2), 'utf8');
  writeMissingReportMarkdown(report);

  console.log(`Wrote ${path.basename(jsonOut)}`);
  console.log(`Wrote ${path.basename(csvOut)}`);
  console.log(`Wrote ${path.basename(reportOut)}`);
  console.log(`Wrote ${path.basename(jsonReportOut)}`);
}

main();
