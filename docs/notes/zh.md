reference\archive\frontend\index_demo_clean_from_git.html:
  113      const nodeDetails = el('node-details'), searchInput = el('node-search'), nav = el('search-results-nav'), prevBtn = el('prev-result'), nextBtn = el('next-result'), resultCounter = el('result-counter'), resultName = el('result-name'), chatMessages = el('chat-messages'), userInput = el('user-question'), sendBtn = el('send-question');
  114:     let currentLang = 'zh', currentAnswerStyle = 'simple', currentAnswerDepth = 'shallow', searchResults = [], currentResultIndex = -1, focusLevel = 0, searchDebounceId = null;
  115      const ui = {zh:{pageTitle:'转座元件知识图谱演示',badge:'LINE-1 本地图谱子图',graphTitle:'图谱可视化',qaTitle:'智能问答演示',search:'搜索 LINE-1、L1HS、疾病或功能',reset:'重置',footer:'演示页使用本地导出的 Neo4j 子图数据，不依赖在线接口。',empty:'点击节点或连线查看图谱详情。',orig:'原始名称',deg:'连接数',evi:'证据',none:'当前未列出',noneLocal:'当前没有检索到本地证据。',ph:'输入你的问题',q:'LINE-1 相关疾病',intro:'当前演示页已接入本地图谱子图。你可以提问：<br>“LINE-1 相关功能”<br>“LINE-1 相关疾病”<br>“L1HS 和 LINE-1 是什么关系”<br>“哪些文献支持 LINE-1 与阿尔茨海默病相关”',f:'根据当前本地图谱，LINE-1 主要涉及：',d:'根据当前本地图谱，LINE-1 与以下疾病关系较突出：',lp:'当前图谱已为 L1HS 整理出本地文献证据。',pp:'当前图谱已为 LINE-1 整理出本地文献证据。',fallback:'当前演示页支持围绕 LINE-1 的功能、疾病、谱系关系和文献证据查询。',ref:'证据来源',no:'暂无结果。',focus:'聚焦程度',focusHint:'高聚焦会更像手动滚轮放大'},en:{pageTitle:'TE Knowledge Graph Demo',badge:'LINE-1 Local Subgraph',graphTitle:'Graph View',qaTitle:'QA Demo',search:'Search LINE-1, L1HS, diseases, or functions',reset:'Reset',footer:'This demo uses a locally exported Neo4j subgraph and does not depend on online services.',empty:'Click a node or edge to inspect graph details.',orig:'Original name',deg:'Degree',evi:'Evidence',none:'Not listed',noneLocal:'No local evidence was found.',ph:'Ask a question',q:'LINE-1 related diseases',intro:'This demo is grounded on a local graph subnetwork. You can ask:<br>"LINE-1 related functions"<br>"LINE-1 related diseases"<br>"What is the relationship between L1HS and LINE-1?"<br>"What papers support the association between LINE-1 and Alzheimer\'s disease?"',f:'Based on the current local graph, LINE-1 is mainly involved in: ',d:'Based on the current local graph, LINE-1 is notably associated with: ',lp:'The current graph already includes local literature evidence for L1HS.',pp:'The current graph already includes local literature evidence for LINE-1.',fallback:'This demo currently supports LINE-1-centered questions about functions, diseases, lineage relations, and literature evidence.',ref:'Evidence',no:'No results.',focus:'Focus level',focusHint:'Higher focus behaves more like repeated wheel zoom'}}; 

  126        if(mapped) return mapped;
  127:       if(currentLang==='zh') return pmid ? `文献 PMID:${pmid}` : '文献节点';
  128        if(containsChinese(name)) return pmid ? `Paper PMID:${pmid}` : 'Paper node';

  151      function buildDefaultDesc(name,type,pmid=''){
  152:       if(type==='TE') return currentLang==='zh' ? `${getName(name,type,'',pmid)} \u662f\u5f53\u524d\u77e5\u8bc6\u56fe\u8c31\u4e2d\u7684\u8f6c\u5ea7\u5143\u4ef6\u8282\u70b9\uff0c\u7528\u4e8e\u8fde\u63a5\u8c31\u7cfb\u3001\u529f\u80fd\u3001\u75be\u75c5\u548c\u6587\u732e\u8bc1\u636e\u3002` : `${getName(name,type,'',pmid)} is a transposable element node used to connect lineage, function, disease, and literature evidence in the graph.`;
  153:       if(type==='Function') return currentLang==='zh' ? `${getName(name,type,'',pmid)} \u662f\u5f53\u524d\u77e5\u8bc6\u56fe\u8c31\u4e2d\u7684\u529f\u80fd\u6216\u673a\u5236\u8282\u70b9\u3002` : `${getName(name,type,'',pmid)} is a function or mechanism node in the current graph.`;
  154:       if(type==='Disease') return currentLang==='zh' ? `${getName(name,type,'',pmid)} \u662f\u5f53\u524d\u77e5\u8bc6\u56fe\u8c31\u4e2d\u7684\u75be\u75c5\u8282\u70b9\u3002` : `${getName(name,type,'',pmid)} is a disease node in the current graph.`;
  155:       if(type==='Paper') return currentLang==='zh' ? `\u8be5\u8282\u70b9\u8868\u793a\u652f\u6491\u5f53\u524d\u56fe\u8c31\u5173\u7cfb\u7684\u6587\u732e\u8bb0\u5f55${pmid ? `\uff08PMID: ${pmid}\uff09` : ''}\u3002` : `This node represents a literature record supporting the current graph knowledge${pmid ? ` (PMID: ${pmid})` : ''}.`;
  156        return '';

  196        if(!payload.elements || payload.elements.length===0){
  197:         throw new Error(currentLang==='zh' ? '未找到相关节点或文献子图。' : 'No matching graph fragment was found.');
  198        }

  207        const depthShallowBtn=el('depth-shallow'), depthMediumBtn=el('depth-medium'), depthDeepBtn=el('depth-deep');
  208:       el('qa-mode-label').textContent=currentLang==='zh'?'回答模式':'Answer mode';
  209:       el('qa-depth-label').textContent=currentLang==='zh'?'回答深度':'Answer depth';
  210        if(modeSimpleBtn&&modeDetailedBtn){
  211:         modeSimpleBtn.textContent=currentLang==='zh'?'简单':'Brief';
  212:         modeDetailedBtn.textContent=currentLang==='zh'?'详细':'Detailed';
  213          modeSimpleBtn.classList.toggle('active', currentAnswerStyle==='simple');

  216        if(depthShallowBtn&&depthMediumBtn&&depthDeepBtn){
  217:         depthShallowBtn.textContent=currentLang==='zh'?'浅':'Shallow';
  218:         depthMediumBtn.textContent=currentLang==='zh'?'中':'Medium';
  219:         depthDeepBtn.textContent=currentLang==='zh'?'深':'Deep';
  220          depthShallowBtn.classList.toggle('active', currentAnswerDepth==='shallow');

  227      function setAnswerDepth(depth){if(['shallow','medium','deep'].includes(depth)) currentAnswerDepth=depth; updateAnswerModeUi();}
  228:     function setUi(){document.documentElement.lang=currentLang==='zh'?'zh-CN':'en'; el('page-title').textContent=ui[currentLang].pageTitle; el('page-badge').textContent=ui[currentLang].badge; el('graph-title').textContent=ui[currentLang].graphTitle; el('qa-title').textContent=ui[currentLang].qaTitle; el('page-footer').textContent=ui[currentLang].footer; el('reset-text').textContent=ui[currentLang].reset; searchInput.placeholder=ui[currentLang].search; userInput.placeholder=ui[currentLang].ph; if(!userInput.value || userInput.value===ui.zh.q || userInput.value===ui.en.q) userInput.value=ui[currentLang].q; el('lang-zh').classList.toggle('active', currentLang==='zh'); el('lang-en').classList.toggle('active', currentLang==='en'); updateAnswerModeUi(); updateFocusUi(); if(searchResults.length===0) nodeDetails.textContent=ui[currentLang].empty; else showNode(searchResults[currentResultIndex]); renderIntro(); cy.style().update(); updateNav();}
  229      function showNode(node){const raw=node.data('label'), type=node.data('type') || 'Unknown', description=node.data('description')||'', pmid=node.data('pmid')||''; nodeDetails.innerHTML=`<strong>${getName(raw,type,description,pmid)}</strong> (${getType(type)})<br>${getDesc(raw,type,description,pmid)}<div class="meta">${ui[currentLang].orig}: ${raw}${pmid ? ` | PMID: ${pmid}` : ''} | ${ui[currentLang].deg}: ${node.degree()}</div>`}

  336      function answerLocal(q){
  337:       const modeLabel=currentLang==='zh'
  338          ? (currentAnswerStyle==='detailed'?'详细模式':'简单模式')
  339          : (currentAnswerStyle==='detailed'?'Detailed mode':'Brief mode');
  340:       const depthLabel=currentLang==='zh' ? ({shallow:'浅',medium:'中',deep:'深'}[currentAnswerDepth]||'浅') : ({shallow:'Shallow',medium:'Medium',deep:'Deep'}[currentAnswerDepth]||'Shallow');
  341:       const intro=`> ${modeLabel} · ${currentLang==='zh' ? '回答深度：' : 'Depth: '}${depthLabel}\n\n`;
  342        const lower=(q||'').toLowerCase();

  350        const buildDetailed=(title, items, refs, limitItems=8, limitRefs=8)=>{
  351:         const bulletLines=(items||[]).slice(0,limitItems).map(i=>`- ${getRel(i.predicate)} ${getName(i.name, inferType(i.name))}`).join('\n') || (currentLang==='zh' ? '- 当前未检索到可展开的本地结构化记录。' : '- No expandable local structured records were found.');
  352:         const refLines=(refs||[]).slice(0,limitRefs).map(formatRef).join('\n') || (currentLang==='zh' ? '当前暂无本地参考文献。' : 'No local references are currently available.');
  353:         if(currentLang==='zh'){
  354            return `${intro}## 结论\n${title}\n\n## 机制与关系解释\n${bulletLines}\n\n## 证据与文献\n${refLines}\n\n## 局限与说明\n- 当前结果来自本地图谱子图，完整答案仍以后端知识检索为准。\n- 若动态图谱未完全展开，部分关系可能未显示在当前页面。`;

  358        const buildSimple=(lead, items, refs, limitItems=5, limitRefs=5)=>{
  359:         const body=(items||[]).slice(0,limitItems).map(i=>`${getRel(i.predicate)} ${getName(i.name, inferType(i.name))}`).join(currentLang==='zh'?'；':'; ');
  360          const refsHtml=makeRef((refs||[]).slice(0,limitRefs));

  366          if(currentAnswerStyle==='detailed'){
  367:           return currentLang==='zh'
  368              ? `${intro}## 结论\nL1HS 是 LINE-1 谱系中的一个亚家族节点，当前本地图谱把它作为更细粒度的转座子分支来展示。\n\n## 机制与关系解释\n- L1HS 通过 SUBFAMILY_OF 关系连接到 LINE-1。\n- 这种关系属于分类/谱系关系，而不是疾病或功能作用关系。\n- 保留亚家族层有助于区分总家族与具体活跃分支。\n\n## 证据与文献\n${refs.slice(0,6).map(formatRef).join('\n') || '当前暂无本地参考文献。'}\n\n## 局限与说明\n- 当前页面优先展示局部子图，更多邻域需要继续展开。\n- 更完整的证据应以后端 Neo4j 检索和问答结果为准。`

  370          }
  371:         return `${intro}${currentLang==='zh' ? 'L1HS 与 LINE-1 的关系是亚家族与总家族之间的谱系关系。' : 'L1HS is connected to LINE-1 as a subfamily within the same lineage.'}${makeRef(refs.slice(0,4))}`;
  372        }

  376          return currentAnswerStyle==='detailed'
  377:           ? buildDetailed(currentLang==='zh' ? '根据当前本地图谱，LINE-1 相关功能主要涉及逆转录转座、序列转导、插入突变及与其他转座元件协同的过程。' : 'According to the current local graph, LINE-1-related functions mainly involve retrotransposition, sequence transduction, insertional mutagenesis, and interactions with other transposable elements.', items, items)
  378            : buildSimple(ui[currentLang].f, items, items);

  383          return currentAnswerStyle==='detailed'
  384:           ? buildDetailed(currentLang==='zh' ? '根据当前本地图谱，LINE-1 与多种神经系统疾病、遗传综合征及肿瘤相关疾病存在结构化关联。' : 'According to the current local graph, LINE-1 shows structured associations with multiple neurological diseases, genetic syndromes, and cancers.', items, items)
  385            : buildSimple(ui[currentLang].d, items, items);

  390          return currentAnswerStyle==='detailed'
  391:           ? buildDetailed(currentLang==='zh' ? '当前本地图谱已经整理出与 L1HS 相关的文献证据，可用于支撑谱系、功能或疾病关系的解释。':'The current local graph already contains literature evidence associated with L1HS.', refs, refs, 6, 8)
  392            : `${intro}${ui[currentLang].lp}${makeRef(refs.slice(0,5))}`;

  397          return currentAnswerStyle==='detailed'
  398:           ? buildDetailed(currentLang==='zh' ? '当前本地图谱已经整理出与 LINE-1 相关的核心文献，可用于支撑疾病、功能和谱系层面的解释。':'The current local graph already contains core literature evidence associated with LINE-1.', refs, refs, 6, 8)
  399            : `${intro}${ui[currentLang].pp}${makeRef(refs.slice(0,5))}`;

  402        return currentAnswerStyle==='detailed'
  403:         ? (currentLang==='zh'
  404            ? `${intro}## 结论\n当前本地演示图支持围绕 LINE-1 的疾病、功能、谱系关系与文献证据问答。\n\n## 机制与关系解释\n- 你可以直接询问“LINE-1 相关疾病”“LINE-1 相关功能”。\n- 也可以询问“L1HS 和 LINE-1 是什么关系”。\n- 若需要证据，可继续追问文献支持情况。\n\n## 证据与文献\n当前回答来自本地演示图；更完整答案以后端知识检索与大模型生成结果为准。\n\n## 局限与说明\n- 这是本地回退回答，不代表完整数据库检索结果。\n- 当后端恢复后，详细模式会返回更完整的证据化回答。`

  408      function formatBackendAnswer(result){
  409:       const styleLabel=currentLang==='zh'?(result.answer_style==='detailed'?'详细模式':'简单模式'):(result.answer_style==='detailed'?'Detailed mode':'Brief mode');
  410:       const depthLabel=currentLang==='zh'
  411          ? ({shallow:'浅',medium:'中',deep:'深'}[result.answer_depth] || '浅')
  412          : ({shallow:'Shallow',medium:'Medium',deep:'Deep'}[result.answer_depth] || 'Shallow');
  413:       const prefix = currentLang==='zh'
  414          ? `> ${styleLabel} · 回答深度：${depthLabel}\n\n`

  428      el('reset-graph').addEventListener('click',()=>{restoreInitialGraph();});
  429:     sendBtn.addEventListener('click',async()=>{const q=userInput.value.trim(); if(!q) return; addMessage(q,'user'); userInput.value=''; const loading=addMessage(currentLang==='zh'?'正在检索图数据库并生成回答…':'Retrieving graph evidence and generating the answer…','assistant'); try{const answer=await answerWithBackend(q); loading.remove(); addMessage(answer,'assistant');}catch(err){loading.remove(); const prefix=currentLang==='zh'?'后端暂未连通，当前已回退到本地规则回答。<div class="ref">':'Backend unavailable. The UI has fallen back to local rule-based answering.<div class="ref">'; const suffix=`${err && err.message ? err.message : 'unknown error'}</div>`; setTimeout(()=>addMessage(prefix + suffix + answerLocal(q),'assistant'),120);}});
  430      userInput.addEventListener('keypress',e => { if(e.key==='Enter') sendBtn.click(); });
  431      document.addEventListener('click',e => { if(e.target && e.target.id==='mode-simple') setAnswerStyle('simple'); if(e.target && e.target.id==='mode-detailed') setAnswerStyle('detailed'); if(e.target && e.target.id==='depth-shallow') setAnswerDepth('shallow'); if(e.target && e.target.id==='depth-medium') setAnswerDepth('medium'); if(e.target && e.target.id==='depth-deep') setAnswerDepth('deep'); });
  432:     el('lang-zh').addEventListener('click',()=>{currentLang='zh'; setUi();}); el('lang-en').addEventListener('click',()=>{currentLang='en'; setUi();});
  433      cy.on('layoutstop',()=>{refineGraphLayout(); cy.fit(undefined,55);}); repairModeControls(); setAnswerStyle('simple'); setUi(); el('focus-level').value=String(focusLevel); restoreInitialGraph();

reference\frontend-backup\legacy-shell-2026-04-03\about.php:
   3  $lang = site_lang();
   4: $pageTitle = site_t(['zh' => '关于 - TEKG', 'en' => 'About - TEKG'], $lang);
   5  $activePage = 'about';

   8  <section class="hero-card">
   9:   <h2 class="page-title"><?= htmlspecialchars(site_t(['zh' => '关于', 'en' => 'About'], $lang), ENT_QUOTES, 'UTF-8') ?></h2>
  10    <p class="page-desc">
  11      <?= htmlspecialchars(site_t([
  12:       'zh' => 'TE-KG 是一个围绕转座元件构建的知识图谱数据库原型，用于组织 TE、疾病、功能机制与文献证据之间的结构化关系，并通过图谱浏览、实体检索与智能问答进行展示。',
  13        'en' => 'TE-KG is a prototype knowledge graph database centered on transposable elements. It organizes structured relationships among TEs, diseases, functions/mechanisms, and literature evidence through graph exploration, entity search, and QA.'

  20      <div class="section-card">
  21:       <h3><?= htmlspecialchars(site_t(['zh' => '项目定位', 'en' => 'Project Positioning'], $lang), ENT_QUOTES, 'UTF-8') ?></h3>
  22        <p><?= htmlspecialchars(site_t([
  23:         'zh' => '本项目面向课程展示与数据库原型验证场景，目标是把分散在文献、参考资源和抽取结果中的 TE 相关信息组织成可查询、可解释、可视化的知识图谱系统。',
  24          'en' => 'This project serves as a course demo and database prototype. Its goal is to organize TE-related information scattered across literature, reference resources, and extraction results into a queryable, explainable, and visual knowledge graph system.'

  28      <div class="section-card">
  29:       <h3><?= htmlspecialchars(site_t(['zh' => '建设思路', 'en' => 'Construction Approach'], $lang), ENT_QUOTES, 'UTF-8') ?></h3>
  30        <p><?= htmlspecialchars(site_t([
  31:         'zh' => '系统从文献抽取与参考资源整理开始，对实体名称、关系词和描述进行标准化处理，再导入图数据库，最终通过多页面网站提供图谱预览、搜索、下载与问答能力。',
  32          'en' => 'The system starts from literature extraction and reference resource curation, standardizes entity names, relations, and descriptions, imports the results into a graph database, and finally exposes graph preview, search, download, and QA through a multi-page website.'

  34        <ul>
  35:         <li><?= htmlspecialchars(site_t(['zh' => '文本侧负责抽取 TE、疾病、功能和文献实体。', 'en' => 'The text side is responsible for extracting TE, disease, function, and literature entities.'], $lang), ENT_QUOTES, 'UTF-8') ?></li>
  36:         <li><?= htmlspecialchars(site_t(['zh' => '图数据库负责组织层级关系、生物学关系与证据关系。', 'en' => 'The graph database organizes lineage, biological, and evidence relations.'], $lang), ENT_QUOTES, 'UTF-8') ?></li>
  37:         <li><?= htmlspecialchars(site_t(['zh' => '网页端负责图谱展示、实体搜索、资源下载与答辩演示。', 'en' => 'The web layer supports graph display, entity search, data download, and presentation.'], $lang), ENT_QUOTES, 'UTF-8') ?></li>
  38        </ul>

  41      <div class="section-card">
  42:       <h3><?= htmlspecialchars(site_t(['zh' => '数据库结构', 'en' => 'Database Structure'], $lang), ENT_QUOTES, 'UTF-8') ?></h3>
  43        <p><?= htmlspecialchars(site_t([
  44:         'zh' => '当前数据库以 TE、Disease、Function 和 Paper 四类核心节点为基础。系统同时维护 TE 层级关系、跨实体生物学关系以及文献证据关系，使图谱既能回答“谁和谁有关”，也能回答“为什么有关”。',
  45          'en' => 'The current database is built around four core node types: TE, Disease, Function, and Paper. It maintains TE lineage relations, cross-entity biological relations, and literature evidence relations, allowing the graph to answer both “what is connected” and “why it is connected.”'

  49      <div class="section-card">
  50:       <h3><?= htmlspecialchars(site_t(['zh' => '可视化与问答', 'en' => 'Visualization and QA'], $lang), ENT_QUOTES, 'UTF-8') ?></h3>
  51        <p><?= htmlspecialchars(site_t([
  52:         'zh' => '前端支持默认 TE 树、局部图谱展开、搜索驱动的局部关系图，以及结合图数据库检索结果的智能问答。问答模块优先基于本地知识图谱上下文回答，并可切换模型与回答策略。',
  53          'en' => 'The frontend supports a default TE tree, local graph expansion, search-driven local relationship views, and graph-grounded QA. The QA module prioritizes local graph context and can switch between response strategies and models.'

  59      <div class="section-card">
  60:       <h3><?= htmlspecialchars(site_t(['zh' => '当前系统骨架', 'en' => 'Current System Skeleton'], $lang), ENT_QUOTES, 'UTF-8') ?></h3>
  61        <div class="mini-stat">
  62:         <div class="mini-stat-row"><span><?= htmlspecialchars(site_t(['zh' => '核心节点', 'en' => 'Core nodes'], $lang), ENT_QUOTES, 'UTF-8') ?></span><strong>TE / Disease / Function / Paper</strong></div>
  63:         <div class="mini-stat-row"><span><?= htmlspecialchars(site_t(['zh' => '核心关系', 'en' => 'Core relations'], $lang), ENT_QUOTES, 'UTF-8') ?></span><strong>SUBFAMILY_OF / BIO_RELATION / EVIDENCE_RELATION</strong></div>
  64:         <div class="mini-stat-row"><span><?= htmlspecialchars(site_t(['zh' => '图数据库', 'en' => 'Graph database'], $lang), ENT_QUOTES, 'UTF-8') ?></span><strong>Neo4j</strong></div>
  65:         <div class="mini-stat-row"><span><?= htmlspecialchars(site_t(['zh' => '网页环境', 'en' => 'Web environment'], $lang), ENT_QUOTES, 'UTF-8') ?></span><strong>WampServer + PHP</strong></div>
  66        </div>

  69      <div class="section-card">
  70:       <h3><?= htmlspecialchars(site_t(['zh' => '数据来源', 'en' => 'Data Sources'], $lang), ENT_QUOTES, 'UTF-8') ?></h3>
  71        <p><?= htmlspecialchars(site_t([
  72:         'zh' => '当前系统主要整合了标准化后的 TE 文献抽取结果、扩展得到的 te_kg2 数据、来自 Repbase 的 TE 参考文件，以及用于谱系整理的 tree.txt。这些资源共同支撑图谱构建、条目搜索和 TE 说明展示。',
  73          'en' => 'The current system integrates normalized TE literature extraction results, the expanded te_kg2 dataset, TE reference records from Repbase, and tree.txt lineage resources. Together they support graph construction, search, and TE description display.'

  75        <div class="note-box"><?= htmlspecialchars(site_t([
  76:         'zh' => '在 TE 名称、谱系层级和说明性属性上，系统优先参考 Repbase；图谱中的实体关系和问答上下文则以当前 Neo4j 数据为主。',
  77          'en' => 'For TE names, lineage levels, and descriptive attributes, the system prioritizes Repbase. Graph relations and QA context are primarily based on the current Neo4j data.'

  81      <div class="section-card">
  82:       <h3><?= htmlspecialchars(site_t(['zh' => '仍在完善的部分', 'en' => 'Ongoing Improvements'], $lang), ENT_QUOTES, 'UTF-8') ?></h3>
  83        <p><?= htmlspecialchars(site_t([
  84:         'zh' => '当前系统已经具备可展示的主链路，但仍在持续优化 TE 标准化、中英文术语映射、Repbase 参考区块、搜索体验，以及大规模图谱展开时的性能表现。',
  85          'en' => 'The main workflow is already presentation-ready, while TE standardization, bilingual terminology mapping, the Repbase reference block, search experience, and large-graph performance are still being refined.'

  89      <div class="section-card">
  90:       <h3><?= htmlspecialchars(site_t(['zh' => '团队协作', 'en' => 'Team Collaboration'], $lang), ENT_QUOTES, 'UTF-8') ?></h3>
  91        <p><?= htmlspecialchars(site_t([
  92:         'zh' => '项目通过分工协作推进，包括文献抽取、数据标准化、图数据库建模、前端展示和问答联调等模块。当前网站承担的是系统整合与展示入口的角色，用于课程答辩和功能演示。',
  93          'en' => 'The project moves forward through collaboration across literature extraction, data standardization, graph modeling, frontend presentation, and QA integration. The current website serves as the integration and presentation layer for demonstration.'

reference\frontend-backup\legacy-shell-2026-04-03\download.php:
   3  $lang = site_lang();
   4: $pageTitle = site_t(['zh' => '下载 - TEKG', 'en' => 'Download - TEKG'], $lang);
   5  $activePage = 'download';

   8      [
   9:         'title' => site_t(['zh' => '原始数据', 'en' => 'Raw Data'], $lang),
  10:         'desc' => site_t(['zh' => '本部分提供知识图谱构建过程中使用的核心原始数据来源，包括文献抽取结果、转座元件参考数据和谱系参考文本。', 'en' => 'This section provides the core raw data sources used during graph construction, including literature extraction results, transposable element reference data, and lineage reference text.'], $lang),
  11          'items' => [
  12:             ['name' => 'te_kg2.jsonl', 'path' => 'data/raw/te_kg2.jsonl', 'type' => 'JSONL', 'desc' => site_t(['zh' => '知识图谱构建使用的主要结构化抽取源数据，包含转座元件、疾病、功能和文献信息。', 'en' => 'The primary structured extraction source used for graph construction, including transposable elements, diseases, functions, and literature information.'], $lang)],
  13:             ['name' => 'TE_Repbase.txt', 'path' => 'data/raw/TE_Repbase.txt', 'type' => 'TXT', 'desc' => site_t(['zh' => '从 Repbase 获取的人类转座元件参考文件，可用于 TE 定义、别名与家族信息。', 'en' => 'Human transposable element reference file obtained from Repbase, useful for TE definitions, aliases, and family information.'], $lang)],
  14:             ['name' => 'tree.txt', 'path' => 'data/raw/tree.txt', 'type' => 'TXT', 'desc' => site_t(['zh' => '基于数据库文件生成的 TE 家族树状参考文本，用于谱系结构整理。', 'en' => 'Reference text describing a TE family tree generated from database files, used for lineage organization.'], $lang)],
  15          ],

  17      [
  18:         'title' => site_t(['zh' => '处理后数据', 'en' => 'Processed Data'], $lang),
  19:         'desc' => site_t(['zh' => '面向图数据库构建与展示使用的标准化结果和结构化产物。', 'en' => 'Normalized outputs and structured artifacts prepared for graph database construction and presentation.'], $lang),
  20          'items' => [
  21:             ['name' => 'te_kg2_normalized_output.jsonl', 'path' => 'data/processed/te_kg2_normalized_output.jsonl', 'type' => 'JSONL', 'desc' => site_t(['zh' => '对 te_kg2 进行标准化和清洗后的结构化输出。', 'en' => 'Structured output after normalization and cleaning of te_kg2.'], $lang)],
  22:             ['name' => 'te_kg2_graph_seed.json', 'path' => 'data/processed/te_kg2_graph_seed.json', 'type' => 'JSON', 'desc' => site_t(['zh' => '用于图数据库导入的图谱种子文件。', 'en' => 'Graph seed file used for graph database import.'], $lang)],
  23:             ['name' => 'tree_te_lineage.json', 'path' => 'data/processed/tree_te_lineage.json', 'type' => 'JSON', 'desc' => site_t(['zh' => '根据清洗后的 tree.txt 生成的 TE 谱系结构数据。', 'en' => 'TE lineage structure data generated from the cleaned tree.txt.'], $lang)],
  24:             ['name' => 'tree_te_lineage.csv', 'path' => 'data/processed/tree_te_lineage.csv', 'type' => 'CSV', 'desc' => site_t(['zh' => 'TE 树状谱系的表格化导出版本，便于人工查看。', 'en' => 'Tabular export of the TE lineage tree for manual inspection.'], $lang)],
  25:             ['name' => 'te_kg2_normalization_report.json', 'path' => 'data/processed/te_kg2_normalization_report.json', 'type' => 'JSON', 'desc' => site_t(['zh' => 'te_kg2 标准化过程的统计报告。', 'en' => 'Statistical report of the te_kg2 normalization process.'], $lang)],
  26          ],

  28      [
  29:         'title' => site_t(['zh' => '术语表与标准化资源', 'en' => 'Terminology and Standardization Resources'], $lang),
  30:         'desc' => site_t(['zh' => '面向中英映射、术语维护和界面展示使用的词表文件。', 'en' => 'Terminology files used for bilingual mapping, terminology maintenance, and interface presentation.'], $lang),
  31          'items' => [
  32:             ['name' => 'te_terminology.json', 'path' => 'terminology/te_terminology.json', 'type' => 'JSON', 'desc' => site_t(['zh' => '主术语表文件，包含节点名称与关系词的中英文映射。', 'en' => 'Main terminology file containing bilingual mappings for node names and relation labels.'], $lang)],
  33:             ['name' => 'te_terminology.csv', 'path' => 'terminology/te_terminology.csv', 'type' => 'CSV', 'desc' => site_t(['zh' => '术语表的表格版本，便于人工维护与审阅。', 'en' => 'Spreadsheet-style version of the terminology table for manual maintenance and review.'], $lang)],
  34:             ['name' => 'te_terminology_overrides.json', 'path' => 'terminology/te_terminology_overrides.json', 'type' => 'JSON', 'desc' => site_t(['zh' => '运行时优先覆盖的术语补丁文件，用于保存新增或修正后的映射。', 'en' => 'Runtime override file that stores newly added or corrected terminology mappings.'], $lang)],
  35          ],

  41  <section class="hero-card">
  42:   <h2 class="page-title"><?= htmlspecialchars(site_t(['zh' => '下载', 'en' => 'Download'], $lang), ENT_QUOTES, 'UTF-8') ?></h2>
  43:   <p class="page-desc"><?= htmlspecialchars(site_t(['zh' => '这里集中提供本数据库项目的主要原始数据、处理后数据与术语表资源，便于查阅、下载和复用。', 'en' => 'This page provides the main raw data, processed data, and terminology resources of the project for browsing, downloading, and reuse.'], $lang), ENT_QUOTES, 'UTF-8') ?></p>
  44  </section>

  62                <span style="font-size:13px;color:#7b8da3;word-break:break-all;"><?= htmlspecialchars($item['path'], ENT_QUOTES, 'UTF-8') ?></span>
  63:               <a href="<?= htmlspecialchars($item['path'], ENT_QUOTES, 'UTF-8') ?>" download style="white-space:nowrap;padding:10px 16px;border-radius:14px;background:#2563eb;color:#fff;font-weight:700;"><?= htmlspecialchars(site_t(['zh' => '下载', 'en' => 'Download'], $lang), ENT_QUOTES, 'UTF-8') ?></a>
  64              </div>

reference\frontend-backup\legacy-shell-2026-04-03\head.php:
    6  if (!isset($pageTitle)) {
    7:     $pageTitle = site_t(['zh' => 'TE 数据库', 'en' => 'TE Database'], $siteLang);
    8  }

   13  $navItems = [
   14:     'home' => ['label' => site_t(['zh' => '首页', 'en' => 'Home'], $siteLang), 'href' => 'index.php'],
   15:     'preview' => ['label' => site_t(['zh' => '预览', 'en' => 'Preview'], $siteLang), 'href' => 'preview.php'],
   16:     'search' => ['label' => site_t(['zh' => '搜索', 'en' => 'Search'], $siteLang), 'href' => 'search.php'],
   17:     'download' => ['label' => site_t(['zh' => '下载', 'en' => 'Download'], $siteLang), 'href' => 'download.php'],
   18:     'about' => ['label' => site_t(['zh' => '关于', 'en' => 'About'], $siteLang), 'href' => 'about.php'],
   19  ];

   24  $currentPath = basename((string) ($_SERVER['PHP_SELF'] ?? 'index.php'));
   25: $zhUrl = site_url_with_state($currentPath, 'zh', $siteRenderer, $currentQueryParams);
   26  $enUrl = site_url_with_state($currentPath, 'en', $siteRenderer, $currentQueryParams);

  303        <div class="brand">
  304:         <div class="brand-mark" aria-label="<?= htmlspecialchars(site_t(['zh' => '站点图标', 'en' => 'Site logo'], $siteLang), ENT_QUOTES, 'UTF-8') ?>">
  305            <img src="assets/img/brand/tekg-logo.png" alt="TE-KG logo">

  307          <div>
  308:           <h1 class="brand-title"><?= htmlspecialchars(site_t(['zh' => '转座元件知识图谱', 'en' => 'Transposable Elements Knowledge Graph'], $siteLang), ENT_QUOTES, 'UTF-8') ?></h1>
  309            <p class="brand-subtitle">Transposable Elements Knowledge Graph</p>

  311        </div>
  312:       <nav class="site-nav" aria-label="<?= htmlspecialchars(site_t(['zh' => '主导航', 'en' => 'Main navigation'], $siteLang), ENT_QUOTES, 'UTF-8') ?>">
  313          <?php foreach ($navItems as $key => $item): ?>

  315          <?php endforeach; ?>
  316:         <div class="lang-switch" aria-label="<?= htmlspecialchars(site_t(['zh' => '语言切换', 'en' => 'Language switch'], $siteLang), ENT_QUOTES, 'UTF-8') ?>">
  317:           <a href="<?= htmlspecialchars($zhUrl, ENT_QUOTES, 'UTF-8') ?>" class="<?= $siteLang === 'zh' ? 'active' : '' ?>">中文</a>
  318            <a href="<?= htmlspecialchars($enUrl, ENT_QUOTES, 'UTF-8') ?>" class="<?= $siteLang === 'en' ? 'active' : '' ?>">English</a>
  319          </div>
  320:         <div class="renderer-switch" aria-label="<?= htmlspecialchars(site_t(['zh' => '渲染器切换', 'en' => 'Renderer switch'], $siteLang), ENT_QUOTES, 'UTF-8') ?>">
  321            <a href="<?= htmlspecialchars($cytUrl, ENT_QUOTES, 'UTF-8') ?>" class="<?= $siteRenderer === 'cytoscape' ? 'active' : '' ?>">Cytoscape</a>

reference\frontend-backup\legacy-shell-2026-04-03\index.php:
    4  $renderer = site_renderer();
    5: $pageTitle = site_t(['zh' => '首页 - TEKG', 'en' => 'Home - TEKG'], $lang);
    6  $activePage = 'home';

   81      <h2 class="page-title" style="color:#fff;margin-bottom:14px;"><?= htmlspecialchars(site_t([
   82:       'zh' => '浏览与检索转座元件知识图谱数据库',
   83        'en' => 'Explore and Search the Transposable Elements Knowledge Graph'

   86        <?= htmlspecialchars(site_t([
   87:         'zh' => '本数据库用于组织转座元件（TE）、疾病、功能机制与文献证据之间的结构化关联，当前支持 TE 树预览、图谱浏览、智能问答、实体检索与数据下载。',
   88          'en' => 'This database organizes structured relationships among transposable elements (TEs), diseases, functions/mechanisms, and literature evidence. It currently supports TE tree preview, graph exploration, QA, entity search, and data download.'

   92        <select name="type" style="width:170px;border:none;border-right:1px solid #d9e5f3;padding:0 18px;background:#f7faff;color:#28425f;font-size:15px;outline:none;">
   93:         <option value="all"><?= htmlspecialchars(site_t(['zh' => '所有数据类型', 'en' => 'All types'], $lang), ENT_QUOTES, 'UTF-8') ?></option>
   94          <option value="TE">TE</option>

   98        </select>
   99:       <input type="text" name="q" placeholder="<?= htmlspecialchars(site_t(['zh' => '输入标识符或关键词进行搜索...', 'en' => 'Search by identifier or keyword...'], $lang), ENT_QUOTES, 'UTF-8') ?>" style="flex:1;border:none;padding:0 18px;font-size:16px;min-height:74px;outline:none;color:#19324d;">
  100:       <button type="submit" style="min-width:132px;border:none;background:#3b67f2;color:#fff;font-size:20px;font-weight:700;cursor:pointer;"><?= htmlspecialchars(site_t(['zh' => '搜索', 'en' => 'Search'], $lang), ENT_QUOTES, 'UTF-8') ?></button>
  101      </form>

  107      <h3 style="margin:0 0 18px;font-size:24px;padding-bottom:12px;border-bottom:1px solid #e5edf7;">
  108:       <a href="<?= htmlspecialchars(site_url_with_state('preview.php', $lang, $renderer), ENT_QUOTES, 'UTF-8') ?>" style="color:inherit;text-decoration:none;"><?= htmlspecialchars(site_t(['zh' => '知识图谱预览', 'en' => 'Knowledge Graph Preview'], $lang), ENT_QUOTES, 'UTF-8') ?></a>
  109      </h3>

  112        src="<?= htmlspecialchars($homePreviewSrc, ENT_QUOTES, 'UTF-8') ?>"
  113:       title="<?= htmlspecialchars(site_t(['zh' => '首页知识图谱预览', 'en' => 'Home knowledge graph preview'], $lang), ENT_QUOTES, 'UTF-8') ?>"
  114        style="width:100%;height:520px;border:1px solid #d8e4f0;border-radius:18px;background:radial-gradient(circle at top,#ffffff,#edf4ff);box-shadow:inset 0 1px 0 rgba(255,255,255,.72);"

  119      <section class="content-card">
  120:       <h3 style="margin:0 0 14px;font-size:22px;padding-bottom:12px;border-bottom:1px solid #e5edf7;"><?= htmlspecialchars(site_t(['zh' => '数据集状态', 'en' => 'Dataset Status'], $lang), ENT_QUOTES, 'UTF-8') ?></h3>
  121        <div style="display:grid;gap:14px;font-size:16px;">
  122:         <div style="display:flex;justify-content:space-between;gap:12px;"><span style="color:#111827;"><?= htmlspecialchars(site_t(['zh' => 'TE 节点：', 'en' => 'TE nodes:'], $lang), ENT_QUOTES, 'UTF-8') ?></span><strong style="color:var(--te);"><?= htmlspecialchars((string) $counts['TE'], ENT_QUOTES, 'UTF-8') ?></strong></div>
  123:         <div style="display:flex;justify-content:space-between;gap:12px;"><span style="color:#111827;"><?= htmlspecialchars(site_t(['zh' => 'Disease 节点：', 'en' => 'Disease nodes:'], $lang), ENT_QUOTES, 'UTF-8') ?></span><strong style="color:var(--disease);"><?= htmlspecialchars((string) $counts['Disease'], ENT_QUOTES, 'UTF-8') ?></strong></div>
  124:         <div style="display:flex;justify-content:space-between;gap:12px;"><span style="color:#111827;"><?= htmlspecialchars(site_t(['zh' => 'Function 节点：', 'en' => 'Function nodes:'], $lang), ENT_QUOTES, 'UTF-8') ?></span><strong style="color:var(--function);"><?= htmlspecialchars((string) $counts['Function'], ENT_QUOTES, 'UTF-8') ?></strong></div>
  125:         <div style="display:flex;justify-content:space-between;gap:12px;"><span style="color:#111827;"><?= htmlspecialchars(site_t(['zh' => 'Paper 节点：', 'en' => 'Paper nodes:'], $lang), ENT_QUOTES, 'UTF-8') ?></span><strong style="color:var(--paper);"><?= htmlspecialchars((string) $counts['Paper'], ENT_QUOTES, 'UTF-8') ?></strong></div>
  126        </div>

  129      <section class="content-card">
  130:       <h3 style="margin:0 0 14px;font-size:22px;padding-bottom:12px;border-bottom:1px solid #e5edf7;"><?= htmlspecialchars(site_t(['zh' => '快速检索示例', 'en' => 'Quick Search Examples'], $lang), ENT_QUOTES, 'UTF-8') ?></h3>
  131:       <p style="margin:0 0 14px;color:#5e7288;line-height:1.7;"><?= htmlspecialchars(site_t(['zh' => '每类提供一个典型入口，点击后将直接跳转到搜索页。', 'en' => 'Each category provides one representative entry that jumps directly to the search page.'], $lang), ENT_QUOTES, 'UTF-8') ?></p>
  132        <div style="display:flex;flex-wrap:wrap;gap:10px;">

reference\frontend-backup\legacy-shell-2026-04-03\preview.php:
   4  $renderer = site_renderer();
   5: $pageTitle = site_t(['zh' => '预览 - TEKG', 'en' => 'Preview - TEKG'], $lang);
   6  $activePage = 'preview';

  44        src="<?= htmlspecialchars($previewSrc, ENT_QUOTES, 'UTF-8') ?>"
  45:       title="<?= htmlspecialchars(site_t(['zh' => '知识图谱预览', 'en' => 'Knowledge graph preview'], $lang), ENT_QUOTES, 'UTF-8') ?>"
  46      ></iframe>

reference\frontend-backup\legacy-shell-2026-04-03\search.php:
    4  $renderer = site_renderer();
    5: $pageTitle = site_t(['zh' => '搜索 - TEKG', 'en' => 'Search - TEKG'], $lang);
    6  $activePage = 'search';

   91      <div>
   92:       <h2 class="page-title"><?= htmlspecialchars(site_t(['zh' => '搜索', 'en' => 'Search'], $lang), ENT_QUOTES, 'UTF-8') ?></h2>
   93        <p class="page-desc"><?= htmlspecialchars(site_t([
   94:         'zh' => '输入关键词后，页面会展示最佳命中实体、局部图谱，以及面向 TE 的 Repbase 参考区块。',
   95          'en' => 'After entering a query, the page shows the best-matched entity, a local graph, and a Repbase reference block for TE entries.'

   99        <select name="type" style="width:170px;min-height:50px;border:1px solid #d8e4f0;border-radius:14px;padding:0 14px;background:#f7faff;color:#28425f;font-size:15px;outline:none;">
  100:         <option value="all" <?= $type === 'all' ? 'selected' : '' ?>><?= htmlspecialchars(site_t(['zh' => '所有数据类型', 'en' => 'All types'], $lang), ENT_QUOTES, 'UTF-8') ?></option>
  101          <option value="TE" <?= $type === 'TE' ? 'selected' : '' ?>>TE</option>

  105        </select>
  106:       <input id="search-query" type="text" name="q" value="<?= htmlspecialchars($query, ENT_QUOTES, 'UTF-8') ?>" placeholder="<?= htmlspecialchars(site_t(['zh' => '输入 TE、疾病、功能或 PMID', 'en' => 'Enter a TE, disease, function, or PMID'], $lang), ENT_QUOTES, 'UTF-8') ?>" style="flex:1;min-width:260px;min-height:50px;border:1px solid #d8e4f0;border-radius:14px;padding:0 16px;font-size:15px;outline:none;">
  107:       <button type="submit" style="min-width:92px;min-height:50px;border:none;border-radius:14px;background:#2563eb;color:#fff;font-weight:700;cursor:pointer;"><?= htmlspecialchars(site_t(['zh' => '搜索', 'en' => 'Search'], $lang), ENT_QUOTES, 'UTF-8') ?></button>
  108      </form>

  114      <section class="content-card">
  115:       <h3 style="margin:0 0 14px;font-size:22px;padding-bottom:12px;border-bottom:1px solid #e5edf7;"><?= htmlspecialchars(site_t(['zh' => '最佳命中', 'en' => 'Best Match'], $lang), ENT_QUOTES, 'UTF-8') ?></h3>
  116        <div id="search-best-match" style="line-height:1.8;color:#5e7288;min-height:120px;">
  117          <?php if ($query === ''): ?>
  118:           <?= htmlspecialchars(site_t(['zh' => '输入关键词后，这里会显示最佳命中的实体详情。', 'en' => 'Enter a query to display the best-matched entity here.'], $lang), ENT_QUOTES, 'UTF-8') ?>
  119          <?php else: ?>
  120:           <?= htmlspecialchars(site_t(['zh' => '正在搜索', 'en' => 'Searching for'], $lang), ENT_QUOTES, 'UTF-8') ?> <strong><?= htmlspecialchars($query, ENT_QUOTES, 'UTF-8') ?></strong> ...
  121          <?php endif; ?>

  125      <section class="content-card">
  126:       <h3 style="margin:0 0 14px;font-size:22px;padding-bottom:12px;border-bottom:1px solid #e5edf7;"><?= htmlspecialchars(site_t(['zh' => 'Repbase 参考区块', 'en' => 'Repbase Reference'], $lang), ENT_QUOTES, 'UTF-8') ?></h3>
  127        <div id="search-repbase" style="line-height:1.8;color:#5e7288;min-height:140px;">
  128          <?php if ($repbase !== null): ?>
  129:           <div><strong><?= htmlspecialchars(site_t(['zh' => '匹配名称：', 'en' => 'Matched name: '], $lang), ENT_QUOTES, 'UTF-8') ?></strong><?= htmlspecialchars($repbase['matched'], ENT_QUOTES, 'UTF-8') ?></div>
  130            <div><strong>Repbase ID：</strong><?= htmlspecialchars($repbase['id'] ?: '-', ENT_QUOTES, 'UTF-8') ?></div>
  131:           <div><strong><?= htmlspecialchars(site_t(['zh' => '标准名：', 'en' => 'Canonical name: '], $lang), ENT_QUOTES, 'UTF-8') ?></strong><?= htmlspecialchars($repbase['nm'] ?: $repbase['id'] ?: '-', ENT_QUOTES, 'UTF-8') ?></div>
  132:           <div><strong><?= htmlspecialchars(site_t(['zh' => '说明：', 'en' => 'Description: '], $lang), ENT_QUOTES, 'UTF-8') ?></strong><?= htmlspecialchars($repbase['description'] ?: site_t(['zh' => '暂无说明', 'en' => 'No description'], $lang), ENT_QUOTES, 'UTF-8') ?></div>
  133:           <div><strong><?= htmlspecialchars(site_t(['zh' => '关键词：', 'en' => 'Keywords: '], $lang), ENT_QUOTES, 'UTF-8') ?></strong><?= htmlspecialchars($repbase['keywords'] ?: site_t(['zh' => '暂无关键词', 'en' => 'No keywords'], $lang), ENT_QUOTES, 'UTF-8') ?></div>
  134:           <div><strong><?= htmlspecialchars(site_t(['zh' => '物种：', 'en' => 'Species: '], $lang), ENT_QUOTES, 'UTF-8') ?></strong><?= htmlspecialchars($repbase['species'] ?: site_t(['zh' => '暂无物种信息', 'en' => 'No species information'], $lang), ENT_QUOTES, 'UTF-8') ?></div>
  135:           <div><strong><?= htmlspecialchars(site_t(['zh' => '序列摘要：', 'en' => 'Sequence summary: '], $lang), ENT_QUOTES, 'UTF-8') ?></strong><?= htmlspecialchars($repbase['sequence_summary'] ?: site_t(['zh' => '暂无序列摘要', 'en' => 'No sequence summary'], $lang), ENT_QUOTES, 'UTF-8') ?></div>
  136:           <div><strong><?= htmlspecialchars(site_t(['zh' => '参考文献数：', 'en' => 'Reference count: '], $lang), ENT_QUOTES, 'UTF-8') ?></strong><?= htmlspecialchars((string) ($repbase['reference_count'] ?? 0), ENT_QUOTES, 'UTF-8') ?></div>
  137          <?php elseif ($query !== ''): ?>
  138            <?= htmlspecialchars(site_t([
  139:             'zh' => '当前查询词暂未在已对齐的 Repbase 子集中命中。如果最佳命中为 TE，页面会优先尝试按最佳命中名称继续匹配。',
  140              'en' => 'The current query is not found in the aligned Repbase subset. If the best match is a TE, the page will try again using the best-matched TE name.'

  143            <?= htmlspecialchars(site_t([
  144:             'zh' => '该区块用于展示当前数据库 TE 能映射到的 Repbase 条目信息，包括标准名、说明、关键词、物种和序列摘要。',
  145              'en' => 'This block shows Repbase information for TE entries aligned to the current database, including canonical name, description, keywords, species, and sequence summary.'

  153      <div style="display:flex;align-items:center;justify-content:space-between;gap:14px;">
  154:       <h3 style="margin:0;font-size:22px;"><?= htmlspecialchars(site_t(['zh' => '局部图谱', 'en' => 'Local Graph'], $lang), ENT_QUOTES, 'UTF-8') ?></h3>
  155:       <button id="search-reset" type="button" style="border:none;border-radius:14px;background:#eef4ff;color:#2753b7;padding:10px 16px;font-weight:700;cursor:pointer;"><?= htmlspecialchars(site_t(['zh' => '重置图谱', 'en' => 'Reset Graph'], $lang), ENT_QUOTES, 'UTF-8') ?></button>
  156      </div>

  160          src="<?= htmlspecialchars($searchGraphSrc, ENT_QUOTES, 'UTF-8') ?>"
  161:         title="<?= htmlspecialchars(site_t(['zh' => '搜索图谱（G6）', 'en' => 'Search graph (G6)'], $lang), ENT_QUOTES, 'UTF-8') ?>"
  162          style="flex:1;min-height:640px;border:1px solid #d8e4f0;border-radius:18px;background:radial-gradient(circle at top,#ffffff,#edf4ff);"

  167          src="<?= htmlspecialchars(site_url_with_state('index_demo.html', $lang, 'cytoscape', ['embed' => 'search-result']), ENT_QUOTES, 'UTF-8') ?>"
  168:         title="<?= htmlspecialchars(site_t(['zh' => '搜索图谱（Cytoscape）', 'en' => 'Search graph (Cytoscape)'], $lang), ENT_QUOTES, 'UTF-8') ?>"
  169          style="flex:1;min-height:640px;border:1px solid #d8e4f0;border-radius:18px;background:radial-gradient(circle at top,#ffffff,#edf4ff);"

reference\frontend-study\te-home-prototype\head.php:
   21  unset($currentQueryParams['lang'], $currentQueryParams['renderer']);
   22: $zhUrl = site_url_with_state($protoCurrentPath, 'zh', $siteRenderer, $currentQueryParams);
   23  $enUrl = site_url_with_state($protoCurrentPath, 'en', $siteRenderer, $currentQueryParams);

   27  <!DOCTYPE html>
   28: <html lang="<?= $siteLang === 'zh' ? 'zh-CN' : 'en' ?>">
   29  <head>

  263            <div class="proto-control-group is-hidden" aria-label="Language switch">
  264:             <a class="proto-control<?= $siteLang === 'zh' ? ' is-active' : '' ?>" href="<?= htmlspecialchars($zhUrl, ENT_QUOTES, 'UTF-8') ?>">中文</a>
  265              <a class="proto-control<?= $siteLang === 'en' ? ' is-active' : '' ?>" href="<?= htmlspecialchars($enUrl, ENT_QUOTES, 'UTF-8') ?>">English</a>

reference\external_examples\G6\packages\site\.dumirc.ts:
   7    locales: [
   8:     { id: 'zh', name: '中文' },
   9      { id: 'en', name: 'English' },

  27      },
  28:     defaultLanguage: 'zh', // 默认语言
  29      isAntVSite: false, // 是否是 AntV 的大官网

reference\external_examples\G6\packages\site\scripts\sort-doc.ts:
  118      docs.forEach(([order, name], index) => {
  119:       ['zh', 'en'].forEach((lang) => {
  120          const filename = `${path}/${name}.${lang}.md`;

reference\external_examples\G6\packages\site\src\MarkdownDocumenter.ts:
  2334              childNode.selfClosingTag &&
  2335:             (childNode.name === 'zh' || childNode.name === 'en')
  2336            ) {

  2338              const currentLanguage =
  2339:               childNode.selfClosingTag && childNode.name === 'zh' ? LocaleLanguage.ZH : LocaleLanguage.EN;
  2340              if (currentLanguage == language) {

  2369    private _getLang() {
  2370:     return this.locale === LocaleLanguage.ZH ? 'zh' : 'en';
  2371    }
