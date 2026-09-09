    const GRAPH_TUNING = {
      focusZoomStep: 0.01,
      neighborOpacity: 0.88,
      dimmedOpacity: 0.38,
      evidenceNodeRepulsion: 900000,
      diseaseFunctionRepulsion: 700000,
      teNodeRepulsion: 600000,
      paperOuterOffset: 220,
      overlapPadding: 28,
      overlapIterations: 14
    };
    const pageParams = new URLSearchParams(window.location.search);
    const embedMode = pageParams.get('embed') || '';
    const currentRenderer = 'g6';
    window.__TEKG_EMBED_MODE = embedMode;
    window.__TEKG_RENDERER_MODE = currentRenderer;
    const initialElements = [];
    const el = id => document.getElementById(id);
    const nodeDetails = el('node-details'), searchInput = el('node-search'), nav = el('search-results-nav'), prevBtn = el('prev-result'), nextBtn = el('next-result'), resultCounter = el('result-counter'), resultName = el('result-name'), chatMessages = el('chat-messages'), userInput = el('user-question'), sendBtn = el('send-question');
    let currentLang = 'en', currentAnswerStyle = 'simple', currentAnswerDepth = 'shallow', currentModelProvider = 'qwen', currentCustomPrompt = '', customPromptDraft = '', currentCustomDepth = {rows:12,references:8}, customDepthDraft = {rows:12,references:8}, customEditorOpen = false, customEditorMode = 'prompt', previousAnswerStyleBeforeCustom = 'simple', previousAnswerDepthBeforeCustom = 'shallow', searchResults = [], currentResultIndex = -1, focusLevel = 0, searchDebounceId = null, fixedView = false, currentGraphKind = 'default-tree', currentKeyNodeLevel = 1;
    let terminologyNames = {en:{}}, terminologyNameLookup = {en:{}}, terminologyRelations = {en:{}};
    let ui = {en:{}}; 
    const typeLabel = {en:{TE:'Transposable Element',Disease:'Disease',Function:'Function/Mechanism',Paper:'Paper'}};
    const relLabel = {en:{SUBFAMILY_OF:'contains','��...���':'associated with','������':'does not cause','�ٽ�':'promotes','�鵼':'mediates','����':'reports','Ӱ��':'affects','ִ��':'executes','����':'participates in','����':'regulates','����':'leads to','����':'uses','����':'inhibits','����':'triggers','�յ�':'induces','���ӷ���':'increases risk of','����':'modulates','�ٳ�':'facilitates','����':'occurs in','����':'activates','�ƻ�':'disrupts','����':'produces','�䵱':'acts as','ʹ��':'enables','����':'explains','�ṩ':'provides','�׸�':'predisposes to','������':'is regulated by','�ı�':'alters','ȱʧ':'lacks','����Ϊ':'manifests as','����':'characterizes'}};
    const nameMap = {en:{}};
    const descMap = {en:{}};
    let teDescMap = {en:{}};
    let entityDescMap = {
      en: {Disease:{}, Function:{}}
    };
    let localQaTemplates = {en:{}};
    const DISPLAY_NAME_OVERRIDES = {
      en: {
        'L1':'LINE1',
        'LINE-1':'LINE1'
      }
    };
        const getTypeColor = t => ({TE:'#2563eb',Disease:'#ef4444',Function:'#10b981',Paper:'#f59e0b'}[t] || '#94a3b8');
    function getTreeDepthColor(depth){
      if(depth <= 0) return '#163a8a';
      if(depth === 1) return '#1d4ed8';
      if(depth === 2) return '#2563eb';
      if(depth === 3) return '#5b8dff';
      return '#8fb2ff';
    }
    function containsChinese(text){return /[\u4e00-\u9fff]/.test(text || '');}
    function getType(type){return (typeLabel[currentLang] && typeLabel[currentLang][type]) || type;}
    function rebuildTerminologyIndexes(){
      terminologyNameLookup = {en:{}};
      ['en'].forEach(lang=>{
        const source = terminologyNames[lang] || {};
        Object.keys(source).forEach(key=>{
          terminologyNameLookup[lang][key] = source[key];
          terminologyNameLookup[lang][String(key).toLowerCase()] = source[key];
        });
      });
    }
    function mergeTerminology(payload){
      if(!payload || typeof payload !== 'object') return;
      ['en'].forEach(lang=>{
        terminologyNames[lang] = Object.assign({}, terminologyNames[lang] || {}, payload.names?.[lang] || {});
        terminologyRelations[lang] = Object.assign({}, terminologyRelations[lang] || {}, payload.relations?.[lang] || {});
      });
      rebuildTerminologyIndexes();
    }
    async function loadTerminology(){
      try{
        const res = await fetch(window.__TEKG_PATHS.terminologyUrl('te_terminology.json'), {cache:'no-store'});
        if(!res.ok) throw new Error(`HTTP ${res.status}`);
        const payload = await res.json();
        mergeTerminology(payload);
      }catch(err){
        console.warn('Failed to load terminology table:', err);
      }
      try{
        const overrideRes = await fetch(window.__TEKG_PATHS.terminologyUrl('te_terminology_overrides.json'), {cache:'no-store'});
        if(!overrideRes.ok) throw new Error(`HTTP ${overrideRes.status}`);
        const overridePayload = await overrideRes.json();
        mergeTerminology(overridePayload);
      }catch(err){
        console.warn('Failed to load terminology override table:', err);
      }
    }
    async function loadTeDescriptions(){
      try{
        const res = await fetch(window.__TEKG_PATHS.dataUrl('processed/te_descriptions.json'), {cache:'no-store'});
        if(!res.ok) throw new Error(`HTTP ${res.status}`);
        const payload = await res.json();
        teDescMap = {
          en: Object.assign({}, payload?.en || {})
        };
      }catch(err){
        console.warn('Failed to load TE descriptions:', err);
        teDescMap = {en:{}};
      }
    }
    async function loadEntityDescriptions(){
      try{
        const res = await fetch(window.__TEKG_PATHS.dataUrl('processed/entity_descriptions.json'), {cache:'no-store'});
        if(!res.ok) throw new Error(`HTTP ${res.status}`);
        const payload = await res.json();
        entityDescMap = {
          en: {
            Disease: Object.assign({}, payload?.en?.Disease || {}),
            Function: Object.assign({}, payload?.en?.Function || {})
          }
        };
      }catch(err){
        console.warn('Failed to load entity descriptions:', err);
        entityDescMap = {en:{Disease:{}, Function:{}}};
      }
    }
    async function loadUiText(){
      try{
        const res = await fetch(window.__TEKG_PATHS.dataUrl('processed/ui_text.json'), {cache:'no-store'});
        if(!res.ok) throw new Error(`HTTP ${res.status}`);
        const payload = await res.json();
        ui = {
          en: Object.assign({}, payload?.en || {})
        };
      }catch(err){
        console.warn('Failed to load UI text:', err);
        ui = {en:{}};
      }
    }
    async function loadLocalQaTemplates(){
      try{
        const res = await fetch(window.__TEKG_PATHS.dataUrl('processed/local_qa_templates.json'), {cache:'no-store'});
        if(!res.ok) throw new Error(`HTTP ${res.status}`);
        const payload = await res.json();
        localQaTemplates = {
          en: Object.assign({}, payload?.en || {})
        };
      }catch(err){
        console.warn('Failed to load local QA templates:', err);
        localQaTemplates = {en:{}};
      }
    }
    function getLocalQaTemplate(key, fallback=''){
      const source = localQaTemplates[currentLang] || {};
      return source[key] ?? fallback;
    }
    function getDisplayNameKey(name=''){
      if(!name) return '';
      return DISPLAY_NAME_OVERRIDES[currentLang]?.[name] || DISPLAY_NAME_OVERRIDES.en?.[name] || name;
    }
    function lookupMappedName(name=''){
      if(!name) return '';
      const displayKey = getDisplayNameKey(name);
      return terminologyNameLookup[currentLang][displayKey] || terminologyNameLookup[currentLang][String(displayKey).toLowerCase()] || nameMap[currentLang][displayKey] || '';
    }
    function lookupMappedRelation(rel=''){
      if(!rel) return '';
      return terminologyRelations[currentLang][rel] || relLabel[currentLang][rel] || rel;
    }
    function getRel(rel){return lookupMappedRelation(rel);}
    function getPaperDisplayName(name, pmid=''){
      const mapped = lookupMappedName(name);
      if(mapped) return mapped;      if(containsChinese(name)) return pmid ? `Paper PMID:${pmid}` : 'Paper node';
      return name;
    }
    function getName(name,type,description='',pmid=''){      const mapped = lookupMappedName(name);
      if(mapped) return mapped;
      if(type==='Paper') return getPaperDisplayName(name, pmid);
      if(containsChinese(name)) return `${getType(type)} node`;
      return name;
    }
    const inferType = name => {
      const current = cy.nodes().filter(n => n.data('label') === name)[0];
      if(current) return current.data('type') || 'Unknown';
      return 'Unknown';
    };
    function isBrokenDescription(text=''){
      const value = String(text || '').trim();
      if(!value) return true;
      if(/^\?+$/.test(value)) return true;
      if((value.match(/\?/g)||[]).length >= 4) return true;
      if(/[\u9500\u951b\u956f\u95ba\u95c2\u95b8\u95b7\u7487\u9391]/.test(value)) return true;
      return false;
    }
    function isTreeImportDescription(text=''){
      const value = String(text || '').trim();
      if(!value) return false;
      return /imported from tree\.txt|lineage node imported|lineage reference|original label:/i.test(value);
    }
    function getTeDesc(name, description=''){
      const key = String(getDisplayNameKey(name) || '').trim();
      const mapped = teDescMap[currentLang] && teDescMap[currentLang][key] ? String(teDescMap[currentLang][key]).trim() : '';
      if(mapped && !isBrokenDescription(mapped)) return mapped;
      const rawDesc = String(description || '').trim();
      if(rawDesc && !isBrokenDescription(rawDesc) && !isTreeImportDescription(rawDesc)) return rawDesc;
      return `${getName(name,'TE')} is a transposable element node in the current knowledge graph. The current page mainly shows its position within TE lineage structure and related mechanism, disease, and literature evidence.`;
    }
    function getEntityDesc(name,type,description=''){
      const key = String(name || '').trim();
      const mapped = entityDescMap[currentLang]?.[type]?.[key] ? String(entityDescMap[currentLang][type][key]).trim() : '';
      if(mapped && !isBrokenDescription(mapped)) return mapped;
      const rawDesc = String(description || '').trim();
      if(rawDesc && !isBrokenDescription(rawDesc)) return rawDesc;
      return '';
    }
    function buildDefaultDesc(name,type,pmid=''){
      if(type==='TE') return getTeDesc(name,'');
      if(type==='Function') return `${getName(name,type,'',pmid)} is a function or mechanism node in the current graph.`;
      if(type==='Disease') return `${getName(name,type,'',pmid)} is a disease node in the current graph.`;
      if(type==='Paper') return `This node represents a literature record supporting the current graph knowledge${pmid ? ` (PMID: ${pmid})` : ''}.`;
      return '';
    }
    function getDesc(name,type,description='',pmid=''){
      const rawDesc = String(description || '').trim();
      if(type==='TE') return getTeDesc(name, rawDesc);
      if(type==='Disease' || type==='Function'){
        const entityDesc = getEntityDesc(name, type, rawDesc);
        if(entityDesc) return entityDesc;
      }
      if(rawDesc && !isBrokenDescription(rawDesc)) return rawDesc;
      const mappedDesc = descMap[currentLang] && descMap[currentLang][name] ? String(descMap[currentLang][name]).trim() : '';
      if(mappedDesc && !isBrokenDescription(mappedDesc)) return mappedDesc;
      return buildDefaultDesc(name,type,pmid);
    }
    function isPureTeTreeElements(elements=[]){
      return Array.isArray(elements) && elements.length>0 && elements.every(item=>{
        const data=item&&item.data?item.data:{};
        if(data.source && data.target) return data.relation==='SUBFAMILY_OF';
        return data.type==='TE';
      });
    }
