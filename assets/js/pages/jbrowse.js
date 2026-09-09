(function () {
          const configNode = document.getElementById('jbrowse-page-meta');
          const meta = configNode ? JSON.parse(configNode.textContent || '{}') : {};
          const { React, createRoot, createViewState, JBrowseLinearGenomeView } = JBrowseReactLinearGenomeView;
          const mount = document.getElementById('jbrowse_linear_genome_view');
          const controls = Array.from(document.querySelectorAll('#jbrowseTrackControls input[data-track-id]'));
          const hitSelect = document.getElementById('jbrowseHitSelect');
          const defaultLocEl = document.getElementById('jbrowseDefaultLoc');
          const repeatCountEl = document.getElementById('jbrowseRepeatCount');
          const refseqCountEl = document.getElementById('jbrowseRefseqCount');
          const root = createRoot(mount);
          let runtimeConfig = null;

          function getSelectedTrackIds() {
            return controls.filter(input => input.checked).map(input => input.dataset.trackId);
          }

          function getSelectedHitParams() {
            const option = hitSelect && hitSelect.selectedOptions.length ? hitSelect.selectedOptions[0] : null;
            return {
              chrom: option ? String(option.dataset.chrom || '') : String(meta.chr || ''),
              start: option ? String(option.dataset.start || '') : String(meta.start || ''),
              end: option ? String(option.dataset.end || '') : String(meta.end || ''),
            };
          }

          function buildConfigUrl() {
            const url = new URL(window.location.href);
            const hit = getSelectedHitParams();
            if (meta.te) {
              url.searchParams.set('te', meta.te);
            }
            if (hit.chrom) {
              url.searchParams.set('chr', hit.chrom);
            }
            if (hit.start) {
              url.searchParams.set('start', hit.start);
            }
            if (hit.end) {
              url.searchParams.set('end', hit.end);
            }
            url.searchParams.set('format', 'config');
            return url.toString();
          }

          function renderBrowser() {
            if (!runtimeConfig) {
              return;
            }
            const selectedTrackIds = getSelectedTrackIds();
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

          function loadSelectedHit() {
            root.render(React.createElement('div', { className: 'jbrowse-loading' }, 'Loading selected genomic hit...'));
            fetch(buildConfigUrl(), { cache: 'no-store' })
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

          controls.forEach(input => {
            input.addEventListener('change', renderBrowser);
          });
          if (hitSelect) {
            hitSelect.addEventListener('change', loadSelectedHit);
          }

          loadSelectedHit();
        })();
