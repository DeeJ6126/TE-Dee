<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/path_config.php';

header('Content-Type: application/javascript; charset=UTF-8');

$payload = [
    'productName' => TEKG_PRODUCT_NAME,
    'productFullName' => TEKG_PRODUCT_FULL_NAME,
    'appBase' => TEKG_APP_URL_BASE,
    'apiBase' => TEKG_API_URL_BASE,
    'assetsBase' => TEKG_ASSETS_URL_BASE,
    'dataBase' => TEKG_DATA_URL_BASE,
    'terminologyBase' => tekg_data_url('terminology'),
];
?>
(function (global) {
  const seed = <?= json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

  function normalizeBase(value) {
    return String(value || '').replace(/\/+$/, '');
  }

  function normalizeSuffix(value) {
    return String(value || '').replace(/\\/g, '/').replace(/^\/+/, '');
  }

  function join(base, suffix) {
    const cleanBase = normalizeBase(base);
    const cleanSuffix = normalizeSuffix(suffix);
    return cleanSuffix ? `${cleanBase}/${cleanSuffix}` : cleanBase;
  }

  const paths = {
    productName: String(seed.productName || ''),
    productFullName: String(seed.productFullName || ''),
    appBase: normalizeBase(seed.appBase),
    apiBase: normalizeBase(seed.apiBase),
    assetsBase: normalizeBase(seed.assetsBase),
    dataBase: normalizeBase(seed.dataBase),
    terminologyBase: normalizeBase(seed.terminologyBase),
    join,
    appUrl(suffix = '') {
      return join(this.appBase, suffix);
    },
    apiUrl(suffix = '') {
      return join(this.apiBase, suffix);
    },
    assetsUrl(suffix = '') {
      return join(this.assetsBase, suffix);
    },
    dataUrl(suffix = '') {
      return join(this.dataBase, suffix);
    },
    terminologyUrl(suffix = '') {
      return join(this.terminologyBase, suffix);
    },
  };

  global.__TEKG_PATHS = paths;
})(window);
