(() => {
  const header = document.getElementById('protoHeader');
  const searchInput = document.getElementById('about-search-input');
  const noResults = document.querySelector('.about-no-results');
  const navLinks = Array.from(document.querySelectorAll('.about-nav a'));
  const parentLinks = Array.from(document.querySelectorAll('.about-nav-parent'));
  const childLinks = Array.from(document.querySelectorAll('.about-nav-child'));
  const sections = Array.from(document.querySelectorAll('.about-doc-section'));
  const subsections = Array.from(document.querySelectorAll('.about-doc-subsection'));

  function syncHeader() {
    if (!header) return;
    header.classList.toggle('is-scrolled', window.scrollY > 12);
  }

  function clearChildActive() {
    childLinks.forEach((link) => link.classList.remove('is-active'));
  }

  function setActivePane(name, subsectionId = '') {
    const activeName = parentLinks.some((link) => link.dataset.pane === name) ? name : 'resource';
    parentLinks.forEach((link) => {
      link.classList.toggle('is-active', link.dataset.pane === activeName);
    });
    clearChildActive();
    if (subsectionId) {
      const child = childLinks.find((link) => link.dataset.subsection === subsectionId);
      if (child) child.classList.add('is-active');
    }
    return activeName;
  }

  function textForNode(node) {
    return (node.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
  }

  function applySearch() {
    const query = (searchInput?.value || '').trim().toLowerCase();
    const terms = query.split(/\s+/).filter(Boolean);
    let visibleSectionCount = 0;

    sections.forEach((section) => {
      const nodeMatches = (node) => {
        if (!terms.length) return true;
        const text = textForNode(node);
        return terms.every((term) => text.includes(term));
      };
      const matchingSubsections = subsections.filter((subsection) => section.contains(subsection) && nodeMatches(subsection));
      const sectionMatches = !query || nodeMatches(section.querySelector('.about-doc-header') || section);
      const isVisible = !query || sectionMatches || matchingSubsections.length > 0;
      section.hidden = !isVisible;
      if (isVisible) visibleSectionCount += 1;

      subsections
        .filter((subsection) => section.contains(subsection))
        .forEach((subsection) => {
          subsection.hidden = Boolean(query) && !sectionMatches && !nodeMatches(subsection);
        });
    });

    parentLinks.forEach((link) => {
      const section = document.querySelector(`#section-${link.dataset.pane}`);
      link.hidden = Boolean(query) && Boolean(section?.hidden);
    });

    childLinks.forEach((link) => {
      const target = document.getElementById(link.dataset.subsection || '');
      const parent = document.querySelector(`#section-${link.dataset.pane}`);
      link.hidden = Boolean(query) && (Boolean(parent?.hidden) || Boolean(target?.hidden));
    });

    if (noResults) noResults.hidden = visibleSectionCount > 0;
  }

  navLinks.forEach((link) => {
    link.addEventListener('click', (event) => {
      event.preventDefault();
      const paneName = link.dataset.pane;
      if (!paneName) return;
      if (searchInput && searchInput.value) {
        searchInput.value = '';
        applySearch();
      }
      const targetId = link.dataset.subsection || `section-${paneName}`;
      const activeName = setActivePane(paneName, link.dataset.subsection || '');
      const target = document.getElementById(targetId);
      if (target) {
        const top = target.getBoundingClientRect().top + window.scrollY - 112;
        const root = document.documentElement;
        const previousScrollBehavior = root.style.scrollBehavior;
        root.style.scrollBehavior = 'auto';
        window.scrollTo(0, Math.max(0, top));
        root.style.scrollBehavior = previousScrollBehavior;
      }
      window.history.replaceState({}, '', link.dataset.subsection ? `#${targetId}` : `#section-${activeName}`);
    });
  });

  if (searchInput) {
    searchInput.addEventListener('input', applySearch);
  }

  if ('IntersectionObserver' in window && sections.length) {
    const observer = new IntersectionObserver((entries) => {
      const visible = entries
        .filter((entry) => entry.isIntersecting && !entry.target.hidden)
        .sort((a, b) => b.intersectionRatio - a.intersectionRatio)[0];
      if (!visible) return;
      if (visible.target.classList.contains('about-doc-subsection')) {
        const section = visible.target.closest('.about-doc-section');
        const name = section?.dataset.section;
        if (name) setActivePane(name, visible.target.id);
        return;
      }
      const name = visible.target.dataset.section;
      if (name) setActivePane(name);
    }, { rootMargin: '-18% 0px -62% 0px', threshold: [0.12, 0.24, 0.4] });
    sections.forEach((section) => observer.observe(section));
    subsections.forEach((subsection) => observer.observe(subsection));
  }

  window.addEventListener('scroll', syncHeader, { passive: true });
  syncHeader();
  applySearch();

  const hash = window.location.hash ? window.location.hash.replace('#', '') : 'section-resource';
  const hashedTarget = document.getElementById(hash);
  if (hashedTarget?.classList.contains('about-doc-subsection')) {
    const section = hashedTarget.closest('.about-doc-section');
    setActivePane(section?.dataset.section || 'resource', hash);
  } else {
    setActivePane(hash.replace(/^section-/, ''));
  }
})();
