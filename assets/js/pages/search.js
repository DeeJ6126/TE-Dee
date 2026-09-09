(() => {
          const header = document.getElementById('protoHeader');
          function syncHeader() {
            if (!header) return;
            header.classList.toggle('is-scrolled', window.scrollY > 12);
          }
          window.addEventListener('scroll', syncHeader, { passive: true });
          syncHeader();
        })();

(function () {
        const copyButton = document.getElementById('search-sequence-copy');
        const sequence = document.querySelector('.sequence-code[data-raw-sequence]');
        const status = document.getElementById('search-sequence-copy-status');
        const label = copyButton ? copyButton.querySelector('[data-copy-label]') : null;
        let resetTimer = 0;

        if (!copyButton || !sequence) {
          return;
        }

        function fallbackCopy(text) {
          const textarea = document.createElement('textarea');
          textarea.value = text;
          textarea.setAttribute('readonly', '');
          textarea.style.position = 'fixed';
          textarea.style.opacity = '0';
          document.body.appendChild(textarea);
          textarea.select();
          const copied = document.execCommand('copy');
          textarea.remove();
          if (!copied) {
            throw new Error('Clipboard copy was rejected');
          }
        }

        async function copyRawSequence() {
          const rawSequence = String(sequence.dataset.rawSequence || sequence.textContent || '').replace(/\s+/g, '');
          if (!rawSequence) {
            throw new Error('No sequence is available to copy');
          }
          if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            await navigator.clipboard.writeText(rawSequence);
            return;
          }
          fallbackCopy(rawSequence);
        }

        function setCopyState(message, className) {
          window.clearTimeout(resetTimer);
          copyButton.classList.remove('is-copied', 'is-copy-error');
          if (className) {
            copyButton.classList.add(className);
          }
          if (label) {
            label.textContent = message;
          }
          if (status) {
            status.textContent = message === 'Copied' ? 'Complete sequence copied.' : message;
          }
          resetTimer = window.setTimeout(function () {
            copyButton.classList.remove('is-copied', 'is-copy-error');
            if (label) {
              label.textContent = 'Copy';
            }
          }, 1800);
        }

        copyButton.addEventListener('click', function () {
          copyRawSequence()
            .then(function () {
              setCopyState('Copied', 'is-copied');
            })
            .catch(function (error) {
              console.error(error);
              setCopyState('Copy failed', 'is-copy-error');
            });
        });
      }());

(function () {
        const panel = document.getElementById('search-variants-panel');
        if (!panel) return;
        const configEl = document.getElementById('search-page-config');
        let config = {};
        try { config = JSON.parse(configEl ? configEl.textContent || '{}' : '{}'); } catch (error) { config = {}; }
        const apiUrl = String(config.variantsApiUrl || '');
        const query = String(config.variantsQuery || '');
        const status = document.getElementById('search-variants-status');
        const tableWrap = document.getElementById('search-variants-table-wrap');
        const pagination = document.getElementById('search-variants-pagination');
        const pageSizeSelect = document.getElementById('search-variants-page-size');
        const pageStatus = document.getElementById('search-variants-page-status');
        const pageJumpInput = document.getElementById('search-variants-page-jump');
        const prevButton = document.getElementById('search-variants-prev');
        const nextButton = document.getElementById('search-variants-next');
        const sourceTabs = Array.from(panel.querySelectorAll('[data-variant-source]'));
        const viewTabs = Array.from(panel.querySelectorAll('[data-variant-view]'));
        let source = 'eqtl';
        let view = 'variant';
        let page = 1;
        let pageSize = 10;
        let totalPages = 1;
        let controller = null;

        if (!pagination || !pageSizeSelect || !pageStatus || !pageJumpInput || !prevButton || !nextButton) return;

        function el(tag, text, className) {
          const node = document.createElement(tag);
          if (className) node.className = className;
          if (text !== undefined && text !== null) node.textContent = String(text);
          return node;
        }

        function value(v) { return v === null || v === undefined || v === '' ? '-' : v; }
        function setTabs(tabs, key, selected) {
          tabs.forEach((tab) => {
            const active = tab.dataset[key] === selected;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
          });
        }

        function renderMessage(message, className) {
          tableWrap.replaceChildren(el('div', message, className || 'variants-empty'));
          pagination.hidden = true;
        }

        function renderTable(payload) {
          if (!payload.available) {
            renderMessage(payload.unavailable_reason || 'This Variant source is unavailable.', 'variants-empty');
            return;
          }
          const rows = Array.isArray(payload.rows) ? payload.rows : [];
          if (!rows.length) {
            renderMessage('No Variants found for this Browse object.', 'variants-empty');
            return;
          }
          const table = el('table', undefined, 'variants-table');
          const headers = source === 'eqtl'
            ? (view === 'variant' ? ['Variant', 'Coordinate (hg38)', 'REF', 'ALT', 'Genes', 'Tissues', 'Min nominal p-value'] : ['Variant', 'Coordinate (hg38)', 'REF', 'ALT', 'Gene', 'Tissue', 'p-value', 'Slope'])
            : ['Record', 'Coordinate (hg38)', 'Alleles', 'Clinical significance', 'Conditions'];
          const thead = el('thead');
          const headRow = el('tr');
          headers.forEach((header) => headRow.appendChild(el('th', header)));
          thead.appendChild(headRow); table.appendChild(thead);
          const tbody = el('tbody');
          rows.forEach((row) => {
            const tr = el('tr');
            const coordinate = `${value(row.chrom)}:${Number(row.variant_start || row.start || 0) + 1}-${value(row.variant_end || row.end)}`;
            const cells = source === 'eqtl'
              ? (view === 'variant'
                ? [row.variant_id, coordinate, row.ref, row.alt, row.gene_names || 'No linked gene', row.tissue_names || 'No tissue evidence', row.minimum_pval_nominal]
                : [row.variant_id, coordinate, row.ref, row.alt, row.gene_name, row.tissue_name, row.pval_nominal, row.slope])
              : [row.record_id, coordinate, row.alleles || '-', row.clinical_significance || '-', row.conditions || '-'];
            cells.forEach((cell) => tr.appendChild(el('td', value(cell))));
            tr.addEventListener('click', () => {
              const existing = tr.nextElementSibling;
              if (existing && existing.classList.contains('variants-detail-row')) { existing.remove(); return; }
              const detailRow = el('tr', undefined, 'variants-detail-row');
              const detailCell = el('td'); detailCell.colSpan = headers.length;
              const detail = el('div', undefined, 'variants-detail');
              if (source === 'eqtl') {
                detail.appendChild(el('div', `Variant: ${value(row.variant_id)}`));
                detail.appendChild(el('div', `Coordinate: ${coordinate} | REF/ALT: ${value(row.ref)}/${value(row.alt)}`));
                if (view === 'variant') detail.appendChild(el('div', `Genes: ${value(row.gene_names || 'No linked gene')} | Tissues: ${value(row.tissue_names || 'No tissue evidence')} | Evidence rows: ${value(row.evidence_row_count)}`));
                else detail.appendChild(el('div', `Gene: ${value(row.gene_name)} | Tissue: ${value(row.tissue_name)} | p-value: ${value(row.pval_nominal)} | Slope: ${value(row.slope)} | AF: ${value(row.af)}`));
              } else {
                detail.appendChild(el('div', `Record: ${value(row.record_id)}`));
                detail.appendChild(el('div', `Clinical significance: ${value(row.clinical_significance)} | Conditions: ${value(row.conditions)}`));
              }
              detailCell.appendChild(detail); detailRow.appendChild(detailCell); tr.after(detailRow);
            });
            tbody.appendChild(tr);
          });
          table.appendChild(tbody); tableWrap.replaceChildren(table);
          const total = Number(payload.total || 0);
          totalPages = Math.max(1, Math.ceil(total / pageSize));
          const start = (page - 1) * pageSize + 1;
          const end = Math.min(start + rows.length - 1, total);
          pageStatus.textContent = `${start.toLocaleString()} - ${end.toLocaleString()} of ${total.toLocaleString()}`;
          pageJumpInput.value = String(page);
          pageJumpInput.max = String(totalPages);
          prevButton.disabled = page <= 1;
          nextButton.disabled = page >= totalPages;
          pagination.hidden = false;
        }

        function renderLoading() {
          tableWrap.replaceChildren();
          const skeleton = el('div', undefined, 'variants-loading-skeleton');
          for (let i = 0; i < 4; i += 1) skeleton.appendChild(el('span'));
          tableWrap.appendChild(skeleton);
          pagination.hidden = true;
        }

        async function load() {
          if (!apiUrl || !query) { renderMessage('Variant data is unavailable for this page.'); return; }
          if (controller) controller.abort();
          controller = new AbortController();
          status.textContent = 'Loading';
          renderLoading();
          const params = new URLSearchParams({ te: query, source, view, page: String(page), page_size: String(pageSize) });
          try {
            const response = await fetch(`${apiUrl}?${params.toString()}`, { cache: 'no-store', signal: controller.signal });
            const payload = await response.json();
            if (!response.ok || !payload.ok) throw new Error(payload.error && payload.error.message ? payload.error.message : 'Variant request failed');
            renderTable(payload); status.textContent = payload.available ? `${Number(payload.total || 0).toLocaleString()} records` : (payload.unavailable_reason || 'Unavailable');
          } catch (error) {
            if (error && error.name === 'AbortError') return;
            renderMessage(error.message || 'Variant data is unavailable right now.', 'variants-error'); status.textContent = 'Unable to load variants';
          }
        }

        sourceTabs.forEach((tab) => tab.addEventListener('click', () => {
          source = tab.dataset.variantSource || 'eqtl'; page = 1;
          const eqtl = source === 'eqtl'; viewTabs.forEach((viewTab) => { viewTab.disabled = !eqtl; });
          if (!eqtl) view = 'variant';
          setTabs(sourceTabs, 'variantSource', source); setTabs(viewTabs, 'variantView', view); load();
        }));
        viewTabs.forEach((tab) => tab.addEventListener('click', () => { if (source !== 'eqtl') return; view = tab.dataset.variantView || 'variant'; page = 1; setTabs(viewTabs, 'variantView', view); load(); }));
        pageSizeSelect.addEventListener('change', () => {
          const selected = Number.parseInt(pageSizeSelect.value || '10', 10);
          pageSize = Number.isNaN(selected) ? 10 : selected;
          page = 1;
          load();
        });
        function jumpToPage() {
          const requested = Number.parseInt(pageJumpInput.value || String(page), 10);
          if (Number.isNaN(requested)) {
            pageJumpInput.value = String(page);
            return;
          }
          const nextPage = Math.max(1, Math.min(requested, totalPages));
          pageJumpInput.value = String(nextPage);
          if (nextPage === page) return;
          page = nextPage;
          load();
        }
        pageJumpInput.addEventListener('keydown', (event) => { if (event.key === 'Enter') jumpToPage(); });
        pageJumpInput.addEventListener('change', jumpToPage);
        prevButton.addEventListener('click', () => { if (page > 1) { page -= 1; load(); } });
        nextButton.addEventListener('click', () => { if (page < totalPages) { page += 1; load(); } });
        viewTabs.forEach((tab) => { tab.disabled = false; });
        load();
      }());

(function () {
        const panel = document.getElementById('search-karyotype-panel');
        const view = document.getElementById('search-karyotype-view');
        const status = document.getElementById('search-karyotype-status');

        if (!panel || !view) {
          return;
        }

        const dataPath = view.dataset.karyotypePath || '';
        if (!dataPath || typeof window.Karyotype !== 'function') {
          if (status) {
            status.textContent = 'Genome annotation distribution is unavailable right now.';
          }
          return;
        }

        fetch(dataPath, { cache: 'no-store' })
          .then(function (response) {
            if (!response.ok) {
              throw new Error('Failed to load karyotype data');
            }
            return response.json();
          })
          .then(function (data) {
            view.innerHTML = '';
            new window.Karyotype(view, data);
            if (status) {
              status.hidden = true;
            }
          })
          .catch(function (error) {
            console.error(error);
            if (status) {
              status.textContent = 'Genome annotation distribution is unavailable right now.';
              status.hidden = false;
            }
          });
      }());

(function () {
        const mount = document.getElementById('search_jbrowse_linear_genome_view');
        const controls = Array.from(document.querySelectorAll('#searchJBrowseTrackControls input[data-track-id]'));
        const hitSelect = document.getElementById('searchJBrowseHitSelect');
        const restoreHitsBtn = document.getElementById('searchJBrowseRestoreHits');
        const hitScopeEl = document.getElementById('searchJBrowseHitScope');
        const hitPickerEl = hitSelect ? hitSelect.closest('.jbrowse-hit-picker') : null;
        const karyotypeView = document.getElementById('search-karyotype-view');
        const karyotypeFeedbackEl = document.getElementById('search-karyotype-feedback');
        const repeatCountEl = document.getElementById('searchJBrowseRepeatCount');
        const refseqCountEl = document.getElementById('searchJBrowseRefseqCount');
        const defaultLocEl = document.getElementById('searchJBrowseDefaultLoc');
        const configNode = document.getElementById('search-page-config');
        const pageConfig = configNode ? JSON.parse(configNode.textContent || '{}') : {};
        const browserBaseUrl = String(pageConfig.browserBaseUrl || '');
        const configUrl = String(pageConfig.configUrl || '');
        const karyotypeHitMap = pageConfig && typeof pageConfig.karyotypeHitMap === 'object' ? pageConfig.karyotypeHitMap : {};

        if (!mount || !configUrl || typeof window.JBrowseReactLinearGenomeView === 'undefined') {
          return;
        }

        const { React, createRoot, createViewState, JBrowseLinearGenomeView } = window.JBrowseReactLinearGenomeView;
        const root = createRoot(mount);
        let runtimeConfig = null;
        let feedbackTimer = 0;
        const browserHeightBounds = { min: 250, max: 780 };
        const trackHeightEstimates = {
          repeats_hg38: 170,
          ncbi_refseq_window: 150,
          clinvar_variants: 110,
          clinvar_cnv: 110,
        };

        function hitKeyFromParts(chrom, start, end) {
          const safeChrom = String(chrom || '').trim();
          const safeStart = String(start ?? '').trim();
          const safeEnd = String(end ?? '').trim();
          return safeChrom && safeStart && safeEnd ? `${safeChrom}:${safeStart}-${safeEnd}` : '';
        }

        function buildHitOptionData(hit, index, prefix) {
          const chrom = String(hit && hit.chrom ? hit.chrom : '').trim();
          const start = String(hit && hit.start !== undefined ? hit.start : '').trim();
          const end = String(hit && hit.end !== undefined ? hit.end : '').trim();
          return {
            value: `${prefix || 'hit'}-${index}`,
            chrom,
            start,
            end,
            strand: String(hit && hit.strand ? hit.strand : '+'),
            length: String(hit && hit.length !== undefined ? hit.length : ''),
            score: String(hit && hit.score !== undefined ? hit.score : ''),
            label: String(hit && hit.label ? hit.label : (chrom && start && end ? `${chrom}:${Number(start) + 1}-${end}` : `${prefix || 'hit'}-${index}`)),
          };
        }

        const sampledHitOptions = hitSelect
          ? Array.from(hitSelect.options).map((option, index) => ({
              value: String(option.value || `sample-${index}`),
              chrom: String(option.dataset.chrom || ''),
              start: String(option.dataset.start || ''),
              end: String(option.dataset.end || ''),
              strand: String(option.dataset.strand || '+'),
              length: String(option.dataset.length || ''),
              score: String(option.dataset.score || ''),
              label: String(option.textContent || '').trim(),
            }))
          : [];

        function getSelectedTrackIds() {
          return controls.filter(input => input.checked).map(input => input.dataset.trackId);
        }

        function clampGenomeBrowserHeight(value) {
          return Math.max(browserHeightBounds.min, Math.min(browserHeightBounds.max, Math.round(value)));
        }

        function estimateGenomeBrowserHeight(selectedTrackIds) {
          const selected = Array.isArray(selectedTrackIds) ? selectedTrackIds : [];
          const trackHeight = selected.reduce(function (total, trackId) {
            return total + Number(trackHeightEstimates[trackId] || 120);
          }, 0);
          return clampGenomeBrowserHeight(170 + trackHeight);
        }

        function applyGenomeBrowserHeight(height) {
          const normalized = clampGenomeBrowserHeight(height);
          mount.style.setProperty('--jbrowse-view-height', `${normalized}px`);
          mount.dataset.browserHeight = String(normalized);
          return normalized;
        }

        function syncGenomeBrowserHeight(allowMeasurement) {
          const estimated = estimateGenomeBrowserHeight(getSelectedTrackIds());
          const applied = applyGenomeBrowserHeight(estimated);
          if (!allowMeasurement) {
            return applied;
          }
          const content = mount.firstElementChild;
          if (!content) {
            return applied;
          }
          const measured = Math.max(content.scrollHeight || 0, Math.ceil(content.getBoundingClientRect().height || 0));
          if (!Number.isFinite(measured) || measured < browserHeightBounds.min || measured > browserHeightBounds.max) {
            return applied;
          }
          if (Math.abs(measured - applied) < 24) {
            return applied;
          }
          return applyGenomeBrowserHeight(measured);
        }

        function getSelectedHitParams() {
          const option = hitSelect && hitSelect.selectedOptions.length ? hitSelect.selectedOptions[0] : null;
          return {
            chrom: option ? String(option.dataset.chrom || '') : '',
            start: option ? String(option.dataset.start || '') : '',
            end: option ? String(option.dataset.end || '') : '',
          };
        }

        function setHitScope(text, showRestore) {
          if (hitScopeEl) {
            const normalized = String(text || '').trim();
            hitScopeEl.textContent = normalized;
            hitScopeEl.hidden = normalized === '';
          }
          if (restoreHitsBtn) {
            restoreHitsBtn.hidden = !showRestore;
          }
        }

        function showGenomicHitUpdated() {
          window.clearTimeout(feedbackTimer);
          if (karyotypeFeedbackEl) {
            karyotypeFeedbackEl.textContent = 'Genomic hit updated';
            karyotypeFeedbackEl.hidden = false;
            window.requestAnimationFrame(function () {
              karyotypeFeedbackEl.classList.add('is-visible');
            });
          }
          if (hitPickerEl) {
            hitPickerEl.classList.add('is-hit-updated');
          }
          feedbackTimer = window.setTimeout(function () {
            if (karyotypeFeedbackEl) {
              karyotypeFeedbackEl.classList.remove('is-visible');
              window.setTimeout(function () {
                if (!karyotypeFeedbackEl.classList.contains('is-visible')) {
                  karyotypeFeedbackEl.hidden = true;
                }
              }, 200);
            }
            if (hitPickerEl) {
              hitPickerEl.classList.remove('is-hit-updated');
            }
          }, 2400);
        }

        function renderHitOptions(options, preferredKey) {
          if (!hitSelect) {
            return;
          }
          const normalizedOptions = Array.isArray(options) ? options : [];
          hitSelect.innerHTML = '';
          if (normalizedOptions.length === 0) {
            return;
          }
          let selectedIndex = 0;
          normalizedOptions.forEach((item, index) => {
            const option = document.createElement('option');
            option.value = String(item.value || `hit-${index}`);
            option.dataset.chrom = String(item.chrom || '');
            option.dataset.start = String(item.start || '');
            option.dataset.end = String(item.end || '');
            if (item.strand !== undefined) option.dataset.strand = String(item.strand || '+');
            if (item.length !== undefined) option.dataset.length = String(item.length || '');
            if (item.score !== undefined) option.dataset.score = String(item.score || '');
            option.textContent = String(item.label || option.value);
            const optionKey = hitKeyFromParts(option.dataset.chrom, option.dataset.start, option.dataset.end);
            if (preferredKey && optionKey === preferredKey) {
              selectedIndex = index;
            }
            hitSelect.appendChild(option);
          });
          if (hitSelect.options.length > 0) {
            hitSelect.selectedIndex = selectedIndex;
          }
        }

        function restoreSampledHitOptions() {
          const currentHit = getSelectedHitParams();
          const preferredKey = hitKeyFromParts(currentHit.chrom, currentHit.start, currentHit.end);
          renderHitOptions(sampledHitOptions, preferredKey);
          setHitScope('', false);
          loadConfig(buildConfigUrl());
        }

        function buildBrowserUrl() {
          const url = new URL(browserBaseUrl, window.location.origin);
          const hit = getSelectedHitParams();
          if (hit.chrom) {
            url.searchParams.set('chr', hit.chrom);
          }
          if (hit.start) {
            url.searchParams.set('start', hit.start);
          }
          if (hit.end) {
            url.searchParams.set('end', hit.end);
          }
          url.searchParams.delete('format');
          return url.toString();
        }

        function buildConfigUrl() {
          const url = new URL(buildBrowserUrl());
          url.searchParams.set('format', 'config');
          return url.toString();
        }

        function renderBrowser() {
          if (!runtimeConfig) {
            return;
          }
          const selectedTrackIds = getSelectedTrackIds();
          syncGenomeBrowserHeight(false);
          const trackConfigs = [
            {
              type: 'FeatureTrack',
              trackId: 'repeats_hg38',
              name: 'Repeats',
              assemblyNames: ['hg38'],
              category: ['Annotation'],
              adapter: {
                type: 'Gff3Adapter',
                gffLocation: { uri: runtimeConfig.repeatTrackUrl },
              },
            },
            {
              type: 'FeatureTrack',
              trackId: 'ncbi_refseq_window',
              name: 'NCBI RefSeq',
              assemblyNames: ['hg38'],
              category: ['Annotation'],
              adapter: {
                type: 'Gff3Adapter',
                gffLocation: { uri: runtimeConfig.refseqTrackUrl },
              },
            },
            {
              type: 'FeatureTrack',
              trackId: 'clinvar_variants',
              name: 'ClinVar variants',
              assemblyNames: ['hg38'],
              category: ['ClinVar'],
              adapter: {
                type: 'BigBedAdapter',
                uri: runtimeConfig.clinvarMainUrl,
              },
            },
            {
              type: 'FeatureTrack',
              trackId: 'clinvar_cnv',
              name: 'ClinVar CNV',
              assemblyNames: ['hg38'],
              category: ['ClinVar'],
              adapter: {
                type: 'BigBedAdapter',
                uri: runtimeConfig.clinvarCnvUrl,
              },
            },
          ];
          const selectedTracks = trackConfigs.filter(track => selectedTrackIds.includes(track.trackId));
          const state = new createViewState({
            assembly: {
              name: 'hg38',
              sequence: {
                type: 'ReferenceSequenceTrack',
                trackId: 'hg38_reference',
                name: 'Reference sequence',
                assemblyNames: ['hg38'],
                adapter: {
                  type: 'IndexedFastaAdapter',
                  fastaLocation: { uri: runtimeConfig.fastaUrl },
                  faiLocation: { uri: runtimeConfig.faiUrl },
                },
              },
            },
            tracks: selectedTracks,
            defaultSession: {
              name: runtimeConfig.pageMeta && runtimeConfig.pageMeta.te ? `JBrowse - ${runtimeConfig.pageMeta.te}` : 'JBrowse locus session',
              view: {
                id: 'linearGenomeView',
                type: 'LinearGenomeView',
                init: {
                  assembly: 'hg38',
                  loc: runtimeConfig.pageMeta.defaultLoc,
                  tracks: selectedTrackIds,
                },
              },
            },
          });
          root.render(React.createElement(JBrowseLinearGenomeView, { viewState: state }));
          window.requestAnimationFrame(function () {
            syncGenomeBrowserHeight(true);
          });
          window.setTimeout(function () {
            syncGenomeBrowserHeight(true);
          }, 160);
        }

        function applyConfig(config) {
          runtimeConfig = config;
          if (defaultLocEl && config.pageMeta) {
            defaultLocEl.textContent = String(config.pageMeta.defaultLoc ?? '-');
          }
          if (repeatCountEl && config.pageMeta) {
            repeatCountEl.textContent = String(config.pageMeta.repeatFeatureCount ?? '-');
          }
          if (refseqCountEl && config.pageMeta) {
            refseqCountEl.textContent = String(config.pageMeta.refseqFeatureCount ?? '-');
          }
          renderBrowser();
          window.requestAnimationFrame(renderBrowser);
          window.setTimeout(renderBrowser, 120);
        }

        function loadConfig(url) {
          root.render(React.createElement('div', { className: 'jbrowse-loading' }, 'Loading selected genomic hit...'));
          fetch(url, { cache: 'no-store' })
            .then(function (response) {
              if (!response.ok) {
                throw new Error('Failed to load JBrowse config');
              }
              return response.json();
            })
            .then(applyConfig)
            .catch(function (error) {
              console.error(error);
              mount.innerHTML = '<div class="jbrowse-loading">Genome browser is unavailable right now.</div>';
            });
        }

        function handleKaryotypeClick(event) {
          const detail = event && event.detail ? event.detail : {};
          const chrom = String(detail.contig || '').trim();
          const start = Number(detail.start || 0);
          const end = Number(detail.end || 0);
          if (!chrom || !start || !end) {
            return;
          }
          const key = `${chrom}:${start}-${end}`;
          const bins = karyotypeHitMap && typeof karyotypeHitMap === 'object' && karyotypeHitMap.bins ? karyotypeHitMap.bins : {};
          const bin = bins[key];
          if (!bin || !Array.isArray(bin.hits) || bin.hits.length === 0) {
            return;
          }

          const filteredHitOptions = bin.hits.map((hit, index) => buildHitOptionData(hit, index, 'bin'));
          renderHitOptions(filteredHitOptions, '');
          setHitScope(`Showing ${filteredHitOptions.length} hit${filteredHitOptions.length === 1 ? '' : 's'} in ${key}`, true);
          loadConfig(buildConfigUrl());
          showGenomicHitUpdated();
        }

        controls.forEach(input => {
          input.addEventListener('change', function () {
            syncGenomeBrowserHeight(false);
            renderBrowser();
          });
        });
        if (hitSelect) {
          hitSelect.addEventListener('change', function () {
            loadConfig(buildConfigUrl());
          });
        }
        if (restoreHitsBtn) {
          restoreHitsBtn.addEventListener('click', restoreSampledHitOptions);
        }
        if (karyotypeView && karyotypeHitMap && karyotypeHitMap.available) {
          karyotypeView.addEventListener('karyotypeclicked', handleKaryotypeClick);
        }

        loadConfig(configUrl);
      })();
(function () {
        const graphPanelEl = document.getElementById('search-graph-panel');
        const graphToggleBtn = document.getElementById('search-graph-toggle');
        const graphToggleIconEl = document.getElementById('search-graph-toggle-icon');
        const navLinks = Array.from(document.querySelectorAll('[data-detail-nav-link]'));

        function setGraphExpanded(expanded) {
          if (!graphPanelEl || !graphToggleBtn) return;
          graphPanelEl.classList.toggle('is-collapsed', !expanded);
          graphToggleBtn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
          if (graphToggleIconEl) {
            graphToggleIconEl.innerHTML = expanded ? '&#9652;' : '&#9662;';
          }
          graphToggleBtn.title = expanded ? 'Collapse local graph' : 'Expand local graph';
        }

        function setActiveSection(id) {
          navLinks.forEach((link) => {
            const isActive = link.getAttribute('href') === `#${id}`;
            link.classList.toggle('is-active', isActive);
            if (isActive) {
              link.setAttribute('aria-current', 'location');
            } else {
              link.removeAttribute('aria-current');
            }
          });
        }

        if (graphToggleBtn) {
          graphToggleBtn.addEventListener('click', function () {
            const expanded = graphPanelEl ? graphPanelEl.classList.contains('is-collapsed') : false;
            setGraphExpanded(expanded);
          });
        }

        navLinks.forEach((link) => {
          link.addEventListener('click', function () {
            const targetId = (link.getAttribute('href') || '').replace('#', '');
            if (targetId === 'search-graph-panel') {
              setGraphExpanded(true);
            }
            if (targetId) {
              setActiveSection(targetId);
            }
          });
        });

        const sections = navLinks
          .map((link) => document.getElementById((link.getAttribute('href') || '').replace('#', '')))
          .filter(Boolean);

        if (sections.length > 0) {
          setActiveSection(sections[0].id);
          if ('IntersectionObserver' in window) {
            const observer = new IntersectionObserver((entries) => {
              const visible = entries
                .filter((entry) => entry.isIntersecting)
                .sort((a, b) => b.intersectionRatio - a.intersectionRatio);
              if (visible.length > 0 && visible[0].target && visible[0].target.id) {
                setActiveSection(visible[0].target.id);
              }
            }, {
              rootMargin: '-15% 0px -65% 0px',
              threshold: [0.05, 0.15, 0.35, 0.6],
            });
            sections.forEach((section) => observer.observe(section));
          }
        }

        setGraphExpanded(false);
      }());
