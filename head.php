<?php
require_once __DIR__ . '/path_config.php';
require_once __DIR__ . '/site_i18n.php';

$siteLang = site_lang();
$activePage = $activePage ?? 'home';
$pageTitle = $pageTitle ?? TEKG_PRODUCT_NAME;
$protoBasePath = TEKG_APP_URL_BASE;
$protoCurrentPath = $protoCurrentPath ?? tekg_app_url('index.php');
$protoSubtitle = $protoSubtitle ?? TEKG_PRODUCT_FULL_NAME;
$pageExtraStylesheets = is_array($pageExtraStylesheets ?? null) ? $pageExtraStylesheets : [];
$protoMainClass = trim((string)($protoMainClass ?? ''));
$enableSideDeepThink = (bool)($enableSideDeepThink ?? !in_array($activePage, ['agent', 'preview'], true));
$sideDeepThinkVersion = max(
    (int)@filemtime(tekg_assets_fs_path('css/components/side-deepthink.css')),
    (int)@filemtime(tekg_assets_fs_path('js/components/deepthink-client.js')),
    (int)@filemtime(tekg_assets_fs_path('js/components/side-deepthink.js'))
);
$brandLogoUrl = tekg_assets_url('img/brand/te-dee-logo.svg');
$brandLogoVersion = (int)@filemtime(tekg_assets_fs_path('img/brand/te-dee-logo.svg'));

$navItems = [
    'home' => ['label' => 'Home', 'href' => tekg_app_url('index.php')],
    'browse' => ['label' => 'Browse', 'href' => tekg_app_url('browse.php')],
    'preview' => ['label' => 'Graph', 'href' => tekg_app_url('preview.php')],
    'path_finder' => ['label' => 'Path', 'href' => tekg_app_url('path_finder.php')],
    'agent' => ['label' => 'Agent', 'href' => tekg_app_url('agent.php')],
    'expression' => ['label' => 'Expression', 'href' => tekg_app_url('expression.php')],
    'download' => ['label' => 'Download', 'href' => tekg_app_url('download.php')],
    'about' => ['label' => 'About', 'href' => tekg_app_url('about.php')],
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars($brandLogoUrl . '?v=' . $brandLogoVersion, ENT_QUOTES, 'UTF-8') ?>">
  <link rel="apple-touch-icon" href="<?= htmlspecialchars($brandLogoUrl . '?v=' . $brandLogoVersion, ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars(tekg_assets_url('css/layout.css'), ENT_QUOTES, 'UTF-8') ?>">
  <?php if ($enableSideDeepThink): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(tekg_assets_url('css/components/side-deepthink.css') . '?v=' . $sideDeepThinkVersion, ENT_QUOTES, 'UTF-8') ?>">
  <?php endif; ?>
  <script src="<?= htmlspecialchars(tekg_assets_url('js/tekg_paths.php'), ENT_QUOTES, 'UTF-8') ?>"></script>
  <?php if ($enableSideDeepThink): ?>
    <script src="<?= htmlspecialchars(tekg_assets_url('js/components/deepthink-client.js') . '?v=' . $sideDeepThinkVersion, ENT_QUOTES, 'UTF-8') ?>" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js" defer></script>
    <script src="<?= htmlspecialchars(tekg_assets_url('js/components/side-deepthink.js') . '?v=' . $sideDeepThinkVersion, ENT_QUOTES, 'UTF-8') ?>" defer></script>
  <?php endif; ?>
  <?php foreach ($pageExtraStylesheets as $stylesheet): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars((string)$stylesheet, ENT_QUOTES, 'UTF-8') ?>">
  <?php endforeach; ?>
</head>
<body>
  <div class="proto-shell">
    <header class="proto-header" id="protoHeader">
      <div class="proto-header-inner">
        <div class="proto-brand">
          <img class="proto-brand-logo" src="<?= htmlspecialchars($brandLogoUrl . '?v=' . $brandLogoVersion, ENT_QUOTES, 'UTF-8') ?>" alt="TE-Dee logo">
          <div>
            <h1 class="proto-brand-title"><?= htmlspecialchars(TEKG_PRODUCT_NAME, ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="proto-brand-subtitle"><?= htmlspecialchars($protoSubtitle, ENT_QUOTES, 'UTF-8') ?></p>
          </div>
        </div>

        <div class="proto-header-right">
          <nav class="proto-nav" aria-label="Primary">
            <?php foreach ($navItems as $key => $item): ?>
              <a class="proto-nav-link<?= $activePage === $key ? ' is-active' : '' ?>" href="<?= htmlspecialchars(site_url_with_state($item['href']), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></a>
            <?php endforeach; ?>
          </nav>

        </div>
      </div>
    </header>
    <?php if ($enableSideDeepThink): ?>
      <?php require __DIR__ . '/templates/components/side_deepthink.php'; ?>
    <?php endif; ?>
    <main class="proto-main<?= $protoMainClass !== '' ? ' ' . htmlspecialchars($protoMainClass, ENT_QUOTES, 'UTF-8') : '' ?>">
