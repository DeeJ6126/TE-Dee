<?php
require_once __DIR__ . '/path_config.php';
$pageTitle = TEKG_PRODUCT_NAME . ' Home';
$activePage = 'home';
$protoCurrentPath = tekg_app_url('index.php');
$protoSubtitle = TEKG_PRODUCT_FULL_NAME;
$indexAssetVersion = max(
    (int)@filemtime(__FILE__),
    (int)@filemtime(tekg_assets_fs_path('css/pages/index.css')),
    (int)@filemtime(tekg_assets_fs_path('js/pages/index.js'))
);
$pageExtraStylesheets = [
    tekg_assets_url('css/pages/index.css') . '?v=' . $indexAssetVersion,
];
require __DIR__ . '/head.php';

$overviewCopy = 'TE-Dee is a comprehensive resource designed to support exploration of transposable elements, their associated diseases, molecular functions, and supporting literature in one integrated environment. This homepage highlights the overall scope of the resource, the public dataset scale, and direct paths into browsing, graph exploration, expression, download, and project information.';

$quickLinks = [
    ['title' => 'Browse', 'href' => site_url_with_state(tekg_app_url('browse.php'), $siteLang), 'icon' => 'browse'],
    ['title' => 'Graph', 'href' => site_url_with_state(tekg_app_url('preview.php'), $siteLang), 'icon' => 'graph'],
    ['title' => 'Path', 'href' => site_url_with_state(tekg_app_url('path_finder.php'), $siteLang), 'icon' => 'pathfinder'],
    ['title' => 'Expression', 'href' => site_url_with_state(tekg_app_url('expression.php'), $siteLang), 'icon' => 'expression'],
    ['title' => 'Download', 'href' => site_url_with_state(tekg_app_url('download.php'), $siteLang), 'icon' => 'download'],
    ['title' => 'About', 'href' => site_url_with_state(tekg_app_url('about.php'), $siteLang), 'icon' => 'about'],
];

?>
      <section class="hero-area">
        <div class="proto-container">
          <div class="hero-row">
            <div class="hero-content">
              <h1>Overview</h1>
              <p><?= htmlspecialchars($overviewCopy, ENT_QUOTES, 'UTF-8') ?></p>
              <a class="learn-more" href="<?= htmlspecialchars(site_url_with_state(tekg_app_url('about.php'), $siteLang), ENT_QUOTES, 'UTF-8') ?>">Learn More...</a>
            </div>
            <div class="hero-figure">
              <div class="hero-figure-frame">
                <div class="figure-canvas">
                  <div class="home-welcome-art" aria-label="Welcome to TE-Dee">
                    <div class="home-welcome-visual" aria-hidden="true">
                      <img
                        class="home-welcome-image"
                        src="<?= htmlspecialchars(tekg_assets_url('img/brand/te-dee-logo.svg'), ENT_QUOTES, 'UTF-8') ?>"
                        alt=""
                        loading="lazy"
                      >
                    </div>
                    <div class="home-welcome-copy">
                      <span>Welcome to</span>
                      <strong>TE-Dee</strong>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <section class="status-section">
        <div class="proto-container">
          <div class="section-title">
            <h3>Dataset status</h3>
            <p class="section-title-subcopy">Built from 2,308 core human TE papers and multi-layer annotation resources.</p>
          </div>
          <div class="status-layout" data-home-stats data-home-stats-url="<?= htmlspecialchars(tekg_api_url('home_stats.php'), ENT_QUOTES, 'UTF-8') ?>">
            <div class="status-donut-grid">
              <article class="status-donut-card" data-status-card="entity">
                <div class="status-donut-copy">
                  <h4>Entity Composition</h4>
                </div>
                <div class="status-donut-shell">
                  <svg class="status-donut" viewBox="0 0 360 360" role="img" aria-label="Entity composition donut chart" data-donut-chart="entity"></svg>
                  <div class="status-donut-center">
                    <div class="status-donut-total" data-donut-total="entity">--</div>
                    <div class="status-donut-label">Nodes</div>
                  </div>
                </div>
                <div class="status-legend" data-donut-legend="entity">
                  <div class="status-loading">Loading live entity statistics</div>
                </div>
              </article>
              <article class="status-donut-card" data-status-card="te">
                <div class="status-donut-copy">
                  <h4>TE Classification</h4>
                </div>
                <div class="status-donut-shell">
                  <svg class="status-donut" viewBox="0 0 360 360" role="img" aria-label="TE classification donut chart" data-donut-chart="te"></svg>
                  <div class="status-donut-center">
                    <div class="status-donut-total" data-donut-total="te">--</div>
                    <div class="status-donut-label">TE Classes</div>
                  </div>
                </div>
                <div class="status-level-control" role="group" aria-label="TE classification level">
                  <button type="button" class="is-active" data-te-level="class">Class</button>
                  <button type="button" data-te-level="order">Order</button>
                  <button type="button" data-te-level="superfamily">Superfamily</button>
                  <button type="button" data-te-level="family">Family</button>
                </div>
                <div class="status-legend" data-donut-legend="te">
                  <div class="status-loading">Loading TE classification</div>
                </div>
              </article>
              <article class="status-donut-card" data-status-card="relation">
                <div class="status-donut-copy">
                  <h4>Relation Composition</h4>
                </div>
                <div class="status-donut-shell">
                  <svg class="status-donut" viewBox="0 0 360 360" role="img" aria-label="Relation predicate composition donut chart" data-donut-chart="relation"></svg>
                  <div class="status-donut-center">
                    <div class="status-donut-total" data-donut-total="relation">--</div>
                    <div class="status-donut-label">Bio relation</div>
                  </div>
                </div>
                <div class="status-legend" data-donut-legend="relation">
                  <div class="status-loading">Loading live relation statistics</div>
                </div>
              </article>
            </div>
            <p class="status-fallback" data-home-stats-error hidden>Live dataset statistics are temporarily unavailable.</p>
          </div>
        </div>
      </section>

      <section class="links-section">
        <div class="proto-container">
          <div class="section-title">
            <h3>Quick Links</h3>
          </div>
          <div class="link-grid">
            <?php foreach ($quickLinks as $item): ?>
              <a class="link-card" href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>">
                <div class="link-card-icon">
                  <?php if ($item['icon'] === 'browse'): ?>
                    <svg viewBox="0 0 64 64" aria-hidden="true"><path d="M46 12H18C14.6863 12 12 14.6863 12 18V46C12 49.3137 14.6863 52 18 52H46C49.3137 52 52 49.3137 52 46V18C52 14.6863 49.3137 12 46 12Z" fill="#EEF6FF" stroke="currentColor" stroke-width="3"/><path d="M22 24H42M22 32H34M22 40H39" stroke="currentColor" stroke-width="3" stroke-linecap="round"/><path d="M45 45C48.3137 45 51 42.3137 51 39C51 35.6863 48.3137 33 45 33C41.6863 33 39 35.6863 39 39C39 42.3137 41.6863 45 45 45Z" fill="white" stroke="#2A9D8F" stroke-width="3"/><path d="M50 44L55 49" stroke="#2A9D8F" stroke-width="3" stroke-linecap="round"/></svg>
                  <?php elseif ($item['icon'] === 'pathfinder'): ?>
                    <svg viewBox="0 0 64 64" aria-hidden="true"><path d="M11 27L57 27" stroke="#B7C7DF" stroke-width="3.2" stroke-linecap="round"/><path d="M9 34C12.866 34 16 30.866 16 27C16 23.134 12.866 20 9 20C5.13401 20 2 23.134 2 27C2 30.866 5.13401 34 9 34Z" fill="#EEF6FF" stroke="currentColor" stroke-width="3"/><path d="M41 51C44.866 51 48 47.866 48 44C48 40.134 44.866 37 41 37C37.134 37 34 40.134 34 44C34 47.866 37.134 51 41 51Z" fill="white" stroke="currentColor" stroke-width="3"/><path d="M45 50L51 56" stroke="currentColor" stroke-width="3" stroke-linecap="round"/><path d="M55 34C58.866 34 62 30.866 62 27C62 23.134 58.866 20 55 20C51.134 20 48 23.134 48 27C48 30.866 51.134 34 55 34Z" fill="white" stroke="#DC2626" stroke-width="3"/></svg>
                  <?php elseif ($item['icon'] === 'graph'): ?>
                    <svg viewBox="0 0 62 62" aria-hidden="true"><path d="M23 21.5L7 7M23 21.5L41.5 7.5M23 21.5L9 36M55 54.5L45 32.5L23 21.5M45 32.5L20 55" fill="none" stroke="#8298B7" stroke-width="3.4" stroke-linecap="round"/><path d="M23 27.5C26.0376 27.5 28.5 25.0376 28.5 22C28.5 18.9624 26.0376 16.5 23 16.5C19.9624 16.5 17.5 18.9624 17.5 22C17.5 25.0376 19.9624 27.5 23 27.5Z" fill="#EEF6FF" stroke="currentColor" stroke-width="3"/><path d="M7 12.5C10.0376 12.5 12.5 10.0376 12.5 7C12.5 3.96243 10.0376 1.5 7 1.5C3.96243 1.5 1.5 3.96243 1.5 7C1.5 10.0376 3.96243 12.5 7 12.5Z" fill="white" stroke="#2A9D8F" stroke-width="3"/><path d="M41 13.5C44.0376 13.5 46.5 11.0376 46.5 8C46.5 4.96243 44.0376 2.5 41 2.5C37.9624 2.5 35.5 4.96243 35.5 8C35.5 11.0376 37.9624 13.5 41 13.5Z" fill="white" stroke="#DC2626" stroke-width="3"/><path d="M9 41.5C12.0376 41.5 14.5 39.0376 14.5 36C14.5 32.9624 12.0376 30.5 9 30.5C5.96243 30.5 3.5 32.9624 3.5 36C3.5 39.0376 5.96243 41.5 9 41.5Z" fill="white" stroke="#D69F1F" stroke-width="3"/><path d="M45 38.5C48.0376 38.5 50.5 36.0376 50.5 33C50.5 29.9624 48.0376 27.5 45 27.5C41.9624 27.5 39.5 29.9624 39.5 33C39.5 36.0376 41.9624 38.5 45 38.5Z" fill="#F7FBFF" stroke="#2F6FBB" stroke-width="3"/><path d="M20 60.5C23.0376 60.5 25.5 58.0376 25.5 55C25.5 51.9624 23.0376 49.5 20 49.5C16.9624 49.5 14.5 51.9624 14.5 55C14.5 58.0376 16.9624 60.5 20 60.5Z" fill="white" stroke="#8F6ED5" stroke-width="3"/><path d="M55 59.5C58.0376 59.5 60.5 57.0376 60.5 54C60.5 50.9624 58.0376 48.5 55 48.5C51.9624 48.5 49.5 50.9624 49.5 54C49.5 57.0376 51.9624 59.5 55 59.5Z" fill="white" stroke="#0F766E" stroke-width="3"/></svg>
                  <?php elseif ($item['icon'] === 'genomic'): ?>
                    <svg viewBox="0 0 64 64" aria-hidden="true"><path d="M27 21L36 21" fill="none" stroke="#2A9D8F" stroke-width="3.2" stroke-linecap="round"/><path d="M27 42H36" fill="none" stroke="#2A9D8F" stroke-width="3.2" stroke-linecap="round"/><path d="M24 28L39 28" fill="none" stroke="#2A9D8F" stroke-width="3.2" stroke-linecap="round"/><path d="M24 35L39 35" fill="none" stroke="#2A9D8F" stroke-width="3.2" stroke-linecap="round"/><path d="M40 6L29.375 15.4545L23 25.9303V37.9091L29.375 48.5455L40 58" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"/><path d="M23 58L34.25 48.5455L41 37.9091L41 26.0909L34.25 15.4545L23 6" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  <?php elseif ($item['icon'] === 'expression'): ?>
                    <svg viewBox="0 0 64 64" aria-hidden="true"><path d="M13 50H52" stroke="currentColor" stroke-width="3" stroke-linecap="round"/><path d="M22 34H19C17.8954 34 17 34.8954 17 36V48C17 49.1046 17.8954 50 19 50H22C23.1046 50 24 49.1046 24 48V36C24 34.8954 23.1046 34 22 34Z" fill="#2A9D8F"/><path d="M34 22H31C29.8954 22 29 22.8954 29 24V48C29 49.1046 29.8954 50 31 50H34C35.1046 50 36 49.1046 36 48V24C36 22.8954 35.1046 22 34 22Z" fill="#2F6FBB"/><path d="M46 14H43C41.8954 14 41 14.8954 41 16V48C41 49.1046 41.8954 50 43 50H46C47.1046 50 48 49.1046 48 48V16C48 14.8954 47.1046 14 46 14Z" fill="#E76F51"/><path d="M17 25.5C25 13.5 28.5 24.5 36.5 12.5C39.5 8.5 45 6 50 5" stroke="#8F6ED5" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  <?php elseif ($item['icon'] === 'epigenetics'): ?>
                    <svg viewBox="0 0 64 64" aria-hidden="true"><path d="M27 23H36" stroke="#2A9D8F" stroke-width="3.2" stroke-linecap="round"/><path d="M27 42H36" stroke="#2A9D8F" stroke-width="3.2" stroke-linecap="round"/><path d="M24 30H39" stroke="#2A9D8F" stroke-width="3.2" stroke-linecap="round"/><path d="M24 36H39" stroke="#2A9D8F" stroke-width="3.2" stroke-linecap="round"/><path d="M40 8L29.375 17.4545L23 27.9303V39.9091L29.375 50.5455L40 60" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round" fill="none"/><path d="M23 60L34.25 50.5455L41 39.9091L41 28.0909L34.25 17.4545L23 8" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round" fill="none"/><line x1="23" y1="31" x2="16" y2="31" stroke="#2A9D8F" stroke-width="2" stroke-linecap="round"/><circle cx="15.5" cy="31" r="2.5" fill="#2A9D8F"/><line x1="41" y1="36" x2="48" y2="36" stroke="#2A9D8F" stroke-width="2" stroke-linecap="round"/><circle cx="50.5" cy="36" r="2.5" fill="#2A9D8F"/></svg>
                  <?php elseif ($item['icon'] === 'download'): ?>
                    <svg viewBox="0 0 64 64" aria-hidden="true"><path d="M16 54H48C49.5913 54 51.1174 53.3679 52.2426 52.2426C53.3679 51.1174 54 49.5913 54 48V24L44 14H16C14.4087 14 12.8826 14.6321 11.7574 15.7574C10.6321 16.8826 10 18.4087 10 20V48C10 49.5913 10.6321 51.1174 11.7574 52.2426C12.8826 53.3679 14.4087 54 16 54Z" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linejoin="round"/><path d="M32 28V44" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"/><path d="M25 38L32 45L39 38" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  <?php elseif ($item['icon'] === 'about'): ?>
                    <svg viewBox="0 0 64 64" aria-hidden="true"><path d="M42 8H20C17.2386 8 15 10.2386 15 13V51C15 53.7614 17.2386 56 20 56H42C44.7614 56 47 53.7614 47 51V13C47 10.2386 44.7614 8 42 8Z" stroke="currentColor" stroke-width="3.2" fill="none"/><path d="M38 8V20H47" fill="white"/><path d="M38 8V20H47" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round" fill="none"/><path d="M23 27H37" stroke="currentColor" stroke-width="3" stroke-linecap="round"/><path d="M23 34H40" stroke="currentColor" stroke-width="3" stroke-linecap="round"/><path d="M23 41H35" stroke="currentColor" stroke-width="3" stroke-linecap="round"/><path d="M46 56C50.4183 56 54 52.4183 54 48C54 43.5817 50.4183 40 46 40C41.5817 40 38 43.5817 38 48C38 52.4183 41.5817 56 46 56Z" fill="white" stroke="#2A9D8F" stroke-width="3.2"/><path d="M46 47V52" stroke="#2A9D8F" stroke-width="3.2" stroke-linecap="round"/><path d="M46 44.4C46.8837 44.4 47.6 43.6837 47.6 42.8C47.6 41.9163 46.8837 41.2 46 41.2C45.1163 41.2 44.4 41.9163 44.4 42.8C44.4 43.6837 45.1163 44.4 46 44.4Z" fill="#2A9D8F"/></svg>
                  <?php else: ?>
                    <svg viewBox="0 0 64 64" aria-hidden="true"><circle cx="32" cy="32" r="18" fill="none" stroke="currentColor" stroke-width="3.2"/><path fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" d="M32 24v10"/><circle cx="32" cy="40" r="2.6" fill="currentColor"/></svg>
                  <?php endif; ?>
                </div>
                <h4><?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?></h4>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      </section>

      <script src="<?= htmlspecialchars(tekg_assets_url('js/pages/index.js') . '?v=' . $indexAssetVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
    </main>
  </div>
</body>
</html>
