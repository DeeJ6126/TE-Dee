(function(){
  let detailNavObserver = null;
  let detailNavScrollHandler = null;

  function initDetailNav(root){
    const navLinks=Array.from(root.querySelectorAll('[data-detail-nav-link]'));
    if(navLinks.length===0){ return; }
    const sections=navLinks
      .map(link=>document.getElementById((link.getAttribute('href')||'').replace(/^#/,'')))
      .filter(Boolean);
    if(sections.length===0){ return; }

    if(detailNavObserver){
      detailNavObserver.disconnect();
      detailNavObserver = null;
    }
    if(detailNavScrollHandler){
      window.removeEventListener('scroll', detailNavScrollHandler);
      detailNavScrollHandler = null;
    }

    function setActiveSection(id){
      navLinks.forEach(function(link){
        const targetId=(link.getAttribute('href')||'').replace(/^#/,'');
        const isActive=targetId===id;
        link.classList.toggle('is-active', isActive);
        if(isActive){
          link.setAttribute('aria-current','location');
        } else {
          link.removeAttribute('aria-current');
        }
      });
    }

    function scrollToSection(section){
      const header=document.getElementById('protoHeader');
      const headerOffset=header ? header.offsetHeight + 18 : 108;
      const top=Math.max(0, Math.round(section.getBoundingClientRect().top + window.scrollY - headerOffset));
      window.scrollTo({ top: top, behavior: 'smooth' });
    }

    navLinks.forEach(function(link){
      link.addEventListener('click', function(event){
        const targetId=(link.getAttribute('href')||'').replace(/^#/,'');
        const section=targetId ? document.getElementById(targetId) : null;
        if(!section){ return; }
        event.preventDefault();
        setActiveSection(targetId);
        const url = new URL(window.location.href);
        url.hash = targetId;
        window.history.replaceState(window.history.state || {}, '', url.toString());
        scrollToSection(section);
      });
    });

    setActiveSection(sections[0].id);

    if('IntersectionObserver' in window){
      detailNavObserver = new IntersectionObserver(function(entries){
        const visible=entries
          .filter(function(entry){ return entry.isIntersecting; })
          .sort(function(a,b){ return b.intersectionRatio - a.intersectionRatio; });
        if(visible.length>0 && visible[0].target && visible[0].target.id){
          setActiveSection(visible[0].target.id);
        }
      }, {
        rootMargin: '-15% 0px -65% 0px',
        threshold: [0.05, 0.15, 0.35, 0.6]
      });
      sections.forEach(function(section){ detailNavObserver.observe(section); });
    } else {
      detailNavScrollHandler = function(){
        const pivot=window.scrollY+180;
        let current=sections[0]||null;
        sections.forEach(function(section){ if(section.offsetTop<=pivot){ current=section; } });
        if(current){ setActiveSection(current.id); }
      };
      window.addEventListener('scroll', detailNavScrollHandler, {passive:true});
      detailNavScrollHandler();
    }
  }

  function initDetailCharts(root){
    if(typeof Plotly==='undefined'){ return; }
    function lightenHex(hex, amount){
      const clean=(hex||'').replace('#','');
      if(clean.length!==6){ return hex; }
      const num=parseInt(clean,16);
      const r=(num>>16)&255; const g=(num>>8)&255; const b=num&255;
      const mix=function(channel){ return Math.min(255, Math.round(channel + (255 - channel) * amount)); };
      return '#' + [mix(r),mix(g),mix(b)].map(function(v){ return v.toString(16).padStart(2,'0'); }).join('');
    }
    root.querySelectorAll('[data-plotly-payload]').forEach(function(node){
      try {
        const payload = JSON.parse(node.getAttribute('data-plotly-payload') || '{}');
        const color = node.getAttribute('data-plot-color') || '#7fa6d8';
        const hoverColor = lightenHex(color, 0.22);
        const labels = Array.isArray(payload.labels) ? payload.labels : [];
        const chartType = payload.chart_type === 'box' ? 'box' : 'bar';
        const sampleCounts = Array.isArray(payload.sample_counts) ? payload.sample_counts : [];
        const baseColors = labels.map(function(){ return color; });
        const commonLayout = {
          title:{text: payload.title || '', font:{size:16,color:'#254670'}},
          margin:{l:68,r:22,t:54,b:128},
          paper_bgcolor:'rgba(0,0,0,0)',
          plot_bgcolor:'#ffffff',
          hovermode:'closest',
          xaxis:{tickangle:-34, automargin:true, tickfont:{size:11,color:'#4d6180'}, gridcolor:'#eef3f9'},
          yaxis:{title:{text:(chartType === 'box' ? 'Normalized Expression' : (payload.metric_label || 'Median') + ' Normalized Expression')}, automargin:true, tickfont:{size:12,color:'#4d6180'}, gridcolor:'#e7eef8', zerolinecolor:'#d7e1ef'},
          showlegend:false,
          font:{family:'Inter, Segoe UI, Microsoft YaHei, sans-serif', color:'#254670'}
        };
        const config = {
          displaylogo:false,
          responsive:true,
          modeBarButtonsToRemove:['lasso2d','select2d','autoScale2d','toggleSpikelines']
        };

        if(chartType === 'box'){
          const q1Values = Array.isArray(payload.q1_values) ? payload.q1_values : [];
          const medianValues = Array.isArray(payload.median_values) ? payload.median_values : [];
          const q3Values = Array.isArray(payload.q3_values) ? payload.q3_values : [];
          const minValues = Array.isArray(payload.min_values) ? payload.min_values : [];
          const maxValues = Array.isArray(payload.max_values) ? payload.max_values : [];
          const hoverText = labels.map(function(label, idx){
            return '<b>' + label + '</b><br>'
              + 'Min: ' + (minValues[idx] ?? '-') + '<br>'
              + 'Q1: ' + (q1Values[idx] ?? '-') + '<br>'
              + 'Median: ' + (medianValues[idx] ?? '-') + '<br>'
              + 'Q3: ' + (q3Values[idx] ?? '-') + '<br>'
              + 'Max: ' + (maxValues[idx] ?? '-') + '<br>'
              + 'Sample Count: ' + (sampleCounts[idx] || 0);
          });
          Plotly.newPlot(node, [{
            type:'box',
            name: payload.metric_label || 'Distribution',
            x: labels,
            q1: q1Values,
            median: medianValues,
            q3: q3Values,
            lowerfence: minValues,
            upperfence: maxValues,
            boxpoints:false,
            fillcolor: hoverColor,
            marker:{color:color},
            line:{color:color, width:1.6},
            hoverinfo:'text',
            text:hoverText,
            hoverlabel:{bgcolor:'#ffffff',bordercolor:'#8db7ef',font:{size:14,color:'#203d67'}}
          }], commonLayout, config);
        } else {
          const values = Array.isArray(payload.values) ? payload.values : [];
          const customData = labels.map(function(_, idx){ return [sampleCounts[idx] || 0]; });
          const hoverTemplate = '<b>%{x}</b><br>' + (payload.metric_label || 'Median') + ': %{y:.3f}<br>Sample Count: %{customdata[0]}<extra></extra>';
          Plotly.newPlot(node, [{
            type:'bar',
            x: labels,
            y: values,
            marker:{color:baseColors, line:{color:color, width:1}},
            customdata: customData,
            hovertemplate: hoverTemplate,
            hoverlabel:{bgcolor:'#ffffff',bordercolor:'#8db7ef',font:{size:14,color:'#203d67'}}
          }], Object.assign({ bargap:0.5 }, commonLayout), config).then(function(){
            node.on('plotly_hover', function(eventData){
              if(!eventData || !eventData.points || !eventData.points[0]){ return; }
              const pointIndex = eventData.points[0].pointNumber;
              const colors = labels.map(function(){ return color; });
              colors[pointIndex] = hoverColor;
              Plotly.restyle(node, {'marker.color':[colors]});
              Plotly.relayout(node, {shapes:[{type:'rect',xref:'x',yref:'paper',x0:labels[pointIndex],x1:labels[pointIndex],x0shift:-0.5,x1shift:0.5,y0:0,y1:1,fillcolor:'rgba(102,116,138,0.08)',line:{width:0},layer:'below'}]});
            });
            node.on('plotly_unhover', function(){
              Plotly.restyle(node, {'marker.color':[baseColors]});
              Plotly.relayout(node, {shapes:[]});
            });
          });
        }
      } catch (error) {
        console.error('Plotly render failed', error);
      }
    });
  }

  async function refreshDetailResults(url){
    const currentScrollY = window.scrollY;
    try {
      const response = await fetch(url, {headers:{'X-Requested-With':'fetch'}, credentials:'same-origin'});
      if(!response.ok){ throw new Error('HTTP ' + response.status); }
      const html = await response.text();
      const parser = new DOMParser();
      const doc = parser.parseFromString(html,'text/html');
      const nextPage = doc.getElementById('expressionDetailPage');
      const livePage = document.getElementById('expressionDetailPage');
      if(!nextPage || !livePage){ throw new Error('Expression detail fragment was not found in the response.'); }
      livePage.outerHTML = nextPage.outerHTML;
      window.history.pushState({ expressionDetailUrl: url }, '', url);
      window.scrollTo(0, currentScrollY);
      const freshRoot = document.getElementById('expressionDetailPage');
      if(freshRoot){ initDetailNav(freshRoot); initDetailCharts(freshRoot); initDetailAsync(freshRoot); }
    } catch(error){
      console.error('Expression detail refresh failed:', error);
      window.location.href = url;
    }
  }

  function initDetailAsync(root){
    root.querySelectorAll('[data-detail-auto-submit]').forEach(function(select){
      select.addEventListener('change', function(){
        const form = select.form;
        if(!form){ return; }
        const url = new URL(form.action || window.location.href, window.location.origin);
        const formData = new FormData(form);
        url.search = '';
        formData.forEach(function(value,key){
          if(typeof value === 'string' && value !== ''){ url.searchParams.set(key, value); }
        });
        refreshDetailResults(url.toString());
      });
    });
  }

  const root=document.getElementById('expressionDetailPage');
  if(root){ initDetailNav(root); initDetailCharts(root); initDetailAsync(root); }
  window.addEventListener('popstate', function(){ refreshDetailResults(window.location.href); });
})();

